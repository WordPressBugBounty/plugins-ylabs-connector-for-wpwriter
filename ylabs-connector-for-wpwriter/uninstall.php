<?php
/**
 * YLabs Connector for WPWriter - Uninstall
 *
 * Removes all plugin data when the plugin is deleted.
 * This file is called automatically by WordPress when the plugin is deleted
 * via the admin interface.
 *
 * @package YLabs_Connector_For_WPWriter
 */

// Exit if not called by WordPress
if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

// Delete plugin options
delete_option('wpm_connector_connections');
delete_option('wpm_connector_hub_url');

// Delete legacy options (from versions prior to 1.7.0)
delete_option('wpm_connector_token_hash');
delete_option('wpm_connector_user_id');

// Delete transients
delete_transient('wpm_connector_pairing');

// Clean up any site meta in multisite
if (is_multisite()) {
    // Get all sites
    $sites = get_sites(array('fields' => 'ids'));

    foreach ($sites as $site_id) {
        switch_to_blog($site_id);

        delete_option('wpm_connector_connections');
        delete_option('wpm_connector_hub_url');
        delete_option('wpm_connector_token_hash');
        delete_option('wpm_connector_user_id');
        delete_transient('wpm_connector_pairing');

        restore_current_blog();
    }
}
