<?php

/**
* Implements COMMANDFILE_drush_help()
*/
function feed_import_drush_help($section) {
  $sections = array(
    'drush:feed-import' => "Imports feed.",
  );
  if (isset($sections[$section])) {
    return dt($sections[$section]);
  }
}

/**
* Implements COMMANDFILE_drush_command()
*/
function feed_import_drush_command() {
  $items['feed-import-cron'] = array(
    'description' => "Imports next feed enabled for cron import.",
    'aliases' => array('feedcron'),
    'bootstrap' => DRUSH_BOOTSTRAP_DRUPAL_LOGIN,
  );
  $items['feed-import-feed'] = array(
    'description' => "Imports specified feed.",
    'arguments' => array(
      'name' => 'Machine name or id of feed to import.',
    ),
    'examples' => array(
      'drush feedimport myfeed' => "Imports feed with machine name myfeed.",
    ),
    'aliases' => array('feedimport'),
    'bootstrap' => DRUSH_BOOTSTRAP_DRUPAL_LOGIN,
  );
  $items['feed-import-list'] = array(
    'description' => "Show all available feeds.",
    'aliases' => array('feedlist'),
    'bootstrap' => DRUSH_BOOTSTRAP_DRUPAL_LOGIN,
  );
  return $items;
}

/**
* Implements drush_COMMANDFILE_COMMANDNAME()
*/
function drush_feed_import_cron() {
  global $conf;
  $conf['feed_import_use_cron'] = TRUE;
  feed_import_cron();
  $conf['feed_import_use_cron'] = FALSE;
}

/**
* Implements drush_COMMANDFILE_COMMANDNAME()
*/
function drush_feed_import_feed($name = 0, $file = NULL) {
  $feed = FeedImport::loadFeeds(FALSE, $name);
  if (empty($feed)) {
    drush_set_error("That feed doesn't exists!");
    return;
  }
  feed_import_import_items($feed);
  drupal_set_message(t('Feed @name imported', array('@name' => $feed['name'])));
}

/**
* Implements drush_COMMANDFILE_COMMANDNAME()
*/
function drush_feed_import_list() {
  $msg = "%1$-25s | %2$-25s | %3$-25s | %4$-3s " . PHP_EOL;
  printf($msg, t('Feed name'), t('Machine name'), t('Source file'), t('Enabled'));
  $feeds = FeedImport::loadFeeds();
  foreach ($feeds as &$feed) {
    $feed['url'] = explode('/', $feed['url']);
    printf($msg, $feed['name'], $feed['machine_name'], end($feed['url']), $feed['enabled'] ? t('yes') : t('no'));
  }
}
