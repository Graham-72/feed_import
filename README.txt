FEED IMPORT

Project page: http://drupal.org/sandbox/SorinSarca/1331632

------------------------------
Features
------------------------------

  -easy to use interface
  -alternative xpaths support and default value
  -ignore field & skip item import
  -multi value fields support
  -pre-filters & filters
  -some usefull provided filters
  -auto-import/delete at cron
  -import/export feed configuration
  -reports
  -add taxonomy terms to field (can add new terms)

------------------------------
About Feed Import
------------------------------

Feed Import module allows you to import content from XML files into entities
like (node, user, ...) using XPATH to fetch whatever you need.
You can create a new feed using php code in you module or you can use the
provided UI (recommended). If you have regular imports you can enable import
to run at cron. Now Feed Import provides two methods to process XML file:
    Normal  - loads the xml file with simplexml_load_file() and parses
              it's content. This method isn't good for huge files because
              needs very much memory.
    Chunked - gets chunks from xml file and recompose each item. This is a good
              method to import huge xml files.

------------------------------
How Feed Import works
------------------------------

Step 1: Downloading xml file and creating items

  -if we selected processFeedNormal function for processing this feed then all
   xml file is loaded. We apply parent xpath, we create entity objects and we
   should have all items in an array.
  -if we selected processFeedChunked function for processing then xml file is
   read in chunks. When we have an item we create the SimpleXMLElement object
   and we create entity object. We delete from memory content read so far and we
   repeat process until all xml content is processed.
  -if we selected another process function then we should take a look at that
   function

Step 2: Creating entities

