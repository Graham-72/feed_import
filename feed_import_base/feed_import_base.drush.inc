<?php

/**
* Implements COMMANDFILE_drush_help()
*/
function feed_import_base_drush_help($section) {
  $sections = array(
    'drush:feed-import' => "Import feeds",
  );
  if (isset($sections[$section])) {
    return dt($sections[$section]);
  }
}

/**
* Implements COMMANDFILE_drush_command()
*/
function feed_import_base_drush_command() {
  $items['feed-import-base-cron'] = array(
    'description' => "Runs feed import cron",
    'aliases' => array('feedcron'),
    'bootstrap' => DRUSH_BOOTSTRAP_DRUPAL_LOGIN,
  );
  $items['feed-import-base-feed'] = array(
    'description' => "Imports specified feed",
    'arguments' => array(
      'name' => 'Machine name or id of feed to import',
    ),
    'examples' => array(
      'drush feedimport myfeed' => "Imports feed with machine name myfeed",
    ),
    'aliases' => array('feedimport'),
    'bootstrap' => DRUSH_BOOTSTRAP_DRUPAL_LOGIN,
  );
  $items['feed-import-base-list'] = array(
    'description' => "Show all available feeds",
    'aliases' => array('feedlist'),
    'bootstrap' => DRUSH_BOOTSTRAP_DRUPAL_LOGIN,
  );
  return $items;
}

/**
* Implements drush_COMMANDFILE_COMMANDNAME()
*/
function drush_feed_import_base_cron() {
  global $conf;
  $conf['feed_import_use_cron'] = TRUE;
  feed_import_base_cron();
  $conf['feed_import_use_cron'] = FALSE;
  drupal_set_message(t('Feed Import cron run'));
}

/**
* Implements drush_COMMANDFILE_COMMANDNAME()
*/
function drush_feed_import_base_feed($name) {
  if (!($feed = FeedImport::loadFeed($name))) {
    drush_set_error('Feed not found!');
    return;
  }
  _feed_import_base_process_feed($feed, TRUE);
  drupal_set_message(t('Feed @name imported', array('@name' => $feed->name)));
}

/**
* Implements drush_COMMANDFILE_COMMANDNAME()
*/
function drush_feed_import_base_list() {
  $msg = '%1$-25s | %2$-25s | %3$-3s ' . PHP_EOL;
  $h = sprintf($msg, t('Feed name'), t('Machine name'), t('Cron import'));
  echo $h, str_repeat('-', strlen($h) - strlen(PHP_EOL)), PHP_EOL;
  $feeds = FeedImport::loadAllFeeds();
  foreach ($feeds as $feed) {
    printf($msg, $feed->name, $feed->machine_name, $feed->cron_import ? t('yes') : t('no'));
  }
}
