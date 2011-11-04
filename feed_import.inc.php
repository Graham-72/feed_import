<?php
//@TODO general way to integrate with all entities
//now it integrates with entities that implements _load(), _save() and _delete()  functions like node
//you may write yourself save/load functions if missing
//while this project is in sandbox I will not use entity api
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
     * Available functions for processing a xml file
     * (this is temporary)
     */
    public static $processFunctions = array('processFeedNormal', 'processFeedChunked');
    
    /**
     * Feed import load feeds settings
     * @param bool $enabled Load only enabled feeds
     * @param mixed $id Load feed by id or name
     * @return array Feeds info
     */
    public static function loadFeeds($enabled = FALSE, $id = NULL) {
        static $feeds = NULL;
        static $enabled_feeds = NULL;
        if($id == NULL) {
            if($feeds != NULL) {
                return $enabled ? $enabled_feeds : $feeds;
            }
            $feeds = db_select('feed_import_settings', 'f')
                        ->fields('f', array('name', 'url', 'time', 'entity_info', 'xpath', 'id', 'enabled'))
                        ->orderBy('enabled', 'DESC')
                        ->execute()
                        ->fetchAllAssoc('name');
            
            foreach($feeds as $name => &$feed) {
                $feed = (array)$feed;
                $feed['entity_info'] = unserialize($feed['entity_info']);
                $feed['xpath'] = unserialize($feed['xpath']);
                if($feed['enabled']) {
                    $enabled_feeds[$name] = &$feed;
                }
            }
            return $enabled ? $enabled_feeds : $feeds;
        } else {
            $feed = db_select('feed_import_settings', 'f')
                        ->fields('f', array('name', 'url', 'time', 'entity_info', 'xpath', 'id', 'enabled'))
                        ->condition(((int)$id) ? 'id' : 'name', $id, '=')
                        ->range(0,1)
                        ->execute()
                        ->fetchAll();
            if($feed) {
                $feed = (array)reset($feed);
                $feed['entity_info'] = unserialize($feed['entity_info']);
                $feed['xpath'] = unserialize($feed['xpath']);
                return $feed;
            } else {
                return NULL;
            }
        }
    }
    
    /**
     * Save/update a feed
     * @param array $feed Feed info
     * @param bool $update Update feed if true, save if false
     */
    public static function saveFeed($feed, $update = FALSE) {
        if($update) {
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
        } else {
            db_insert('feed_import_settings')
                ->fields(array(
                    'enabled' => $feed['enabled'],
                    'name' => $feed['name'],
                    'url' => $feed['url'],
                    'time' => $feed['time'],
                    'entity_info' => serialize($feed['entity_info']),
                    'xpath' => serialize($feed['xpath']),
                ))->execute();
        }
    }
    
    
    /**
     * Gets info about entities and fields
     * @param string $entity Entity name
     * @return array Info about entities
     */
    public static function getEntityInfo($entity = NULL) {
      $fields = variable_get('feed_import_entity_info', array());
      $expired = variable_get('feed_import_entity_info_expire', 0) < REQUEST_TIME;
      if($expired || empty($fields)) {
        $info = array();
        $fields = _field_info_collate_fields(FALSE);
        if(isset($fields['fields'])) {
            $fields = $fields['fields'];
        }
        foreach($fields as &$field) {
          $info[$field['field_name']] = array (
            'name' => $field['field_name'],
            'column' => key($field['columns']),
            'bundles' => array_keys($field['bundles']),
          );
          $field = NULL;
        }
        $fields = entity_get_info();
        foreach($fields as $key => &$field) {
          $field = array (
            'name' => $key,
            'column' => $field['entity keys']['id'],
            'columns' => $field['schema_fields_sql']['base table'],
          );
          $field['columns'] = array_flip($field['columns']);
          foreach($field['columns'] as &$f) {
            $f = NULL;
          }
          foreach($info as &$f) {
            if(!in_array($key, $f['bundles'])) continue;
            $field['columns'][$f['name']] = $f['column'];
          }
        }
        unset($info);
        variable_set('feed_import_entity_info', $fields);
        variable_set('feed_import_entity_info_expire',
                     REQUEST_TIME + variable_get('feed_import_entity_info_keep', 3600));
      }
      if(!$entity) {
        return $fields;
      } else {
        return isset($fields[$entity]) ? $fields[$entity] : NULL;
      }
    }
    
    /**
     * This function is choosing process function and executes it
     * @param array $feed Feed info
     */
    public static function processFeed(array $feed) {
        //reset report
        self::$report = array(
            'rescheduled' => 0,
            'updated' => 0,
            'new' => 0,
            'total' => 0,
            'start' => time(),
            'parse' => 0,
        );
        
        //give import time (for large imports)
        set_time_limit(0);
        $func = @$feed['xpath']['#process_function'];
        if(!$func || !method_exists(__CLASS__, $func)) {
            $func = reset(self::$processFunctions);
        }
        //call process function
        self::$func($feed);
        
        //set total time report
        self::$report['time'] = time() - self::$report['start'];
        self::$report['parse'] -= self::$report['start'];
    }
    
    /**
     * Deletes items by entity id
     * @param array $ids Entity ids
     */
    public static function deleteItemsbyEntityId(array $ids) {
        if(empty($ids)) {
            return;
        }
        $ids = array_chunk($ids, variable_get('feed_import_update_ids_chunk', 1000));
        $q_delete = db_delete('feed_import_hashes');
        $conditions = &$q_delete->conditions();
        foreach($ids as &$id) {
            $q_delete->condition('entity_id', $id, 'IN')
                     ->execute();
            array_pop($conditions); //remove last IN condition
            $id = NULL;
        }
    }
    /**
     * Delete entity by type and ids
     * @param string $type Entity type (node, user, ...)
     * @param array $ids Array of entity ids
     * @return array Array of deleted ids
     */
    public static function entityDelete($type, $ids) {
        $func = $type . '_delete_multiple';
        if(function_exists($func)) {
           try {
           call_user_func($func, $ids);
           } catch (Exception $e) {
             return array();
           }
           return $ids;
        } else {
            $func = $type . '_delete';
                if(function_exists($func)) {
                    foreach($ids as $k => &$id) {
                        try {
                            call_user_func($func, $id);
                        } catch (Exception $e) {
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
     * @param int $limit Limit the number of returned items
     * @return array Array keyed with item ids and value entity_ids
     */
    public static function getExpiredItems($limit = 99999999) {
        $results =  db_select('feed_import_hashes', 'f')
                 ->fields('f', array('feed_id', 'entity_id'))
                 ->condition('expire', array(1, REQUEST_TIME), 'BETWEEN')
                 ->range(0, $limit)
                 ->execute()
                 ->fetchAll();
        if(empty($results)) {
            return $results;
        }
        $temp = array();
        $feeds = self::loadFeeds();
        foreach($feeds as &$feed) {
            $temp[$feed['id']] = $feed['entity_info']['#entity'];
            $feed = NULL;
        }
        unset($feed);
        $res = array();
        foreach($results as &$result) {
            $res[$temp[$result->feed_id]][] = $result->entity_id;
            $result = NULL;
        }
        unset($results, $temp);
        return $res;
    }
    /**
     * Get url status
     * @param string $url URL to XML file
     * @return array Info about status
     */
    public static function getXMLStatus($url) {
        try {
            $fp = fopen($url, 'rb');
            stream_set_timeout($fp, 1);
            $meta = stream_get_meta_data($fp);
            fclose($fp);
            return $meta;
        } catch (Exception $e) {
            return array();
        }
    }
    /**
     * Get value with xpath
     * @param SimpleXMLElement &$item Simplexmlobject
     * @param string $xpath Xpath to value
     */
    protected static function getXpathValue(&$item, $xpath) {
        $xpath = $item->xpath($xpath);
        if(count($xpath) == 1) {
            $xpath = (array)reset($xpath);
            $xpath = isset($xpath[0]) ? $xpath[0] : reset($xpath);
        } else {
            foreach($xpath as $key => &$x) {
                $x = (array)$x;
                $x = isset($x[0]) ? $x[0] : reset($x);
                if(empty($x)) {
                    unset($xpath[$key], $x);
                }
            }
        }
        return $xpath;
    }
    /**
     * Creates a hash using uniq and feed source name
     * @param string $uniq Unique item
     * @param string $feed_name Feed id
     * @return string Hash value
     */
    protected static function createHash($uniq, $feed_id) {
      return md5($uniq . '-|-' . $feed_id);
    }
    
    /**
     * Gets entity ids from a hashes
     * @param array &$hashes Array of hashes
     * @return array Fetched hashes in database
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
     * @param mixed $var Variable to check
     * @return bool TRUE if there is content
     */
    protected static function hasContent(&$var) {
        if(is_scalar($var)) {
            if((string)$var === '') {
                return FALSE;
            }
        } elseif(empty($var)) {
            return FALSE;
        }
        return TRUE;
    }
    /**
     * Create Entity object
     * @param array &$feed Feed info
     * @param object &$item Current SimpleXMLElement object
     * @return object Created Entity
     */
    protected static function createEntity(&$feed, &$item) {
        //create new object to hold fields values
        $entity = new stdClass();
        //check if item already exists
        $uniq = self::getXpathValue($item, $feed['xpath']['#uniq']);
        //create a hash to identify this item in bd
        $entity->{self::$tempHash} = self::createHash($uniq, $feed['id']);
        //add to hashes array
        self::$generatedHashes[] = $entity->{self::$tempHash};
        //set default language, this can be changed by language item
        $entity->language = LANGUAGE_NONE;
        //get all fields
        foreach($feed['xpath']['#items'] as &$field) {
            $i = 0;
            $aux = '';
            $count = count($field['#xpath']);
            //check ONCE if we have to filter or prefilter field
            $prefilter = !empty($field['#pre_filter']);
            $filter = !empty($field['#filter']);
            //loop through xpaths until we have data, otherwise use default value
            while($i < $count) {
                if(!$field['#xpath'][$i]) {
                  $i++;
                  continue;
                }
                $aux = self::getXpathValue($item, $field['#xpath'][$i]);
                if($prefilter) {
                    //if item doesn't pass prefilter than go to next option
                    $pfval = self::applyFilter($aux, $field['#pre_filter']);
                    if(!self::hasContent($pfval)) {
                        $i++;
                        continue;
                    }
                    unset($pfval);
                }
                //if filter passed prefilter then apply filter and exit while loop
                if(self::hasContent($aux)) {
                   if($filter) {
                     $aux = self::applyFilter($aux, $field['#filter']);
                   }
                   break; 
                }
                $i++;
            }
            //if we don't have any data we use default value
            if(!self::hasContent($aux)) {
                $aux = $field['#default_value'];
            }
            
            //set field value
            if($field['#column']) {
              if(is_array($aux)) {
                $i = 0;
                foreach($aux as &$auxv) {
                  $entity->{$field['#field']}[$entity->language][$i][$field['#column']] = $auxv;
                  $i++;
                }
              } else {
                $entity->{$field['#field']}[$entity->language][0][$field['#column']] = $aux;
              }
            } else {
              $entity->{$field['#field']} = $aux;
            }
            unset($aux); //no need anymore, free memory
        }
        return $entity;
    }
    
    /**
     * Saves (or updates) all created entities
     * @param array &$feed Feed info
     * @param array &$items An array with entities
     */
    protected static function saveEntities(&$feed, &$items) {      
      //parse report
      self::$report['parse'] = time();
      //get existing items for update
      if(!empty(self::$generatedHashes)) {
          $ids = self::getEntityIdsFromHash(self::$generatedHashes);
          self::$generatedHashes = array(); //delete all hashes
      } else {
          $ids = array();
      }
      //this sets expire timestamp
      $feed['time'] = (int)$feed['time'];
      //report data
      self::$report['total'] = count($items);
      //now we create real entityes or update existent
      foreach($items as &$item) {
          //save hash and remove from item
          $hash = $item->{self::$tempHash};
          unset($item->{self::$tempHash});
          //check if item is already imported
          if(isset($ids[$hash])) { //we have to update item
              $changed = FALSE; //if entity changed status
              //load entity
              $entity = @call_user_func(self::$functionLoad, $ids[$hash]->entity_id);
              //if entity is missing then skip
              if(empty($entity)) {
                unset($entity);
                continue;
              }
              $lang = $item->language;
              //find if entity is different from last feed
              foreach($item as $key => &$value) {
                if(is_array($value)) {
                    if(!isset($entity->{$key}[$lang]) || empty($entity->{$key}[$lang])) {
                        $changed = TRUE;
                        $entity->{$key} = $value;
                    } elseif(count($value[$lang]) <= 1) {
                        $col = @key($value[$lang][0]);
                        if($entity->{$key}[$lang][0][$col] != $value[$lang][0][$col]) {
                            $changed = TRUE;
                            $entity->{$key} = $value;
                        }
                        unset($col);
                    } else {
                        if(count($entity->{$key}[$lang]) != count($value[$lang])) {
                            $changed = TRUE;
                            $entity->{$key} = $value;
                        } else {
                            $col = key($value[$lang][0]);
                            $temp = array();
                            foreach($entity->{$key}[$lang] as &$ev) {
                                $temp[][$col] = $ev[$col];
                            }
                            if($temp != $value[$lang]) {
                                $changed = TRUE;
                                $entity->{$key} = $value;
                            }
                            unset($temp, $col);
                        }
                    }   
                } else {
                    if(!isset($entity->{$key}) || $entity->{$key} != $value) {
                        $changed = TRUE;
                        $entity->{$key} = $value;
                    }
                }
                
              }
              
              $ok = TRUE;
              //check if entity is changed and save changes
              if($changed) {
                try {
                    @call_user_func(self::$functionSave, $entity);
                    self::$report['updated']++; //set report about updated items
                } catch (Exception $e) {
                    //report error?
                    $ok = FALSE;   
                }
              } else {
                self::$report['rescheduled']++; //set report about rescheduled items
              }
              
              if($ok) {
                //add to update ids
                self::updateIds($ids[$hash]->id);
              }
              unset($ids[$hash], $entity, $lang); //free some memory
          } else {
              //mark as new
              $item->{$feed['entity_info']['#table_pk']} = NULL;
              $ok = TRUE;
              try {
                //save imported item
                @call_user_func(self::$functionSave, $item);
              }  catch (Exception $e) {
                //report error?
                $ok = FALSE;
              }
              if($ok) {
                //insert to feed import hash table
                self::insertItem(array(
                                       $feed['id'],
                                       $item->{$feed['entity_info']['#table_pk']},
                                       $hash,
                                       $feed['time'] ? time() + $feed['time'] : 0
                                       )
                                 );
                self::$report['new']++; //set report about new items
              }
          }
          $item = NULL; //no need anymore
      }
      unset($items, $ids); //free some memory
      self::insertItem(NULL); //insert left items
      //update ids for existing items
      self::updateIds(array(
                        'expire' => $feed['time'] ? time() + $feed['time'] : 0,
                        'feed_id' => $feed['id'],
                    )
                );
    }
    /**
     * Filters a field
     * @param string $field Field value
     * @param array $filters Filters to apply
     * @return mixed Filtered value of field
     */
    protected static function applyFilter($field, $filters) {
        $field_param = variable_get('feed_import_field_param_name', '[field]');
        foreach($filters as &$filter) {
            $filter['#function'] = trim($filter['#function']);
            //check if function exists, support static functions
            if(strpos($filter['#function'], '::') !== FALSE) {
                $filter['#function'] = explode('::', $filter['#function'], 2);
                if(!method_exists(@$filter['#function'][0], @$filter['#function'][1])) {
                    continue;
                }
            } else {
                if(!function_exists($filter['#function'])) {
                    continue;
                }
            }
            //set field value
            $key = array_search($field_param, $filter['#params']);
            $filter['#params'][$key] = $field;
            //apply filter
            $field = call_user_func_array($filter['#function'], $filter['#params']);
            $filter = NULL;
        }
        return $field;
    }
    
    /**
     * Checks if entity functions exists
     * @param string $entity Entity name
     * @return bool True if function exists
     */
    protected static function checkFunctions($entity) {
        self::$functionSave = $entity . '_save';
        self::$functionLoad = $entity . '_load';
        if(!function_exists(self::$functionSave) || !function_exists(self::$functionLoad)) {
           drupal_set_message(t('Could not find @func _save()/_load() function!',
                             array('@func' => $entity)),
                           'error');
           return FALSE;
        }
        return TRUE;
    }
    /**
     * Insert imported item in feed_import_hashes
     * @param mixed $values An array of values or NULL to execute insert
     */
    protected static function insertItem($values) {
        static $q_insert = NULL;
        static $q_insert_items = 0;
        
        if($q_insert == NULL) {
            $q_insert = db_insert('feed_import_hashes')
                            ->fields(array('feed_id', 'entity_id', 'hash', 'expire'));
        }
    
        $q_insert_chunk = variable_get('feed_import_insert_hashes_chunk', 500);
        //call execute and reset number of insert items
        if($values == NULL) {
            if($q_insert_items) {
                $q_insert->execute();
                $q_insert_items = 0;
            }
            return;
        }
        //set values
        $q_insert->values($values);
        $q_insert_items++;
        if($q_insert_items == $q_insert_chunk) {
            $q_insert->execute();
            $q_insert_items = 0;
        }
    }
    
    /**
     * Update imported items ids in feed_import_hashes
     * @param mixed $value An int value to add id to list or an array containing info about update conditions to execute update
     */
    protected static function updateIds($value) {
       static $update_ids = array();
       if(is_array($value)) {
        if(empty($update_ids)) {
            return;
        }
        $q_update = db_update('feed_import_hashes')
              ->fields(array('expire' => $value['expire']))
              ->condition('feed_id', $value['feed_id'], '=');
        $conditions = &$q_update->conditions();
        //split in chunks
        $update_ids = array_chunk($update_ids, variable_get('feed_import_update_ids_chunk', 1000));
        foreach($update_ids as &$ids) {
            $q_update->condition('id', $ids, 'IN')
                     ->execute();
            array_pop($conditions); //remove last IN condition
            $ids = NULL;
        }
        $update_ids = array(); //reset update ids
       } else { //add to list
        $update_ids[] = (int)$value;
       }
    }
    

/***  Feed processor variables *****/

    //Save function name (_save)
    protected static $functionSave;
    //Load function name (_load)
    protected static $functionLoad;
    //SimpleXMLElement class, you can use a class that extends default
    protected static $simpleXMLElement = 'SimpleXMLElement';
    //Temporary property name for hash
    protected static $tempHash = '_feed_item_hash';
    //Generated Hashes
    protected static $generatedHashes = array();
    
/*** Feed processors **********/

    /**
     * Imports and process a feed normally
     * @param array &$feed Feed info
     */
    protected static function processFeedNormal(array &$feed) {
      if(!self::checkFunctions($feed['entity_info']['#entity'])) {
        return FALSE;
      }
      //load xml file from url
      try {
          $xml = simplexml_load_file($feed['url'], self::$simpleXMLElement, LIBXML_NOCDATA);
      } catch(Exception $e) {
          //report error?
          $xml = FALSE;    
      }
      //if is empty then exit
      if($xml == FALSE) {
          //report ?
          return FALSE;
      }
      //get items from root
      $xml = $xml->xpath($feed['xpath']['#root']);
      //get total number of items
      $count_items = count($xml);
      //check if there are items
      if(!$count_items) {
        //report ?
        return FALSE;
      }
      //this is temp name to hold hash
      self::$tempHash = variable_get('feed_import_hash_property', self::$tempHash);
      //reset generated hashes
      self::$generatedHashes = array();
      //check feed items
      foreach($xml as &$item) {
          //set this item value to entity, so all entities will be in $xml at end
          $item = self::createEntity($feed, $item);
      }
      //save all created entities
      self::saveEntities($feed, $xml);
      unset($feed, $xml);
    }
    

    /**
     * Imports and process a huge xml in chunks
     * @param array $feed Feed info
     */
    protected static function processFeedChunked(array &$feed) {
      if(!self::checkFunctions($feed['entity_info']['#entity'])) {
        return FALSE;
      }
      //this is temp name to hold hash
      self::$tempHash = variable_get('feed_import_hash_property', self::$tempHash);
      //reset generated hashes
      self::$generatedHashes = array();
      //this will hold all generated entities
      $entities = array();
      
      $xml_head = '<?xml version="1.0" encoding="utf-8"?>'; //xml head
      $chunk_length = 8192; //bytes read with fread
      $xml_head = variable_get('feed_import_processFeedChunked_xml_head', $xml_head);
      $chunk_length = variable_get('feed_import_processFeedChunked_chunk_length', $chunk_length);
      if($chunk_length <= 0) $chunk_length = 8192; //8k
      //open xml url
      if(!($fp = fopen($feed['url'], 'rb'))) {
        //report error?
        return;
      }
      //preparing tags
      $tag = trim($feed['xpath']['#root'], '/');
      $tag = array(
        'open' => '<' . $tag,
        'close' => '</' . $tag . '>',
        'length' => strlen($tag),
      );
      $tag['closelength'] = strlen($tag['close']);
      //this holds xml content
      $content = '';
      //read all content in chunks
      while(!feof($fp)) {
        $content .= fread($fp, $chunk_length);
        //if there isn't content read again
        if(!$content) {
            continue;
        }
        while(TRUE) {
          $openpos = strpos($content, $tag['open']);
          $openposclose = $openpos+$tag['length']+1;
          if($openpos === FALSE ||
             !isset($content[$openposclose]) ||
             (@$content[$openposclose] != ' ' && @$content[$openposclose] != '>')) {
            break;
          }
          $closepos = strpos($content, $tag['close'], $openposclose);
          if($closepos === FALSE) {
            break;
          }
          //we have data
          $closepos += $tag['closelength'];
          //create xml string
          $item = $xml_head . substr($content, $openpos, $closepos - $openpos);
          //new content
          $content = substr($content, $closepos-1);
          //create xml object
          try {
            $item = simplexml_load_string($item, self::$simpleXMLElement, LIBXML_NOCDATA);
          } catch(Exception $e) {
            //report error?
            continue;
          }
          //parse item
          $item = reset($item->xpath($feed['xpath']['#root']));
          if(empty($item)) {
            continue;
          }
          //create entity
          $item = self::createEntity($feed, $item);
          $entities[] = $item; //put in entities array
          unset($item); //no need anymore
        }
      }
      //close file
      fclose($fp);
      //save all created entities
      self::saveEntities($feed, $entities);
      unset($feed, $entities);
    }
}