Well this step is contained in Step 1 to create entity objects from
SimpleXMLElement objects using feed info:
We generate an unique hash for item using unique xpath from feed. Then for each
field in feed we apply xpaths until one xpath passes pre-filter. If there is an
xpath that passed we take the value and filter it. If filtered value is empty
(or isn't a value) we use default action/value. In this mode we can have
alternative xpaths. Example:

<Friends>
  <Friend type="bestfriend">Jerry</Friend>
  <Friend type="normal">Tom</Friend>
</Friends>

Here we can use the following xpaths to take friend name:
Friends/Friend[@type="bestfriend"]
Friends/Friend[@type="normal"]

If bestfriend is missing then we go to normal friend. If normal friend is
missing too, we can specify a default value like "Forever alone".

Step 3: Saving/Updating entities

First we get the IDs of generated hashes to see if we need to create a new
entity or just to update it.
For each object filled with data earlier we check the hash:
  -if hash is in IDs list then we check if entity data changed to see if we have
   to save changes or just to update the expire time.
  -if hash isn't in list then we create a new entity and hash needs to be
   inserted in database.

Feed Import can add multiple values to fields which support this. For example
above we need only one xpath:
Friends/Friend
and both Tom and Jerry will be inserted in field values, which is great.

Expire time is used to automatically delete entities (at cron) if they are
missing from feed for more than X seconds.
Expire time is updated for each item in feed. For performance reasons we make a
query for X items at once to update or insert.

------------------------------
Using Feed Import UI
------------------------------

First, navigate to admin/config/services/feed_import. You can change global settings
using "Settings" link. To add a new feed click "Add new feed" link and fill the
form with desired data. After you saved feed click "Edit" link from operations
column. Now at the bottom is a fieldset with XPATH settings. Add XPATH for
required item parent and unique id (you can now save feed). To add a new field
choose one from "Add new field" select and click "Add selected field" button.
A fieldset with field settings appeared and you can enter xpath(s) and default
action/value. If you wish you can add another field and when you are done click
"Save feed" submit button.
Check if all fields are ok. If you want to (pre)filter values select
"Edit (pre)filter" tab. You can see a fieldset for each selected field. Click
"Add new filter" button for desired field to add a new filter. Enter unique
filter name per field (this can be anything that let you quickly identify
filter), enter function name (any php function, even static functions
ClassName::functionName) and enter parameters for function, one per line.
To send field value as parameter enter [field] in line. There are some static
filter functions in feed_import_filter.inc.php file >> class FeedImportFilter
that you can use. Please take a look. I'll add more soon.
If you want to change [field] with somenthing else go to Settings.
You can add/remove any filters you want but don't forget to click "Save filters"
submit button to save all.
Now you can enable feed and test it.

------------------------------
Feed Import API
------------------------------

If you want, you can use your own function to parse content. To do that you have
to implement hook_feed_import_process_info() which returns an array keyed by
function alias and with value of function name. If function is a static member
of a class then value is an array containing class name and function name.
Please note that in process function EVERY possible exception MUST BE CAUGHT!
Example:

function hook_feed_import_process_info() {
  return array(
    'processFeedSuperFast' => 'php_process_function_name',
    'processFeedByMyClass' => array('MyClassName', 'myProcessFunction'),
    // Other functions ...
  );
}

Every function is called with a parameter containing feed info and must return
an array of objects (stdClass). For example above we will have:

function php_process_function_name(array $feed) {
  $items = array();
  // ...
  // Here process feed items
  // ...
  return $items;
}

For the static function:

class MyClassName {
  // Class stuff
  // ...

  public static function myProcessFunction(array $feed) {
    $items = array();
    // ...
    // Here process feed items
    // ...
    return $items;
  }

  // Other class stuff
}

Concrete example (we assume that the module name is test_module):

/**
 * Implements hook_feed_import_process_info().
 */
function test_module_feed_import_process_info() {
  return array(
    'Test module process function' => 'test_module_process_function',
  );
}


/**
 * This function simulates FeedImport::processFeedNormal function
 *
 * @param array
 *   An array containing feed info
 *
 * @return array
 *   An array containing objects
 */
function test_module_process_function(array $feed) {
  // Every possible warning or error must be caught!!!
  // Load xml file from url
  try {
    $xml = simplexml_load_file($feed['url'], FeedImport::$simpleXMLElement,
                                LIBXML_NOCDATA);
  }
  catch (Exception $e) {
    // Error in xml file
    return NULL;
  }
  // If there is no SimpleXMLElement object
  if (!($xml instanceof FeedImport::$simpleXMLElement)) {
    return NULL;
  }
  // Now we are sure that $xml is an SimpleXMLElement object
  // Get items from root
  $xml = $xml->xpath($feed['xpath']['#root']);
  // Get total number of items
  $count_items = count($xml);

  // Check if there are items
  if (!$count_items) {
    return NULL;
  }

  // Check feed items
  foreach ($xml as &$item) {
    // Set this item value to entity, so all entities will be in $xml at end
    // You must use FeedImport::createEntity to get an object which will turn
    // into an entity at the end of import process
    $item = FeedImport::createEntity($feed, $item);
  }
  // Return created entities
  return $xml;
}

Now you can go to edit your feed and select for processing your new function.

------------------------------
Feed info structure
------------------------------

Feed info is an array containing all info about feeds: name, url, xpath keyed
by feed name.
A feed is an array containing the following keys:

name => This is feed name

id => This is feed unique id

enabled => Shows if feed is enabled or not. Enabled feeds are processed at cron
           if import at cron option is activated from settings page.

url => URL to xml file. To avoid problems use an absolute url.

time => This contains feed items lifetime. If 0 then items are kept forever else
        items will be deleted after this time is elapse and they don't exist
        in xml file anymore. On each import existing items will be rescheduled.

entity_info => This is an array containing two elements

  #entity => Entity name like node, user, ...

  #table_pk => This is entity's table index. For node is nid, for
               user si uid, ...


xpath => This is an array containing xpath info and fields

  #root => This is XPATH to parent item. Every xpath query will run in
           this context.

  #uniq => This is XPATH (relative to #root xpath) to a unique value
           which identify the item. Resulted value is used to create a
           hash for item so this must be unique per item.

  #process_function => This is function alias used to process xml file.
                       See documentation above about process functions.

  #items => This is an array containing xpath for fields and filters keyed by
            field name.
    [field_name] => An array containing info about field, xpath, filters

      #field => This is field name

      #column => This is column in field table. For body is value, for taxonomy
                 is tid and so on. If this field is a column in entity field
                 then this must be NULL.

      #xpath => This is an array containig xpaths for this field. Xpaths are
                used from first to last until one passes pre-filter functions.
                All xpaths are relative to #root.

      #default_value => This is default value for field if none of xpaths passes
                        pre-filter functions. This is used only for
                        default_value and default_value_filtered actions.

      #default_action => Can be one of (see FeedImport::getDefaultActions()):
          default_value           -field will have this value
          default_value_filtered  -field will have this value after was filtered
          ignore_field            -field will have no value
          skip_item               -item will not be imported

      #filter => An array containing filters info keyed by filter name
        [filter_name] => An array containing filter function and params
          #function => This is function name. Can also be a public static
                       function from a class with value ClassName::functionName
          #params => An array of parameters which #function recives. You can use
                     [field] (this value can be changed from settings page) to
                     send current field value as parameter.

      #pre_filter => Same as filter, but these functions are used to pre-filter
                     values to see if we have to choose an alternative xpath.


To see a real feed array, first create some feeds using UI and then you can use
code below to print its structure:
$feeds = FeedImport::loadFeeds();
drupal_set_message('<pre>' . print_r($feeds, TRUE) . '</pre>');

------------------------------
Real example
------------------------------

Please check project page for an example.
http://drupal.org/sandbox/SorinSarca/1331632
