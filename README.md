# Feed Import

Feed Import provides a capability for importing various types of data file 
into entities within Backdrop.

It is a contributed project ported to Backdrop that comprises two modules: 
Feed Import Base and Feed Import, the latter being a User Interface.

By default, six methods of import (also named readers) are provided by Feed Import:

    - XML document - import content from XML files using XPATH
    - XML chunked - import content from huge XML files using XPATH
    - DOM document HTML/XML - import content from HTML/XML files using XPATH (you can use php functios in xpaths)
    - SQL query - import content from SQL query result using column names
    - CSV file - import content from CSV files using column names or indexes (php >= 5.3)
    - JSON file - import content from JSON files using path to value


For more information please see the files readme.txt and feed_import_base/readme.txt
also the project page at drupal.org/project/feed_import

## Status

  Currently under development. It has been used successfully for CSV import.
  It is a port from version 7.x-3.4 of the Drupal module.

## Installation and use

  - Install as usual.
  - Notes on use can be found in the wiki for this module at
  https://github.com/backdrop-contrib/feed_import/wiki/Feed-Import-overview

## License

This project is GPL v2 software. See the LICENSE file in this directory.
    
    
## Current Maintainers

### For Drupal:
+ Sorin Sarca (sorin-sarca)

### Port to Backdrop
+Graham Oliver github.com/Graham-72

### Acknowledgement

This port to Backdrop would not, of course, be possible without all
the work done by the developer and maintainer of the Drupal module.


