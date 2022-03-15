<?php
/*
 * bspaz
 * Automatically rewrite a bookmark from followed links
 *
 * I was motivated to create this to browse large text-heavy websites
 * over a long timeframe without constantly rewriting my browser bookmarks,
 * specifically with online learning in mind. Private hosting and the
 * insensitive nature of the data mean we can store the URL in the clear
 * on the server.
 * 
 * Copyright (C) 2014
 * Kevin L. Durette <kevinthenerd@gmail.com>
 *
 * Forked from aPAz, Copyright (C) 2006
 * Emmanuel Saracco <esaracco@users.labs.libre-entreprise.org>
 *
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program; if not, write to the Free Software
 * Foundation, Inc., 59 Temple Place, Suite 330,
 * Boston, MA 02111-1307, USA.
 */

  define ('APP_VERSION', '0.2014.07.13');
  define ('APP_TITLE', 'bspaz - A Bookmarking Fork of aPAz');

  /* Enable/Disable encryption of page links (so Web server logs 
   * could not/could be used to spy your web targets). */
  define ('A_CRYPT_ENABLED', 0);
  /* Default URL to display */
  define ('A_DEFAULT_URL', 'http://docs.oracle.com/javase/tutorial/');
  /* Filename to store bookmark */
  define ('A_URL_FILENAME', 'url.txt');

  /* Sockets connection timeout */
  define ('A_CONNECTION_TIMEOUT', 30);
  /* Prefix used to identified encrypted items */
  define ('A_CRYPTED_PREFIX', 'bspazEU');
  /* Prefix used to identified serialized items */
  define ('A_SERIALIZED_PREFIX', 'bspazSU');

  /* Main configuration array */
  $config = array ();

  $config['HTTPCodes'] = array (
    '401' => "You need authorization to access this URL",
    '403' => "Your don't have permission to acces this URL",
    '404' => "The page could not be found"
  );

  class bspaz
  {
    var $_config = array ();
    var $vars = array (); // Internal variables
    var $formVars = array (); // Submitted pages variables (from form page)
    var $_fileInfos = array ();
    var $_socket = 0;
    var $_errno = 0;
    var $_errstr = '';
    var $_errurl = '';
    var $_isSubmit = false;
    var $_mainContent = '';
    var $_endTags = '';
    var $_previousKeyEU = '';
    var $_currentKeyEU = '';
    var $_scriptServer = '';
    var $_mainServer = '';
    var $_basePath = '';
    var $_firstTime = 0;
  
    function bspaz ($config)
    {
      $this->_config = $config;

      /* Crypto is based on a new salt at every new loaded page, so it wont
       * be easy to decrypt Web server logs lines. */
      if (A_CRYPT_ENABLED)
      {
        /* Get previous EU key (to unencrypt) */
        $this->_previousKeyEU = $this->getCookie ('keyEU');
        /* Set next EU key (to encrypt) */
        if ($_SERVER['REQUEST_METHOD'] == 'POST')
        {
          $this->_currentKeyEU = $this->getUniqId ();
          $this->setCookie ('keyEU', $this->_currentKeyEU);
        }
        else
          $this->_currentKeyEU = $this->_previousKeyEU;
      }

      $this->getHTTPValues ();

      if ($this->isSubmit ())
      {
        $this->init ();
        $this->process ();
      }
      else
        $this->initFirst ();
    }

    function getHTTPValues ()
    {
      foreach (array (
        'bspazBoxState' => 'visible',
        'bspazFrame' => 0,
        'bspazHistory' => '',
        'bspazUseHistory' => 0,
        'bspazHistoryIndex' => 0,
        'bspazFormMethod' => '',
        'bspazRawURL' => '',
        'bspazMainURL' => '',
        'bspazScriptURL' => '',
        'bspazCurrentURL' => '',
        'bspazBasePath' => ''
      ) as $k => $v)
        $this->vars[$k] = $this->getHTTPVar ($k, $v);

      /* Get submitted form variables if a form was submitted */
      if ($this->vars['bspazFormMethod'])
      {
        $this->vars['bspazFormMethod'] = 
          strtolower ($this->vars['bspazFormMethod']);

        foreach ($_POST as $k => $v)
          if (!ereg ('^bspaz', $k))
            $this->formVars[$k] = $v;
      }

      $this->unserializeValues ();

      if (A_CRYPT_ENABLED)
        $this->unencryptValues ();

      if ($_SERVER['REQUEST_METHOD'] == 'POST')
      {
        $this->vars['incrustation'] = 1;

        if ($this->vars['bspazUseHistory'])
          $this->useHistory ($this->vars['bspazHistoryIndex']);
      }
    }

    function setBspazCurrentURL ()
    {
      if (empty ($this->vars['bspazCurrentURL']))
        $this->vars['bspazCurrentURL'] = $this->vars['bspazMainURL'];

      if (!preg_match ('/^\s*(http|ftp)/', $this->vars['bspazCurrentURL']))
      {
        if (
            !empty ($this->vars['initBasePath']) && 
            $this->vars['bspazCurrentURL']{0} != '/')
          $this->vars['bspazCurrentURL'] = 
            $this->vars['initBasePath'] . '/' . $this->vars['bspazCurrentURL'];

        if (isset ($this->vars['bspazMainURLStruct']['scheme']))
          $this->vars['bspazCurrentURL'] = 
            $this->vars['bspazMainURLStruct']['scheme'] . '://' . 
            $this->vars['bspazMainURLStruct']['host'] . ':' . 
            $this->vars['bspazMainURLStruct']['port'] . '/' . 
              $this->vars['bspazCurrentURL'];
      }

      if (!($this->vars['bspazCurrentURLStruct'] = 
        @parse_url ($this->vars['bspazCurrentURL'])))
      {
        $this->_errno = 1;
        $this->_errstr = "An error occured while parsing the URL";
        return false;
      }

      /* We changing of main host */
      if (preg_match ('/^\s*(http|ftp)/', $this->vars['bspazCurrentURL']) &&
        $this->vars['bspazCurrentURLStruct']['host'] != 
          $this->vars['bspazMainURLStruct']['host'])
      {
        $this->vars['bspazMainURL'] = $this->vars['bspazCurrentURL'];
        $this->vars['bspazMainURLStruct'] = $this->vars['bspazCurrentURLStruct'];
        $this->normalizeMainURL ();
        $this->normalizeCurrentURL ();
      }

      $this->normalizeCurrentURL ();

      /* Relative to absolute URL */
      $this->vars['bspazCurrentURLStruct']['path'] = 
        $this->getAbsoluteURL ($this->vars['bspazCurrentURLStruct']['path'], 
        $this->vars['initBasePath']);

      return true;
    }

    function setBspazMainURL ()
    {
      if (!($this->vars['bspazMainURLStruct'] = 
        @parse_url ($this->vars['bspazMainURL'])))
      {
        $this->_errno = 1;
        $this->_errstr = "An error occured while parsing the URL";
        return false;
      }
      $this->normalizeMainURL ();

      return true;
    }

    function setBspazScriptURL ()
    {
      $this->vars['bspazScriptURL'] = 
        (isset ($_SERVER['HTTPS']) ? 'https' : 'http') . '://' .
        $_SERVER['HTTP_HOST'] . ':' . 
        $_SERVER['SERVER_PORT'] . $_SERVER['PHP_SELF'];
      if (!($this->vars['bspazScriptURLStruct'] = 
        @parse_url ($this->vars['bspazScriptURL'])))
      {
        $this->_errno = 1;
        $this->_errstr = 
          "An error occured while parsing the URL application script";
        return false;
      }
      $this->normalizeScriptURL ();

      return true;
    }

    function initFirst ()
    {
      $this->_firstTime = 1;
      $this->setBspazScriptURL ();
      $file_contents = file_get_contents(A_URL_FILENAME);
      if (!$file_contents)
      {
		$this->vars['bspazMainURL'] = A_DEFAULT_URL;
		$this->vars['bspazCurrentURL'] = A_DEFAULT_URL;
	  }
	  else
	  {
	  	$file_contents = trim($file_contents);
		$this->vars['bspazMainURL'] = $file_contents;
		$this->vars['bspazCurrentURL'] = $file_contents;
	  }

      $this->vars['bspazHistory'] = '';
      $this->_mainContent = $this->getDefaultPageHTML ();
    }

    function isSerialized ($value)
    {
      return (ereg ('^' . A_SERIALIZED_PREFIX, $value));
    }

    function isEncrypted ($value)
    {
      return (ereg ('^' . A_CRYPTED_PREFIX, $value));
    }

    function unserializeValues ()
    {
      foreach (array (
        'bspazHistory') as $name)
        if ($this->isSerialized (@$this->vars[$name]))
          $this->vars[$name] = $this->getUnserializedValue ($this->vars[$name]);
    }

    function unencryptValues ()
    {
      foreach (array (
        'bspazRawURL', 
        'bspazCurrentURL') as $name)
        if ($this->isEncrypted ($this->vars[$name]))
          $this->vars[$name] = $this->getUncryptedValue ($this->vars[$name]);
    }

    function getUniqId ()
    {
      srand ((double) microtime () * 1000000);

      return md5 (rand (0, time ()));
    }

    function _keyEU ($txt, $encrypt_key)
    {
      $encrypt_key = md5 ($encrypt_key);
      $ctr = 0;
      $tmp = '';

      for ($i = 0; $i < strlen($txt); $i++)
      {
        if ($ctr == strlen ($encrypt_key))
          $ctr = 0;
        $tmp .= substr ($txt, $i, 1) ^ substr ($encrypt_key, $ctr, 1);
        $ctr++;
      }

      return $tmp;
    }

    function encrypt ($txt, $key)
    {
      $encrypt_key = $this->getUniqId ();
      $ctr = 0;
      $tmp = '';

      for ($i = 0; $i < strlen ($txt); $i++)
      {
        if ($ctr == strlen ($encrypt_key)) 
          $ctr = 0;
        $tmp .= substr ($encrypt_key, $ctr, 1) . 
          (substr ($txt, $i, 1) ^ substr ($encrypt_key, $ctr, 1));
        $ctr++;
      }

      return $this->_keyEU ($tmp, $key);
    }

    function unencrypt ($txt, $key)
    {
      $txt = $this->_keyEU ($txt, $key);
      $tmp = '';

      for ($i= 0; $i < strlen ($txt); $i++)
      {
        $md5 = substr ($txt, $i, 1);
        $i++;
        $tmp .= (substr ($txt, $i, 1) ^ $md5);
      }

      return $tmp;
    }

    function getUncryptedValue ($value)
    {
      if (!A_CRYPT_ENABLED)
        return $value;

      $value = ereg_replace ('^' . A_CRYPTED_PREFIX, '', $value);
      $value = $this->unencrypt (base64_decode ($value), $this->_previousKeyEU);

      return base64_decode ($value);
    }

    function getEncryptedValue ($value)
    {
      if (!A_CRYPT_ENABLED)
        return $value;

      $value = base64_encode ($value);

      return A_CRYPTED_PREFIX . 
        base64_encode ($this->encrypt ($value, $this->_currentKeyEU));
    }

    function getSerializedValue ($value)
    {
      return A_SERIALIZED_PREFIX . base64_encode (serialize ($value));
    }

    function getUnserializedValue ($value)
    {
      $value = ereg_replace ('^' . A_SERIALIZED_PREFIX, '', $value);
      return unserialize (base64_decode ($value));
    }

    function getCookie ($name)
    {
      if (@empty ($_COOKIE[$name]))
        return '';

      return unserialize (base64_decode ($_COOKIE[$name]));
    }

    function setCookie ($name, $value)
    {
      setcookie ($name, base64_encode (serialize ($value)), 0, '/');
    }

    function isSubmit ()
    {
      return ($this->vars['bspazMainURL'] || $this->vars['bspazRawURL']);
    }

    function htmlentities ($value)
    {
      return @htmlentities (
        urldecode ($value), ENT_QUOTES, $this->vars['pageEncoding']);
    }

    function utf8_decode ($value)
    {
      if (is_array ($value))
      {
        for ($i = 0; $i < count ($value); $i++)
          $value[$i] = $this->utf8_decode ($value[$i]);
      }
      else
      {
        return (preg_match (
          '%^(?:
             [\x09\x0A\x0D\x20-\x7E]
           | [\xC2-\xDF][\x80-\xBF]
           |  \xE0[\xA0-\xBF][\x80-\xBF]
           | [\xE1-\xEC\xEE\xEF][\x80-\xBF]{2}
           |  \xED[\x80-\x9F][\x80-\xBF]
           |  \xF0[\x90-\xBF][\x80-\xBF]{2}
           | [\xF1-\xF3][\x80-\xBF]{3}
           |  \xF4[\x80-\x8F][\x80-\xBF]{2}
           )*$%s', $value)) ?
            utf8_decode ($value) : $value;
      }
    }

    function getPageEncoding ($buf)
    {
      if (!@$this->vars['pageEncoding'])
      {
        if (preg_match ('/meta.*http-equiv.*charset=([^\',",\>]+)/si', 
            $buf, $match))
          $this->vars['pageEncoding'] = strtolower ($match[1]);
        else
          $this->vars['pageEncoding'] = 'iso-8859-1';
      }

      return $this->vars['pageEncoding'];
    }

    function updateFileInfos ($mimeType)
    {
      $extension = 'html';

      if (($pos = strpos ($mimeType, ';')) !== false)
        $mimeType = trim (substr ($mimeType, 0, $pos));

      if (($pos = strpos ($mimeType, '/')) !== false)
        $extension = substr ($mimeType, $pos + 1, 
          strlen ($mimeType) - $pos - 1);

      $this->_fileInfos = $this->getFileInfos ('', $extension);
    }

    function getFileInfos ($filename = '', $extension = '')
    {
      $ret = array (
        'mimeType' => 'text/html',
        'raw' => 0,
        'incrustation' => 0
      );

      if ($filename != '')
      {
        if (($pos = strrpos ($filename, '.')) === false)
          return $ret;
      }

      $extension = ($extension != '') ?
        $extension : strtolower (substr ($filename, $pos + 1));

      switch ($extension)
      {
        case 'gif':
          $ret['mimeType'] = 'image/gif';
          $ret['raw'] = 1;
          break;
        case 'jpeg':
        case 'jpg':
        case 'jpe':
          $ret['mimeType'] = 'image/jpeg';
          $ret['raw'] = 1;
          break;
        case 'pcx':
          $ret['mimeType'] = 'image/pcx';
          $ret['raw'] = 1;
          break;
        case 'png':
          $ret['mimeType'] = 'image/png';
          $ret['raw'] = 1;
          break;
        case 'svg':
        case 'svgz':
          $ret['mimeType'] = 'image/svg+xml';
          $ret['raw'] = 1;
          break;
        case 'tiff':
        case 'tif':
          $ret['mimeType'] = 'image/tiff';
          $ret['raw'] = 1;
          break;
        case 'ico':
          $ret['mimeType'] = 'image/x-icon';
          $ret['raw'] = 1;
          break;
        case 'bmp':
          $ret['mimeType'] = 'image/x-ms-bmp';
          $ret['raw'] = 1;
          break;
        case 'xpm':
          $ret['mimeType'] = 'image/x-xpixmap';
          $ret['raw'] = 1;
          break;
        case 'ogg':
          $ret['mimeType'] = 'application/ogg';
          $ret['raw'] = 1;
          break;
        case 'mp3':
          $ret['mimeType'] = 'audio/mpeg';
          $ret['raw'] = 1;
          break;
        case 'pdf':
          $ret['mimeType'] = 'application/pdf';
          $ret['raw'] = 1;
          break;
        case 'asc':
        case 'txt':
        case 'text':
        case 'diff':
        case 'pot':
          $ret['mimeType'] = 'text/plain';
          $ret['raw'] = 1;
          break;
        case 'css':
          $ret['mimeType'] = 'text/css';
          $ret['raw'] = 0;
          break;
        case 'rss':
          $ret['mimeType'] = 'application/rss+xml';
          $ret['raw'] = 1;
          break;
        case 'html':
        case 'htm':
        case 'shtml':
          $ret['mimeType'] = 'text/html';
          $ret['raw'] = 0;
          $ret['incrustation'] = 1;
          /* Don't rewrite our bookmark to a JPEG or something silly */
          file_put_contents(A_URL_FILENAME, $this->vars['bspazCurrentURL']);
          break;
        case 'js':
          $ret['mimeType'] = 'text/javascript';
          $ret['raw'] = 0;
          break;
        case 'zip':
          $ret['mimeType'] = 'application/zip';
          $ret['raw'] = 1;
          break;
        case 'x-gtar':
        case 'gtar':
        case 'tgz':
        case 'taz':
          $ret['mimeType'] = 'application/x-gtar';
          $ret['raw'] = 1;
          break;
        case 'tar':
          $ret['mimeType'] = 'application/x-tar';
          $ret['raw'] = 1;
          break;
        default:
          $ret['incrustation'] = 0;
      }

      return $ret;
    }

    function init ()
    {
      $this->_mainContent = '';

      if (!@$this->vars['initBasePath'])
        $this->vars['initBasePath'] = $this->vars['bspazBasePath'];

      if ($this->vars['bspazRawURL'])
      {
        $this->vars['bspazMainURL'] = $this->vars['bspazRawURL'];
        $this->vars['bspazCurrentURL'] = $this->vars['bspazRawURL'];
      }

      /* Set bspaz script URL */
      if (!$this->setBspazScriptURL ())
        return false;

      /* Set main URL */
      if (!$this->setBspazMainURL ())
        return false;

      /* Set current URL */
      if (!$this->setBspazCurrentURL ())
        return false;

      /* If current URL is different than main URL we are in another
       * Web site, so change main URL to reflect this change. */
      if (
        $this->vars['bspazCurrentURLStruct']['host'] != '' &&
        $this->vars['bspazMainURLStruct']['host'] != 
          $this->vars['bspazCurrentURLStruct']['host'])
      {
        $this->vars['bspazMainURL'] = $this->vars['bspazCurrentURL'];
        $this->vars['bspazMainURLStruct'] = $this->vars['bspazCurrentURLStruct'];
      }

      if (preg_match ('/\.(.*?){1,5}$/', 
          $this->vars['bspazCurrentURLStruct']['path']))
        $this->vars['bspazBasePath'] = 
          dirname ($this->vars['bspazCurrentURLStruct']['path']);
      else
        $this->vars['bspazBasePath'] = 
          $this->vars['bspazCurrentURLStruct']['path'];

      $this->cleanURLs ();

      if ($this->vars['bspazCurrentURLStruct']['scheme'] != 'http')
      {
        $this->_errno = 1;
        $this->_errstr = 
          "Sorry, but for the moment bspaz only supports HTTP";
        $this->_errurl = $this->vars['bspazCurrentURL'];

        $this->useHistory ($this->vars['bspazHistoryIndex']);
        $this->vars['bspazHistoryIndex']++;

        return;
      }

      $this->_scriptServer = $this->vars['bspazScriptURL'];
      $this->_basePath = $this->vars['bspazBasePath'];
      $this->_mainServer = 
        $this->vars['bspazMainURLStruct']['scheme'] . '://' . 
        $this->vars['bspazMainURLStruct']['host'] . ':' . 
        $this->vars['bspazMainURLStruct']['port'];

      $this->initCookies ();
    }

    function process ()
    {
      $this->connect (
        $this->vars['bspazCurrentURLStruct']['host'], 
        $this->vars['bspazCurrentURLStruct']['port']
      );

      if ($this->getMainContent () === false)
      {
        $this->_mainContent = '';

        $this->init ();
        $this->process ();
      }
      else
        $this->normalizeMainContent ();
    }

    function getErrorPageHTML ()
    {
      $this->_fileInfos['incrustation'] = 1;

      return "
        <html><head><title>" . APP_TITLE . " " . APP_VERSION . "</title>
        <style>
          div.bspazError
          {
            text-align: center;
            color: black;
            font-family: Arial,Helvetica;
            font-size: 16px;
            font-weight: bold;
            padding: 5px;
          }
          span.bspazError {color: green;}
        </style></head><body>
        <br /><br /><br /><br /><br /><br />
        <div class='bspazError'>
           [&nbsp;<a href=\"" . $this->_errurl . "\">" . 
            $this->_errurl . "</a>&nbsp;]
          <p /><span class='bspazError'>" . $this->_errstr . "</span><p />
           Please, check your input.
        </div></body></html>
        ";
    }

    function useHistory ($index)
    {
      if (isset ($this->vars['bspazHistory'][$index]))
      {
        $history = $this->vars['bspazHistory'][$index];

        $this->vars['bspazMainURL'] = $history['bspazMainURL'];
        $this->vars['bspazCurrentURL'] = $history['bspazCurrentURL'];
        $this->vars['bspazFormMethod'] = $history['bspazFormMethod'];
        $this->formVars = unserialize ($history['formVars']);
      }
    }

    function canAddToCookies ()
    {
      return (isset ($this->vars['currentHeader']['set-cookie']));
    }

    function getMainServerCookies ($path)
    {
      $ret = '';

      if (
          !isset ($this->_cookies[$this->_mainServer]) ||
          !is_array ($this->_cookies[$this->_mainServer]))
        return '';

      foreach ($this->_cookies[$this->_mainServer] as $name => $cookie)
        if (ereg ('^' . $cookie['path'], $path))
          $ret .= "$name=" . $cookie['value'] . '; ';

      $ret = ereg_replace ('; $', '', $ret);

      return $ret;
    }

    function addToCookies ()
    {
      $cookies = $this->vars['currentHeader']['set-cookie'];
      if (!is_array ($cookies))
        $cookies = array ($cookies);

      if (!isset ($this->_cookies[$this->_mainServer]))
        $this->_cookies[$this->_mainServer] = array ();

      // We manage neither expire date nor domain.
      foreach ($cookies as $item)
      {
        $path = $this->_basePath;

        if  (strpos ($item, ';') === false)
          $cookie = $item;
        else
        {
          $infos = explode (';', $item);
          $cookie = $infos[0]; unset ($infos[0]);
          foreach ($infos as $info)
            if (ereg ('^path', $info))
            {
              $info = preg_replace ("/(\r|\n)/", '', $info);
              list (, $path) = explode ('=', $info);
              break;
            }
        }

        list ($name, $value) = explode ('=', $cookie);
        $this->_cookies[$this->_mainServer][$name] = array (
          'value' => $value,
          'path' => $path
        );
      }

      $this->setCookie ('cookies', $this->_cookies);
    }

    function initCookies ()
    {
      $this->_cookies = $this->getCookie ('cookies');
    }

    function canAddToHistory ()
    {
      return (
        !$this->_firstTime &&
        !$this->_errno &&
        !$this->vars['bspazUseHistory']
      );
    }

    function addToHistory ()
    {
      $this->vars['bspazHistoryIndex']++;

      if (!is_array ($this->vars['bspazHistory']))
        $this->vars['bspazHistory'] = array ();

      $this->vars['bspazHistory'][$this->vars['bspazHistoryIndex']] = array (
        'bspazMainURL' => $this->vars['bspazCurrentURL'],
        'bspazCurrentURL' => $this->vars['bspazCurrentURL'],
        'bspazFormMethod' => $this->vars['bspazFormMethod'],
        'formVars' => serialize ($this->formVars)
      );
    }

    function getDefaultPageHTML ()
    {
      $this->_fileInfos['incrustation'] = 1;

      return "
        <html><head><title>" . APP_TITLE . " " . APP_VERSION . "</title>
        <style>
        </style></head><body>
        </body></html>
        ";
    }

    function displayMainContentRaw ()
    {
      print $this->_mainContent;

      $this->done ();

      exit;
    }

    function displayMainContent ()
    {
      /* Send content-type to the browser */
      if (@$this->_fileInfos['mimeType'] != '')
        header ('Content-Type: ' . $this->_fileInfos['mimeType']);

      if (
          (@$this->_fileInfos['raw']) || !$this->_fileInfos['incrustation'])
      {
        $this->displayMainContentRaw ();
        return;
      }

      /* Get/Remove end tags to install our code in current page content */
      if (preg_match (
        '/<\/(body|noframes|frameset)>([^>]*)<\/html>\s*[^--\>]?/si', 
        $this->_mainContent, $match))
      {
        if (!$this->vars['bspazFrame'])
          $this->vars['bspazFrame'] = eregi ('frame', $match[1]);

        $this->_mainContent = preg_replace (
          '/<\/(body|noframes|frameset)>(.*)<\/html>/si', 
          '',
          $this->_mainContent
        );

        $this->_endTags = '</' . $match[1] . '>' . $match[2] . '</html>';
      }

      /* Try to determinate page encoding */
      $this->vars['pageEncoding'] = 
        $this->getPageEncoding ($this->_mainContent);

      if ($this->canAddToHistory ())
        $this->addToHistory ();

      /* Display first content part */
        print $this->_mainContent;
    }

    function displayEndTags ()
    {
      /* Display second content part (HTML end tags) */
      print $this->_endTags;
    }

    // FIXME
    function getAbsoluteURL ($url, $basePath)
    {
      $new = '';

      $url = $this->cleanBufferURLs ($url);
      $basePath = $this->vars['bspazBasePath'];

      if ($url{0} != '/' && !eregi ("^$basePath", $url))
        $url = "$basePath/$url";

      $url = preg_replace (
        array (
          '/\/+/',
          '/^\/\./',
          '/([^\.]\.\/)/'
        ), 
        array (
          '/',
          '\.',
          '/'
        ), 
        $url
      );

      for ($i = 0; $i < strlen ($url); $i++)
      {
        if (substr ($url, $i, 3) != '../')
          $new .= $url{$i};
        else
        {
          $new = substr ($new ,0, strlen ($new) - 1);
          if ($new)
            $new = substr ($new, 0, strrpos ($new, '/'));
          $i++;
        }
      }

      return $new;
    }

    function normalizeMainContentRaw ()
    {
      if ($this->_errno) return;

      $p = strpos ($this->_mainContent, "\r\n\r\n") + 4;
      $this->_mainContent = 
        substr ($this->_mainContent, $p, strlen ($this->_mainContent) - $p);
    }

    /* FIXME 
     * 2/ contenu += "<a href=\"#\" onClick=\"addHomepage(this);\">label</a>";
     * -> give :
     * contenu += "<a  href="javascript:bspazPost('\"#\" onClick=\"addHomepage(this);\"')">label</a>";
     * 3/ <a href="/web/page.php?keyword=assurance sante&accroche=Comparez les mutuelles">label</a>
     * -> give:
     * <a href= javascript:bspazPost('/web/page.pho?keyword=assurance') sante&accroche=Comparez les mutuelles">label</a>
     */
    function _processHTML_A_HREF_cb ($a)
    {
      if ($a[5] == '"')
        return "$a[1]$a[2]<a $a[3] href=$a[4]$a[5]$a[6]";

      $dum = '';
      if ($a[6] == '>') 
      {
        $a[6] = '"'; 
        $dum = '>';
      }

      if (preg_match ('/\w\+\w/i', $a[5]) || 
          eregi ('(javascript|mailto)', $a[5]))
        return "$a[1]$a[2]<a $a[3] href=$a[4]$a[5]$a[6]$dum";

      if ($a[6] == "'")
      {
        $apos = ($a[1] == '"') ? '\"' : '"';
        $link = ereg_replace ('(%22|")', '\"', $a[5]);
      }
      else
      {
        $apos = ($a[1] == "'") ? "\\'" : "'";
        $link = ereg_replace ("(\%27|')", "\\'", $a[5]);
      }

      $link = ($link{0} == '#') ?
        urlencode (urldecode ($link)) :
        urlencode ($this->getEncryptedValue (urldecode ($link)));

      return "$a[1]$a[2]<a $a[3] " .
        "href=$a[6]javascript:bspazPost($apos$link$apos)$a[6]$dum";
    }

    function _processHTML_LINK_HREF_cb ($a)
    {
      $proto = eregi ('^(http|ftp)', $a[3]);

      if ($proto)
        $link = $a[3];
      elseif ($a[3]{0} == '/')
        $link = $this->_mainServer . $a[3];
      else
        $link = $this->_mainServer . '/' . $this->_basePath . '/' . $a[3];
 
      $link = urlencode ($this->getEncryptedValue (urldecode ($link)));
  
      return 
        "<link $a[1] href=$a[2]" . $this->_scriptServer . 
        "?bspazRawURL=$link$a[4]";
    }

      /* HTML - IMG, IMAGE, SCRIPT, INPUT, IFRAME, FRAME */
    function _processHTML_IMAGES_SCRIPT_INPUT_FRAMES_cb ($a)
    {
      if (
          empty ($a[4]) ||
          /* Remove google-analytics links */
          eregi ('google-analytics', $a[4]))
        return
          "$a[1]$a[2] src=$a[3]$a[5]";

      $proto = eregi ('^(http|ftp)', $a[4]);

      if ($proto)
        $link = $a[4];
      elseif ($a[4]{0} == '/')
        $link = $this->_mainServer . $a[4];
      else
        $link = $this->_mainServer . '/' . $this->_basePath . '/' . $a[4];

      $bspazFrame = '';
      if (eregi ('frame', $a[1]))
        $bspazFrame = 'bspazFrame=1&';

      $link = urlencode ($this->getEncryptedValue (urldecode ($link)));

      return 
        "$a[1]$a[2] src=$a[3]" . $this->_scriptServer . 
        "?${bspazFrame}bspazRawURL=$link$a[5]";
    }

    function _processHTML_BACKGROUND_ACTION_cb ($a)
    {
      if (strpos ($a[5], '/') === false && substr_count ($a[5], '.') > 1)
        return "<$a[1]$a[2]$a[3]=$a[4]$a[5]$a[6]$a[7]>";

      if (empty ($a[5]))
        $a[5] = $this->vars['bspazCurrentURL'];
    
      $proto = eregi ('^(http|ftp)', $a[5]);

      if ($proto)
        $link = $a[5];
      elseif ($a[5]{0} == '/')
        $link = $this->_mainServer . $a[5];
      else
        $link = $this->_mainServer . '/' . $this->_basePath . '/' . $a[5];

      $link = $this->getEncryptedValue ($link);

      /* If this is a form, do transformation to hijack post/get data */
      // FIXME We must try to deal with the "onSubmit" attribute.
      if (strtolower ($a['3']) == 'action')
      {
        $bspazFormMethod = '';

        if (preg_match ('/method\s*=\s*(\'|"|\s)?([^\'"\s]*)/', 
          $a[1], $match))
        {
          $a[1] = preg_replace ('/method\s*=\s*(\'|"|\s)?([^\'"\s]*)/',
            'method=\\1post', $a[1]);
          $bspazFormMethod = $match[2];
        }
        elseif (preg_match ('/method\s*=\s*(\'|"|\s)?([^\'"\s]*)/', 
          $a[7], $match))
        {
          $a[7] = preg_replace ('/method\s*=\s*(\'|"|\s)?([^\'"\s]*)/',
            'method=\\1post', $a[7]);
          $bspazFormMethod = $match[2];
        }
        else
        {
          $a[1] .= " method=post ";
          $bspazFormMethod = 'get';
        }
  
        $link = htmlentities ($link);
        $a[7] .= ">\n
          <input type='hidden' name='bspazFormMethod' value='$bspazFormMethod' />
          <input type='hidden' name='bspazCurrentURL' value='$link' />\n". 
          $this->writeBspazHiddenFields (true);

        $ret = 
          "<$a[1]$a[2]$a[3]=$a[4]" .  $this->_scriptServer . 
          "$a[6]$a[7]";
      }
      else
      {
        $link = urlencode (urldecode ($link));
        $ret =  
          "<$a[1]$a[2]$a[3]=$a[4]" . $this->_scriptServer . 
          "?bspazRawURL=$link$a[6]$a[7]>";
      }

      return $ret;
    }

    function _processCSS_URL_cb ($a)
    {
      $link = "$a[3]$a[4]";

      if (strpos ($a[4], '.') === false)
        return "$a[1]url$a[2]$a[3]$a[4]$a[5]$a[6]";

      $proto = eregi ('^(http|ftp)', $link);

      if ($proto)
        ;
      elseif ($link{0} == '/')
        $link = $this->_mainServer . $link;
      else
        $link = $this->_mainServer . '/' . $this->_basePath . '/' . $link;

      $link = urlencode ($this->getEncryptedValue (urldecode ($link)));

      return 
        "$a[1]url$a[2]" . $this->_scriptServer . 
        "?bspazRawURL=$link$a[5]$a[6]";
    }

    function _processCSS_IMPORT_cb ($a)
    {
      $proto = eregi ('^(http|ftp)', $a[2]);

      if ($proto)
        $link = $a[2];
      elseif ($a[2]{0} == '/')
        $link = $this->_mainServer . $a[2];
      else
        $link = $this->_mainServer . '/' . $this->_basePath . '/' . $a[2];

      $link = urlencode ($this->getEncryptedValue (urldecode ($link)));

      return 
        "$a[1]@import \"" . $this->_scriptServer . 
        "?bspazRawURL=$link\"$a[3]";
    }

    function _processJAVASCRIPT_SRC_cb ($a)
    {
      if (strpos ($a[2], '.') === false || eregi ('^eval', $a[2]))
        return ".src=$a[1]$a[2]$a[3]";

      $proto = eregi ('^(http|ftp)', $a[2]);

      if ($proto)
        $link = $a[2];
      elseif ($a[2]{0} == '/')
        $link = $this->_mainServer . $a[2];
      else
        $link = $this->_mainServer . '/' . $this->_basePath . '/' . $a[2];

      $link = urlencode ($this->getEncryptedValue (urldecode ($link)));

      return 
        ".src=$a[1]" . $this->_scriptServer . 
        "?bspazRawURL=$link$a[3]";
    }

    function _processJAVASCRIPT_URL_ASSIGNATION_cb ($a)
    {
      if (
          strpos ($a[3], '.') === false || 
          strpos ($a[3], '[') !== false || 
          $a[3]{0} == '+' || 
          eregi ('^(eval|window\.|document\.)', $a[3]))
        return "$a[1]=$a[2]$a[3]$a[4]";

      $proto = eregi ('^(http|ftp)', $a[3]);

      if ($proto)
        $link = $a[3];
      elseif ($a[3]{0} == '/')
        $link = $this->_mainServer . $a[3];
      else
        $link = $this->_mainServer . '/' . $this->_basePath . '/' . $a[3];

      $link = urlencode ($this->getEncryptedValue (urldecode ($link)));

      return 
        "$a[1]=$a[2]" . $this->_scriptServer . 
        "?bspazRawURL=$link$a[4]";
    }

    function _processJAVASCRIPT_LOCATION_cb ($a)
    {
      if (
          (strpos ($a[3], '.') === false && strpos ($a[3], '/') === false) || 
          eregi ('^(eval|window\.|document\.)', $a[3]))
        return ".location.href=$a[2]$a[3]$a[4]";

      $proto = eregi ('^(http|ftp)', $a[3]);

      if ($proto)
        $link = $a[3];
      elseif ($a[3]{0} == '/')
        $link = $this->_mainServer . $a[3];
      else
        $link = $this->_mainServer . '/' . $this->_basePath . '/' . $a[3];

      $link = urlencode ($this->getEncryptedValue (urldecode ($link)));

      return 
        ".location.href=$a[2]" . $this->_scriptServer . 
        "?bspazRawURL=$link$a[4]";
    }

    function _processJAVASCRIPT_OPEN_cb ($a)
    {
      if (strpos ($a[2], '.') === false)
        return "open($a[1]$a[2]$a[3]";

      $proto = eregi ('^(http|ftp)', $a[2]);

      if ($proto)
        $link = $a[2];
      elseif ($a[2]{0} == '/')
        $link = $this->_mainServer . $a[2];
      else
        $link = $this->_mainServer . '/' . $this->_basePath . '/' . $a[2];

      $link = urlencode ($this->getEncryptedValue (urldecode ($link)));

      return 
        "open($a[1]" . $this->_scriptServer . 
        "?bspazRawURL=$link$a[3]";
    }

    function _processALL_OtherLinks_cb ($a)
    {
      $link = "$a[2]://$a[3]";
      if (strpos ($a[3], 'bspazRawURL') !== false ||
          strpos ($link, $this->_mainServer) == 0)
        return "=$a[1]$link$a[4]";

      $link = urlencode ($this->getEncryptedValue (urldecode ($link)));

      return 
        "=$a[1]" . $this->_scriptServer . 
        "?bspazRawURL=$link$a[4]";
    }

    function _processALL_Comments_cb ($a)
    {
      return (preg_match ('/\<(style|script)/', $a[1])) ?
        "$a[1]$a[2]" : $a[1];
    }

    // FIXME
    function normalizeMainContent ()
    {
      if ($this->_errno) return;

      /* If data must not be modified (images etc.) */
      if ($this->_fileInfos['raw'])
      {
        $this->normalizeMainContentRaw ();
        return;
      }

      $p = strpos ($this->_mainContent, "\r\n\r\n") + 4;
      $this->_mainContent = 
        substr ($this->_mainContent, $p, strlen ($this->_mainContent) - $p);

      $this->_mainContent = preg_replace (
        array (
          /* Remove all targets */
          '/target\s*=\s*(\'|")?\s*[\w]+(\s|\'|")?/si',
          /* Remove AsSolution tags */
          '/\<\!--([^>]+)BEGIN\s*:\s*AdSolution(.*?)END\s*:\s*AdSolution([^>]+)--\>/si',
        ), 
        array (
          '',
          ''
        ), 
        $this->_mainContent 
      );

      /* Remove all HTML comments (but take care of HTML javascript and 
       * style comments) */
      $this->_mainContent = preg_replace_callback (
        '/(<[^>]*>)?<!--([^#]?[^--]*[^\/\/]\s+)-->/si',
        array ($this, '_processALL_Comments_cb'),
        $this->_mainContent
      );

      /* Relatives URLs conversion */
      // FIXME ne prend en compte que ce qui commence par du relatif. Il 
      // faudrait modifier tout ce qui *contient* du relatif.
      if (preg_match_all (
        '/(href|src|url|import|background)\s*(=|\()?\s*(\'|")?\s*\.(.*?)\\3/si', 
        $this->_mainContent, $match))
      foreach ($match[4] as $link)
      {
        $absolute = $this->getAbsoluteURL (".$link", $this->_basePath);
        $link = ereg_replace ('/', '\/', $link);
        $link = ereg_replace ('\.', '\\.', $link);
        $this->_mainContent = 
          preg_replace ("/\.$link/", $absolute, $this->_mainContent);
      }

      /* HTML - A HREF */
      $this->_mainContent = preg_replace_callback (
        '/(.)?(\s*)<\s*a\s*([^>]*)\s*href\s*=\s*([\',"])?\s*([^\\4].*?)(\\4|\s|\>)/si',
        array ($this, '_processHTML_A_HREF_cb'),
        $this->_mainContent 
      );

      /* HTML - LINK HREF */
      $this->_mainContent = preg_replace_callback (
        '/<\s*link\s*([^>]*)\s*href\s*=\s*(\'|")?\s*(.*?)(\s|\'|\"|\>)/si',
        array ($this, '_processHTML_LINK_HREF_cb'),
        $this->_mainContent 
      );

      /* HTML - IMG, IMAGE, SCRIPT, INPUT, IFRAME, FRAME */
      $this->_mainContent = preg_replace_callback (
        '/(img|image|script|input|iframe|frame)([^>]*)\s+src\s*=\s*(\'|"|\s)?\s*(.*?)(\s|\'|"|\>)/si',
        array ($this, '_processHTML_IMAGES_SCRIPT_INPUT_FRAMES_cb'),
        $this->_mainContent 
      );

      /* HTML - BACKGROUND, ACTION */
      $this->_mainContent = preg_replace_callback (
        '/<([^>]*)(\s*)(background|action)\s*=\s*(\'|"|\s)?\s*([^\'"\s\>]*)(\'|"|\s)?([^>]*)\>/si',
        array ($this, '_processHTML_BACKGROUND_ACTION_cb'),
        $this->_mainContent 
      );

      /* CSS - URL */
      $this->_mainContent = preg_replace_callback (
        '/(\W)url\s*([\s,\',",\(]+)([a-z,\/])\s*(.*?)([\',",\s,\)])\s*(\)?)/si',
        array ($this, '_processCSS_URL_cb'),
        $this->_mainContent 
      );

      /* CSS - IMPORT */
      $this->_mainContent = preg_replace_callback (
        '/(\W)@import\s*[\',"]+\s*(.*?)[\',"]+(\s*)/si',
        array ($this, '_processCSS_IMPORT_cb'),
        $this->_mainContent 
      );

      /* Javascript - images SRC */
      $this->_mainContent = preg_replace_callback (
        '/\.src\s*=\s*(\'|"|\s)?\s*(.*?)(\s|\'|"|\>)/si',
        array ($this, '_processJAVASCRIPT_SRC_cb'),
        $this->_mainContent 
      );

      /* Javascript - url variables assignation (hum...) */
      $this->_mainContent = preg_replace_callback (
        '/([^bspazRaw]url)\s*=\s*(\'|"|\s)?\s*(.*?)(\s|\'|"|;)/si',
        array ($this, '_processJAVASCRIPT_URL_ASSIGNATION_cb'),
        $this->_mainContent 
      );

      /* Javascript - LOCATION */
      $this->_mainContent = preg_replace_callback (
        '/\.location(\.href)?\s*=\s*(\'|"|\s)?\s*(.*?)(\s|\'|"|\>)/si',
        array ($this, '_processJAVASCRIPT_LOCATION_cb'),
        $this->_mainContent 
      );

      /* Javascript - OPEN functions' link */
      $this->_mainContent = preg_replace_callback (
        '/open\s*\(\s*(\'|")?\s*([^\'"]*)?\s*(\'|")*/si',
        array ($this, '_processJAVASCRIPT_OPEN_cb'),
        $this->_mainContent 
      );

      /* Javascript/HTML - The other links */
      $this->_mainContent = preg_replace_callback (
        '/=\s*(\'|")\s*(http|https|ftp)\:\/\/(.*?)(\'|")/si',
        array ($this, '_processALL_OtherLinks_cb'),
        $this->_mainContent 
      );
    }

    function normalizeScriptURL ()
    {
      /* Host */
      $this->vars['bspazScriptURLStruct']['host'] = 
        strtolower ($this->vars['bspazScriptURLStruct']['host']);

      /* Path */
      if (!isset ($this->vars['bspazScriptURLStruct']['path']))
        $this->vars['bspazScriptURLStruct']['path'] = '/';
    }

    function normalizeMainURL ()
    {
      /* Host */
      $this->vars['bspazMainURLStruct']['host'] = 
        strtolower (@$this->vars['bspazMainURLStruct']['host']);

      /* Port */
      if (!isset ($this->vars['bspazMainURLStruct']['port']))
        $this->vars['bspazMainURLStruct']['port'] = 80;

      /* Path */
      if (!isset ($this->vars['bspazMainURLStruct']['path']))
        $this->vars['bspazMainURLStruct']['path'] = '/';
    }

    function normalizeCurrentURL ()
    {
      /* Scheme */
      if (!isset ($this->vars['bspazCurrentURLStruct']['scheme']))
        $this->vars['bspazCurrentURLStruct']['scheme'] = 
          @$this->vars['bspazMainURLStruct']['scheme'];

      /* Host */
      if (!isset ($this->vars['bspazCurrentURLStruct']['host']))
        $this->vars['bspazCurrentURLStruct']['host'] = 
          $this->vars['bspazMainURLStruct']['host'];
      $this->vars['bspazCurrentURLStruct']['host'] = 
        strtolower ($this->vars['bspazCurrentURLStruct']['host']);

      /* Port */
      if (!isset ($this->vars['bspazCurrentURLStruct']['port']))
        $this->vars['bspazCurrentURLStruct']['port'] = 
          $this->vars['bspazMainURLStruct']['port'];

      /* Path */
      if (!isset ($this->vars['bspazCurrentURLStruct']['path']))
        $this->vars['bspazCurrentURLStruct']['path'] = 
          $this->vars['bspazMainURLStruct']['path'];
      elseif ($this->vars['bspazCurrentURLStruct']['path']{0} != '/')
        $this->vars['bspazCurrentURLStruct']['path'] = 
          $this->vars['bspazMainURLStruct']['path'] . '/' . 
            $this->vars['bspazCurrentURLStruct']['path']; 
    }

    function cleanBufferURLs ($str)
    {
      return preg_replace ('/([a-z,0-9]+)(\/+)/i', '\\1/', $str);
    }

    function cleanURLs ()
    {
      $this->vars['bspazMainURLStruct']['path'] =
        $this->cleanBufferURLs ($this->vars['bspazMainURLStruct']['path']);
      $this->vars['bspazCurrentURLStruct']['path'] = 
        $this->cleanBufferURLs ($this->vars['bspazCurrentURLStruct']['path']);
      $this->vars['bspazMainURL'] = 
        $this->cleanBufferURLs ($this->vars['bspazMainURL']); 
      $this->vars['bspazCurrentURL'] = 
        $this->cleanBufferURLs ($this->vars['bspazCurrentURL']);
      $this->vars['initBasePath'] = 
        $this->cleanBufferURLs ($this->vars['initBasePath']); 
      $this->vars['bspazBasePath'] = 
        $this->cleanBufferURLs ($this->vars['bspazBasePath']);
    }

    function connect ($host, $port)
    {
      if ($this->_errno) return;

      $this->close ();

      $this->_socket = @fsockopen (
        $host, $port, 
        $this->_errno, $this->_errstr, 
        A_CONNECTION_TIMEOUT
      );

      if (!$this->_socket)
      {
        $this->_errno = 1;
        $this->_errstr = "An error occured while connecting to the host";
        return;
      }
    }

    function getHTTPUserAgent ()
    {
      $userAgent = 
        'Mozilla/5.0 (X11; U; Linux i686; fr; rv:1.8.0.4) ' .
        'Gecko/20060406 Firefox/1.5.0.4';

      if (isset ($_SERVER['HTTP_USER_AGENT']))
        $userAgent = $_SERVER['HTTP_USER_AGENT'];

      return $userAgent;
    }

    function getFormVarsURLEncoded ($vars, $arrName = '')
    {
      $ret = '';

      foreach ($vars as $k => $v)
      {
        if (is_array ($v))
          $ret .= $this->getFormVarsURLEncoded ($v, $k);
        else
        {
          if ($arrName)
          {
            if (is_numeric ($k)) $k = '';
            $k = $arrName . "[$k]";
          }

          $ret .= "$k=" . urlencode ($v) . '&';
        }
      }

      return $ret;
    }

    // FIXME Use something cleaner than this bad stuff...
    function getNormalizedHTTPRequestURL ($url)
    {
      $url = urlencode (str_replace (
        array ('&amp;', '?', '/', '&', '=', '#', ';'),
        array ('&', 'bspazSUBST1', 'bspazSUBST2', 'bspazSUBST3', 'bspazSUBST4',
               'bspazSUBST5', 'bspazSUBST6'),
        urldecode ($url)
      ));

      return str_replace (
        array ('bspazSUBST1', 'bspazSUBST2', 'bspazSUBST3', 'bspazSUBST4',
               'bspazSUBST5', 'bspazSUBST6'),
        array ('?', '/', '&', '=', '#', ';'),
        $url
      );
    }

    function getMainContent ()
    {
      $referer =
        $this->vars['bspazCurrentURLStruct']['scheme'] . '://' . 
        $this->vars['bspazCurrentURLStruct']['host'];
      $referer .= @$this->vars['bspazCurrentURLStruct']['path'];

      $acceptCharset = (isset ($_SERVER['HTTP_ACCEPT_CHARSET']) && 
          $_SERVER['HTTP_ACCEPT_CHARSET']) ?
        $_SERVER['HTTP_ACCEPT_CHARSET'] : 'ISO-8859-1,utf-8;q=0.7,*;q=0.7';

      $acceptLanguage = ($_SERVER['HTTP_ACCEPT_LANGUAGE']) ?
        $_SERVER['HTTP_ACCEPT_LANGUAGE'] : 'en';

      /* Common part for requests */
      $common = sprintf (
        "User-Agent: %s\r\n" .
        "Host: %s\r\n" .
        "Referer: %s\r\n" .
        "Accept-Charset: %s\r\n" .
        "Accept-Language: %s\r\n" .
        "Accept: */*\r\n",
        $this->getHTTPUserAgent (),
        $this->vars['bspazCurrentURLStruct']['host'],
        $referer,
        $acceptCharset,
        $acceptLanguage
      );

      if ($cookies = $this->getMainServerCookies ($this->_basePath))
        $common .= "Cookie: $cookies\r\n";

      /* If all is ok, retreive data from the host */
      if (!$this->_errno)
      {
        /* Form submit POST */
        if ($this->vars['bspazFormMethod'] == 'post')
        {
          $args = $this->getFormVarsURLEncoded ($this->formVars);

          $url = $this->vars['bspazCurrentURLStruct']['path'];
          $url .= isset ($this->vars['bspazCurrentURLStruct']['query']) ?
            '?' . $this->vars['bspazCurrentURLStruct']['query'] : '';
          $url .= isset ($this->vars['bspazCurrentURLStruct']['fragment']) ?
            '#' . $this->vars['bspazCurrentURLStruct']['fragment'] : '';

          /* Build query */
          $query =
            "POST " . $this->getNormalizedHTTPRequestURL ($url) . 
              " HTTP/1.0\r\n" .
            $common .
            "Content-Type: application/x-www-form-urlencoded\r\n" .
            "Content-Length: " . strlen ($args) . "\r\n" .
            "\r\n" .
            $args;
        }
        /* Form submit GET */
        elseif ($this->vars['bspazFormMethod'] == 'get')
        {
          $args = $this->getFormVarsURLEncoded ($this->formVars);

          if (!isset ($this->vars['bspazCurrentURLStruct']['query']))
            $this->vars['bspazCurrentURLStruct']['query'] = '';

          $this->vars['bspazCurrentURLStruct']['query'] .= "&$args";

          $url = $this->vars['bspazCurrentURLStruct']['path'];
          $url .= '?' . $this->vars['bspazCurrentURLStruct']['query'];

          /* Build query */
          $query = 
            "GET " . $this->getNormalizedHTTPRequestURL ($url) . 
              " HTTP/1.0\r\n" .
            "$common" .
            "\r\n";
        }
        /* Default GET request */
        else
        {
          $url = $this->vars['bspazCurrentURLStruct']['path'];
          $url .= isset ($this->vars['bspazCurrentURLStruct']['query']) ?
            '?' . $this->vars['bspazCurrentURLStruct']['query'] : '';
          $url .= isset ($this->vars['bspazCurrentURLStruct']['fragment']) ?
            '#' . $this->vars['bspazCurrentURLStruct']['fragment'] : '';

          /* Build query */
          $query = 
            "GET " . $this->getNormalizedHTTPRequestURL ($url) . 
              " HTTP/1.0\r\n" .
            $common . 
            "\r\n";
        }

        /* Execute query */
        fwrite ($this->_socket, $query);
     
        /* Get data */
        $buf = '';
        while (!feof ($this->_socket))
          $buf .= fread ($this->_socket, 8192);
  
        /* Get HTTP header */
        $this->vars['currentHeader'] = $this->getHTTPHeader ($buf);

        /* Check if there is a immediate HTTP META refresh */
        if (
            preg_match ('/meta.*http-equiv.*\W0\s*;\s*url=([^\',",\>]+)/i', 
              $buf, $match) &&
            $match[1] != $this->vars['bspazCurrentURL'])
          $this->vars['currentHeader']['location'] = $match[1];

        /* Check from header if data is on another location */
        if (isset ($this->vars['currentHeader']['location']))
        {
          if ($this->vars['bspazFormMethod'])
          {
            $this->vars['bspazRawURL'] = '';
            $this->vars['bspazFormMethod'] = '';
          }

          $this->vars['bspazCurrentURL'] = 
            $this->vars['currentHeader']['location'];
 
          if (eregi ('^(http|ftp)', $this->vars['bspazCurrentURL']))
          {
            $this->vars['bspazRawURL'] = $this->vars['bspazCurrentURL'];
            $this->vars['bspazMainURL'] = $this->vars['bspazCurrentURL'];
          }

          $this->vars['bspazBasePath'] = $this->vars['initBasePath'];

          return false;
        }
        /* Check HTTP status code */
        elseif ($this->isHTTPError ($this->vars['currentHeader']['code']))
        {
          $this->_errno = $this->vars['currentHeader']['code'];
          $this->_errstr = 
            $this->_config['HTTPCodes'][$this->vars['currentHeader']['code']];
        }
        else
        {
          /* Get file informations */
          $this->updateFileInfos ($this->vars['currentHeader']['content-type']);
          $this->_mainContent = $buf;
        }
      }

      /* If a error occured, build a error page */
      if ($this->_errno)
        $this->_mainContent = $this->getErrorPageHTML ();
      elseif ($this->canAddToCookies ())
        $this->addToCookies ();
    }

    function isHTTPError ($code)
    {
      return (in_array ($code, array (
        '401',
        '403',
        '404'
      )));
    }

    function getHTTPHeader ($content)
    {
      $ret = array ();

      $p = strpos ($content, "\r\n\r\n");
      $header = substr ($content, 0, $p + 1);

      /* Get HTTP response code */
      preg_match ('/(\d{3})/', $header, $match);
      $ret['code'] = $match[1];

      /* Get other HTTP header data */
      preg_match_all ("/^(.*?):\s+(.*)$/m", $header, $match);
      for ($i = 0; $i < count ($match[1]); $i++)
      {
        $key = strtolower ($match[1][$i]);
        $value = $this->utf8_decode (urldecode ($match[2][$i]));

        if (isset ($ret[$key]))
        {
          if (is_array ($ret[$key]))
            $ret[$key][] = $value;
          else
          {
            $old = $ret[$key];
            $ret[$key] = array ($old, $value);
          }
        }
        else
          $ret[$key] = trim ($match[2][$i]);
      }

      return $ret;
    }

    function close ()
    {
      if ($this->_socket)
        fclose ($this->_socket);
    }

    function getHTTPVar ($name, $default = '')
    {
      $ret = $default;
  
      if (isset ($_GET[$name]))
        $ret = $_GET[$name];
      elseif (isset ($_POST[$name]))
        $ret = $_POST[$name];
  
      $ret = $this->utf8_decode ($ret);
  
      return $ret;
    }

    function done ()
    {
      $this->close ();
    }

    function getShortenString ($str)
    {
      return (strlen ($str) > 80) ? 
        substr ($str, 0, 80) . '...' : $str;
    }

    function browserIsIE ()
    {
      return (strpos ($_SERVER['HTTP_USER_AGENT'], 'MSIE') !== false);
    }

    function isError ()
    {
      return ($this->_errno != 0);
    }

    function writeBspazHiddenFields ($excludeBspazCurrentURL = false)
    {
      $ret = '
  <input type="hidden" name="bspazUseHistory" value="0" />
  <input type="hidden" name="bspazHistoryIndex"
         value="' . htmlentities ($this->vars['bspazHistoryIndex']) . '" />
  <input type="hidden" name="bspazHistory"
         value="' . htmlentities ($this->getSerializedValue (
          $this->vars['bspazHistory'])) . '" />
  <input type="hidden" name="bspazBoxState" id="bspazBoxState"
         value="' . htmlentities ($this->vars['bspazBoxState']) . '" />
  <input type="hidden" name="bspazMainURL" 
         value="' . $this->htmlentities ($this->vars['bspazMainURL']) . '" />
  <input type="hidden" name="bspazBasePath" 
         value="' . $this->htmlentities ($this->vars['bspazBasePath']) . '" />';

      if (!$excludeBspazCurrentURL)
        $ret .= '
  <input type="hidden" name="bspazCurrentURL" 
         value="' . 
          $this->htmlentities ($this->vars['bspazCurrentURL']) . '" />';
      
      return $ret;
    }
  }

  function debug ($var)
  {
    print "<pre>\n";
    print "bspaz - DEBUG\n";
    print_r ($var);
    print "</pre>\n";
  }

  $bspaz = new bspaz ($config);
  $bspaz->displayMainContent ();
