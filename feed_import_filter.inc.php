<?php
/**
 * This class contains filter functions for feed import
 * All functions must be static
 */
class FeedImportFilter {
    /**
     * removes CDATA
     */
    public static function removeCDATA($field) {
        if(is_array($field)) {
            foreach($field as &$f) {
                $f = self::removeCDATA($f);
            }
            return $field;
        }
        preg_match('/<!\[CDATA\[(.*?)\]\]>/is', $field, $matches);
        return isset($matches[1]) ? $matches[1] : $field;
    }
    
    /**
     * removes duplicate spaces
     */
    public static function removeDoubleSpaces($field) {
        if(is_array($field)) {
            foreach($field as &$f) {
                $f = self::removeDoubleSpaces($f);
            }
            return $field;
        }
        while(strpos($field, '  ') !== FALSE) {
            $field = str_replace('  ', ' ', $field);
        }
        return trim($field);
    }
    
    /**
     * get all lines from text
     */
    public static function getLines($field, $glue = PHP_EOL) {
        if(is_array($field)) {
            foreach($field as &$f) {
                self::getLines($field, $glue);
            }
            return $field;
        }
        return explode($glue, $field);
    }
    /**
     * Glue all lines
     */
    public static function glueLines($field, $glue = PHP_EOL) {
        if(is_array($field)) {
            foreach($field as &$f) {
                self::glueLines($field, $glue);
            }
            return $field;
        }
        return implode($glue, $field);
    }
    
    /**
     * Append text
     */
    public static function append($field, $text) {
        if(is_array($field)) {
            foreach($field as &$f) {
                $f = self::append($f, $text);
            }
            return $field;
        }
        return $field . $text;
    }
    
    /**
     * Prepend text
     */
    public static function prepend($field, $text) {
        if(is_array($field)) {
            foreach($field as &$f) {
                $f = self::prepend($f, $text);
            }
            return $field;
        }
        return $text . $field;
    }
    
    /**
     * Trims a variable or array
     */
    public static function trim($field, $chars = NULL) {
        if(is_array($field)) {
            foreach($field as &$f) {
                $f = trim($f, $chars);
            }
            return $field;
        }
        return $chars ? trim($field, $chars) : trim($field);
    }
    
    /**
     * Convert from encodings
     */
    public static function convertEncoding($field, $to = 'UTF-8', $from = 'ISO-8859-1//TRANSLIT') {
        if(is_array($field)) {
            foreach($field as &$f) {
                $f = self::convertEncoding($f, $to, $from);
            }
            return $field;
        }
        return iconv($from, $to, $field);
    }
    //save picture from url ...
    //other filters ...
}