<?php

/**
 * Plugin Name: Importer Plugin
 * Plugin URI: https://valso.nl
 * Description: Feed Importer
 * Version: 1.0
 * Author:  Valso
 * Author URI: https://valso.nl
 */

register_activation_hook( __FILE__, "activate_importer_plugin" );

function activate_importer_plugin()
{
    global $table_prefix, $wpdb;
    // Include Upgrade Script
    require_once( ABSPATH . '/wp-admin/includes/upgrade.php' );

    // Imports table
	$table = $table_prefix . 'importer_imports';
	if( $wpdb->get_var( "show tables like '$table'" ) != $table ) {
        // Query - Create Table
        $sql = "CREATE TABLE `wp_importer_imports` (
          `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
          `configuration` text,
          `name` varchar(255) DEFAULT NULL,
          `cron_every` varchar(255) DEFAULT NULL,
          `updated_at` timestamp NULL DEFAULT NULL,
          `cron_at` varchar(255) DEFAULT NULL,
          `products_created` int(11) DEFAULT NULL,
          `is_running` tinyint(4) DEFAULT '0',
          PRIMARY KEY (`id`),
          UNIQUE KEY `id` (`id`)
        ) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=latin1;";
        dbDelta( $sql );
    }
    // Log table
    $table = $table_prefix . 'importer_log';
    if( $wpdb->get_var( "show tables like '$table'" ) != $table ) {
        // Query - Create Table
        $sql = "CREATE TABLE `wp_importer_log` (
              `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
              `import_id` varchar(255) DEFAULT NULL,
              `name` varchar(255) DEFAULT NULL,
              `created` int(11) DEFAULT NULL,
              `skipped` int(11) DEFAULT NULL,
              `updated` int(11) DEFAULT NULL,
              `import_start` datetime DEFAULT NULL,
              `duration` int(11) DEFAULT NULL,
              `import_end` datetime DEFAULT NULL,
              `rows` int(11) DEFAULT NULL,
              `results` longtext,
              PRIMARY KEY (`id`),
              UNIQUE KEY `id` (`id`)
            ) ENGINE=InnoDB AUTO_INCREMENT=93 DEFAULT CHARSET=latin1;";
        dbDelta( $sql );
    }
}


function main()
{
    if ( ! is_front_page() ) {
        return false;
    }

    if ( !defined('ABSPATH') )
        define('ABSPATH', dirname(__FILE__));

    require_once('classes/import.php');
    require_once( ABSPATH . 'wp-load.php');
    include_once( ABSPATH . 'wp-admin/includes/image.php' );

    //add this->check hook to cron job that checks every minute if there is a import to run
    check();
}

function check()
{
    global $wpdb;

    if ( !defined('ABSPATH') )
        define('ABSPATH', dirname(__FILE__));

    require_once('classes/import.php');
    require_once( ABSPATH . 'wp-load.php');
    include_once( ABSPATH . 'wp-admin/includes/image.php' );

    $sql = "SELECT * FROM " . $wpdb->prefix . "importer_imports";
    $imports = $wpdb->get_results($sql);

    foreach ($imports as $import) {

        if ($import->cron_every == 'daily') {
            if ($import->cron_at == date('H:i', time())) {
                $import = new Import($import);
                $import->run();
                return true;
            }
        }
        if ($import->cron_every == 'every_15_minutes') {
            $t = date('i', time());
            if ($t % 15 == 0) {
                $import = new Import($import);
                $import->run();
                return true;
            }
        }
        if ($import->cron_every == 'every_5_minutes') {
            $t = date('i', time());
            if ($t % 5 == 0) {
                $import = new Import($import);
                $import->run();
                return true;
            }
        }
        if ($import->cron_every == 'every_1_minute') {
            $import = new Import($import);
            $import->run();
            return true;
        }
    }

//    $import = new Import($imports[1]);
//    $import->id = $imports[1]->id;
//    $import->run();
}

//Run on plugin activation
if ( ! wp_next_scheduled( 'importer_import')) {
    wp_schedule_event( time(), 'every_minute', 'importer_import');
}
add_action( 'importer_import', 'check' );
//
//add_action('template_redirect', 'main');