FEED IMPORT BASE

Project page: https://drupal.org/project/feed_import
Developers info: https://drupal.org/node/2190383

------------------------------
About Feed Import Base
------------------------------

This module provides basic import functionality and abstractization supporting
all entity types.

The reader (source)
----------
Reader's job is to fetch content from a resource and map it to values by paths.
By default there are 6 provided readers:
  -XML files - XPATH mapped
  -XML Chunked for huge xml files - XPATH mapped
  -DomDocument XML/HTML - XPATH mapped
  -CSV fiels - Column name or index mapped
  -JSON files - Path to value mapped
  -SQL databases - Column name mapped


The Hash Manager
----------------
Used to monitor imported items for update/delete.
This module provides only an SQL based Hash Manager.


The filter
----------
Used to filter values. This module provides a powerful filter class.


The processor
-------------
The processor takes care of all import process.
This module provides just one processor compatibile with all readers.
