<?php

/**
 * @file
 * This file contains Feed Import helpers.
 */

/**
 * This class provides helper functions for feed import.
 */
class FeedImport {

  // Entity info cache.
  protected static $entityInfo = array();

  /**
   * Gets info about an entity.
   * This info is cached.
   *
   * @param string $entity_name The entity name
   *
   * @return object An object that describes entity
   */
  public static function getEntityInfo($entity_name) {
    if (empty($entity_name)) {
      return FALSE;
    }
    elseif (isset(static::$entityInfo[$entity_name])) {
      return static::$entityInfo[$entity_name];
    }

    if (!($entity = entity_get_info($entity_name))) {
      return FALSE;
    }

    // Set main entity info.
    $info = (object) array(
      'name' => $entity_name,
      'idKey' => isset($entity['entity keys']['id']) ?
                    $entity['entity keys']['id'] : NULL,
      'langKey' => isset($entity['entity keys']['language']) ?
                    $entity['entity keys']['language'] : 'language',
      'bundleKey' => isset($entity['entity keys']['bundle']) ?
                    $entity['entity keys']['bundle'] : NULL,
      'createCallback' => isset($entity['creation callback']) ?
                          $entity['creation callback'] : NULL,
      'saveCallback' => isset($entity['save callback']) ?
                          $entity['save callback'] : NULL,
      'deleteCallback' => isset($entity['deletion callback']) ?
                            $entity['deletion callback'] : NULL,
      'controller' => entity_get_controller($entity_name),
      'properties' => array(),
      'fields' => array(),
    );

    if (!$info->saveCallback && function_exists($entity_name . '_save')) {
      $info->saveCallback = $entity_name . '_save';
    }

    if (!$info->deleteCallback && function_exists($entity_name . '_delete')) {
      $info->deleteCallback = $entity_name . '_delete';
    }

    // Get fields info.
    if ($fieldlist = field_info_instances($entity_name)) {
      foreach ($fieldlist as &$fields) {
        foreach ($fields as &$field) {
          if (!empty($field['deleted']) || isset($info->fields[$field['field_name']])) {
            continue;
          }
          $field_info = field_info_field($field['field_name']);
          $info->fields[$field['field_name']] = array(
            'name' => $field['field_name'],
            'column' => key($field_info['columns']),
            'columns' => array_keys($field_info['columns']),
            'cardinality' => $field_info['cardinality'],
          );
        }
      }
    }

    if (function_exists('entity_get_property_info')) {
      // Get properties info.
      $prop_info = entity_get_property_info($entity_name);
      if (isset($prop_info['properties'])) {
        $info->properties = array_diff(
          array_keys($prop_info['properties']),
          array_keys($info->fields)
        );
      }
    }
    elseif (isset($entity['schema_fields_sql']['base table'])) {
      $info->properties = array_diff(
        $entity['schema_fields_sql']['base table'],
        array_keys($info->fields)
      );
    }

    return static::$entityInfo[$entity_name] = $info;
  }

  /**
   * Cache used for entity names.
   */
  protected static $entityNames = array();

  /**
   * Get all entity names.
   *
   * @return array
   *    List of all entity names.
   */
  public static function getAllEntities() {
    if (!static::$entityNames) {
      foreach (entity_get_info() as $entity => $info) {
        static::$entityNames[$entity] = $info['label'];
      }
    }
    return static::$entityNames;
  }

  /**
   * Returns a new feed processor
   *
   * @param object $feed
   *     Feed settings
   * @param string $filter_dir
   *     Path to extra filters dir
   *
   * @return FeedImportProcessor
   *     An instance of FeedImportProcessor or FALSE on error
   */
  public static function getProcessor($feed, $filter_dir) {
    $s = $feed->settings;

    $fi = new $s['processor']['class']($feed->machine_name);
    if (!$fi->setReader(new $s['reader']['class']($s['reader']['options']))) {
      return FALSE;
    }
    $fi->setOptions($s['processor']['options']);
    $fi->setEntityInfo(static::getEntityInfo($feed->entity));


    $fi->setFilter(new $s['filter']['class']($filter_dir, $s['filter']['options']));
    $fi->setPreFilter(new $s['filter']['class']($filter_dir, $s['filter']['options']));

    $hm = new $s['hashes']['class']($feed->entity, $feed->machine_name);
    $hm->setOptions($s['hashes']['options']);
    $fi->setHashManager($hm);

    $fi->setUniq($s['uniq_path']);
    $fi->setFields($s['fields'], $s['static_fields']);

    // Remove the basic settings.
    $base_settings = array(
      'uniq_path', 'processor', 'reader', 'hashes',
      'filter', 'fields', 'static_fields',
    );
    foreach ($base_settings as $f) {
      unset($s[$f]);
    }

    // Set custom settings.
    if (!$fi->setCustomSettings($s)) {
      return FALSE;
    }

    return $fi;
  }

  // A list of all feeds configuration.
  protected static $feedsList = array();

  /**
   * Get a list with all feeds.
   */
  public static function loadAllFeeds() {
    if (static::$feedsList) {
      return static::$feedsList;
    }
    $feeds = db_select('feed_import_settings', 'f')
            ->fields('f')
            ->execute()
            ->fetchAll();
    $ret = array();
    foreach ($feeds as &$feed) {
      $feed->settings = unserialize($feed->settings);
      $ret[$feed->machine_name] = $feed;
    }
    return static::$feedsList = $ret;
  }

