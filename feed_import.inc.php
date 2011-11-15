<?php
/**
 * @file
 * Feed import class for parsing and processing content
 *
 * @TODO general way to integrate with all entities
 * now it integrates with entities that implements _load(), _save()
 * and _delete()  functions like node
 * you may write yourself save/load functions if missing
 * while this project is in sandbox I will not use entity api
 */
class FeedImport {
  /**
   * A report about import process
   * -rescheduled
   * -updated
   * -new
   * -total
   * -time
   * -download
   */
  public static $report = array();
  /**
   * Feed import load feeds settings
   *
   * @param bool $enabled
   *   Load only enabled feeds
   * @param mixed $id
   *   Load feed by id or name
   *
   * @return array
   *   Feeds info
   */
  public static function loadFeeds($enabled = FALSE, $id = NULL) {
    static $feeds = NULL;
    static $enabled_feeds = NULL;
    if ($id == NULL) {
      if ($feeds != NULL) {
        return $enabled ? $enabled_feeds : $feeds;
      }
      $feeds = db_select('feed_import_settings', 'f')
                  ->fields('f', array('name', 'url', 'time', 'entity_info', 'xpath', 'id', 'enabled'))
                  ->orderBy('enabled', 'DESC')
                  ->execute()
                  ->fetchAllAssoc('name');
      foreach ($feeds as $name => &$feed) {
        $feed = (array) $feed;
        $feed['entity_info'] = unserialize($feed['entity_info']);
        $feed['xpath'] = unserialize($feed['xpath']);
        if ($feed['enabled']) {
          $enabled_feeds[$name] = &$feed;
        }
      }
      return $enabled ? $enabled_feeds : $feeds;
    }
    else {
      $feed = db_select('feed_import_settings', 'f')
                ->fields('f', array('name', 'url', 'time', 'entity_info', 'xpath', 'id', 'enabled'))
                ->condition(((int) $id) ? 'id' : 'name', $id, '=')
                ->range(0, 1)
                ->execute()
                ->fetchAll();
      if ($feed) {
        $feed = (array) reset($feed);
        $feed['entity_info'] = unserialize($feed['entity_info']);
        $feed['xpath'] = unserialize($feed['xpath']);
        return $feed;
      }
      else {
        return NULL;
      }
    }
  }
  /**
   * Save/update a feed
   *
   * @param array $feed
   *   Feed info array
   * @param bool $update
   *   Update feed if true, save if false
   */
  public static function saveFeed($feed, $update = FALSE) {
    if ($update) {
        db_update('feed_import_settings')
          ->fields(array(
            'enabled' => $feed['enabled'],
            'name' => $feed['name'],
            'url' => $feed['url'],
            'time' => $feed['time'],
            'entity_info' => serialize($feed['entity_info']),
            'xpath' => serialize($feed['xpath']),
          ))
          ->condition('id', $feed['id'], '=')
          ->execute();
    }
    else {
      db_insert('feed_import_settings')
        ->fields(array(
          'enabled' => $feed['enabled'],
          'name' => $feed['name'],
          'url' => $feed['url'],
          'time' => $feed['time'],
          'entity_info' => serialize($feed['entity_info']),
          'xpath' => serialize($feed['xpath']),
        ))
        ->execute();
    }
  }
  /**
   * Gets info about entities and fields
   *
   * @param string $entity
   *   Entity name
   *
   * @return array
   *   Info about entities
   */
  public static function getEntityInfo($entity = NULL) {
    $fields = variable_get('feed_import_entity_info', array());
    $expired = variable_get('feed_import_entity_info_expire', 0) < REQUEST_TIME;
    if ($expired || empty($fields)) {
      $info = array();
      $fields = _field_info_collate_fields(FALSE);
      if (isset($fields['fields'])) {
        $fields = $fields['fields'];
      }
      foreach ($fields as &$field) {
        $info[$field['field_name']] = array(
          'name' => $field['field_name'],
          'column' => key($field['columns']),
          'bundles' => array_keys($field['bundles']),
        );
        $field = NULL;
      }
      $fields = entity_get_info();
      foreach ($fields as $key => &$field) {
        $field = array(
          'name' => $key,
          'column' => $field['entity keys']['id'],
          'columns' => $field['schema_fields_sql']['base table'],
        );
        $field['columns'] = array_flip($field['columns']);
        foreach ($field['columns'] as &$f) {
          $f = NULL;
        }
        foreach ($info as &$f) {
          if (!in_array($key, $f['bundles'])) {
            continue;
          }
          $field['columns'][$f['name']] = $f['column'];
        }
      }
      unset($info);
      variable_set('feed_import_entity_info', $fields);
      variable_set('feed_import_entity_info_expire', REQUEST_TIME + variable_get('feed_import_entity_info_keep', 3600));
    }
    if (!$entity) {
      return $fields;
    }
    else {
      return isset($fields[$entity]) ? $fields[$entity] : NULL;
    }
  }
  /**
   * Returns all available functions for processing a feed.
   */
  public static function processFunctions() {
    static $functions = NULL;
    if ($functions != NULL) {
      return $functions;
    }
    $functions = module_invoke_all('feed_import_process_info');
    // Well, check if functions really exists
    foreach ($functions as $alias => &$func) {
      if (is_array($func)) {
        if (!method_exists($func[0], $func[1])) {
          unset($functions[$alias]);
        }
      }
      else {
        if (!function_exists($func)) {
          unset($functions[$alias]);
        }
      }
    }
    return $functions;
  }
  /**
   * This function is choosing process function and executes it
   *
   * @param array $feed
   *   Feed info array
   */
  public static function processFeed(array $feed) {
    // Reset report
    self::$report = array(
      'rescheduled' => 0,
      'updated' => 0,
      'new' => 0,
      'total' => 0,
      'start' => time(),
      'parse' => 0,
    );

    // Check if entity node/save/load functions exists
    if (!self::checkFunctions($feed['entity_info']['#entity'])) {
      return FALSE;
    }

    $func = $feed['xpath']['#process_function'];
    $functions = self::processFunctions();
    if (!$func || !isset($functions[$func])) {
      // Get first function if there's no specified function
      $func = reset(self::processFunctions());
    }
    else {
      $func = $functions[$func];
    }
    unset($functions);

    // Get property temp name to store hash value
    self::$tempHash = variable_get('feed_import_hash_property', self::$tempHash);
    // Reset generated hashes
    self::$generatedHashes = array();

    // Give import time (for large imports)
    set_time_limit(0);
    // Call process function to get processed items
    $items = call_user_func($func, $feed);
    // Save items
    if (!empty($items)) {
      self::saveEntities($feed, $items);
    }
    // Set total time report
    self::$report['time'] = time() - self::$report['start'];
    self::$report['parse'] -= self::$report['start'];
  }
  /**
   * Deletes items by entity id
   *
   * @param array $ids
   *   Entity ids
   */
  public static function deleteItemsbyEntityId(array $ids) {
    if (empty($ids)) {
      return;
    }
    $ids = array_chunk($ids, variable_get('feed_import_update_ids_chunk', 1000));
    $q_delete = db_delete('feed_import_hashes');
    $conditions = &$q_delete->conditions();
    foreach ($ids as &$id) {
      $q_delete->condition('entity_id', $id, 'IN')->execute();
      // Remove last IN condition
      array_pop($conditions);
      $id = NULL;
    }
  }
  /**
   * Delete entity by type and ids
   *
   * @param string $type
   *   Entity type (node, user, ...)
   * @param array $ids
   *   Array of entity ids
   *
   * @return array
   *   Array of deleted ids
   */
  public static function entityDelete($type, $ids) {
    $func = $type . '_delete_multiple';
    if (function_exists($func)) {
      try {
        call_user_func($func, $ids);
      }
      catch (Exception $e) {
        return array();
      }
      return $ids;
    }
    else {
      $func = $type . '_delete';
      if (function_exists($func)) {
        foreach ($ids as $k => &$id) {
          try {
            call_user_func($func, $id);
          }
          catch (Exception $e) {
            unset($ids[$k]);
          }
        }
        return $ids;
      }
    }
    unset($type, $ids);
    return array();
  }
  /**
   * Get expired items
   *
   * @param int $limit
   *   Limit the number of returned items
   *
   * @return array
   *   Array keyed with item ids and value entity_ids
   */
  public static function getExpiredItems($limit = 99999999) {
    $results = db_select('feed_import_hashes', 'f')
                ->fields('f', array('feed_id', 'entity_id'))
                ->condition('expire', array(1, REQUEST_TIME), 'BETWEEN')
                ->range(0, $limit)
                ->execute()
                ->fetchAll();
    if (empty($results)) {
      return $results;
    }
    $temp = array();
    $feeds = self::loadFeeds();
    foreach ($feeds as &$feed) {
      $temp[$feed['id']] = $feed['entity_info']['#entity'];
      $feed = NULL;
    }
    unset($feed);
    $res = array();
    foreach ($results as &$result) {
      $res[$temp[$result->feed_id]][] = $result->entity_id;
      $result = NULL;
    }
    unset($results, $temp);
    return $res;
  }
  /**
   * Get url status
   *
   * @param string $url
   *   URL to XML file
   *
   * @return array
   *   Info about status
   */
  public static function getXMLStatus($url) {
    try {
      $fp = fopen($url, 'rb');
      stream_set_timeout($fp, 1);
      $meta = stream_get_meta_data($fp);
      fclose($fp);
      return $meta;
    }
    catch (Exception $e) {
      return array();
    }
  }
  /**
   * Get value with xpath
   *
   * @param SimpleXMLElement &$item
   *   Simplexmlobject to apply xpath on
   * @param string $xpath
   *   Xpath to value
   *
   * @return mixed
   *   A string or array of strings as a result of xpath function
   */
  protected static function getXpathValue(&$item, $xpath) {
    $xpath = $item->xpath($xpath);
    if (count($xpath) == 1) {
      $xpath = (array) reset($xpath);
      $xpath = isset($xpath[0]) ? $xpath[0] : reset($xpath);
    }
    else {
      foreach ($xpath as $key => &$x) {
        $x = (array) $x;
        $x = isset($x[0]) ? $x[0] : reset($x);
        if (empty($x)) {
          unset($xpath[$key], $x);
        }
      }
    }
    return $xpath;
  }
  /**
   * Creates a hash using uniq and feed source name
   *
   * @param string $uniq
   *   Unique item
   * @param string $feed_name
   *   Feed id
   *
   * @return string
   *   Hash value
   */
  protected static function createHash($uniq, $feed_id) {
    return md5($uniq . '-|-' . $feed_id);
  }
  /**
   * Gets entity ids from a hashes
   *
   * @param array &$hashes
   *   Array of hashes
   *
   * @return array
   *   Fetched hashes in database
   */
  protected static function getEntityIdsFromHash(array &$hashes) {
    return db_select('feed_import_hashes', 'f')
            ->fields('f', array('hash', 'id', 'entity_id'))
            ->condition('hash', $hashes, 'IN')
            ->execute()
            ->fetchAllAssoc('hash');
  }
  /**
   * Checks if a variable has content
   *
   * @param mixed $var
   *   Variable to check
   *
   * @return bool
   *   TRUE if there is content FALSE otherwise
   */
  protected static function hasContent(&$var) {
    if (is_scalar($var)) {
      if ((string) $var === '') {
        return FALSE;
      }
    }
    elseif (empty($var)) {
      return FALSE;
    }
    return TRUE;
  }
  /**
   * Default actions when result is empty
   */
  public static function getDefaultActions() {
    return array(
      'default_value' => t('Provide a default value'),
      'default_value_filtered' => t('Provide a filtered default value'),
      'ignore_field' => t('Ignore this field'),
      'skip_item' => t('Skip importing this item'),
    );
  }
  /**
   * Create Entity object
   *
   * @param array &$feed
   *   Feed info array
   * @param object &$item
   *   Current SimpleXMLElement object
   *
   * @return object
   *   Created Entity
   */
  protected static function createEntity(&$feed, &$item) {
    // Create new object to hold fields values
    $entity = new stdClass();
    // Check if item already exists
    $uniq = self::getXpathValue($item, $feed['xpath']['#uniq']);
    // Create a hash to identify this item in bd
    $entity->{self::$tempHash} = self::createHash($uniq, $feed['id']);
    // add to hashes array
    self::$generatedHashes[] = $entity->{self::$tempHash};
    // Set default language, this can be changed by language item
    $entity->language = LANGUAGE_NONE;
    // Get all fields
    foreach ($feed['xpath']['#items'] as &$field) {
      $i = 0;
      $aux = '';
      $count = count($field['#xpath']);
      // Check ONCE if we have to filter or prefilter field
      $prefilter = !empty($field['#pre_filter']);
      $filter = !empty($field['#filter']);
      // Loop through xpaths until we have data, otherwise use default value
      while ($i < $count) {
        if (!$field['#xpath'][$i]) {
          $i++;
          continue;
        }
        $aux = self::getXpathValue($item, $field['#xpath'][$i]);
        if ($prefilter) {
          $pfval = self::applyFilter($aux, $field['#pre_filter']);
          // If item doesn't pass prefilter than go to next option
          if (!self::hasContent($pfval)) {
            $i++;
            continue;
          }
          unset($pfval);
        }
        // If filter passed prefilter then apply filter and exit while loop
        if (self::hasContent($aux)) {
          if ($filter) {
            $aux = self::applyFilter($aux, $field['#filter']);
          }
          break;
        }
        $i++;
      }
      // If we don't have any data we take default action
      if (!self::hasContent($aux)) {
        switch ($field['#default_action']) {
          // Provide default value
          // This is also default action
          case 'default_value':
          default:
            $aux = $field['#default_value'];
            break;
          // Provide default value before it was filtered
          case 'default_value_filtered':
            $aux = self::applyFilter($field['#default_value'], $field['#filter']);
            break;
          // Skip this item by returning NULL
          case 'skip_item':
            return NULL;
            break;
          // Don't add this field to entity
          case 'ignore_field':
            continue 2;
            break;
        }
      }
      // Set field value
      if ($field['#column']) {
        if (is_array($aux)) {
          $i = 0;
          foreach ($aux as &$auxv) {
            $entity->{$field['#field']}[$entity->language][$i][$field['#column']] = $auxv;
            $i++;
          }
        }
        else {
          $entity->{$field['#field']}[$entity->language][0][$field['#column']] = $aux;
        }
      }
      else {
        $entity->{$field['#field']} = $aux;
      }
      // No need anymore, free memory
      unset($aux);
    }
    return $entity;
  }
  /**
   * Saves/updates all created entities
   *
   * @param array &$feed
   *   Feed info array
   * @param array &$items
   *   An array with entities
   */
  protected static function saveEntities(&$feed, &$items) {
    // Parse report
    self::$report['parse'] = time();
    // Get existing items for update
    if (!empty(self::$generatedHashes)) {
      $ids = self::getEntityIdsFromHash(self::$generatedHashes);
      // Reset all generated hashes
      self::$generatedHashes = array();
    }
    else {
      $ids = array();
    }
    // This sets expire timestamp
    $feed['time'] = (int) $feed['time'];
    // Report data
    self::$report['total'] = count($items);
    // Now we create real entityes or update existent
    foreach ($items as &$item) {
      // Check if item is skipped
      if ($item == NULL) {
        continue;
      }
      // Save hash and remove from item
      $hash = $item->{self::$tempHash};
      unset($item->{self::$tempHash});
      // Check if item is already imported
      if (isset($ids[$hash])) {
        $changed = FALSE;
        // Load entity
        $entity = call_user_func(self::$functionLoad, $ids[$hash]->entity_id);
        // If entity is missing then skip
        if (empty($entity)) {
          unset($entity);
          continue;
        }
        $lang = $item->language;
        // Find if entity is different from last feed
        foreach ($item as $key => &$value) {
          if (is_array($value)) {
            if (!isset($entity->{$key}[$lang]) || empty($entity->{$key}[$lang]) || count($entity->{$key}[$lang]) != count($value[$lang])) {
              $changed = TRUE;
              $entity->{$key} = $value;
            }
            elseif (count($value[$lang]) <= 1) {
              $col = isset($value[$lang][0]) ? key($value[$lang][0]) : '';
              if ($entity->{$key}[$lang][0][$col] != $value[$lang][0][$col]) {
                $changed = TRUE;
                $entity->{$key} = $value;
              }
              unset($col);
            }
            else {
              $col = key($value[$lang][0]);
              $temp = array();
              foreach ($entity->{$key}[$lang] as &$ev) {
                $temp[][$col] = $ev[$col];
              }
              if ($temp != $value[$lang]) {
                $changed = TRUE;
                $entity->{$key} = $value;
              }
              unset($temp, $col);
            }
          }
          else {
            if (!isset($entity->{$key}) || $entity->{$key} != $value) {
              $changed = TRUE;
              $entity->{$key} = $value;
            }
          }
        }
        $ok = TRUE;
        // Check if entity is changed and save changes
        if ($changed) {
          try {
            call_user_func(self::$functionSave, $entity);
            // Set report about updated items
            self::$report['updated']++;
          }
          catch (Exception $e) {
            // Report error?
            $ok = FALSE;
          }
        }
        else {
          // Set report about rescheduled items
          self::$report['rescheduled']++;
        }

        if ($ok) {
          // Add to update ids
          self::updateIds($ids[$hash]->id);
        }
        // Free some memory
        unset($ids[$hash], $entity, $lang);
      }
      else {
        // Mark as new
        $item->{$feed['entity_info']['#table_pk']} = NULL;
        $ok = TRUE;
        try {
          // Save imported item
          call_user_func(self::$functionSave, $item);
        }
        catch (Exception $e) {
          // Report error?
          $ok = FALSE;
        }
        if ($ok) {
          $vars = array(
            $feed['id'],
            $item->{$feed['entity_info']['#table_pk']},
            $hash,
            $feed['time'] ? time() + $feed['time'] : 0,
          );
          // Insert into feed import hash table
          self::insertItem($vars);
          // Set report about new items
          self::$report['new']++;
        }
      }
      // No need anymore
      $item = NULL;
    }
    // No need anymore
    unset($items, $ids);
    // Insert left items
    self::insertItem(NULL);
    $vars = array(
      'expire' => $feed['time'] ? time() + $feed['time'] : 0,
      'feed_id' => $feed['id'],
    );
    // Update ids for existing items
    self::updateIds($vars);
  }
  /**
   * Filters a field
   *
   * @param mixed $field
   *   A string or array of strings containing field value
   * @param array $filters
   *   Filters to apply
   *
   * @return mixed
   *   Filtered value of field
   */
  protected static function applyFilter($field, $filters) {
    $field_param = variable_get('feed_import_field_param_name', '[field]');
    foreach ($filters as &$filter) {
      $filter['#function'] = trim($filter['#function']);
      // Check if function exists, support static functions
      if (strpos($filter['#function'], '::') !== FALSE) {
        $filter['#function'] = explode('::', $filter['#function'], 2);
        if (!method_exists($filter['#function'][0], $filter['#function'][1])) {
          continue;
        }
      }
      else {
        if (!function_exists($filter['#function'])) {
          continue;
        }
      }
      // Set field value
      $key = array_search($field_param, $filter['#params']);
      $filter['#params'][$key] = $field;
      // Apply filter
      $field = call_user_func_array($filter['#function'], $filter['#params']);
      $filter = NULL;
    }
    return $field;
  }
  /**
   * Checks if entity functions exists
   *
   * @param string $entity
   *   Entity name
   *
   * @return bool
   *   TRUE if function exists, FALSE otherwise
   */
  protected static function checkFunctions($entity) {
    self::$functionSave = $entity . '_save';
    self::$functionLoad = $entity . '_load';
    if (!function_exists(self::$functionSave) || !function_exists(self::$functionLoad)) {
      drupal_set_message(t('Could not find @func _save()/_load() function!', array('@func' => $entity)), 'error');
      return FALSE;
    }
    return TRUE;
  }
  /**
   * Insert imported item in feed_import_hashes
   *
   * @param mixed $values
   *   An array of values or NULL to execute insert
   */
  protected static function insertItem($values) {
    static $q_insert = NULL;
    static $q_insert_items = 0;
    if ($q_insert == NULL) {
      $q_insert = db_insert('feed_import_hashes')
                    ->fields(array('feed_id', 'entity_id', 'hash', 'expire'));
    }
    $q_insert_chunk = variable_get('feed_import_insert_hashes_chunk', 500);
    // Call execute and reset number of insert items
    if ($values == NULL) {
      if ($q_insert_items) {
        $q_insert->execute();
        $q_insert_items = 0;
      }
      return;
    }
    // Set values
    $q_insert->values($values);
    $q_insert_items++;
    if ($q_insert_items == $q_insert_chunk) {
      $q_insert->execute();
      $q_insert_items = 0;
    }
  }
  /**
   * Update imported items ids in feed_import_hashes
   *
   * @param mixed $value
   *   An int value to add id to list or an array containing
   *   info about update conditions to execute update
   */
  protected static function updateIds($value) {
    static $update_ids = array();
    if (is_array($value)) {
      if (empty($update_ids)) {
        return;
      }
      $q_update = db_update('feed_import_hashes')
                    ->fields(array('expire' => $value['expire']))
                    ->condition('feed_id', $value['feed_id'], '=');
      $conditions = &$q_update->conditions();
      // Split in chunks
      $update_ids = array_chunk($update_ids, variable_get('feed_import_update_ids_chunk', 1000));
      foreach ($update_ids as &$ids) {
        $q_update->condition('id', $ids, 'IN')->execute();
        // Remove last IN condition
        array_pop($conditions);
        $ids = NULL;
      }
      // Reset update ids
      $update_ids = array();
    }
    else {
      // Add to list
      $update_ids[] = (int) $value;
    }
  }
  /**
   * *****************************
   * Feed processors variables
   * *****************************
   */
  // Save function name (_save)
  protected static $functionSave;
  // Load function name (_load)
  protected static $functionLoad;
  // SimpleXMLElement class, you can use a class that extends default
  protected static $simpleXMLElement = 'SimpleXMLElement';
  // Temporary property name for hash
  protected static $tempHash = '_feed_item_hash';
  // Generated Hashes
  protected static $generatedHashes = array();
  /**
   * *****************************
   * Feed processors
   * *****************************
   */
  /**
   * Imports and process a feed normally
   *
   * @param array $feed
   *   Feed info array
   *
   * @return array
   *   An array of objects
   */
  protected static function processFeedNormal(array $feed) {
    // Load xml file from url
    try {
      $xml = simplexml_load_file($feed['url'], self::$simpleXMLElement, LIBXML_NOCDATA);
    }
    catch (Exception $e) {
      // Report error?
      $xml = FALSE;
    }
    // If is empty then exit
    if ($xml == FALSE) {
      // Report?
      return FALSE;
    }
    // Get items from root
    $xml = $xml->xpath($feed['xpath']['#root']);
    // Get total number of items
    $count_items = count($xml);
    // Check if there are items
    if (!$count_items) {
      // Report?
      return FALSE;
    }
    // Check feed items
    foreach ($xml as &$item) {
      // Set this item value to entity, so all entities will be in $xml at end
      $item = self::createEntity($feed, $item);
    }
    unset($feed);
    // Return created entities
    return $xml;
  }
  /**
   * Imports and process a huge xml in chunks
   *
   * @param array $feed
   *   Feed info array
   *
   * @return array
   *   An array of objects
   */
  protected static function processFeedChunked(array $feed) {
    // Get substr function
    global $multibyte;
    $substr = ($multibyte == UNICODE_MULTIBYTE) ? 'mb_substr' : 'substr';

    // This will hold all generated entities
    $entities = array();
    // XML head
    $xml_head = '<?xml version="1.0" encoding="utf-8"?>';
    // Bytes read with fread
    $chunk_length = 8192;
    $xml_head = variable_get('feed_import_processFeedChunked_xml_head', $xml_head);
    $chunk_length = variable_get('feed_import_processFeedChunked_chunk_length', $chunk_length);
    if ($chunk_length <= 0) {
      $chunk_length = 8192;
    }
    // Open xml url
    if (!($fp = fopen($feed['url'], 'rb'))) {
      // Report error?
      return;
    }
    // Preparing tags
    $tag = trim($feed['xpath']['#root'], '/');
    $tag = array(
      'open' => '<' . $tag,
      'close' => '</' . $tag . '>',
      'length' => drupal_strlen($tag),
    );
    $tag['closelength'] = drupal_strlen($tag['close']);
    // This holds xml content
    $content = '';
    // Read all content in chunks
    while (!feof($fp)) {
      $content .= fread($fp, $chunk_length);
      // If there isn't content read again
      if (!$content) {
        continue;
      }
      while (TRUE) {
        $openpos = strpos($content, $tag['open']);
        $openposclose = $openpos+$tag['length']+1;
        // Check for open tag
        if ($openpos === FALSE || !isset($content[$openposclose]) || ($content[$openposclose] != ' ' && $content[$openposclose] != '>')) {
          break;
        }
        $closepos = strpos($content, $tag['close'], $openposclose);
        if ($closepos === FALSE) {
          break;
        }
        // We have data
        $closepos += $tag['closelength'];

        // Create xml string
        $item = $xml_head . $substr($content, $openpos, $closepos - $openpos);
        // New content
        $content = $substr($content, $closepos-1);
        // Create xml object
        try {
          $item = simplexml_load_string($item, self::$simpleXMLElement, LIBXML_NOCDATA);
        }
        catch (Exception $e) {
          // Report error?
          continue;
        }
        // Parse item
        $item = reset($item->xpath($feed['xpath']['#root']));
        if (empty($item)) {
          continue;
        }
        // Create entity
        $item = self::createEntity($feed, $item);
        // Put in entities array
        $entities[] = $item;
        // No need anymore
        unset($item);
      }
    }
    // Close file
    fclose($fp);
    unset($feed);
    // Return created items
    return $entities;
  }
}