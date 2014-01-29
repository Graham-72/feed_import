<?php

/**
 * @file
 * This file contains abstract classes and interfaces for feed import.
 */


/**
 * This class provides methods to set options.
 */
abstract class FeedImportConfigurable {

  // Class options
  protected $options = array();

  /**
   * Sets options for this instance
   *
   * @param array $options
   *    An assoc array containig options
   * @param bool $overwrite
   *    TRUE if the options should pe overwrited, FALSE to merge them
   */
  public function setOptions(array $options, $overwrite = FALSE) {
    if ($overwrite) {
      $this->options = $options;
    }
    else {
      $this->options = $options + $this->options;
    }
  }

  /**
   * Validates option value
   *
   * @param string $option_name
   *    Option to check
   * @param mixed $value
   *    Option value
   *
   * @return bool
   *    TRUE if option is valid
   */
  public static function validateOption($option_name, $value) {
    return TRUE;
  }

  /**
   * Helper function to get lines of a string
   *
   * @param string $str
   *    The string to get non empty lines from
   *
   * @return array
   *    An array of strings
   */
  public static function cleanLines($str) {
    return preg_split('/\r?\n/',$str);
  }
}


/**
 * Abstract implementation of reader.
 */
abstract class FeedImportReader extends FeedImportConfigurable {

  // Used to store items.
  protected $items = array();

  /**
   * Constructor of reader. Constructor is final but you'll have to
   * implement init() to init your reader.
   *
   * @param array $options An array of options to pass to reader
   */
  final public function __construct(array $options = array()) {
    $this->setOptions($options);
  }

  /**
   * Destructor.
   */
  public function __destruct() {
    unset($this->items, $this->options);
  }

  /**
   * Here you'll init your reader.
   */
  abstract protected function init();

  /**
   * This method returns the next available item or NULL if there are no items
   * left.
   *
   * @return mixed The read item
   */
  abstract public function get();

  /**
   * Returns a value mapped from obj by path.
   *
   * @param mixed $obj  Variable to search
   * @param mixed $path Path to value
   *
   * @return mixed Mapped value
   */
  abstract public function map(&$obj, &$path);

  /**
   * Override this to preprocess your paths before they are used in map().
   *
   * @param string $path Path to format
   *
   * @return mixed Formatted path
   */
  public function formatPath($path) {
    return $path;
  }

}


/**
 * This class is a helper for xpath readers using SimpleXMLElement.
 */
abstract class FeedImportSimpleXPathReader extends FeedImportReader {

  /**
   * {@inheritdoc}
   */
  public function map(&$item, &$xpath) {
    // Get values and handle xpath exceptions.
    try {
      $values = $item->xpath($xpath);
    }
    catch (Exception $e) {
      return NULL;
    }

    // Xpath gave no values return null.
    if (!$values) {
      return NULL;
    }

    // Get the number of values.
    $count = count($values);
    $i = -1;
    while (++$i < $count) {
      // Get each value.
      $values[$i] = (string) $values[$i][0];
    }

    // Return value or an array of values.
    return $count == 1 ? $values[0] : $values;
  }

}



/**
 * This class is a helper for vector (n dimensions) reader.
 * Path format is like a/b/c which results in [a, b, c] array.
 */
abstract class FeedImportVectorReader extends FeedImportReader {

  /**
   * {@inheritdoc}
   */
  public function map(&$vector, &$path) {
    foreach ($path as &$p) {
      if (is_array($vector)) {
        if (isset($vector[$p])) {
          $vector = &$vector[$p];
          continue;
        }
      }
      elseif (is_object($vector)) {
        if (isset($vector->$p)) {
          $vector = &$vector->$p;
          continue;
        }
      }
      return NULL;
    }
    return $vector;
  }

  /**
   * {@inheritdoc}
   */
  public function formatPath($path) {
    return explode('/', $path);
  }
}


/**
 * This class is a helper for unidimensional vector reader.
 * This can be used for CSV, SQL results, etc.
 */
abstract class FeedImportUniVectorReader extends FeedImportReader {

  /**
   * {@inheritdoc}
   */
  public function map(&$vector, &$path) {
    if (is_array($vector)) {
      if (isset($vector[$path])) {
        return $vector[$path];
      }
    }
    elseif (is_object($vector) && isset($vector->$path)) {
      return $vector->$path;
    }
    return NULL;
  }

}

/**
 * Static methods for hash manager
 */
interface FeedImportStaticHashManager {

  /**
   * Deletes hashes from database.
   *
   * @param array $ids
   *    Hash ids to delete
   */
  public static function delete(array $ids);

  /**
   * Deletes entities by type and id
   *
   * @param int|array $ids
   *    Entity ids
   * @param string $entity_type
   *    Entity type
   */
  public static function deleteEntities($ids, $entity_type);

  /**
   * Deletes all hashes of specified group
   *
   * @param string $group
   *    Group name
   */
  public static function deleteByGroup($group);

  /**
   * Deletes all hashes for specified feed name
   *
   * @param string $name
   *    Feed machine name
   */
  public static function deleteByFeed($name);

  /**
   * Returns an array of expired entity ids keyed by entity name
   *
   * @param int $max
   *    Max number of ids or 0 for all
   * @return array
   *    An array of expired items. Format:
   *    entity_name:
   *      hash_id: enity_id
   */
  public static function getExpired($max = 0);

}

/**
 * Class which provides methods to manage hashes.
 */
abstract class FeedImportHashManager extends FeedImportConfigurable {

  // Expire entities.
  protected $ttl = 0;

  // Generated hashes.
  protected $generatedHashes = array();

  /**
   * Constructor.
   */
  abstract public function __construct($entity_name, $group, $feed_machine_name);

  /**
   * Returns an array of entity keyed by hash,
   * using generated hashes.
   *
   * @return array
   *     An array containing entity ids keyed by hash.
   */
  abstract public function get();

  /**
   * Inserts a new hash.
   *
   * @param integer $id
   *     Entity id
   * @param mixed $hash
   *     Unique hash coresponding to entity id
   * @param int $expire
   *     Time to live
   */
  abstract public function insert($id, $hash);

  /**
   * Commits the insert to storage.
   */
  abstract public function insertCommit();

  /**
   * Updates hashes.
   *
   * @param integer $id
   *     Entity id
   * @param int $expire
   *     Time to live
   */
  abstract public function update($id);

  /**
   * Commits the update to storage.
   *
   * @param int $expire
   *     Time to live
   */
  abstract public function updateCommit();

  /**
   * Creates a hash, based on uniq
   * Also adds it on generated hashes
   *
   * @param mixed $uniq
   *     Unique identifier.
   *
   * @return string
   *     A hash for specified identifier.
   */
  abstract public function hash(&$uniq);

}