  /**
   * Loads a feed from database
   *
   * @param int|string $name
   *    Feed machine name or id
   *
   * @return object
   *    Feed object or FALSE
   */
  public static function loadFeed($name) {
    $feed = db_select('feed_import_settings', 'f')
                ->fields('f')
                ->condition(((int) $name) ? 'id' : 'machine_name', $name, '=')
                ->range(0, 1)
                ->execute()
                ->fetchObject();
    if (!$feed) {
      return FALSE;
    }
    $feed->settings = unserialize($feed->settings);
    return $feed;
  }

  /**
   * Deletes a feed.
   *
   * @param object $feed
   *    Feed info
   * @param bool $hashes
   *    Also remove hashes
   */
  public static function deleteFeed($feed, $hashes = TRUE) {
    db_delete('feed_import_settings')
      ->condition('machine_name', $feed->machine_name)
      ->execute();
    if ($hashes && isset($feed->settings['hashes']['class'])) {
      $class = $feed->settings['hashes']['class'];
      $class::deleteByFeed($feed->machine_name);
    }
  }


  /**
   * Gets a new empty feed configuration.
   *
   * @return array
   *     An empty feed configuration.
   */
  public static function getEmptyFeed() {
    return array(
      'name' => NULL,
      'machine_name' => NULL,
      'entity' => NULL,
      'cron_import' => 0,
      'last_run' => 0,
      'last_run_duration' => 0,
      'settings' => array(
        'expire' => 0,
        'uniq_path' => NULL,
        'processor' => array(
          'name' => 'default',
          'class' => 'FeedImportProcessor',
          'options' => array(
            'items_count' => 0,
            'skip_imported' => FALSE,
            'reset_cache' => 100,
          ),
        ),
        'reader' => array(
          'name' => 'xml',
          'class' => 'SimpleXMLFIReader',
          'options' => array(),
        ),
        'hashes' => array(
          'name' => 'sql',
          'class' => 'FeedImportSQLHashes',
          'options' => array(
            'ttl' => 0,
            'insert_chunk' => 300,
            'update_chunk' => 300,
            'group' => '',
          ),
        ),
        'filter' => array(
          'name' => 'default',
          'class' => 'FeedImportMultiFilter',
          'options' => array(
            'param' => '[field]',
            'include' => NULL,
          ),
        ),
        'fields' => array(),
        'static_fields' => array(),
      ),
    );
  }

  /**
   * Saves a feed in database
   *
   * @param object $feed
   *    Feed info
   */
  public static function saveFeed($feed) {
    if (empty($feed->name) || empty($feed->machine_name) ||
        empty($feed->entity) || empty($feed->settings)) {
      return FALSE;
    }
    if (!isset($feed->cron_import)) {
      $feed->cron_import = 0;
    }
    $fields = array(
      'name' => $feed->name,
      'machine_name' => $feed->machine_name,
      'entity' => $feed->entity,
      'cron_import' => (int) $feed->cron_import,
      'settings' => serialize($feed->settings),
    );
    if (isset($feed->id)) {
      db_update('feed_import_settings')
        ->fields($fields)
        ->condition('id', $feed->id)
        ->execute();
    }
    else {
      $fields += array(
        'last_run' => 0,
        'last_run_duration' => 0,
      );
      db_insert('feed_import_settings')
        ->fields($fields)
        ->execute();
    }
    return TRUE;
  }

  /**
   * Saves feed import status
   */
  public static function saveFeedImportStatus($feed) {
    db_update('feed_import_settings')
      ->fields(array(
        'last_run' => (int) $feed->last_run,
        'last_run_duration' => (int) $feed->last_run_duration,
      ))
      ->condition('machine_name', $feed->machine_name)
      ->execute();
  }

  /**
   * Imports a feed.
   *
   * @param object $feed
   *    Feed info
   * @param string $filters_dir
   *    Path to filters dir
   */
  public static function import($feed, $filters_dir) {
    if ($f = static::getProcessor($feed, $filters_dir)) {
      return $f->process();
    }
    return FALSE;
  }

  /**
   * Gets all hash manager classes.
   *
   * @return array
   *    An array of classes
   */
  public static function getHashManagers() {
    static $hm = array();
    if ($hm) {
      return $hm;
    }
    $base = 'FeedImportHashManager';
    foreach (get_declared_classes() as $class) {
      if (is_subclass_of($class, $base)) {
        $hm[] = $class;
      }
    }
    return $hm;
  }

  /**
   * Delete all expired items.
   *
   * @param int $max
   *    Max number of entity ids to delete from
   *    a hash manager
   *
   * @return int
   *    Number of deleted entities
   */
  public static function deleteExpired($max = 0) {
    $deleted = 0;
    foreach (static::getHashManagers() as $class) {
      $items = $class::getExpired($max);
      foreach ($items as $e => &$ids) {
        $entity = static::getEntityInfo($e);
        // Delete entities.
        if ($entity->deleteCallback) {
          $f = $entity->deleteCallback . '_multiple';
          if (function_exists($f)) {
            $f($ids);
          }
          else {
            array_map($entity->deleteCallback, $ids);
          }
        }
        else {
          $entity->controller->delete($ids);
        }
        // Delete hashes.
        $class::delete(array_keys($ids));
        $deleted += count($ids);
        unset($items[$e], $entity);
      }
    }
    return $deleted;
  }

  // Deleted entities.
  protected static $deletedEntities = array();

