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


----------------
Developer info
----------------

What is FeedImportConfigurable class?

FeedImportConfigurable is extended by all classes that receive configuration
options (processors, readers, hash managers, filter handlers...).
You can override setOptions() method to handle options input.
Also, optionally, you can override validateOption() method which is used only in
UI to report invalid option values.

What is FeedImportReader class?

An abstract class (extends FeedImportConfigurable) containing mandatory methods
in order to extract data from a source.
Override init() method to setup your requirments (open file, connect to
database, ...).
Override map() method to return a value by a given path. If you want the path
in a different format (other than string) then override formatPath() method,
which will be called only once for every path.
Finnaly, override get() method which must return the next available item or
NULL/FALSE if there are no more items.

There are also some abstract classes having map() and format() implemented:

- FeedImportSimpleXPathReader: returns value by xpath from a SimpleXMLElement
- FeedImportDomXPathReader: returns value by xpath using DomXPath object
- FeedImportVectorReader: returns value from nested array/objects by a path (can
  be used to map nested JSON-like values)
- FeedImportUniVectorReader: returns value from a linear array (can be used to
  map CSV / SQL resultset columns)
