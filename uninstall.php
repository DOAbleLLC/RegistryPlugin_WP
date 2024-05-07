<?php
// If uninstall is not called from WordPress, exit
if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit();
}

global $wpdb;

// Security check: Verify that the user has administrative privileges
if (!current_user_can('activate_plugins')) {
    return;
}

// Define the table names
$registry_details_table = $wpdb->prefix . 'baby_registry_details';
$registry_items_table = $wpdb->prefix . 'baby_registry_items';

// SQL to drop tables
$sql_registry_details = "DROP TABLE IF EXISTS $registry_details_table;";
$sql_registry_items = "DROP TABLE IF EXISTS $registry_items_table;";

// Execute SQL to drop the tables
$wpdb->query($sql_registry_details);
$wpdb->query($sql_registry_items);

// Optionally, remove options or other data stored in the options table
// delete_option('my_plugin_option_name');
// delete_site_option('my_plugin_option_name'); // Use this for multisite installations

// Any other cleanup code can go here, for example deleting user meta, post meta, or other entities created by your plugin.