?>

<!-- ////////////////////////// -->
<!-- BEGIN - bspaz incrustation -->
<!-- ////////////////////////// -->

<script language="javascript">

  function bspazHideShow ()
  {
    var state = document.getElementById('bspazBox').style.visibility;
    var bspazBoxState = bspazGetValue ('bspazBoxState');
    state = (state == 'hidden') ? 'visible' : 'hidden';
    bspazSetValue ('bspazBoxState', state);
    document.getElementById('bspazBox').style.visibility = state;
    document.getElementById('bspazMainURL1').style.visibility = state;
    document.getElementById('browseIt').style.visibility = state;
  }

  function bspazPrevious ()
  {
    var bspazHistoryIndex = parseInt (bspazGetValue ('bspazHistoryIndex'));
    if (bspazHistoryIndex > 1)
    {
      bspazSetValue ('bspazHistoryIndex', bspazHistoryIndex - 1);
      bspazSetValue ('bspazUseHistory', 1);
      document.forms['bspazSingleForm01'].submit ();
    }
  }

  function bspazNext ()
  {
    var bspazHistoryIndex = parseInt (bspazGetValue ('bspazHistoryIndex'));
    bspazSetValue ('bspazHistoryIndex',  bspazHistoryIndex + 1);
    bspazSetValue ('bspazUseHistory', 1);
    document.forms['bspazSingleForm01'].submit ();
  }

  function bspazRAZ ()
  {
    bspazSetValue ('bspazMainURL', '');
    bspazSetValue ('bspazCurrentURL', '');
    bspazSetValue ('bspazBasePath', '');
  }

  function bspazSetValue (name, value)
  {
    for (var i = 0; i < document.forms.length; i++)
      eval (
        "if (document.forms[i]." + name + ") " +
        "document.forms[i]." + name + ".value = value"
      );
  }

  function bspazGetValue (name)
  {
    var ret = '';
    eval ("ret = document.forms['bspazSingleForm01']." + name + ".value");
    return ret;
  }

  function bspazPost (url)
  {
    if (url[0] == '#')
      document.location.href = url;
    else
    {
      bspazSetValue ('bspazCurrentURL', url);
      document.forms['bspazSingleForm01'].submit ();
    }
  }

