<?php
/**
 * @file
 * This class contains filter functions for feed import
 *
 * All functions must be static
 */

class FeedImportFilter {
  /**
   * Removes CDATA
   *
   * @param mixed $field
   *   A string or an array of strings
   *
   * @return mixed
   *   String/Array of strings with no CDATA
   */
  public static function removeCDATA($field) {
    if (is_array($field)) {
      foreach ($field as &$f) {
        $f = self::removeCDATA($f);
      }
      return $field;
    }
    preg_match('/<!\[CDATA\[(.*?)\]\]>/is', $field, $matches);
    return isset($matches[1]) ? $matches[1] : $field;
  }

  /**
   * Removes duplicate spaces
   *
   * @param mixed $field
   *   A string or an array of strings
   *
   * @return mixed
   *   Trimmed string/array of strings with no double whitespaces
   */
  public static function removeDoubleSpaces($field) {
    if (is_array($field)) {
      foreach ($field as &$f) {
        $f = self::removeDoubleSpaces($f);
      }
      return $field;
    }
    while (strpos($field, '  ') !== FALSE) {
      $field = str_replace('  ', ' ', $field);
    }
    return trim($field);
  }

  /**
   * Splits content by delimiter
   *
   * @param mixed $field
   *   A string or an array of strings
   * @param string $glue
   *   Delimiter
   *
   * @return array
   *   An array containing splitted string
   */
  public static function split($field, $glue = PHP_EOL) {
    if (is_array($field)) {
      foreach ($field as &$f) {
        $f = self::split($f, $glue);
      }
      return $field;
    }
    return explode($glue, $field);
  }

  /**
   * Glue all lines
   *
   * @param mixed $field
   *   An array of strings
   * @param string $glue
   *   Delimiter
   *
   * @return string
   *   Joined string
   */
  public static function join($field, $glue = PHP_EOL) {
    if (is_array($field)) {
      return implode($glue, $field);
    }
    else {
      return $field;
    }
  }

  /**
   * Merge all array levels
   *
   * @param array $field
   *   Array to merge
   *
   * @return array
   *   Merged array
   */
  public static function merge($field) {
    if (!is_array($field)) {
      return array($field);
    }
    $merged = array();
    foreach ($field as &$f) {
      if (is_array($f)) {
        $f = self::merge($f);
      }
      else {
        $f = array($f);
      }
      $merged = array_merge($merged, $f);
    }
    return $merged;
  }

  /**
   * Replace content
   *
   * @param mixed $field
   *   Content to replace
   * @paream string $what
   *   String to replace
   * @param string $with
   *   Replace string with
   * @param bool $insensitive
   *   Case insensitive replace
   *
   * @return mixed
   *   Replaced content
   */
  public static function replace($field, $what='', $with = '', $insensitive = FALSE) {
    if ($what == $with) {
      return $field;
    }
    elseif (is_array($field)) {
      foreach ($field as &$f) {
        $f = self::replace($f, $what, $with);
      }
      return $field;
    }
    if ($insensitive) {
      return str_ireplace($what, $with, $field);
    }
    else {
      return str_replace($what, $with, $field);
    }
  }

  /**
   * Append text
   *
   * @param mixed $field
   *   A string or an array of strings
   * @param string $text
   *   Text to append
   *
   * @return mixed
   *   A string or an array of strings with text appended
   */
  public static function append($field, $text) {
    if (is_array($field)) {
      foreach ($field as &$f) {
        $f = self::append($f, $text);
      }
      return $field;
    }
    return $field . $text;
  }

  /**
   * Prepend text
   *
   * @param mixed $field
   *   A string or an array of strings
   * @param string $text
   *   Text to prepend
   *
   * @return mixed
   *   A string or an array of strings with text prepended
   */
  public static function prepend($field, $text) {
    if (is_array($field)) {
      foreach ($field as &$f) {
        $f = self::prepend($f, $text);
      }
      return $field;
    }
    return $text . $field;
  }

  /**
   * Trims a string or an array of strings
   *
   * @param mixed $field
   *   A string or an array of strings
   * @param string $chars
   *   Chars to trim
   *
   * @return mixed
   *   Trimmed string or array of strings
   */
  public static function trim($field, $chars = NULL) {
    if (is_array($field)) {
      foreach ($field as &$f) {
        $f = trim($f, $chars);
      }
      return $field;
    }
    return $chars ? trim($field, $chars) : trim($field);
  }

  /**
   * Convert encodings
   *
   * @param mixed $field
   *   A string or an array of strings
   * @param string $to
   *   Convert to encoding
   * @param string $from
   *   Convert from encoding
   *
   * @return mixed
   *   Encoded string or array of strings
   */
  public static function convertEncoding($field, $to = 'UTF-8', $from = 'ISO-8859-1// TRANSLIT') {
    if (is_array($field)) {
      foreach ($field as &$f) {
        $f = self::convertEncoding($f, $to, $from);
      }
      return $field;
    }
    return iconv($from, $to, $field);
  }

