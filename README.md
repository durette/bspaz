# bspaz
Automatic bookmark rewriter

When I find myself studying a lot of pages on a large website, with the intention of reading every page in a linear order, I hate having to remember to update my browser bookmark to the current page I'm on.

This fork of the aPAz web anonymizer is a server-side bookmarking tool that updates itself every time the page is changed, turning a web browser into an Internet e-reader that remembers where you left off.

Usage:
Drop PHP file on server. Make sure HTTP daemon can write to the folder. Open file from browser.

Currently, the URL is stored in a text file on the server.

I originally wrote this in 2014 and migrated it from Sourceforge to Github as-is.