  /**
   * Schedule a deleted entity to be removed from hashes.
   *
   * @param string $entity_type
   *    Entity type
   * @param int $id
   *    Entity id
   */
  public static function addDeletedEntity($entity_type, $id) {
    static $unregistered = TRUE;
    if ($unregistered) {
      $unregistered = FALSE;
      register_shutdown_function(array(__CLASS__, 'removeEntityHashes'));
    }
    static::$deletedEntities[$entity_type][] = $id;
  }

  /**
   * Removes entity hashes.
   */
  public static function removeEntityHashes($entities = NULL) {
    if ($entities == NULL) {
      $entities = &static::$deletedEntities;
    }
    foreach (static::getHashManagers() as $hm) {
      array_walk($entities, array($hm, 'deleteEntities'));
    }
    $entities = NULL;
  }

}

/**
 * Class that processess the import.
 */
class FeedImportProcessor extends FeedImportConfigurable {

  // No duplicates
  const UPDATE_COMBINE = 0;
  // Duplicates
  const UPDATE_MERGE = 1;
  // Overwrite
  const UPDATE_OVERWRITE = 2;

  // No field value actions.
  const ACTION_DEFAULT_VALUE = 0;
  const ACTION_DEFAULT_FILTERED_VALUE = 1;
  const ACTION_IGNORE_FIELD = 2;
  const ACTION_SKIP_ITEM = 3;

  // Action before/after combine entities
  const ENTITY_CONTINUE = 0;
  const ENTITY_RESCHEDULE = 1;
  const ENTITY_SKIP = 2;
  const ENTITY_NO_COMBINE = 3;

  // Hash property name.
  const TEMP_HASH = '$_FI~HASH_$';

  // Fields imported.
  protected $fields = array();
  // Static fields.
  protected $staticFields = array();
  // Base entity.
  protected $baseEntity = array();
  // Fields info.
  protected $fieldsInfo = array();
  // Uniq path.
  protected $uniq;
  // Feed group.
  protected $group;
  // Feed machine name.
  protected $machineName;
  // Flag to skip imported items.
  protected $skipImportedItems = FALSE;
  // Entity info.
  protected $entityInfo;
  // Current reader.
  protected $reader;
  // Current hash manager.
  protected $hashes;
  // filters
  protected $filters;
  // prefilters
  protected $prefilters;
  // After how many of entities to save.
  protected $itemsCount = 0;
  // After how many items to reset entity static cache.
  protected $resetStaticCache = 0;
  // Number entities in static cache.
  protected $staticCacheEntities = 0;
  // Callback for before combine entities
  protected $beforeCombine = FALSE;
  protected $afterCombine  = FALSE;

  // Current entities in work.
  public $current;
  // Current prefiltered values.
  public $prefilteredValue;
  // Reports.
  public $report;

  // List of missing entities (as hash ids)
  protected $orphanHashes = array();

  // A null value.
  protected $NULL = NULL;

  /**
   * Constructor.
   */
  public function __construct($machine_name) {
    $this->machineName = $machine_name;
  }

  /**
   * {@inheritdoc}
   */
  public function setOptions(array $options, $overwrite = FALSE) {
    if (isset($options['items_count']) && $options['items_count'] >= 0) {
      $this->itemsCount = (int) $options['items_count'];
    }
    if (isset($options['skip_imported'])) {
      $this->skipImportedItems = (bool) $options['skip_imported'];
    }
    if (isset($options['max_reported_errors'])) {
      $this->errorsLeft = (int) $options['max_reported_errors'];
    }
    if (isset($options['throw_exception'])) {
      $this->throwException = (bool) $options['throw_exception'];
    }
    if (isset($options['reset_cache']) && $options['reset_cache'] >= 0) {
      $this->resetStaticCache = (int) $options['reset_cache'];
    }
    if (isset($options['before_combine']) && function_exists($options['before_combine'])) {
      $this->beforeCombine = $options['before_combine'];
    }
    if (isset($options['after_combine']) && function_exists($options['after_combine'])) {
      $this->afterCombine = $options['after_combine'];
    }
  }

  /**
   * {@inheritdoc}
   */
  public static function validateOption($option, $value) {
    switch ($option) {
      case 'max_reported_errors':
      case 'items_count':
      case 'reset_cache':
        return $value >= 0;
    }
    return TRUE;
  }

  /**
   * Sets the entity info object.
   */
  public function setEntityInfo($entity_info) {
    if (!$entity_info) {
      return FALSE;
    }
    $this->entityInfo = $entity_info;
  }

  /**
   * Sets the filters object.
   *
   * @param FeedImportMultiFilter $filter
   *    An instance of FeedImportMultiFilter
   */
  public function setFilter(FeedImportMultiFilter $filter) {
    $this->filters = $filter;
  }

  /**
   * Sets the prefilters object.
   *
   * @param FeedImportMultiFilter $filter
   *    An instance of FeedImportMultiFilter
   */
  public function setPreFilter(FeedImportMultiFilter $filter) {
    $this->prefilters = $filter;
  }

  /**
   * Sets the reader and inits it
   *
   * @param FeedImportReader $reader
   *     An instance of reader
   *
   * @return boolean
   *     TRUE if success
   */
  public function setReader(FeedImportReader $reader) {
    $this->reader = $reader;
    return $this->reader->init();
  }


  /**
   * Sets uniq path for monitoring items.
   *
   * @param string $uniq
   *     A path.
   *
   * @return boolean
   *     TRUE if success
   */
  public function setUniq($uniq) {
    if ($uniq == NULL) {
      return TRUE;
    }
    if ($this->reader) {
      $this->uniq = $this->reader->formatPath($uniq);
      if (is_scalar($this->uniq)) {
        if ((string) $this->uniq === '') {
          $this->uniq = NULL;
        }
      }
      elseif (empty($this->uniq)) {
        $this->uniq = NULL;
      }
      return TRUE;
    }
    return FALSE;
  }