  /**
   * Gets vocabulary vid from name
   *
   * @param string $name
   *   Vocabulary name
   *
   * @return int
   *   Vocabulary vid
   */
  public static function getVidFromName($name) {
    static $vids = array();
    $name = drupal_strtolower($name);
    if (isset($vids[$name])) {
      return $vids[$name];
    }
    $query = new EntityFieldQuery();
    $query = $query->entityCondition('entity_type', 'taxonomy_vocabulary')
                    ->propertyCondition('name', $name)
                    ->execute();
    if (empty($query)) {
      $vids[$name] = 0;
    }
    else {
      $query = reset($query['taxonomy_vocabulary']);
      $vids[$name] = $query->vid;
      unset($query);
    }
    return $vids[$name];
  }

  /**
   * Extract tids by term name and vocabulari id
   *
   * @param mixed $name
   *   A string or an array of strings
   * @param int|string $voc
   *   (optionally) Vocabulary id/name
   *
   * @return mixed
   *   Fetched tids
   */
  public static function getTaxonomyIdByName($name, $voc = 0) {
    if (!is_numeric($voc)) {
      // Get vid from name.
      $voc = self::getVidFromName($voc);
    }

    // Get tids.
    $query = new EntityFieldQuery();
    $query->entityCondition('entity_type', 'taxonomy_term');
    $query->propertyCondition('name', $name);
    if ($voc) {
      $query->propertyCondition('vid', $voc);
    }
    $query = $query->execute();
    if (empty($query)) {
      return NULL;
    }
    else {
      return array_keys($query['taxonomy_term']);
    }
  }

  /**
   * Save specified taxonomy terms to vocabulary
   *
   * @param mixed $name
   *   A string or an array of strings
   * @param int|string $voc
   *   (optionally) Vocabulary id/name
   *
   * @return mixed
   *   Fetched and inserted tids
   */
  public static function setTaxonomyTerms($name, $voc = 0) {
    if (!is_numeric($voc)) {
      $voc = self::getVidFromName($voc);
    }
    if (!is_array($name)) {
      $name = array($name);
    }
    $tids = array();
    $existing = self::getTaxonomyIdByName($name, $voc);
    if (!empty($existing)) {
      $existing = taxonomy_term_load_multiple($existing, array('vid' => $voc));
      foreach ($existing as &$term) {
        $tids[drupal_strtolower($term->name)] = $term->tid;
        $term = NULL;
      }
    }
    unset($existing);

    foreach ($name as &$term) {
      if (!isset($tids[drupal_strtolower($term)])) {
        $t = new stdClass();
        $t->vid = $voc;
        $t->name = $term;
        taxonomy_term_save($t);
        $tids[$t->name] = $t->tid;
        $t = NULL;
        $term = NULL;
      }
    }
    return $tids;
  }

  /**
   * Strips tags
   *
   * @param mixed $field
   *   A string or an array of strings
   * @param string $tags
   *   Allowed tags
   *
   * @return mixed
   *   Result without tags
   */
  public static function stripTags($field, $tags = '') {
    if (is_array($field)) {
      foreach ($field as &$f) {
        $f = self::stripTags($field, $tags);
      }
      return $field;
    }
    return strip_tags($field, $tags);
  }

  /**
   * Remove tags
   *
   * @param mixed $field
   *   A string or an array of strings
   * @param string $tags
   *   A string containing tags to remove separated by space or array of tags
   *
   * @return mixed
   *   Result without tags
   */
  public static function removeTags($field, $tags) {
    if (!is_array($tags)) {
      $tags = explode(' ', trim($tags));
    }
    if (is_array($field)) {
      foreach ($field as &$f) {
        $f = self::removeTags($field, $tags);
      }
      return $field;
    }
    foreach ($tags as &$tag) {
      $field = preg_replace('@<' . $tag . '( |>).*?</' . $tag . '>@si', '', $field);
    }
    return $field;
  }

  /**
   * Downloads and saves an image in a field
   *
   * @param mixed $field
   *   A string or an array of strings
   * @param string $path
   *   Where to save image. Default is public://
   *
   * @return mixed
   *   An object or an array of objects containing image info
   */
  public static function saveImage($field, $path = 'public://') {
    if (is_array($field)) {
      foreach ($field as &$f) {
        $f = self::saveImage($field, $path);
      }
      return $field;
    }
    // Get image data.
    $image = file_get_contents($field);
    $field = trim($field, '/');
    $field = drupal_substr($field, strrpos($field, '/') + 1);
    return file_save_data($image, $path . $field, FILE_EXISTS_RENAME);
  }

  /**
   * Get property
   *
   * @param mixed $field
   *   Array or object to get property
   *
   * @return mixed
   *   Fetched property
   */
  public static function getProperty($field, $property = NULL) {
    if (is_array($field)) {
      return array_key_exists($property, $field) ? $field[$property] : NULL;
    }
    elseif (is_object($field)) {
      return isset($field->{$property}) ? $field->{$property} : NULL;
    }
    else {
      return $field;
    }
  }
  /**
   * This function hashes an user password
   *
   * @param mixed $field
   *   A string or an array of strings
   *
   * @return mixed
   *   Resulted hashes
   */
  public static function userHashPassword($field) {
    require_once DRUPAL_ROOT . '/' . variable_get('password_inc', 'includes/password.inc');
    if (is_array($field)) {
      foreach ($field as &$f) {
        $f = self::userHashPassword($field);
      }
      return $field;
    }
    return user_hash_password($field);
  }
  // Other filters ...
}
