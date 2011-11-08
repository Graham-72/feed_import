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
   * Get all lines from text
   *
   * @param mixed $field
   *   A string or an array of strings
   * @param string $glue
   *   Delimiter
   *
   * @return array
   *   An array containing splitted string
   */
  public static function getLines($field, $glue = PHP_EOL) {
    if (is_array($field)) {
      foreach ($field as &$f) {
        self::getLines($field, $glue);
      }
      return $field;
    }
    return explode($glue, $field);
  }
  /**
   * Glue all lines
   *
   * @param mixed $field
   *   A string or an array of strings
   * @param string $glue
   *   Delimiter
   *
   * @return string
   *   Joined string
   */
  public static function glueLines($field, $glue = PHP_EOL) {
    if (is_array($field)) {
      foreach ($field as &$f) {
        self::glueLines($field, $glue);
      }
      return $field;
    }
    return implode($glue, $field);
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
   * Extract tids by term name and vocabulari id
   *
   * @param mixed $name
   *   A string or an array of strings
   * @param int|string $voc
   *   (optinally) Vocabulary id/name
   *
   * @return mixed
   *   Fetched tids
   */
  public static function getTaxonomyIdByName($name, $voc = 0) {
    if (!is_numeric($voc)) {
      // Get vocabulary vid by name.
      $query = new EntityFieldQuery();
      $query = $query->entityCondition('entity_type', 'taxonomy_vocabulary')
                      ->propertyCondition('name', $voc)
                      ->execute();
      if (empty($query)) {
        $voc = 0;
      }
      else {
        $query = reset($query['taxonomy_vocabulary']);
        $voc = $query->vid;
        unset($query);
      }
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
  // other filters ...
}