<?php
  if (!@empty ($bspaz->vars['bspazCurrentURLStruct']['fragment']))
    print "document.location.href='#" . 
      $bspaz->vars['bspazCurrentURLStruct']['fragment'] . "';";
?>

</script>

<style>

  div#bspazControl
  {
    position: <?php echo ($bspaz->browserIsIE ()) ? 'absolute' : 'fixed' ?>;
    top: 3px;
    left: 3px;
    z-index: 10001;
		background: navy;
    font-weight: normal;
    text-align: center;
    padding: 1px;
  }

  div#bspazControl input:hover
  {
    color: cornflowerblue;
  }

  div#bspazControl input
  {
    z-index: 10001;
    font-family: Verdana,Arial,Helvetica;
    font-size: 9px;
		background: navy;
    color: white;
    text-align: center;
    font-weight: normal;
    padding: 1px;
    margin: 1px;
    border: 1px silver solid;
  }
  
  div.bspazText
  {
    z-index: 10001;
    font-family: Verdana,Arial,Helvetica;
    font-size: 10px;
    font-weight: normal;
		background: gray;
    color: black;
    text-align: center;
    padding: 1px;
    margin: 1px;
    border: 1px silver solid;
  }

  div#bspazBox
  {
    position: <?php echo ($bspaz->browserIsIE ()) ? 'absolute' : 'fixed' ?>;
    top: 1px;
    left: 1px;
    z-index: 10000;
    font-family: Verdana,Arial,Helvetica;
    font-size: 10px;
    background: #000063;
    color: white;
    text-align: center;
    padding: 5px;
    border: 1px cornflowerblue solid;
    font-weight: bold;
  }

  div#bspazBox input
  {
    font-family: Verdana,Arial,Helvetica;
    font-size: 10px;
    background: #DB8C97;
    color: black;
    border: 1px white solid;
    font-weight: bold;
  }

  div#bspazBox a:link
  {
    font-family: Verdana,Arial,Helvetica;
    font-size: 10px;
    background: cornflowerblue;
		color: black;
    border: 1px solid silver;
    text-decoration: none;
    font-weight: normal;
  }

  div#bspazBox a:visited
  {
    font-family: Verdana,Arial,Helvetica;
    font-size: 10px;
    background: cornflowerblue;
		color: black;
    border: 1px solid silver;
    text-decoration: none;
    font-weight: normal;
  }

  div#bspazBox a:hover 
  {
    font-family: Verdana,Arial,Helvetica;
    font-size: 10px;
    background: orange;
    color: black;
    border: 1px solid white;
    text-decoration: none;
    font-weight: normal;
	}

  div#bspazHelp
  {
    font-family: Verdana,Arial,Helvetica;
    font-size: 10px;
    background: black;
    color: white;
    font-weight: normal;
    border: 2px green solid;
    padding: 3px;
    text-align: justify;
  }

  div#bspazHelp ul
  { 
    line-height: 1.5em;
    background: black;
    list-style-type: square;
    font-size: 10px;
    color: white;
    margin: 1.5em 0 0 1.5em;
    padding: 10px;
    list-style-image: none;
  } 

  div#bspazHelp li 
  {
    margin-bottom: 1em;
  }

  div#bspazWarning
  {
    font-family: Verdana,Arial,Helvetica;
    font-size: 12px;
    background: black;
    color: green;
    font-weight: bold;
    border: 1px red solid;
    padding: 3px;
    text-align: center;
  }

  div#bspazWarning span {color: red;}

  div#bspazWarning a:link 
  {
    background: black;
    color: yellow;
    font-weight: bold;
    font-size: 12px;
    border: 0px;
  }

  div#bspazWarning a:visited 
  {
    background: black;
    color: yellow;
    font-weight: bold;
    font-size: 12px;
    border: 0px;
  }

  div#bspazWarning a:hover 
  { 
    background: black;
    color: orange;
    font-weight: bold;
    font-size: 12px;
    border: 0px;
  }