  /**
   * Sets the hash manager
   *
   * @param FeedImportHashManager $hm
   *     An instance of FeedImportHashManager
   */
  public function setHashManager(FeedImportHashManager $hm) {
    $this->hashes = $hm;
  }

  /**
   * Sets entity fields.
   *
   * @param array $fields
   *     List of dynamic fields
   * @param array $static_fields
   *     List of static fields
   *
   * @return boolean
   *     TRUE if fields were set
   */
  public function setFields(array $fields = array(), array $static_fields = array()) {

    // Check for requirements.
    if (!$this->entityInfo || !$this->reader ||
        !$this->prefilters || !$this->filters ||
        !empty($this->baseEntity)
        ) {
      return FALSE;
    }

    // Create format paths callback.
    $fp = array($this->reader, 'formatPath');

    // Get language key.
    $lk = $this->entityInfo->langKey;

    // Set static fields.
    $this->staticFields = &$static_fields;

    // Parse all fields.
    foreach ($fields as &$f) {

      // Set field info.
      $this->fieldsInfo[$f['field']] = &$f;

      // Check if column must be guessed.
      if ($f['column'] === TRUE && isset($this->entityInfo->fields[$f['field']])) {
        $f['column'] = $this->entityInfo->fields[$f['field']]['column'];
      }

      // Get paths count
      $f['paths_count'] = count($f['paths']);

      // Check if can be used as static field to improve performance
      if (!$f['paths_count'] && $f['default_action'] == 'default_value') {
        if ($f['column']) {
          // Its a field, add it to static fields.
          $this->staticFields[$f['field']][$f['column']] = $f['default_value'];
        }
        else {
          // Its a property.
          $this->staticFields[$f['field']] = $f['default_value'];
        }
        // Ignore filters.
        $f['prefilters'] = $f['filters'] = FALSE;
        // Next field.
        continue;
      }

      // Check prefilters
      if ($f['prefilters']) {
        $c = 0;
        // Add prefilters
        foreach ($f['prefilters'] as &$v) {
          $this->prefilters->add($f['field'], $v['function'], $v['params']) && $c++;
        }
        $f['prefilters'] = $c > 0;
      }
      else {
        // No prefilters
        $f['prefilters'] = FALSE;
      }

      // Check filters
      if (count($f['filters'])) {
        $c = 0;
        // Add filters
        foreach ($f['filters'] as &$v) {
          $this->filters->add($f['field'], $v['function'], $v['params']) && $c++;
        }
        $f['filters'] = $c > 0;
      }
      else {
        // No filters
        $f['filters'] = FALSE;
      }

      // Format paths
      $f['paths'] = array_map($fp, $f['paths']);

      // Add to fields
      $this->fields[$f['field']] = &$f;
    }

    // Remove reference to $f.
    unset($f);

    // Set all static fields properties.
    // In the end just fields remain in static fields (no properties).
    foreach ($this->staticFields as $f => &$value) {
      if (is_scalar($value)) {
        if (!isset($this->fieldsInfo[$f])) {
          $this->fieldsInfo[$f] = array(
            'field' => $f,
            'column' => FALSE,
            'update_mode' => static::UPDATE_COMBINE,
          );
        }
        $this->baseEntity[$f] = $value;
        unset($this->staticFields[$f]);
      }
      elseif (!isset($this->fieldsInfo[$f])) {
        $this->fieldsInfo[$f] = array(
          'field' => $f,
          'column' => isset($this->entityInfo->fields[$f]) ?
                      $this->entityInfo->fields[$f]['column'] : 'value',
          'update_mode' => static::UPDATE_COMBINE,
        );
      }
    }

    // There isn't lang in base entity nor in fields so use default.
    if (!isset($this->baseEntity[$lk]) && !isset($this->fields[$lk])) {
      $this->baseEntity[$lk] = LANGUAGE_NONE;
    }

    // Check for language in base entity.
    if (isset($this->baseEntity[$lk])) {
      // We have lang so set static fields in base entity.
      $lang = $this->baseEntity[$lk];
      foreach ($this->staticFields as $f => &$value) {
        if (!isset($this->fields[$f])) {
          // This field is not dynamically generated so use it in base entity.
          $this->baseEntity[$f][$lang][] = $value;
          // Remove it from static fields because now is part of base entity.
          unset($this->staticFields[$f]);
        }
      }
    }
    else {
      // No language, it means language is dynamically created.
      // Move language on top to be available for other fields.
      $this->fields = array_merge(array($lk => NULL), $this->fields);
      // However, set it to NONE, and if used will be overwritten.
      $this->baseEntity[$lk] = LANGUAGE_NONE;
    }

    return TRUE;
  }

  /**
   * Sets custom settings, after base settings were set.
   * Can be used by child classes.
   *
   * @param array $settings
   *    An array of settings.
   *
   * @return bool
   *    TRUE if success. If not, the import will be stopped.
   */
  public function setCustomSettings(array $settings) {
    return TRUE;
  }


  /**
   * Checks if a variable has content
   *
   * @param mixed &$var
   *     Variable to check
   *
   * @return bool
   *     TRUE if there is content FALSE otherwise
   */
  protected function hasContent(&$var) {
    if (is_scalar($var)) {
      return ((string) $var) !== '';
    }
    return !empty($var);
  }

