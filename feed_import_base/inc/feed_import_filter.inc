<?php

/**
 * @file
 * This file contains filter related classes.
 */

/**
 * This class is used to apply several filters to a value
 * in a quick way.
 */
class FeedImportMultiFilter extends FeedImportConfigurable {

  // Dynamic param placeholder.
  protected $dynParam = '[field]';

  // Array of filters.
  protected $filters = array();

  // Default class to use for filter methods.
  public static $defaultFilterClass = 'FeedImportFilter';

  // Path to filters dir.
  protected $filtersDir;

  /**
   * Creates a new instance of filters
   */
  public function __construct($filters_dir, array $options = array()) {
    $this->filtersDir = $filters_dir;
    if (!class_exists(static::$defaultFilterClass)) {
      include_once $filters_dir . '/feed_import_default_filters.php';
    }
    $this->setOptions($options);
  }

  /**
   * {@inheritdoc}
   */
  public function setOptions(array $options, $overwrite = FALSE) {
    if (isset($options['param'])) {
      $this->dynParam = $options['param'];
    }
    if (isset($options['include'])) {
      $options = $options['include'];
      if (!is_array($options)) {
        $options = array_filter(static::cleanLines($options));
      }
      foreach ($options as $file) {
        if ($file[0] == '/') {
          include_once $file;
        }
        else {
          include_once $this->filtersDir . '/' . $file;
        }
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  public static function validateOption($option_name, $value) {
    if ($option_name == 'param') {
      return !is_numeric($value);
    }
    return TRUE;
  }

  /**
   * Parse function name and returns a callable.
   *
   * @param string $function
   *    The function to parse
   *
   * @return mixed
   *    A callable string|array or FALSE
   */
  protected function getCallable($function) {
    if (is_scalar($function)) {
      if (strpos($function, '::') !== FALSE) {
        $function = explode('::', $function, 2);
        if (!$function[0]) {
          $function[0] = static::$defaultFilterClass;
        }
        if (!class_exists($function[0]) || !method_exists($function[0], $function[1])) {
          // Class or method not found.
          return FALSE;
        }
      }
      elseif (!function_exists($function)) {
        // Function not found.
        return FALSE;
      }
    }
    elseif (!class_exists($function[0]) || !method_exists($function[0], $function[1])) {
      // Class or method not found.
      return FALSE;
    }
    return $function;
  }

  /**
   * Adds a new filter.
   *
   * @param string $group The name of filter group
   * @param string $function The function name. Use Class::method for static methods.
   * @param array $params The params for this function.
   *
   * @return boolean TRUE if filter was added.
   */
  public function add($group, $function, $params = array()) {
    // Get function name.
    if (!($function = $this->getCallable($function))) {
      return FALSE;
    }

    // Create function info.
    $f = array(
      // Function name
      'f' => $function,
      // Params.
      'p' => array_values($params),
      // A reference to dynamic param.
      'd' => NULL,
    );

    // Check params for dynamic fields.
    for ($i = 0, $m = count($f['p']); $i < $m; $i++) {
      if ($f['p'][$i] === $this->dynParam) {
        // Set reference to dynamic param.
        $f['p'][$i] = &$f['d'];
      }
    }

    // Add to filters array.
    $this->filters[$group][] = &$f;

    // All good, filter was added.
    return TRUE;
  }

  /**
   * Applies all filters to a value.
   *
   * @param string $group The name of filter group
   * @param mixed $value  The value to be filtered
   *
   * @return mixed Filtered value
   */
  public function apply($group, $value) {
    // Check if filter group exists.
    if (isset($this->filters[$group])) {
      $fl = &$this->filters[$group];
      // Apply all filters.
      for ($i = 0, $m = count($fl); $i < $m; $i++) {
        // Set a reference to value in params array.
        $fl[$i]['d'] = $value;
        // Call function.
        $value = call_user_func_array($fl[$i]['f'], $fl[$i]['p']);
        // Break reference to value.
        $fl[$i]['d'] = NULL;
      }
    }
    // Return filtered value.
    return $value;
  }

}