</style>

<form name="bspazSingleForm01" method="post" 
      action="<?php echo $bspaz->vars['bspazScriptURL'] ?>">

<?php echo $bspaz->writeBspazHiddenFields () ?>

<!-- BEGIN - bspaz Hide/Show button -->
<div id="bspazControl">
  <input type="button" <?php 
         echo ($bspaz->vars['bspazHistoryIndex'] <= 1) ? 'disabled' : '' ?>
         title="Go to previous page" value="<" 
         onClick="bspazPrevious();" />
  <input type="button" 
         title="Hide/Show the bspaz control box"
         value="bspaz" onClick="bspazHideShow(); return false;"/>
  <input type="button" <?php 
      echo (!is_array ($bspaz->vars['bspazHistory']) || 
      $bspaz->vars['bspazHistoryIndex'] >= count ($bspaz->vars['bspazHistory'])) ?
        'disabled' : '' ?>
         title="Go to next page" value=">" onClick="bspazNext();" />
</div>
<!-- END - bspaz Hide/Show button -->
<br />
<!-- BEGIN - bspaz console -->
<div id="bspazBox" 
     style="visibility: <?php echo 
      htmlentities ($bspaz->vars['bspazBoxState']) ?>">
    <br />
    <a title="The aPAz Project Homepage" 
             target="_BLANK" 
             href="https://sourceforge.net/projects/bspaz/">The Web eReader - 
             <?php echo APP_VERSION ?></a>
    <br />
    <br />
    <input type="text" id="bspazMainURL1" name="bspazMainURL1" size="50" 
           style="visibility: <?php echo 
            htmlentities ($bspaz->vars['bspazBoxState']) ?>"
           value="<?php echo 
           $bspaz->htmlentities (($bspaz->vars['bspazMainURL']) ? 
              $bspaz->vars['bspazMainURL'] : 'http://') ?>" />

    <input type="button" id="browseIt" value="Browse it" 
           style="visibility: <?php echo 
            $bspaz->htmlentities ($bspaz->vars['bspazBoxState']) ?>"
           onClick="bspazRAZ();bspazSetValue('bspazMainURL', bspazMainURL1.value);
                    bspazPost(bspazMainURL1.value)" />
    <br />
