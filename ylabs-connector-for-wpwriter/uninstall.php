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

// Exit if not called by WordPress.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

// Delete plugin options.
delete_option( 'wpm_connector_connections' );
delete_option( 'wpm_connector_hub_url' );
delete_option( 'wpm_connector_pairing_state' );

// Pull transport (1.13.0+).
delete_option( 'wpm_connector_pull_armed_until' );
delete_option( 'wpm_connector_pull_last_poll' );
delete_option( 'wpm_connector_pull_last_status' );
delete_option( 'wpm_connector_pull_next_idle' );
delete_option( 'wpm_connector_pull_processed' );
delete_option( 'wpm_connector_pull_unsent' );
delete_transient( 'wpm_connector_wake_lock' );
wp_clear_scheduled_hook( 'wpm_connector_pull_tick' );
wp_clear_scheduled_hook( 'wpm_connector_pull_tick', array( 'wake' ) );

// Delete legacy options (from versions prior to 1.7.0).
delete_option( 'wpm_connector_token_hash' );
delete_option( 'wpm_connector_user_id' );

// Delete transients.
delete_transient( 'wpm_connector_pairing' );

// Clean up any site meta in multisite.
if ( is_multisite() ) {
	// Get all sites.
	$wpm_connector_sites = get_sites( array( 'fields' => 'ids' ) );

	foreach ( $wpm_connector_sites as $wpm_connector_site_id ) {
		switch_to_blog( $wpm_connector_site_id );

		delete_option( 'wpm_connector_connections' );
		delete_option( 'wpm_connector_hub_url' );
		delete_option( 'wpm_connector_pairing_state' );
		delete_option( 'wpm_connector_token_hash' );
		delete_option( 'wpm_connector_user_id' );
		delete_transient( 'wpm_connector_pairing' );
		delete_option( 'wpm_connector_pull_armed_until' );
		delete_option( 'wpm_connector_pull_last_poll' );
		delete_option( 'wpm_connector_pull_last_status' );
		delete_option( 'wpm_connector_pull_next_idle' );
		delete_option( 'wpm_connector_pull_processed' );
		delete_option( 'wpm_connector_pull_unsent' );
		delete_transient( 'wpm_connector_wake_lock' );
		wp_clear_scheduled_hook( 'wpm_connector_pull_tick' );
		wp_clear_scheduled_hook( 'wpm_connector_pull_tick', array( 'wake' ) );

		restore_current_blog();
	}
}