  /**
   * Attaches a field value to entity.
   *
   * @param array &$entity
   *     Entity where to attach field.
   * @param array &$field
   *     Field settings.
   * @param mixed &$value
   *     Field value.
   */
  protected function attachField(array &$entity, &$field, &$value) {
    if ($field['column']) {
      // It is a field.
      $fn = &$field['field'];
      $fc = &$field['column'];

      $lang = &$entity[$this->entityInfo->langKey];
      // Check if field exists.
      if (!isset($entity[$fn][$lang])) {
        $entity[$fn][$lang] = array();
      }

      // Get field reference.
      $ef = &$entity[$fn][$lang];

      // Check for static fields.
      $st = FALSE;
      if (!empty($this->staticFields[$fn])) {
        $st = &$this->staticFields[$fn];
      }

      // Make it look multivalued.
      if (!is_array($value)) {
        $value = array($value);
      }

      // Set field properties.
      if ($st) {
        // There are static field props.
        $fa = array($fc => NULL);
        $fv = &$fa[$fc];
        foreach ($value as &$v) {
          if (is_object($v)) {
            $ef[] = ((array) $v) + $st;
          }
          else {
            $fv = $v;
            $ef[] = $fa + $st;
          }
        }
      }
      else {
        // No static field props.
        foreach ($value as &$v) {
          if (is_object($v)) {
            $ef[] = (array) $v;
          }
          else {
            $ef[][$fc] = $v;
          }
        }
      }
    }
    else {
      // If this is a property then get only first value.
      if (is_array($value) || is_object($value)) {
        // This still can be array but if so then problem is elsewhere.
        $value = reset($value);
      }
      // Set property.
      $entity[$field['field']] = $value;
    }
  }


  /**
   * Creates a new entity.
   *
   * @param mixed $item
   *     Item to be mapped to entity
   *
   * @return object
   *     An array containing the entity or NULL
   */
  protected function &createEntity(&$item) {
    // Create the entity.
    $entity = $this->baseEntity;
    // Set a ref for current entity.
    $this->current = &$entity;
    // Check for unique id of item.
    if ($this->uniq === NULL) {
      // No hash, so not monitorized.
      $entity[static::TEMP_HASH] = NULL;
    }
    else {
      // Get uniq.
      $uniq = $this->reader->map($item, $this->uniq);
      if (is_array($uniq)) {
        $uniq = isset($uniq[0]) ? $uniq[0] : reset($uniq);
      }
      // Create hash.
      $entity[static::TEMP_HASH] = $this->hashes->hash($uniq);
    }

    // Set entity fields.
    foreach ($this->fields as $name => &$field) {
      // Get filtered field value.
      $val = NULL; $i = -1;
      while (++$i < $field['paths_count']) {
        // Get value for specified path.
        $val = $this->reader->map($item, $field['paths'][$i]);
        // Check if passes prefilter.
        if ($field['prefilters']) {
          $this->prefilteredValue = $this->prefilters->apply($name, $val);
          if (!$this->hasContent($this->prefilteredValue)) {
            $val = $this->prefilteredValue = NULL;
            // If item doesn't pass prefilter than go to next path.
            continue;
          }
        }
        if ($this->hasContent($val)) {
          // We have content, check for filters.
          if ($field['filters']) {
            $val = $this->filters->apply($name, $val);
            if (!$this->hasContent($val)) {
              $val = NULL;
            }
          }
          // We matched one path.
          break;
        }
      }
      // Check if default action is needed.
      if ($val === NULL) {
        switch ($field['default_action']) {
          // Provide default value.
          // This is also default action.
          case static::ACTION_DEFAULT_VALUE:
          default:
            $val = $field['default_value'];
            break;
          // Provide filtered default value.
          case static::ACTION_DEFAULT_FILTERED_VALUE:
            $val = $this->filters->apply($name, $field['default_value']);
            break;
          // Skip this item by returning NULL.
          case static::ACTION_SKIP_ITEM:
            $this->report['skipped']++;
            $this->current = $this->prefilteredValue = NULL;
            return $this->NULL;
          // Don't add this field to entity.
          case static::ACTION_IGNORE_FIELD:
            $this->prefilteredValue = NULL;
            continue 2;
        }
      }

      // Remove prefiltered value.
      $this->prefilteredValue = NULL;

      // Set field value in entity.
      $this->attachField($entity, $field, $val);

      // Not needed anymore.
      unset($val);
    }

    // Remove current reference.
    unset($this->current);
    $this->current = NULL;

    // Return the entity array.
    return $entity;
  }


