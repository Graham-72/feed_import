<h1>Feed Import</h1>
-------------------------

Feed Import provides a capability for importing various types of data file 
into entities within Backdrop.

It is a contributed project ported to Backdrop that comprises two modules: 
Feed Import Base and Feed Import, the latter being a User Interface.

By default, six methods of import (also named readers) are provided by Feed Import:

    XML document - import content from XML files using XPATH
    XML chunked - import content from huge XML files using XPATH
    DOM document HTML/XML - import content from HTML/XML files using XPATH (you can use php functios in xpaths)
    SQL query - import content from SQL query result using column names
    CSV file - import content from CSV files using column names or indexes (php >= 5.3)
    JSON file - import content from JSON files using path to value


For more information please see the files readme.txt and feed_import_base/readme.txt
also the project page at drupal.org/project/feed_import

<h2>Status</h2>
Currently under test.

<h2>Installation</h2>

Install as usual.

<h2>License</h2>

This project is GPL v2 software. See the LICENSE file in this directory for complete text.
    
    
<h2>Current Maintainers</h2>

<h3>For Drupal:</h3>
<p>Sorin Sarca (sorin-sarca)</p>


<h3>Port to Backdrop:</h3>
Graham Oliver github.com/Graham-72