<?php if ($bspaz->vars['bspazCurrentURL'] && !$bspaz->isError ()) { ?>
    <br />
    <a target="_BLANK"
       title="Clicking on this link will browse the real URL" 
       href="<?php echo $bspaz->vars['bspazCurrentURL'] ?>"><?php echo 
        $bspaz->htmlentities ($bspaz->getShortenString ($bspaz->vars['bspazCurrentURL'])) ?></a>
    <br />
<?php } ?>

    <br />
    <div id="bspazWarning">
      This software is still in a very early stage of development.
    </div>
    <br />
    <div id="bspazHelp">

    <br />
    This is the first release of bspaz, a private php auto-bookmarking tool based on the aPAz anonymizer.
    <br />
    <br />
    bspaz already supports HTTP, HTML GET/POST forms, and cookies.<br />
    Also "one time" encryption is applied on page links. However neither<br />
    frames nor pages containing strange/complex javascript code are yet<br />
    well supported, and some web sites will not work. 
    <br />

    Help/Tips:
    <ul>
      <li>Do not use browser history previous/next buttons. Use bspaz<br />
      controls instead.</li>
      <li>You can Hide/Show the bspaz console at any time by clicking on <br /> 
      the topleft bspaz button.</li>
    </ul>
    </div>
</div>
</form>
<!-- END - bspaz console -->

<!-- /////////////////////// -->
<!-- END - bspaz incrustation -->
<!-- /////////////////////// -->

<?php 
  $bspaz->displayEndTags ();
  $bspaz->done ();
?>