  /**
   * Combines two entities.
   *
   * @param array $new
   *     Created entity
   * @param object $curr
   *     Current entity
   *
   * @return boolean
   *     TRUE if current entity was changed
   */
  protected function combineEntities(array &$new, $curr) {

    // Initial entity status.
    $changed = FALSE;

    // Get entity language
    $lang = &$new[$this->entityInfo->langKey];

    // Compare fields from entities.
    foreach ($this->fieldsInfo as &$field) {
      // Get update mode.
      $overwrite = $field['update_mode'] == static::UPDATE_OVERWRITE;

      // Get field name and column.
      $fn = &$field['field'];
      $fc = &$field['column'];

      if ($fc) {
        // It is a field
        if (isset($new[$fn][$lang])) {
          if (isset($curr->{$fn}[$lang])) {
            // Get a ref to use short names.
            $en = &$new[$fn][$lang];
            $ec = &$curr->{$fn}[$lang];

            // Check if overwrite is enabled
            if ($overwrite) {
              if ($ec != $en) {
                $ec = $en;
                $changed = TRUE;
              }
              unset($ec, $en);
              continue;
            }

            // Check if duplicate field values are allowed.
            if ($field['update_mode'] == static::UPDATE_MERGE) {
              $ec = array_merge($ec, $en);
              // Mark as changed.
              $changed = TRUE;
              unset($ec, $en);
              continue;
            }

            // Well, we must search to see if item already exists.
            $cnt = count($ec);
            while ($en) {
              $val = array_shift($en);
              // Check if already exists.
              $add = TRUE;
              for ($i = 0; $i < $cnt; $i++) {
                // Compare by key-value.
                if (!array_diff_assoc($val, $ec[$i])) {
                  $add = FALSE;
                  break;
                }
              }
              // Do not exists so add it.
              if ($add) {
                $ec[] = $val;
                $cnt++;
                // Mark as changed.
                $changed = TRUE;
              }
            }

            // Remove refs.
            unset($en, $ec);
          }
          elseif ($overwrite) {
            // Overwrite.
            $curr->{$fn}[$lang] = $new[$fn][$lang];
            // Mark as changed.
            $changed = TRUE;
          }
          // Remove from memory.
          unset($new[$fn]);
        }
        elseif ($overwrite && isset($curr->{$fn}[$lang])) {
          // Forced update mode.
          unset($curr->{$fn}[$lang]);
          // Don't leave an empty property.
          if (empty($curr->{$fn})) {
            unset($curr->{$fn});
          }
          // Mark as changed.
          $changed = TRUE;
        }
      }
      else {
        // It is a property.
        if (isset($new[$fn])) {
          if (isset($curr->{$fn}) && $curr->{$fn} == $new[$fn]) {
            // Nothing changed.
            continue;
          }
          // Set the new property value.
          $curr->{$fn} = $new[$fn];
          // Mark as changed.
          $changed = TRUE;
          // Remove from memory.
          unset($new[$fn]);
        }
        elseif ($overwrite && isset($curr->{$fn})) {
          // Forced update mode is selected.
          unset($curr->{$fn});
          // Mark as changed.
          $changed = TRUE;
        }
      }
    }

    return $changed;
  }

  /**
   * Saves created entities.
   *
   * @param array &$entities
   *   An array of entities to be saved
   */
  protected function saveEntities(array &$entities) {
    static $loadArr = array(NULL);
    // Number of entities.
    $total = count($entities);
    $this->report['total'] += $total;

    // Get ids from hashes.
    $ids = $this->hashes->get();

    // Entity create callback.
    $createCallback = &$this->entityInfo->createCallback;
    // Entity save callback.
    $saveCallback = &$this->entityInfo->saveCallback;
    // Entity before combine callback
    $beforeCombine = &$this->beforeCombine;
    // Entity after combine callback
    $afterCombine = &$this->afterCombine;

    // Entity load id.
    $loadId = &$loadArr[0];

    $i = -1;
    // Save each entity.
    while (++$i < $total) {
      $entity = &$entities[$i];
      unset($entities[$i]);
      if ($entity == NULL) {
        continue;
      }
      // Save hash.
      $hash = &$entity[static::TEMP_HASH];
      // Not needed anymore.
      unset($entity[static::TEMP_HASH]);
      // Check if item is already imported or is not monitorized.
      if ($hash !== NULL && isset($ids[$hash])) {
        // Check if is used option to skip item if already imported.
        if ($this->skipImportedItems) {
          $this->report['skipped']++;
          unset($ids[$hash]);
          continue;
        }

        // Current entity id.
        $loadId = $ids[$hash]->entity_id;

        // Load current entity.
        $current = entity_load($this->entityInfo->name, $loadArr);

        // If entity is missing then skip.
        if (!isset($current[$loadId])) {
          $this->report['missing']++;
          $this->orphanHashes[] = $ids[$hash]->id;
          unset($ids[$hash], $current);
          continue;
        }
        // Get current entity value.
        $current = &$current[$loadId];
        // Entity might be in static cache.
        $this->staticCacheEntities++;

        // Check for before combine callback.
        if ($beforeCombine) {
          switch ($beforeCombine($entity, $current)) {
            case static::ENTITY_RESCHEDULE:
              $this->report['rescheduled']++;
              goto ADD_HASH_UPDATE;
            case static::ENTITY_SKIP:
              $this->report['skipped']++;
              goto UNSET_VARS;
            case static::ENTITY_NO_COMBINE:
              goto COMBINE_FINISHED;
          }
        }

        // Check for diff.
        $changed = $this->combineEntities($entity, $current);

        // Check for after combine callback.
        if ($afterCombine) {
          switch ($afterCombine($entity, $current, $changed)) {
            case static::ENTITY_RESCHEDULE:
              $this->report['rescheduled']++;
              goto ADD_HASH_UPDATE;
            case static::ENTITY_SKIP:
              $this->report['skipped']++;
              goto UNSET_VARS;
          }
        }

        COMBINE_FINISHED:
        // Not needed anymore.
        unset($entity);
        // Check if entity is changed and save changes.
        if ($changed) {
          // Save entity.
          if (!$saveCallback && !$current->save()) {
            unset($current);
            continue;
          }
          else {
            $saveCallback($current);
          }
          // Set report about updated items.
          $this->report['updated']++;
        }
        else {
          // Set report about rescheduled items.
          $this->report['rescheduled']++;
        }
        ADD_HASH_UPDATE:
        // Add to update ids.
        $this->hashes->update($ids[$hash]->id);
        UNSET_VARS:
        // Free some memory.
        unset($ids[$hash], $current, $entity, $hash);
      }
      else {
        // Create a new entity.
        if ($createCallback) {
          $entity = $createCallback($entity);
        }
        elseif (method_exists($this->entityInfo->controller, 'create')) {
          $entity = $this->entityInfo->controller->create($entity);
        }
        else {
          $entity = (object) $entity;
        }
        // Save entity, if any.
        if (!$entity || (!$saveCallback && ($entity->save() === FALSE))) {
          continue;
        }
        else {
          $saveCallback($entity);
        }
        // Entity might be added in static cache.
        $this->staticCacheEntities++;
        // Check if is monitorized.
        if ($hash !== NULL) {
          // Insert into feed import hash table.
          $this->hashes->insert($entity->{$this->entityInfo->idKey}, $hash);
        }
        // Set report about new items.
        $this->report['new']++;

        // Not needed anymore.
        unset($entity, $hash);
      }
      // Remove entity cache.
      if ($this->staticCacheEntities == $this->resetStaticCache) {
        $this->entityInfo->controller->resetCache();
        $this->staticCacheEntities = 0;
      }
    }
  }

  /**
   * Load entities by id
   * Hoever, this is not used...
   *
   * @param array $ids
   *     An array of entity ids
   *
   * @return array
   *     An array of entities keyed by ids
   */
  protected function entityLoad(array &$ids) {
    static $conditions = array();
    return $this->entityInfo->controller->load($ids, $conditions);
  }

  /**
   * Processes the import.
   */
  public function process() {
    // Give import time (for large imports).
    // Well, if safe mode is on this cannot be done so it may break import.
    if (!ini_get('safe_mode')) {
      set_time_limit(0);
    }
    // Reset report.
    $this->report = array(
      'total' => 0,
      'updated' => 0,
      'new' => 0,
      'rescheduled' => 0,
      'skipped' => 0,
      'missing' => 0,
      'started' => time(),
      'finished' => 0,
      'errors' => array(),
    );
    // Set error handler.
    set_error_handler(array($this, 'errorHandler'));
    $entities = array();
    $reader = $this->reader;
    if ($this->itemsCount) {
      $current = 0;
      // Get next item from reader.
      while ($item = $reader->get()) {
        // Create the entity.
        $entities[] = &$this->createEntity($item);
        // Check for save.
        if (++$current == $this->itemsCount) {
          unset($item);
          $this->saveEntities($entities);
          $current = 0;
          $entities = array();
        }
      }
    }
    else {
      // Get next item from reader.
      while ($item = $reader->get()) {
        // Create entity.
        $entities[] = &$this->createEntity($item);
      }
    }
    // Check for left over entities.
    if ($entities) {
      $this->saveEntities($entities);
    }

    // Not needed anymore.
    unset($entities, $reader);

    // Reset the static cache.
    if ($this->staticCacheEntities && $this->resetStaticCache) {
      $this->entityInfo->controller->resetCache();
      $this->staticCacheEntities = 0;
    }

    // Commit left over hash updates.
    $this->hashes->updateCommit();
    // Also the new hash inserts.
    $this->hashes->insertCommit();
    // Remove orphan hashes.
    if ($this->orphanHashes) {
      $this->hashes->delete($this->orphanHashes);
      $this->orphanHashes = array();
    }

    // Restor error handler.
    restore_error_handler();

    // Return report.
    $this->report['finished'] = time();
    return $this->report;
  }


  // Max number of errors to report.
  protected $errorsLeft = 100;
  protected $throwException = TRUE;
  /**
   * Error handler callback
   * This is setted with set_error_handling()
   */
  public function errorHandler($errno, $errmsg, $file, $line) {
    // Handle silenced errors with @.
    if (error_reporting() == 0) {
      return FALSE;
    }

    // Add error to reports.
    if ($this->errorsLeft > 0) {
      $this->report['errors'][] = array(
        'error' => $errmsg,
        'error number' => $errno,
        'line' => $line,
        'file' => $file,
      );
      $this->errorsLeft--;
    }

    // Throw an exception to be caught by a try-catch statement.
    if ($this->throwException) {
      throw new Exception('(Uncaught Feed Import Exception) ' . $errmsg, $errno);
    }
  }
}


/**
 * This class implements SQL hash storage
 */
class FeedImportSQLHashes extends FeedImportHashManager {

  // Feed machine name.
  protected $feedName;

  // Entity name.
  protected $entity;

  // Insert format.
  protected $insertFormat;
  // Update format.
  protected $updateFormat;

  // Used to generate hash.
  protected $hashPart;

  // Reuseable db objects.
  protected $select;
  protected $insert;
  protected $update;

  // Options
  protected $updateChunkSize = 500;
  protected $insertChunkSize = 500;
  protected $group = '';

  /**
   * {@inheritdoc}
   */
  public function __construct($entity_name, $feed_machine_name) {
    $this->entity = $entity_name;
    $this->feedName = $feed_machine_name;
  }

  /**
   * {@inheritdoc}
   */
  public function setOptions(array $options, $overwrite = FALSE) {
    if (isset($options['ttl']) && $options['ttl'] >= 0) {
      $this->ttl = (int) $options['ttl'];
    }
    if (isset($options['update_chunk']) && $options['update_chunk'] > 0) {
      $this->updateChunkSize = (int) $options['update_chunk'];
    }
    if (isset($options['insert_chunk']) && $options['insert_chunk'] > 0) {
      $this->insertChunkSize = (int) $options['insert_chunk'];
    }
    if (isset($options['group'])) {
      $this->group = $options['group'];
    }

    // Create insert format.
    $this->insertFormat = array(
      $this->feedName,
      $this->group,
      $this->entity,
      0, // holds id
      NULL, // holds hash
      0, // holds expire
    );
    $this->hashPart = $this->entity . '/' . $this->group . '/';

    // Create select object.
    $this->select = db_select('feed_import_hashes', 'f')
                      ->fields('f', array('hash', 'id', 'entity_id'))
                      ->condition('feed_group', $this->group)
                      ->condition('entity', $this->entity)
                      ->condition('hash', array(), 'IN');

    // Create update object.
    $this->update = db_update('feed_import_hashes')
                    ->condition('feed_group', $this->group)
                    ->condition('entity', $this->entity);

    // Create update format.
    $this->updateFormat = array('expire' => 0);

    // Create insert object.
    $this->insert = db_insert('feed_import_hashes')
                      ->fields(array('feed_machine_name', 'feed_group', 'entity', 'entity_id', 'hash', 'expire'));
  }

  /**
   * {@inheritdoc}
   */
  public static function validateOption($option_name, $value) {
    switch ($option_name) {
      case 'update_chunk': case 'insert_chunk':
        return $value > 0;
      case 'ttl':
        return $value >= 0;
    }
    return TRUE;
  }

  /**
   * {@inheritdoc}
   */
  public function hash(&$uniq) {
    $this->generatedHashes[] = $hash = md5($this->hashPart . $uniq);
    return $hash;
  }

  /**
   * {@inheritdoc}
   */
  public function get() {
    if (!$this->generatedHashes) {
      return array();
    }
    // Get select conditions (reuse same select object).
    $cond = &$this->select->conditions();
    // Remove last condition.
    array_pop($cond);
    // Return hashes.
    $hashes = $this->select
        ->condition('hash', $this->generatedHashes, 'IN')
        ->execute()
        ->fetchAllAssoc('hash');
    $this->generatedHashes = array();
    return $hashes;
  }

  // Items left to insert.
  protected $toInsert = 0;

  /**
   * {@inheritdoc}
   */
  public function insert($id, $hash) {
    $this->insertFormat[3] = $id;
    $this->insertFormat[4] = $hash;
    $this->insertFormat[5] = $this->ttl ? $this->ttl + time() : 0;

    $this->insert->values($this->insertFormat);

    // Commit insert.
    if (++$this->toInsert == $this->insertChunkSize) {
      $this->insertCommit();
    }
  }

  /**
   * {@inheritdoc}
   */
  public function insertCommit() {
    if ($this->toInsert) {
      $this->insert->execute();
      $this->toInsert = 0;
    }
  }

  // Ids that need to be updated.
  protected $updateIds = array();
  protected $toUpdate = 0;

  /**
   * {@inheritdoc}
   */
  public function update($id) {
    $this->updateIds[] = $id;
    if (++$this->toUpdate == $this->updateChunkSize) {
      $this->updateCommit();
    }
  }

  /**
   * {@inheritdoc}
   */
  public function updateCommit() {
    if (!$this->toUpdate) {
      return;
    }
    $this->updateFormat['expire'] = $this->ttl ? $this->ttl + time() : 0;
    $this->update->fields($this->updateFormat);
    // Get update conditions.
    $conditions = &$this->update->conditions();
    if (count($this->updateIds) <= $this->updateChunkSize) {
      // Update hashes.
      $this->update->condition('id', $this->updateIds, 'IN')->execute();
      // Remove last IN condition.
      array_pop($conditions);
    }
    else {
      // Split ids in chunks.
      $this->updateIds = array_chunk($this->updateIds, $this->updateChunkSize);
      for ($i = 0, $m = count($this->updateIds); $i < $m; $i++) {
        // Update hashes.
        $this->update->condition('id', $this->updateIds[$i], 'IN')->execute();
        unset($this->updateIds[$i]);
        // Remove last IN condition.
        array_pop($conditions);
      }
    }
    $this->toUpdate = 0;
  }

  /**
   * {@inheritdoc}
   */
  public static function delete(array $ids) {
    db_delete('feed_import_hashes')
      ->condition('id', $ids)
      ->execute();
  }

  /**
   * {@inheritdoc}
   */
  public static function deleteByFeed($name) {
    db_delete('feed_import_hashes')
      ->condition('feed_machine_name', $name)
      ->execute();
  }

  /**
   * {@inheritdoc}
   */
  public static function deleteByGroup($group) {
    db_delete('feed_import_hashes')
      ->condition('feed_group', $group)
      ->execute();
  }

  /**
   * {@inheritdoc}
   */
  public static function deleteEntities($ids, $entity_type) {
    db_delete('feed_import_hashes')
      ->condition('entity', $entity_type)
      ->condition('entity_id', $ids)
      ->execute();
  }

  /**
   * {@inheritdoc}
   */
  public static function getExpired($max = 0) {
    $q = db_select('feed_import_hashes', 'f')
          ->fields('f', array('entity', 'id', 'entity_id'))
          ->condition('expire', array(0, time()), 'BETWEEN');
    if ($max) {
      $q->range(0, $max);
    }
    $q = $q->execute();
    $ret = array();
    while ($r = $q->fetch(PDO::FETCH_ASSOC)) {
      $ret[$r['entity']][$r['id']] = $r['entity_id'];
    }
    return $ret;
  }


}
