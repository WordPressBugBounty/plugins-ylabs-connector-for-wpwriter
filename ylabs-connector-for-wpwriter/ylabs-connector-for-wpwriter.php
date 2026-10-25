<?php
/**
 * Plugin Name: YLabs AI Site Manager with ChatGPT and Claude
 * Description: Connect WordPress to WPWriter so you can manage posts, SEO and media from ChatGPT or Claude.
 * Version: 1.15.0
 * Author: YLabs
 * Author URI: https://www.wpwriter.com
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: ylabs-connector-for-wpwriter
 * Requires at least: 5.6
 * Requires PHP: 7.4
 * @package YLabs_AI_Site_Manager
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'WPM_CONNECTOR_VERSION', '1.15.0' );

// Storage keys.
const WPM_CONNECTOR_OPTION_CONNECTIONS = 'wpm_connector_connections';
const WPM_CONNECTOR_TRANSIENT_PAIRING  = 'wpm_connector_pairing';
const WPM_CONNECTOR_OPTION_PAIRING     = 'wpm_connector_pairing_state';
const WPM_CONNECTOR_OPTION_HUB_URL     = 'wpm_connector_hub_url';

// Legacy keys (for migration from v1.4.0 and earlier).
const WPM_CONNECTOR_LEGACY_TOKEN_HASH = 'wpm_connector_token_hash';
const WPM_CONNECTOR_LEGACY_USER_ID    = 'wpm_connector_user_id';

/**
 * Get hub url.
 */
function wpm_connector_get_hub_url() {
	return get_option( WPM_CONNECTOR_OPTION_HUB_URL, 'https://www.wpwriter.com' );
}

/**
 * Set hub url.
 *
 * @param mixed $url Url.
 */
function wpm_connector_set_hub_url( $url ) {
	$url = esc_url_raw( trim( $url ) );
	if ( '' === $url ) {
		$url = 'https://www.wpwriter.com';
	}
	update_option( WPM_CONNECTOR_OPTION_HUB_URL, $url );
}

/**
 * Base64url.
 *
 * @param mixed $bytes Bytes.
 */
function wpm_connector_base64url( $bytes ) {
	// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- encodes random token bytes as base64url, not obfuscation.
	return rtrim( strtr( base64_encode( $bytes ), '+/', '-_' ), '=' );
}

/**
 * Generate id.
 */
function wpm_connector_generate_id() {
	return substr( bin2hex( random_bytes( 8 ) ), 0, 12 );
}

/**
 * Get all connections, migrating from legacy single-token format if needed.
 */
function wpm_connector_get_connections() {
	$connections = get_option( WPM_CONNECTOR_OPTION_CONNECTIONS, null );

	// If we have connections array, return it.
	if ( is_array( $connections ) ) {
		return $connections;
	}

	// Check for legacy single-token format and migrate.
	$legacy_hash    = get_option( WPM_CONNECTOR_LEGACY_TOKEN_HASH, '' );
	$legacy_user_id = (int) get_option( WPM_CONNECTOR_LEGACY_USER_ID, 0 );

	if ( ! empty( $legacy_hash ) && $legacy_user_id > 0 ) {
		// Migrate to new format.
		$connections = array(
			array(
				'id'         => wpm_connector_generate_id(),
				'token_hash' => $legacy_hash,
				'wp_user_id' => $legacy_user_id,
				'label'      => 'Migrated Connection',
				'created_at' => time(),
			),
		);

		// Save in new format.
		update_option( WPM_CONNECTOR_OPTION_CONNECTIONS, $connections );

		// Remove legacy options.
		delete_option( WPM_CONNECTOR_LEGACY_TOKEN_HASH );
		delete_option( WPM_CONNECTOR_LEGACY_USER_ID );

		return $connections;
	}

	// No connections.
	return array();
}

/**
 * Save connections array.
 *
 * @param mixed $connections Connections.
 */
function wpm_connector_save_connections( $connections ) {
	update_option( WPM_CONNECTOR_OPTION_CONNECTIONS, $connections );
}

/**
 * Issue a new token and add to connections.
 *
 * @param mixed $wp_user_id Wp user id.
 * @param mixed $label Label.
 */
function wpm_connector_issue_token( $wp_user_id, $label ) {
	$token = wpm_connector_base64url( random_bytes( 32 ) );
	$hash  = hash( 'sha256', $token );

	$connections   = wpm_connector_get_connections();
	$connections[] = array(
		'id'         => wpm_connector_generate_id(),
		'token_hash' => $hash,
		'wp_user_id' => (int) $wp_user_id,
		'label'      => sanitize_text_field( $label ),
		'created_at' => time(),
	);

	wpm_connector_save_connections( $connections );

	return $token;
}

/**
 * Store the pairing state.
 *
 * Ponytail: an option, not a transient. With an external object cache installed,
 * set_transient() writes to the cache only. If that cache is per-request or broken,
 * the wp-admin request that creates the code and the REST request that verifies it
 * see different caches, the state is gone, and EVERY code fails as "expired or not
 * found" — no matter how fast the user pastes it. Options have a real DB row, and
 * wpm_connector_read_pairing_state() drops the cached copy before reading.
 * The transient is still written so a code minted by <= 1.12.2 keeps working
 * across the upgrade.
 *
 * @param mixed $state State.
 * @param mixed $expires_in Expires in.
 */
function wpm_connector_write_pairing_state( $state, $expires_in ) {
	update_option( WPM_CONNECTOR_OPTION_PAIRING, $state, false );
	set_transient( WPM_CONNECTOR_TRANSIENT_PAIRING, $state, $expires_in );
}

/**
 * Read the pairing state, bypassing any object cache.
 */
function wpm_connector_read_pairing_state() {
	wp_cache_delete( WPM_CONNECTOR_OPTION_PAIRING, 'options' );
	$state = get_option( WPM_CONNECTOR_OPTION_PAIRING );
	if ( ! is_array( $state ) || ! isset( $state['code_hash'] ) ) {
		$state = get_transient( WPM_CONNECTOR_TRANSIENT_PAIRING ); // code minted by <= 1.12.2.
	}
	return ( is_array( $state ) && isset( $state['code_hash'] ) ) ? $state : false;
}

/**
 * Has this pairing state run out? Fails closed: a missing or unreadable
 * expires_at counts as expired, because an option has no TTL of its own.
 *
 * @param mixed $state State.
 */
function wpm_connector_pairing_state_expired( $state ) {
	if ( ! isset( $state['expires_at'] ) || ! is_numeric( $state['expires_at'] ) ) {
		return true;
	}
	return time() >= (int) $state['expires_at'];
}

/**
 * Forget the pairing state.
 */
function wpm_connector_clear_pairing_state() {
	delete_option( WPM_CONNECTOR_OPTION_PAIRING );
	delete_transient( WPM_CONNECTOR_TRANSIENT_PAIRING );
}

/**
 * Generate a pairing code for the current user.
 *
 * @param mixed $user_id User id.
 * @param mixed $label Label.
 */
function wpm_connector_generate_pairing_code( $user_id, $label = '' ) {
	$code       = strtoupper( bin2hex( random_bytes( 16 ) ) ); // 32 chars
	$expires_in = 600; // 10 minutes

	$state = array(
		'code'       => $code,
		'code_hash'  => hash( 'sha256', $code ),
		'user_id'    => (int) $user_id,
		'label'      => sanitize_text_field( $label ),
		'expires_at' => time() + $expires_in,
	);

	wpm_connector_write_pairing_state( $state, $expires_in );

	return $state;
}

/**
 * Verify a pairing code.
 *
 * @param mixed $code Code.
 */
function wpm_connector_verify_pairing_code( $code ) {
	if ( ! is_string( $code ) || trim( $code ) === '' ) {
		return new WP_Error( 'wpm_invalid_code', 'Invalid pairing code.', array( 'status' => 400 ) );
	}

	$state = wpm_connector_read_pairing_state();
	if ( ! $state ) {
		return new WP_Error( 'wpm_code_expired', 'Pairing code expired or not found. Generate a new one.', array( 'status' => 400 ) );
	}

	$candidate = hash( 'sha256', strtoupper( trim( $code ) ) );
	if ( ! hash_equals( $state['code_hash'], $candidate ) ) {
		return new WP_Error( 'wpm_code_mismatch', 'Pairing code does not match. Check for typos or generate a new one.', array( 'status' => 400 ) );
	}

	if ( wpm_connector_pairing_state_expired( $state ) ) {
		wpm_connector_clear_pairing_state();
		return new WP_Error( 'wpm_code_expired', 'Pairing code has expired. Generate a new one.', array( 'status' => 400 ) );
	}

	return $state;
}

/**
 * Get the current pairing state (if any).
 */
function wpm_connector_get_pairing_state() {
	$state = wpm_connector_read_pairing_state();
	if ( ! $state ) {
		return null;
	}
	if ( wpm_connector_pairing_state_expired( $state ) ) {
		wpm_connector_clear_pairing_state();
		return null;
	}
	return $state;
}

/**
 * Get connection count.
 */
function wpm_connector_get_connection_count() {
	return count( wpm_connector_get_connections() );
}

/**
 * Disconnect a specific connection by ID.
 *
 * @param mixed $connection_id Connection id.
 */
function wpm_connector_disconnect_by_id( $connection_id ) {
	$connections = wpm_connector_get_connections();
	$found       = false;

	$connections = array_filter(
		$connections,
		function ( $conn ) use ( $connection_id, &$found ) {
			if ( isset( $conn['id'] ) && $conn['id'] === $connection_id ) {
				$found = true;
				return false;
			}
			return true;
		}
	);

	if ( $found ) {
		wpm_connector_save_connections( array_values( $connections ) );
	}

	return $found;
}

/**
 * Disconnect all connections.
 */
function wpm_connector_disconnect_all() {
	wpm_connector_save_connections( array() );
}

/**
 * Authenticate a request by checking the token against all connections.
 */
/**
 * 1.13.1: background polling (added in 1.13.0) is removed. A site upgrading from 1.13.0 would otherwise keep a
 * once-a-minute WP-Cron event with no handler and the polling options, so clear them once. Cheap on every other
 * request: both checks read cached values.
 */
function wpm_connector_remove_pull_leftovers() {
	if ( ! wp_next_scheduled( 'wpm_connector_pull_tick' ) && ! wp_next_scheduled( 'wpm_connector_pull_tick', array( 'wake' ) )
		&& get_option( 'wpm_connector_pull_last_poll', null ) === null ) {
		return;
	}
	wp_clear_scheduled_hook( 'wpm_connector_pull_tick' );
	wp_clear_scheduled_hook( 'wpm_connector_pull_tick', array( 'wake' ) );
	foreach ( array(
		'wpm_connector_pull_armed_until',
		'wpm_connector_pull_last_poll',
		'wpm_connector_pull_last_status',
		'wpm_connector_pull_next_idle',
		'wpm_connector_pull_processed',
		'wpm_connector_pull_unsent',
	) as $option ) {
		delete_option( $option );
	}
	delete_transient( 'wpm_connector_wake_lock' );
}
add_action( 'init', 'wpm_connector_remove_pull_leftovers' );

/**
 * One permission check for every connector REST route except /pair (it needs a valid pairing code) and /ping:
 * WordPress refuses the request before the handler runs unless it carries a valid connector token AND the paired
 * WordPress user has the capability the route needs. The handlers keep their own checks as a second layer.
 *
 * @param string $capability The capability the paired user must have ('read' = any paired user).
 * @return callable
 */
function wpm_connector_permission( $capability ) {
	return function ( WP_REST_Request $request ) use ( $capability ) {
		$auth = wpm_connector_authenticate_request( $request );
		if ( is_wp_error( $auth ) ) {
			return $auth;
		}
		if ( ! current_user_can( $capability ) ) {
			return new WP_Error(
				'wpm_forbidden',
				'The WordPress user this site is connected with does not have the "' . $capability . '" capability needed for this request.',
				// The capability as data, so WPWriter can name it without relaying the site's message.
				array(
					'status'     => 403,
					'capability' => $capability,
				)
			);
		}
		return true;
	};
}

/**
 * Authenticate request.
 *
 * @param WP_REST_Request $request The REST request.
 */
function wpm_connector_authenticate_request( WP_REST_Request $request ) {
	$token = $request->get_header( 'x-wp-manager-token' );

	if ( ! is_string( $token ) || trim( $token ) === '' ) {
		return new WP_Error( 'wpm_missing_token', 'Missing connector token.', array( 'status' => 401 ) );
	}

	$connections = wpm_connector_get_connections();
	if ( empty( $connections ) ) {
		return new WP_Error( 'wpm_not_connected', 'Connector is not configured. Pair the site from the plugin page.', array( 'status' => 401 ) );
	}

	$candidate_hash     = hash( 'sha256', trim( $token ) );
	$matched_connection = null;

	foreach ( $connections as $conn ) {
		if ( isset( $conn['token_hash'] ) && hash_equals( $conn['token_hash'], $candidate_hash ) ) {
			$matched_connection = $conn;
			break;
		}
	}

	if ( ! $matched_connection ) {
		return new WP_Error( 'wpm_invalid_token', 'Invalid connector token.', array( 'status' => 401 ) );
	}

	$user_id = isset( $matched_connection['wp_user_id'] ) ? (int) $matched_connection['wp_user_id'] : 0;
	if ( $user_id <= 0 || ! get_user_by( 'id', $user_id ) ) {
		return new WP_Error( 'wpm_invalid_user', 'Connector user is missing or invalid. Re-pair the site from the plugin page.', array( 'status' => 401 ) );
	}

	// 1.15.0: record whether the paired user REALLY holds manage_options before the token capability limit applies (only
	// the display-settings writes read it). Then mark the token user BEFORE switching to it: wp_set_current_user() fires
	// set_current_user, where WordPress (kses_init) reads the user's capabilities, so the limit must already apply then.
	if ( ! isset( $GLOBALS['wpm_connector_token_user_id'] ) || (int) $GLOBALS['wpm_connector_token_user_id'] !== $user_id ) {
		$GLOBALS['wpm_connector_paired_can_manage_options'] = user_can( $user_id, 'manage_options' );
	}
	$GLOBALS['wpm_connector_token_user_id'] = $user_id;
	wp_set_current_user( $user_id );
	return $matched_connection;
}

/**
 * Capabilities a connector token request never has (1.15.0), whatever the paired user's role: no plugin, theme, core,
 * file, user, import/export or general-settings administration, and no unfiltered uploads. What remains is at most
 * Editor-level content work (including unfiltered_html, which a single-site Editor has) plus edit_theme_options (menus,
 * widgets) and WooCommerce product capabilities.
 *
 * @return string[]
 */
function wpm_connector_token_denied_caps() {
	return array(
		// Plugins.
		'activate_plugins',
		'install_plugins',
		'update_plugins',
		'delete_plugins',
		'edit_plugins',
		'upload_plugins',
		'resume_plugins',
		// Themes.
		'switch_themes',
		'install_themes',
		'update_themes',
		'delete_themes',
		'edit_themes',
		'upload_themes',
		'resume_themes',
		// Core, files, languages, site health.
		'update_core',
		'update_php',
		'update_https',
		'edit_files',
		'install_languages',
		'update_languages',
		'view_site_health_checks',
		// Users.
		'create_users',
		'edit_users',
		'delete_users',
		'promote_users',
		'list_users',
		'remove_users',
		'add_users',
		'edit_user',
		'delete_user',
		'promote_user',
		'remove_user',
		'add_user_to_blog',
		// Unfiltered uploads and the Customizer's Additional CSS (unfiltered_html itself stays: Editor parity).
		'unfiltered_upload',
		'edit_css',
		// Import, export, settings, privacy, dashboard.
		'import',
		'export',
		'manage_options',
		'manage_privacy_options',
		'export_others_personal_data',
		'erase_others_personal_data',
		'edit_dashboard',
		// Multisite network administration.
		'manage_network',
		'manage_sites',
		'manage_network_users',
		'manage_network_plugins',
		'manage_network_themes',
		'manage_network_options',
		'upgrade_network',
		'setup_network',
		'create_sites',
		'delete_sites',
		'delete_site',
	);
}

/**
 * True when $user_id is the user of the current connector token request.
 *
 * @param int $user_id User ID.
 * @return bool
 */
function wpm_connector_is_token_user( $user_id ) {
	return isset( $GLOBALS['wpm_connector_token_user_id'] ) && (int) $GLOBALS['wpm_connector_token_user_id'] > 0
		&& (int) $GLOBALS['wpm_connector_token_user_id'] === (int) $user_id;
}

/**
 * Filter user_has_cap: remove the denied capabilities from the token user's capability set.
 *
 * @param bool[]  $allcaps All capabilities of the user.
 * @param string[] $caps   Required primitive capabilities.
 * @param array   $args    Capability check arguments.
 * @param WP_User $user    The user.
 * @return bool[]
 */
function wpm_connector_limit_token_caps( $allcaps, $caps, $args, $user ) {
	if ( ! ( $user instanceof WP_User ) || ! wpm_connector_is_token_user( $user->ID ) ) {
		return $allcaps;
	}
	foreach ( wpm_connector_token_denied_caps() as $cap ) {
		$allcaps[ $cap ] = false;
	}
	return $allcaps;
}
add_filter( 'user_has_cap', 'wpm_connector_limit_token_caps', PHP_INT_MAX, 4 );

/**
 * Filter map_meta_cap: deny a denied capability (or any capability that maps to one) for the token user. This also covers
 * a multisite super admin, whose capability checks do not reach the user_has_cap filter.
 *
 * @param string[] $caps    Primitive capabilities required.
 * @param string   $cap     Capability being checked.
 * @param int      $user_id User ID.
 * @param array    $args    Capability check arguments.
 * @return string[]
 */
function wpm_connector_limit_token_meta_caps( $caps, $cap, $user_id, $args ) {
	if ( ! wpm_connector_is_token_user( $user_id ) ) {
		return $caps;
	}
	$denied = wpm_connector_token_denied_caps();
	if ( in_array( $cap, $denied, true ) || array_intersect( (array) $caps, $denied ) ) {
		return array( 'do_not_allow' );
	}
	return $caps;
}
add_filter( 'map_meta_cap', 'wpm_connector_limit_token_meta_caps', PHP_INT_MAX, 4 );

/**
 * REST endpoint: Exchange pairing code for token.
 *
 * @param WP_REST_Request $request The REST request.
 */
function wpm_connector_rest_pair( WP_REST_Request $request ) {
	$data         = $request->get_json_params();
	$pairing_code = '';

	if ( is_array( $data ) && isset( $data['pairingCode'] ) ) {
		$pairing_code = $data['pairingCode'];
	}

	if ( '' === $pairing_code ) {
		$pairing_code = $request->get_param( 'pairingCode' );
	}

	$verified = wpm_connector_verify_pairing_code( $pairing_code );
	if ( is_wp_error( $verified ) ) {
		return $verified;
	}

	$user_id = (int) $verified['user_id'];
	$label   = isset( $verified['label'] ) ? $verified['label'] : '';

	$token = wpm_connector_issue_token( $user_id, $label );

	wpm_connector_clear_pairing_state();

	$user = get_user_by( 'id', $user_id );

	return new WP_REST_Response(
		array(
			'success'         => true,
			'token'           => $token,
			'user'            => array(
				'username' => $user ? $user->user_login : null,
			),
			'connectionCount' => wpm_connector_get_connection_count(),
		),
		200
	);
}

/**
 * Remote scope (1.15.0): the connector's proxies forward only these requests (fail closed; anything not listed is refused).
 * Every request also runs under the token capability limit (wpm_connector_token_denied_caps()).
 *
 * Routes under wp/v2, all methods: posts, pages, media, categories, tags, comments, menus, menu-items, menu-locations.
 * wp/v2, read only (GET/HEAD/OPTIONS): types, taxonomies, statuses, search, users.
 * wp/v2/settings: never forwarded; wpm_connector_rest_proxy() answers it itself with the display settings only.
 * wc/v3: products (including products/categories, tags, attributes, variations and reviews).
 * Refused (403 wpm_connector_out_of_scope): plugins, themes, every user change, application passwords, block directory,
 * batch requests, every other WooCommerce route, and any path not listed above.
 *
 * @param string $api_namespace 'wp/v2' or 'wc/v3'.
 * @param string $path      Path below the namespace.
 * @param string $method    HTTP method.
 * @param array  $params    The request's body and query parameters.
 * @return WP_Error|null Null when allowed.
 */
function wpm_connector_proxy_scope_error( $api_namespace, $path, $method, $params ) {
	$path = strtolower( trim( (string) $path, '/' ) );
	if ( false !== strpos( $path, '..' ) || false !== strpos( $path, '//' ) || false !== strpos( $path, '\\' ) ) {
		return new WP_Error( 'wpm_connector_out_of_scope', 'Invalid proxy path.', array( 'status' => 400 ) );
	}
	$query = strpos( $path, '?' );
	if ( false !== $query ) {
		$path = substr( $path, 0, $query );
	}
	$parts  = explode( '/', $path );
	$first  = isset( $parts[0] ) ? $parts[0] : '';
	$method = strtoupper( (string) $method );
	$read   = in_array( $method, array( 'GET', 'HEAD', 'OPTIONS' ), true );

	if ( 'wp/v2' === $api_namespace ) {
		if ( false !== strpos( $path, 'application-passwords' ) ) {
			return wpm_connector_out_of_scope_error();
		}
		$content = array( 'posts', 'pages', 'media', 'categories', 'tags', 'comments', 'menus', 'menu-items', 'menu-locations' );
		if ( in_array( $first, $content, true ) ) {
			return null;
		}
		$read_only = array( 'types', 'taxonomies', 'statuses', 'search', 'users' );
		if ( $read && in_array( $first, $read_only, true ) ) {
			return null;
		}
	} elseif ( 'wc/v3' === $api_namespace ) {
		if ( 'products' === $first ) {
			return null;
		}
	}

	return wpm_connector_out_of_scope_error();
}

/**
 * The display settings WPWriter may read and change (core REST names). Nothing that affects security, access or search
 * engines.
 *
 * @return string[]
 */
function wpm_connector_display_settings_keys() {
	return array(
		'title',
		'description',
		'show_on_front',
		'page_on_front',
		'page_for_posts',
		'posts_per_page',
		'date_format',
		'time_format',
		'timezone',
		'start_of_week',
		'site_icon',
		'site_logo',
		'default_comment_status',
		'default_ping_status',
	);
}

/**
 * The refusal every out-of-scope proxy request gets.
 *
 * @return WP_Error
 */
function wpm_connector_out_of_scope_error() {
	return new WP_Error(
		'wpm_connector_out_of_scope',
		'This connector version does not perform site administration (plugins, themes, users, settings). Make that change in wp-admin.',
		array( 'status' => 403 )
	);
}

/**
 * REST endpoint: Proxy requests to WordPress REST API.
 *
 * @param WP_REST_Request $request The REST request.
 */
function wpm_connector_rest_proxy( WP_REST_Request $request ) {
	$auth = wpm_connector_authenticate_request( $request );
	if ( is_wp_error( $auth ) ) {
		return $auth;
	}

	$path   = $request->get_param( 'path' );
	$method = $request->get_method();

	// 1.15.0: wp/v2/settings is never forwarded to WordPress core. The plugin answers it itself with the display settings
	// only, so existing WPWriter calls keep working.
	$settings_path  = strtolower( trim( (string) $path, '/' ) );
	$settings_query = strpos( $settings_path, '?' );
	if ( false !== $settings_query ) {
		$settings_path = substr( $settings_path, 0, $settings_query );
	}
	if ( 'settings' === $settings_path ) {
		$gate = wpm_connector_display_settings_gate( $method );
		if ( is_wp_error( $gate ) ) {
			return $gate;
		}
		if ( in_array( strtoupper( $method ), array( 'GET', 'HEAD' ), true ) ) {
			return wpm_connector_get_site_settings( $request );
		}
		if ( in_array( strtoupper( $method ), array( 'POST', 'PUT', 'PATCH' ), true ) ) {
			return wpm_connector_update_site_settings( $request );
		}
		return wpm_connector_out_of_scope_error();
	}

	$scope = wpm_connector_proxy_scope_error(
		'wp/v2',
		$path,
		$method,
		array_merge(
			(array) $request->get_query_params(),
			(array) $request->get_body_params(),
			(array) $request->get_json_params()
		)
	);
	if ( is_wp_error( $scope ) ) {
		return $scope;
	}

	$wp_request = new WP_REST_Request( $method, '/wp/v2/' . ltrim( $path, '/' ) );

	foreach ( $request->get_query_params() as $key => $value ) {
		if ( 'path' !== $key ) {
			$wp_request->set_param( $key, $value );
		}
	}

	$body = $request->get_body();
	if ( $body ) {
		$wp_request->set_body( $body );
		$ct = $request->get_header( 'content-type' );
		if ( $ct ) {
			$wp_request->set_header( 'content-type', $ct );
		}
		// Forward Content-Disposition header for media uploads.
		$cd = $request->get_header( 'content-disposition' );
		if ( $cd ) {
			$wp_request->set_header( 'content-disposition', $cd );
		}
		$json = $request->get_json_params();
		if ( $json ) {
			foreach ( $json as $key => $value ) {
				$wp_request->set_param( $key, $value );
			}
		}
	}

	$response = rest_do_request( $wp_request );
	$server   = rest_get_server();

	// Process _embed parameter to include related resources (e.g., featured images)
	// This is normally done by the REST server at the HTTP level, but we need to do it
	// manually when using rest_do_request() internally.
	$embed = $request->get_param( '_embed' );
	if ( null !== $embed ) {
		// Convert response to data with embedded links.
		$data = $server->response_to_data( $response, $embed );
	} else {
		$data = $response->get_data();
	}

	$status  = $response->get_status();
	$headers = $response->get_headers();

	$out = new WP_REST_Response( $data, $status );
	foreach ( $headers as $key => $value ) {
		$out->header( $key, $value );
	}

	return $out;
}

/**
 * REST endpoint: Proxy requests to WooCommerce REST API.
 *
 * WooCommerce's public REST authentication normally uses consumer key/secret pairs.
 * This connector route lets WPWriter keep one auth surface: the existing connector
 * token maps to a real WordPress user, and WooCommerce's own REST permission checks
 * still decide whether that user may read or edit products.
 *
 * @param WP_REST_Request $request The REST request.
 */
function wpm_connector_rest_wc_proxy( WP_REST_Request $request ) {
	$auth = wpm_connector_authenticate_request( $request );
	if ( is_wp_error( $auth ) ) {
		return $auth;
	}

	$path   = $request->get_param( 'path' );
	$method = $request->get_method();

	$scope = wpm_connector_proxy_scope_error( 'wc/v3', $path, $method, array() );
	if ( is_wp_error( $scope ) ) {
		return $scope;
	}

	if ( ! class_exists( 'WooCommerce' ) ) {
		return new WP_Error( 'woocommerce_not_active', 'WooCommerce is not active on this site.', array( 'status' => 404 ) );
	}

	$wc_request = new WP_REST_Request( $method, '/wc/v3/' . ltrim( $path, '/' ) );

	foreach ( $request->get_query_params() as $key => $value ) {
		if ( 'path' !== $key ) {
			$wc_request->set_param( $key, $value );
		}
	}

	$body = $request->get_body();
	if ( $body ) {
		$wc_request->set_body( $body );
		$ct = $request->get_header( 'content-type' );
		if ( $ct ) {
			$wc_request->set_header( 'content-type', $ct );
		}
		$json = $request->get_json_params();
		if ( $json ) {
			foreach ( $json as $key => $value ) {
				$wc_request->set_param( $key, $value );
			}
		}
	}

	$response = rest_do_request( $wc_request );
	$server   = rest_get_server();
	$embed    = $request->get_param( '_embed' );
	$data     = null !== $embed ? $server->response_to_data( $response, $embed ) : $response->get_data();
	$status   = $response->get_status();
	$headers  = $response->get_headers();

	$out = new WP_REST_Response( $data, $status );
	foreach ( $headers as $key => $value ) {
		$out->header( $key, $value );
	}

	return $out;
}

/**
 * REST endpoint: Simple ping/health check.
 *
 * @param WP_REST_Request $request The REST request.
 */
function wpm_connector_rest_ping( WP_REST_Request $request ) {
	return new WP_REST_Response(
		array(
			'success'          => true,
			'message'          => 'WPWriter Connector is active.',
			'connectorVersion' => WPM_CONNECTOR_VERSION,
		),
		200
	);
}

/**
 * REST endpoint: Token-authenticated connection health check.
 * Endpoint: GET /wp-json/wp-manager/v1/status
 *
 * Exists because security/hardening plugins commonly block or redirect any URL
 * containing "wp/v2/users" (user-enumeration protection) — including our own
 * proxy route — which broke the post-pairing verify on hardened sites while the
 * connection itself was fine. This route's URL contains no blocked substring and
 * proves end-to-end that the token authenticates and maps to a real WP user.
 *
 * @param WP_REST_Request $request The REST request.
 */
function wpm_connector_rest_status( WP_REST_Request $request ) {
	$auth = wpm_connector_authenticate_request( $request );
	if ( is_wp_error( $auth ) ) {
		return $auth;
	}

	$user = wp_get_current_user();
	return new WP_REST_Response(
		array(
			'success'          => true,
			'connectorVersion' => WPM_CONNECTOR_VERSION,
			'wpVersion'        => get_bloginfo( 'version' ),
			'user'             => array(
				'id'       => $user->ID,
				'name'     => $user->display_name,
				'username' => $user->user_login,
				'roles'    => array_values( $user->roles ),
			),
		),
		200
	);
}

/**
 * REST endpoint: Returns status information about the connector and detected SEO plugins.
 * Endpoint: GET /wp-json/wp-writer/v1/seo-meta-status
 *
 * @param WP_REST_Request $request The REST request.
 */
function wpm_connector_seo_meta_status( WP_REST_Request $request ) {
	$detected_plugins = array();

	// Check for Yoast SEO.
	if ( defined( 'WPSEO_VERSION' ) || class_exists( 'WPSEO_Options' ) ) {
		$detected_plugins[] = array(
			'slug'    => 'yoast',
			'name'    => 'Yoast SEO',
			'version' => defined( 'WPSEO_VERSION' ) ? WPSEO_VERSION : 'unknown',
		);
	}

	// Check for Rank Math.
	if ( defined( 'RANK_MATH_VERSION' ) || class_exists( 'RankMath' ) ) {
		$detected_plugins[] = array(
			'slug'    => 'rankmath',
			'name'    => 'Rank Math',
			'version' => defined( 'RANK_MATH_VERSION' ) ? RANK_MATH_VERSION : 'unknown',
		);
	}

	// Check for All in One SEO Pack (legacy version).
	if ( defined( 'AIOSEOP_VERSION' ) || class_exists( 'All_in_One_SEO_Pack' ) ) {
		$detected_plugins[] = array(
			'slug'    => 'aioseo',
			'name'    => 'All in One SEO',
			'version' => defined( 'AIOSEOP_VERSION' ) ? AIOSEOP_VERSION : 'unknown',
		);
	}

	// Check for All in One SEO (new version 4.x+).
	if ( defined( 'AIOSEO_VERSION' ) || class_exists( 'AIOSEO\Plugin\AIOSEO' ) ) {
		// Avoid duplicates if both old and new detected.
		$already_added = false;
		foreach ( $detected_plugins as $p ) {
			if ( 'aioseo' === $p['slug'] ) {
				$already_added = true;
				break;
			}
		}
		if ( ! $already_added ) {
			$detected_plugins[] = array(
				'slug'    => 'aioseo',
				'name'    => 'All in One SEO',
				'version' => defined( 'AIOSEO_VERSION' ) ? AIOSEO_VERSION : 'unknown',
			);
		}
	}

	return rest_ensure_response(
		array(
			'connector_installed' => true,
			'connector_version'   => WPM_CONNECTOR_VERSION,
			'seo_plugins'         => $detected_plugins,
			'has_seo_plugin'      => ! empty( $detected_plugins ),
		)
	);
}

/**
 * =====================================================
 * SEO META FIELDS - Expose Yoast/Rank Math/AIOSEO to REST API
 * =====================================================
 */

/**
 * Auth callback for SEO meta fields - only allow if user can edit the post.
 *
 * @param mixed $allowed Allowed.
 * @param mixed $meta_key Meta key.
 * @param mixed $post_id Post id.
 * @param mixed $user_id User id.
 * @param mixed $cap Cap.
 * @param mixed $caps Caps.
 */
function wpm_connector_seo_meta_auth_callback( $allowed, $meta_key, $post_id, $user_id, $cap, $caps ) {
	return current_user_can( 'edit_post', $post_id );
}

/**
 * Register SEO meta fields for a post type to make them available via REST API.
 *
 * @param mixed $post_type Post type.
 */
function wpm_connector_register_seo_meta_for_post_type( $post_type ) {
	$seo_keys = array(
		// Yoast SEO.
		'_yoast_wpseo_title',
		'_yoast_wpseo_metadesc',
		'_yoast_wpseo_focuskw',
		// Rank Math.
		'rank_math_title',
		'rank_math_description',
		'rank_math_focus_keyword',
		// All in One SEO.
		'_aioseop_title',
		'_aioseop_description',
	);

	foreach ( $seo_keys as $key ) {
		register_post_meta(
			$post_type,
			$key,
			array(
				'type'              => 'string',
				'single'            => true,
				'show_in_rest'      => true,
				'sanitize_callback' => 'sanitize_text_field',
				'auth_callback'     => 'wpm_connector_seo_meta_auth_callback',
			)
		);
	}
}

// Register SEO meta fields for all public post types on init.
add_action(
	'init',
	function () {
		$post_types = get_post_types( array( 'public' => true ), 'names' );
		if ( empty( $post_types ) || ! is_array( $post_types ) ) {
			$post_types = array( 'post', 'page' );
		}
		foreach ( $post_types as $post_type ) {
			wpm_connector_register_seo_meta_for_post_type( $post_type );
		}
	},
	20
); // Priority 20 to run after post types are registered.

/**
 * REST endpoint: Convert post type (post to page or vice versa).
 * Endpoint: POST /wp-json/wp-writer/v1/convert-post-type/{post_id}
 * Body: { "targetType": "post" | "page" }
 *
 * @param WP_REST_Request $request The REST request.
 */
function wpm_connector_convert_post_type( WP_REST_Request $request ) {
	$auth = wpm_connector_authenticate_request( $request );
	if ( is_wp_error( $auth ) ) {
		return $auth;
	}

	$post_id     = (int) $request->get_param( 'post_id' );
	$target_type = $request->get_param( 'targetType' );

	// Validate post_id.
	if ( $post_id <= 0 ) {
		return new WP_Error( 'invalid_post_id', 'Invalid post ID.', array( 'status' => 400 ) );
	}

	// Validate target type.
	if ( ! in_array( $target_type, array( 'post', 'page' ), true ) ) {
		return new WP_Error( 'invalid_target_type', 'Invalid target type. Must be "post" or "page".', array( 'status' => 400 ) );
	}

	// Get the post.
	$post = get_post( $post_id );
	if ( ! $post ) {
		return new WP_Error( 'post_not_found', 'Post not found.', array( 'status' => 404 ) );
	}

	// Check permissions.
	if ( ! current_user_can( 'edit_post', $post_id ) ) {
		return new WP_Error( 'forbidden', 'You do not have permission to edit this post.', array( 'status' => 403 ) );
	}

	$previous_type = $post->post_type;

	// Check if already the target type.
	if ( $previous_type === $target_type ) {
		return rest_ensure_response(
			array(
				'success'      => true,
				'postId'       => $post_id,
				'previousType' => $previous_type,
				'postType'     => $target_type,
				'message'      => sprintf( 'Content is already a %s.', $target_type ),
			)
		);
	}

	// Only allow conversion between post and page.
	if ( ! in_array( $previous_type, array( 'post', 'page' ), true ) ) {
		return new WP_Error(
			'invalid_conversion',
			sprintf( 'Cannot convert "%s" to "%s". Only posts and pages can be converted.', $previous_type, $target_type ),
			array( 'status' => 400 )
		);
	}

	// Update the post type using wp_update_post.
	$result = wp_update_post(
		array(
			'ID'        => $post_id,
			'post_type' => $target_type,
		),
		true
	);

	if ( is_wp_error( $result ) ) {
		return new WP_Error(
			'update_failed',
			sprintf( 'Failed to convert post type: %s', $result->get_error_message() ),
			array( 'status' => 500 )
		);
	}

	return rest_ensure_response(
		array(
			'success'      => true,
			'postId'       => $post_id,
			'previousType' => $previous_type,
			'postType'     => $target_type,
			'message'      => sprintf( 'Successfully converted from %s to %s.', $previous_type, $target_type ),
		)
	);
}

/**
 * #1040 (dev-wpwriter, source-only mitigation; not released). Connector requests carry no cookie and no Authorization
 * header, so a page cache in front of WordPress may treat them as anonymous and cacheable, whatever WordPress itself
 * decides later. Every response on this plugin's own routes is now marked private and uncacheable, errors included, and
 * LiteSpeed Cache is told not to store it. Mitigation only: it does not remove entries a cache already stored.
 *
 * @param mixed $route Route.
 */
function wpm_connector_is_own_route( $route ) {
	// WordPress matches REST routes case-insensitively, so the namespace check must too (review NO-GO 6216538012).
	// Bounded: the namespace itself, or the namespace followed by '/', never a longer namespace such as /wp-manager/v10.
	$route = strtolower( (string) $route );
	foreach ( array( '/wp-manager/v1', '/wp-writer/v1' ) as $namespace ) {
		if ( $route === $namespace || strpos( $route, $namespace . '/' ) === 0 ) {
			return true;
		}
	}
	return false;
}

/**
 * Nocache responses.
 *
 * @param mixed $response Response.
 * @param mixed $server Server.
 * @param mixed $request Request.
 */
function wpm_connector_nocache_responses( $response, $server, $request ) {
	if ( ! ( $request instanceof WP_REST_Request ) || ! wpm_connector_is_own_route( $request->get_route() ) ) {
		return $response;
	}
	if ( $response instanceof WP_HTTP_Response ) {
		foreach ( wp_get_nocache_headers() as $name => $value ) {
			if ( false !== $value && '' !== $value ) {
				$response->header( $name, $value );
			}
		}
		$response->header( 'Cache-Control', 'no-cache, no-store, must-revalidate, max-age=0, private' );
		$response->header( 'X-LiteSpeed-Cache-Control', 'no-cache' );
	}
	// LiteSpeed Cache's own switch for "do not store this response" (no-op when LiteSpeed Cache is not active).
    // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- LiteSpeed Cache's own hook, not ours to prefix.
	do_action( 'litespeed_control_set_nocache', 'WPWriter connector route' );
	return $response;
}
add_filter( 'rest_post_dispatch', 'wpm_connector_nocache_responses', 10, 3 );

add_action(
	'rest_api_init',
	function () {
		register_rest_route(
			'wp-manager/v1',
			'/pair',
			array(
				'methods'             => 'POST',
				'callback'            => 'wpm_connector_rest_pair',
				'permission_callback' => '__return_true',
			)
		);

		register_rest_route(
			'wp-manager/v1',
			'/ping',
			array(
				'methods'             => 'GET',
				'callback'            => 'wpm_connector_rest_ping',
				'permission_callback' => '__return_true',
			)
		);

		register_rest_route(
			'wp-manager/v1',
			'/status',
			array(
				'methods'             => 'GET',
				'callback'            => 'wpm_connector_rest_status',
				// Auth happens inside the callback via the connector token header,
				// same pattern as the proxy routes.
				'permission_callback' => wpm_connector_permission( 'read' ),
			)
		);

		register_rest_route(
			'wp-manager/v1',
			'/proxy/wp/v2/(?P<path>.*)',
			array(
				'methods'             => WP_REST_Server::ALLMETHODS,
				'callback'            => 'wpm_connector_rest_proxy',
				'permission_callback' => wpm_connector_permission( 'read' ),
				'args'                => array(
					'path' => array(
						'required' => true,
						'type'     => 'string',
					),
				),
			)
		);

		register_rest_route(
			'wp-manager/v1',
			'/proxy/wc/v3/(?P<path>.*)',
			array(
				'methods'             => WP_REST_Server::ALLMETHODS,
				'callback'            => 'wpm_connector_rest_wc_proxy',
				'permission_callback' => wpm_connector_permission( 'read' ),
				'args'                => array(
					'path' => array(
						'required' => true,
						'type'     => 'string',
					),
				),
			)
		);

		// SEO Meta Status endpoint - detects installed SEO plugins
		// Public endpoint - only returns which SEO plugins are installed (not sensitive).
		register_rest_route(
			'wp-writer/v1',
			'/seo-meta-status',
			array(
				'methods'             => 'GET',
				'callback'            => 'wpm_connector_seo_meta_status',
				'permission_callback' => wpm_connector_permission( 'edit_posts' ),
			)
		);

		// Convert post type endpoint - convert post to page or vice versa.
		register_rest_route(
			'wp-writer/v1',
			'/convert-post-type/(?P<post_id>\d+)',
			array(
				'methods'             => 'POST',
				'callback'            => 'wpm_connector_convert_post_type',
				'permission_callback' => wpm_connector_permission( 'edit_posts' ),
				'args'                => array(
					'post_id' => array(
						'required'          => true,
						'type'              => 'integer',
						'sanitize_callback' => 'absint',
					),
				),
			)
		);

		// Theme status endpoint - returns active theme info for design recommendations.
		register_rest_route(
			'wp-writer/v1',
			'/theme-status',
			array(
				'methods'             => 'GET',
				'callback'            => 'wpm_connector_theme_status',
				'permission_callback' => wpm_connector_permission( 'edit_posts' ),
			)
		);

		// Menu locations — list available theme locations and their assigned menus.
		register_rest_route(
			'wp-writer/v1',
			'/menu-locations',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => 'wpm_connector_list_menu_locations',
					'permission_callback' => wpm_connector_permission( 'edit_theme_options' ),
				),
				array(
					'methods'             => 'POST',
					'callback'            => 'wpm_connector_assign_menu_location',
					'permission_callback' => wpm_connector_permission( 'edit_theme_options' ),
				),
			)
		);

		// Widget / sidebar management.
		register_rest_route(
			'wp-writer/v1',
			'/sidebars',
			array(
				'methods'             => 'GET',
				'callback'            => 'wpm_connector_list_sidebars',
				'permission_callback' => wpm_connector_permission( 'edit_theme_options' ),
			)
		);

		register_rest_route(
			'wp-writer/v1',
			'/widgets',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => 'wpm_connector_list_widgets',
					'permission_callback' => wpm_connector_permission( 'edit_theme_options' ),
				),
				array(
					'methods'             => 'POST',
					'callback'            => 'wpm_connector_add_widget',
					'permission_callback' => wpm_connector_permission( 'edit_theme_options' ),
				),
			)
		);

		register_rest_route(
			'wp-writer/v1',
			'/widgets/(?P<widget_id>[a-zA-Z0-9_-]+)',
			array(
				'methods'             => 'DELETE',
				'callback'            => 'wpm_connector_remove_widget',
				'permission_callback' => wpm_connector_permission( 'edit_theme_options' ),
			)
		);

	}
);

/**
 * REST endpoint: Returns information about the active WordPress theme.
 * Endpoint: GET /wp-json/wp-writer/v1/theme-status
 * Public endpoint - only returns theme name and type (not sensitive).
 *
 * @param WP_REST_Request $request The REST request.
 */
function wpm_connector_theme_status( WP_REST_Request $request ) {
	$theme  = wp_get_theme();
	$parent = $theme->parent();

	$result = array(
		'active_theme'   => $theme->get( 'Name' ),
		'theme_slug'     => $theme->get_stylesheet(),
		'theme_version'  => $theme->get( 'Version' ),
		'is_child_theme' => false !== $parent,
	);

	if ( false !== $parent ) {
		$result['parent_theme']      = $parent->get( 'Name' );
		$result['parent_theme_slug'] = $parent->get_stylesheet();
	}

	// Detect if WPWriter theme is active.
	$stylesheet                  = strtolower( $theme->get_stylesheet() );
	$parent_stylesheet           = $parent ? strtolower( $parent->get_stylesheet() ) : '';
	$result['is_wpwriter_theme'] = (
		strpos( $stylesheet, 'wpwriter' ) !== false ||
		strpos( $stylesheet, 'wp-writer' ) !== false
	);
	$result['is_astra']          = (
		'astra' === $stylesheet || 'astra' === $parent_stylesheet
	);

	// Detect if it's a default/basic WordPress theme.
	$default_themes             = array(
		'twentytwentyfive',
		'twentytwentyfour',
		'twentytwentythree',
		'twentytwentytwo',
		'twentytwentyone',
		'twentytwenty',
		'twentynineteen',
		'twentyseventeen',
		'twentysixteen',
		'twentyfifteen',
		'twentyfourteen',
		'twentythirteen',
		'twentytwelve',
		'twentyeleven',
		'twentyten',
	);
	$check_slug                 = $parent ? $parent_stylesheet : $stylesheet;
	$result['is_default_theme'] = in_array( $check_slug, $default_themes, true );

	$result['connector_version'] = WPM_CONNECTOR_VERSION;

	return new WP_REST_Response( $result, 200 );
}

/**
 * Admin page.
 */
function wpm_connector_admin_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}

	$notice      = '';
	$notice_type = 'updated';

	$request_method = isset( $_SERVER['REQUEST_METHOD'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) : '';
	if ( 'POST' === $request_method && isset( $_POST['wpm_action'] ) ) {
		check_admin_referer( 'wpm_connector_admin' );
		$action = sanitize_text_field( wp_unslash( $_POST['wpm_action'] ) );

		if ( 'save_settings' === $action ) {
			$hub_url = isset( $_POST['wpm_hub_url'] ) ? sanitize_text_field( wp_unslash( $_POST['wpm_hub_url'] ) ) : '';
			wpm_connector_set_hub_url( $hub_url );
			$notice = 'Settings saved.';
		} elseif ( 'generate_pairing' === $action ) {
			$label = isset( $_POST['wpm_label'] ) ? trim( sanitize_text_field( wp_unslash( $_POST['wpm_label'] ) ) ) : '';
			if ( '' === $label ) {
				$notice      = 'Connection name is required.';
				$notice_type = 'error';
			} else {
				wpm_connector_generate_pairing_code( get_current_user_id(), $label );
				$notice = 'Pairing code generated. It expires in 10 minutes.';
			}
		} elseif ( 'cancel_pairing' === $action ) {
			wpm_connector_clear_pairing_state();

			// Also remove any connection created from this pairing attempt.
			// The label is passed via hidden form field (the transient may already
			// be consumed if the pairing code was exchanged).
			$cancel_label  = isset( $_POST['pairing_label'] ) ? sanitize_text_field( wp_unslash( $_POST['pairing_label'] ) ) : '';
			$cancel_user   = get_current_user_id();
			$removed_stale = false;

			if ( '' !== $cancel_label ) {
				$connections = wpm_connector_get_connections();
				$filtered    = array();
				foreach ( $connections as $conn ) {
					$conn_label = isset( $conn['label'] ) ? $conn['label'] : '';
					$conn_user  = isset( $conn['wp_user_id'] ) ? (int) $conn['wp_user_id'] : 0;

					if ( $conn_label === $cancel_label && $conn_user === $cancel_user ) {
						$removed_stale = true;
						continue; // remove this connection.
					}
					$filtered[] = $conn;
				}

				if ( $removed_stale ) {
					wpm_connector_save_connections( array_values( $filtered ) );
				}
			}

			$notice = $removed_stale
				? 'Pairing cancelled and connection removed.'
				: 'Pairing code cancelled.';
		} elseif ( 'disconnect_all' === $action ) {
			wpm_connector_disconnect_all();
			$notice = 'All connections disconnected.';
		} elseif ( 'disconnect_one' === $action ) {
			$conn_id = isset( $_POST['connection_id'] ) ? sanitize_text_field( wp_unslash( $_POST['connection_id'] ) ) : '';
			if ( '' !== $conn_id && wpm_connector_disconnect_by_id( $conn_id ) ) {
				$notice = 'Connection disconnected.';
			} else {
				$notice      = 'Connection not found.';
				$notice_type = 'error';
			}
		}
	}

	$hub_url          = wpm_connector_get_hub_url();
	$pairing          = wpm_connector_get_pairing_state();
	$connections      = wpm_connector_get_connections();
	$connection_count = count( $connections );

	echo '<div class="wrap">';
	echo '<h1>WPWriter Connector</h1>';

	if ( $notice ) {
		echo '<div class="notice ' . esc_attr( $notice_type ) . ' is-dismissible"><p>' . esc_html( $notice ) . '</p></div>';
	}

	// Status Section.
	echo '<h2>Status</h2>';
	echo '<div style="display: flex; align-items: center; gap: 15px; margin-bottom: 10px;">';
	if ( 0 === $connection_count ) {
		echo '<span><strong style="color: #999;">Not connected</strong></span>';
	} elseif ( 1 === $connection_count ) {
		echo '<span><strong style="color: #46b450;">Connected</strong> — 1 WPWriter account</span>';
	} else {
		echo '<span><strong style="color: #46b450;">Connected</strong> — ' . esc_html( $connection_count ) . ' WPWriter accounts</span>';
	}
	echo '<a href="' . esc_url( admin_url( 'admin.php?page=wpm-connector' ) ) . '" class="button button-small">↻ Refresh Status</a>';
	echo '</div>';

	// Connected Accounts Table.
	if ( $connection_count > 0 ) {
		echo '<table class="wp-list-table widefat fixed striped" style="max-width: 700px; margin-top: 15px;">';
		echo '<thead><tr>';
		echo '<th style="width: 35%;">Account</th>';
		echo '<th style="width: 25%;">WordPress User</th>';
		echo '<th style="width: 25%;">Connected</th>';
		echo '<th style="width: 15%;">Action</th>';
		echo '</tr></thead><tbody>';

		foreach ( $connections as $conn ) {
			$conn_id    = isset( $conn['id'] ) ? $conn['id'] : '';
			$label      = isset( $conn['label'] ) ? $conn['label'] : 'Unknown';
			$wp_user_id = isset( $conn['wp_user_id'] ) ? (int) $conn['wp_user_id'] : 0;
			$created_at = isset( $conn['created_at'] ) ? (int) $conn['created_at'] : 0;

			$wp_user     = $wp_user_id > 0 ? get_user_by( 'id', $wp_user_id ) : null;
			$wp_username = $wp_user ? $wp_user->user_login : '<em>unknown</em>';
			$created_str = $created_at > 0 ? human_time_diff( $created_at, time() ) . ' ago' : 'Unknown';

			echo '<tr>';
			echo '<td><strong>' . esc_html( $label ) . '</strong></td>';
			echo '<td><code>' . wp_kses( $wp_username, array( 'em' => array() ) ) . '</code></td>';
			echo '<td>' . esc_html( $created_str ) . '</td>';
			echo '<td>';
			echo '<form method="post" style="display:inline;">';
			wp_nonce_field( 'wpm_connector_admin' );
			echo '<input type="hidden" name="wpm_action" value="disconnect_one">';
			echo '<input type="hidden" name="connection_id" value="' . esc_attr( $conn_id ) . '">';
			echo '<button type="submit" class="button button-small" onclick="return confirm(\'Disconnect this account?\');">Disconnect</button>';
			echo '</form>';
			echo '</td>';
			echo '</tr>';
		}

		echo '</tbody></table>';

		if ( $connection_count > 1 ) {
			echo '<form method="post" style="margin-top: 10px;">';
			wp_nonce_field( 'wpm_connector_admin' );
			echo '<input type="hidden" name="wpm_action" value="disconnect_all">';
			echo '<button type="submit" class="button" onclick="return confirm(\'Disconnect ALL accounts?\');">Disconnect All</button>';
			echo '</form>';
		}
	}

	// Connect New Account Section.
	echo '<hr style="margin: 25px 0;">';
	echo '<h2>Connect New Account</h2>';
	echo '<p>Generate a pairing code and paste it in WPWriter to connect.</p>';

	echo '<div style="background:#fff; border:1px solid #c3c4c7; padding:12px 16px; margin:12px 0; max-width:720px;">';
	echo '<p style="margin-top:0;"><strong>What WPWriter can and cannot do on this site</strong></p>';
	echo '<p>WPWriter acts as the WordPress user you are logged in as when you pair, but always with the limits below, even if that user is an Administrator.</p>';
	echo '<p style="margin-bottom:4px;"><strong>WPWriter can:</strong></p>';
	echo '<ul style="list-style:disc; margin-left:20px;">';
	echo '<li>create, edit, schedule, publish and delete posts and pages</li>';
	echo '<li>upload and edit media, and manage categories, tags and comments</li>';
	echo '<li>save full HTML in posts and pages (styles, embeds and structured data), as a WordPress Editor can</li>';
	echo '<li>edit menus, menu locations and widgets</li>';
	echo '<li>read the active theme and which SEO plugin is installed, and save SEO fields on posts</li>';
	echo '<li>edit WooCommerce products, if WooCommerce is installed</li>';
	echo '<li>read the site display settings, and change them only if you pair as an Administrator (site title, tagline, homepage settings, posts per page, date and time format, timezone, week start, site icon, site logo, default comment and ping status)</li>';
	echo '</ul>';
	echo '<p style="margin-bottom:4px;"><strong>WPWriter cannot:</strong></p>';
	echo '<ul style="list-style:disc; margin-left:20px;">';
	echo '<li>install, activate, update, edit or delete plugins or themes, or update WordPress</li>';
	echo '<li>create, edit, delete or list users, or change anyone&#8217;s role</li>';
	echo '<li>change any other setting (for example the admin email, membership, permalinks or search engine visibility)</li>';
	echo '<li>read passwords, API keys or other stored secrets</li>';
	echo '<li>edit theme or plugin files, upload unfiltered file types, or import or export site data</li>';
	echo '</ul>';
	echo '</div>';

	echo '<form method="post" style="margin-bottom: 15px;">';
	wp_nonce_field( 'wpm_connector_admin' );
	echo '<input type="hidden" name="wpm_action" value="generate_pairing">';
	echo '<div style="display: flex; gap: 10px; align-items: center; margin-bottom: 10px;">';
	echo '<input type="text" name="wpm_label" placeholder="e.g., My Laptop, Office PC" style="width: 250px;" required>';
	echo '<button type="submit" class="button button-primary">Generate Pairing Code</button>';
	echo '</div>';
	echo '<p class="description">Name helps identify this connection in the list above (required).</p>';
	echo '</form>';

	if ( $pairing && isset( $pairing['code'] ) ) {
		$expires_in    = isset( $pairing['expires_at'] ) ? max( 0, $pairing['expires_at'] - time() ) : 0;
		$expires_min   = ceil( $expires_in / 60 );
		$label_display = isset( $pairing['label'] ) ? $pairing['label'] : '';

		echo '<div style="background: #f0f0f1; padding: 15px; border-left: 4px solid #2271b1; margin: 15px 0; max-width: 500px;">';
		echo '<p style="margin: 0 0 5px;"><strong>Connection name:</strong> ' . esc_html( $label_display ) . '</p>';
		echo '<p style="margin: 0 0 10px;"><strong>Pairing code</strong> (expires in ~' . esc_html( $expires_min ) . ' min):</p>';
		echo '<div style="display: flex; gap: 10px; align-items: center;">';
		echo '<input type="text" id="wpm-pairing-code" value="' . esc_attr( $pairing['code'] ) . '" readonly style="font-family: monospace; font-size: 14px; padding: 8px; width: 320px;">';
		echo '<button type="button" class="button" onclick="navigator.clipboard.writeText(document.getElementById(\'wpm-pairing-code\').value); this.textContent=\'Copied!\'; setTimeout(() => this.textContent=\'Copy\', 2000);">Copy</button>';
		echo '<form method="post" style="display:inline; margin:0;">';
		wp_nonce_field( 'wpm_connector_admin' );
		echo '<input type="hidden" name="wpm_action" value="cancel_pairing">';
		echo '<input type="hidden" name="pairing_label" value="' . esc_attr( $label_display ) . '">';
		echo '<button type="submit" class="button" style="color: #b32d2e;">Cancel</button>';
		echo '</form>';
		echo '</div>';
		echo '<p style="margin: 10px 0 0; color: #666; font-size: 13px;">💡 After pasting the code in WPWriter, click <strong>Refresh Status</strong> above to see your new connection.</p>';
		echo '</div>';
	}

	// Settings Section.
	echo '<hr style="margin: 25px 0;">';
	echo '<h2>Settings</h2>';
	echo '<table class="form-table"><tbody>';
	echo '<tr><th scope="row">App URL</th>';
	echo '<td><code>' . esc_html( $hub_url ) . '</code>';
	echo '<p class="description">Where the WPWriter app is hosted.</p></td></tr>';
	echo '</tbody></table>';

	// Technical Section.
	echo '<hr style="margin: 25px 0;">';
	echo '<h2>Technical</h2>';
	echo '<p><strong>Version:</strong> ' . esc_html( WPM_CONNECTOR_VERSION ) . '</p>';
	echo '<p><strong>API endpoints:</strong></p>';
	echo '<ul style="list-style: disc; margin-left: 20px;">';
	echo '<li><code>' . esc_html( home_url( '/wp-json/wp-manager/v1/pair' ) ) . '</code> (POST)</li>';
	echo '<li><code>' . esc_html( home_url( '/wp-json/wp-manager/v1/ping' ) ) . '</code> (GET)</li>';
	echo '<li><code>' . esc_html( home_url( '/wp-json/wp-manager/v1/proxy/wp/v2/...' ) ) . '</code> (content and read-only site information; no administration)</li>';
	echo '</ul>';

	echo '</div>';
}

add_action(
	'admin_menu',
	function () {
		add_menu_page(
			'WPWriter Connector',
			'WPWriter',
			'manage_options',
			'wpm-connector',
			'wpm_connector_admin_page',
			'dashicons-admin-links',
			80
		);
	}
);

add_filter(
	'plugin_action_links_' . plugin_basename( __FILE__ ),
	function ( $links ) {
		$settings_link = '<a href="' . admin_url( 'admin.php?page=wpm-connector' ) . '">Settings</a>';
		array_unshift( $links, $settings_link );
		return $links;
	}
);

// ─── WP360 Product Spin Viewer Shortcode ───────────────────────────────────────
//
// [wp360 ids="101,102,103" speed="100" autoplay="true" reverse="false"]
// ids      = comma-separated WP media attachment IDs (required)
// urls     = comma-separated image URLs (alternative to ids)
// speed    = ms between frames (default 100)
// autoplay = auto-rotate on load (default true)
// reverse  = rotation direction (default false)
//
// The viewer JS & CSS live in the WPWriter theme (js/wp360-viewer.js, style-base.css).
// This shortcode renders the HTML container that the theme JS initializes.

add_shortcode( 'wp360', 'wpm_connector_wp360_shortcode' );

/**
 * Wp360 shortcode.
 *
 * @param mixed $atts Atts.
 */
function wpm_connector_wp360_shortcode( $atts ) {
	$atts = shortcode_atts(
		array(
			'ids'      => '',
			'urls'     => '',
			'speed'    => '100',
			'autoplay' => 'true',
			'reverse'  => 'false',
			'fade'     => '0',
		),
		$atts,
		'wp360'
	);

	$image_urls = array();

	// Resolve from attachment IDs.
	if ( ! empty( $atts['ids'] ) ) {
		$ids = array_map( 'intval', array_filter( explode( ',', $atts['ids'] ) ) );
		foreach ( $ids as $id ) {
			$url = wp_get_attachment_image_url( $id, 'large' );
			if ( $url ) {
				$image_urls[] = $url;
			}
		}
	}

	// Or use direct URLs.
	if ( empty( $image_urls ) && ! empty( $atts['urls'] ) ) {
		$image_urls = array_map( 'esc_url', array_filter( explode( ',', $atts['urls'] ) ) );
	}

	if ( count( $image_urls ) < 2 ) {
		return '<!-- wp360: need at least 2 images -->';
	}

	$json     = wp_json_encode( $image_urls );
	$speed    = intval( $atts['speed'] );
	$autoplay = ( 'true' === $atts['autoplay'] ) ? 'true' : 'false';
	$reverse  = ( 'true' === $atts['reverse'] ) ? 'true' : 'false';
	$fade     = intval( $atts['fade'] );

	return sprintf(
		'<div class="wp360-viewer" data-wp360-images=\'%s\' data-wp360-speed="%d" data-wp360-autoplay="%s" data-wp360-reverse="%s" data-wp360-fade="%d"></div>',
		esc_attr( $json ),
		$speed,
		esc_attr( $autoplay ),
		esc_attr( $reverse ),
		$fade
	);
}

/**
 * REST endpoint: List available theme menu locations and their assigned menus.
 * Endpoint: GET /wp-json/wp-writer/v1/menu-locations
 *
 * @param WP_REST_Request $request The REST request.
 */
function wpm_connector_list_menu_locations( WP_REST_Request $request ) {
	$auth = wpm_connector_authenticate_request( $request );
	if ( is_wp_error( $auth ) ) {
		return $auth;
	}

	$registered  = get_registered_nav_menus();
	$assignments = get_nav_menu_locations();
	$locations   = array();

	foreach ( $registered as $slug => $description ) {
		$menu_id   = isset( $assignments[ $slug ] ) ? (int) $assignments[ $slug ] : 0;
		$menu_name = '';
		if ( $menu_id > 0 ) {
			$menu_obj = wp_get_nav_menu_object( $menu_id );
			if ( $menu_obj ) {
				$menu_name = $menu_obj->name;
			}
		}

		$locations[] = array(
			'location'    => $slug,
			'description' => $description,
			'menu_id'     => $menu_id,
			'menu_name'   => $menu_name,
		);
	}

	return rest_ensure_response(
		array(
			'locations' => $locations,
			'theme'     => wp_get_theme()->get( 'Name' ),
		)
	);
}

/**
 * REST endpoint: Assign a menu to a theme location.
 * Endpoint: POST /wp-json/wp-writer/v1/menu-locations
 * Body: { "location": "primary", "menu_id": 123 }
 * Set menu_id to 0 to unassign a menu from a location.
 *
 * @param WP_REST_Request $request The REST request.
 */
function wpm_connector_assign_menu_location( WP_REST_Request $request ) {
	$auth = wpm_connector_authenticate_request( $request );
	if ( is_wp_error( $auth ) ) {
		return $auth;
	}

	if ( ! current_user_can( 'edit_theme_options' ) ) {
		return new WP_Error( 'forbidden', 'Current user cannot manage menu locations.', array( 'status' => 403 ) );
	}

	$data     = $request->get_json_params();
	$location = isset( $data['location'] ) ? sanitize_text_field( $data['location'] ) : '';
	$menu_id  = isset( $data['menu_id'] ) ? (int) $data['menu_id'] : -1;

	if ( '' === $location ) {
		return new WP_Error( 'missing_location', 'Location slug is required.', array( 'status' => 400 ) );
	}
	if ( $menu_id < 0 ) {
		return new WP_Error( 'missing_menu_id', 'menu_id is required (0 to unassign).', array( 'status' => 400 ) );
	}

	$registered = get_registered_nav_menus();
	if ( ! isset( $registered[ $location ] ) ) {
		return new WP_Error(
			'invalid_location',
			sprintf( 'Location "%s" is not registered by the current theme. Available: %s', $location, implode( ', ', array_keys( $registered ) ) ),
			array( 'status' => 400 )
		);
	}

	if ( $menu_id > 0 ) {
		$menu_obj = wp_get_nav_menu_object( $menu_id );
		if ( ! $menu_obj ) {
			return new WP_Error( 'invalid_menu', sprintf( 'Menu ID %d does not exist.', $menu_id ), array( 'status' => 404 ) );
		}
	}

	$assignments              = get_nav_menu_locations();
	$assignments[ $location ] = $menu_id;
	set_theme_mod( 'nav_menu_locations', $assignments );

	$menu_name = '';
	if ( $menu_id > 0 ) {
		$menu_obj = wp_get_nav_menu_object( $menu_id );
		if ( $menu_obj ) {
			$menu_name = $menu_obj->name;
		}
	}

	return rest_ensure_response(
		array(
			'success'     => true,
			'location'    => $location,
			'description' => $registered[ $location ],
			'menu_id'     => $menu_id,
			'menu_name'   => $menu_name,
		)
	);
}

/**
 * The sidebars_widgets option as stored, without its array_version key. Malformed (non-array) storage reads as no widgets.
 *
 * Used for WRITES (adding or removing a widget), so a filter's view of the sidebars is never saved into the option.
 *
 * @return array Sidebar id => list of widget ids.
 */
function wpm_connector_sidebars_widgets_stored() {
	$sidebars = get_option( 'sidebars_widgets', array() );
	if ( ! is_array( $sidebars ) ) {
		$sidebars = array();
	}
	unset( $sidebars['array_version'] );
	return $sidebars;
}

/**
 * The sidebars as the site presents them: the stored option passed through WordPress's public sidebars_widgets filter, as
 * the core function wp_get_sidebars_widgets() does. Core's private in-request cache is not used; in a REST request it
 * holds the same stored option unless another plugin changed it within the request.
 *
 * Used for READS (the sidebar and widget lists).
 *
 * @return array Sidebar id => list of widget ids.
 */
function wpm_connector_sidebars_widgets_for_display() {
	// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WordPress core's own filter, applied as core applies it.
	$sidebars = apply_filters( 'sidebars_widgets', wpm_connector_sidebars_widgets_stored() );
	return is_array( $sidebars ) ? $sidebars : array();
}

/**
 * GET /wp-json/wp-writer/v1/sidebars
 * Lists all registered sidebar/widget areas with their widgets.
 *
 * @param WP_REST_Request $request The REST request.
 */
function wpm_connector_list_sidebars( WP_REST_Request $request ) {
	global $wp_registered_sidebars;

	$sidebars_widgets = wpm_connector_sidebars_widgets_for_display();
	$result           = array();

	foreach ( $wp_registered_sidebars as $id => $sidebar ) {
		$widgets  = ( isset( $sidebars_widgets[ $id ] ) && is_array( $sidebars_widgets[ $id ] ) ) ? $sidebars_widgets[ $id ] : array();
		$result[] = array(
			'id'           => $id,
			'name'         => $sidebar['name'],
			'description'  => $sidebar['description'],
			'widget_count' => count( $widgets ),
			'widget_ids'   => $widgets,
		);
	}

	return rest_ensure_response(
		array(
			'sidebars' => $result,
			'total'    => count( $result ),
		)
	);
}

/**
 * WordPress core widget types (id_base) whose "title" (and, for the Text widget, "text") are public display fields.
 *
 * @return string[]
 */
function wpm_connector_core_widget_types() {
	return array(
		'archives',
		'block',
		'calendar',
		'categories',
		'custom_html',
		'media_audio',
		'media_gallery',
		'media_image',
		'media_video',
		'meta',
		'nav_menu',
		'pages',
		'recent-comments',
		'recent-posts',
		'rss',
		'search',
		'tag_cloud',
		'text',
	);
}

/**
 * GET /wp-json/wp-writer/v1/widgets
 * Lists all active widgets across all sidebars: identity (id, type, sidebar) and, for WordPress core widget types only,
 * the public title and a plain-text excerpt of a Text widget. 1.15.0: stored widget settings are never returned (a
 * third-party widget may keep credentials in them), and other widget types get no title or content.
 *
 * @param WP_REST_Request $request The REST request.
 */
function wpm_connector_list_widgets( WP_REST_Request $request ) {
	$sidebars_widgets = wpm_connector_sidebars_widgets_for_display();
	$result           = array();

	foreach ( $sidebars_widgets as $sidebar_id => $widget_ids ) {
		if ( 'wp_inactive_widgets' === $sidebar_id || ! is_array( $widget_ids ) ) {
			continue;
		}

		foreach ( $widget_ids as $widget_id ) {
			// Parse widget type and instance number (e.g., "text-2" → type="text", number=2).
			if ( ! preg_match( '/^(.+)-(\d+)$/', $widget_id, $matches ) ) {
				continue;
			}
			$type   = $matches[1];
			$number = (int) $matches[2];

			$title   = '';
			$content = '';
			if ( in_array( $type, wpm_connector_core_widget_types(), true ) ) {
				$instances = get_option( "widget_{$type}", array() );
				$settings  = ( is_array( $instances ) && isset( $instances[ $number ] ) && is_array( $instances[ $number ] ) ) ? $instances[ $number ] : array();
				if ( isset( $settings['title'] ) && is_string( $settings['title'] ) ) {
					$title = wp_strip_all_tags( substr( $settings['title'], 0, 200 ) );
				}
				if ( 'text' === $type && isset( $settings['text'] ) && is_string( $settings['text'] ) ) {
					$content = wp_strip_all_tags( substr( $settings['text'], 0, 200 ) );
				}
			}

			$result[] = array(
				'widget_id'  => $widget_id,
				'type'       => $type,
				'sidebar_id' => $sidebar_id,
				'title'      => $title,
				'content'    => $content,
			);
		}
	}

	return rest_ensure_response(
		array(
			'widgets' => $result,
			'total'   => count( $result ),
		)
	);
}

/**
 * POST /wp-json/wp-writer/v1/widgets
 * Adds an HTML/text widget to a sidebar.
 * Body: { "sidebar_id": "sidebar-1", "title": "My Widget", "content": "<p>Hello</p>" }
 *
 * @param WP_REST_Request $request The REST request.
 */
function wpm_connector_add_widget( WP_REST_Request $request ) {
	$auth = wpm_connector_authenticate_request( $request );
	if ( is_wp_error( $auth ) ) {
		return $auth;
	}

	if ( ! current_user_can( 'edit_theme_options' ) ) {
		return new WP_Error( 'forbidden', 'Current user cannot manage widgets.', array( 'status' => 403 ) );
	}

	$sidebar_id = $request->get_param( 'sidebar_id' );
	$title      = $request->get_param( 'title' ) ? $request->get_param( 'title' ) : '';
	$content    = $request->get_param( 'content' );

	if ( empty( $sidebar_id ) || empty( $content ) ) {
		return new WP_REST_Response(
			array(
				'success' => false,
				'message' => 'Missing sidebar_id or content parameter.',
			),
			400
		);
	}

	// Verify sidebar exists.
	global $wp_registered_sidebars;
	if ( ! isset( $wp_registered_sidebars[ $sidebar_id ] ) ) {
		$available = array_keys( $wp_registered_sidebars );
		return new WP_REST_Response(
			array(
				'success' => false,
				'message' => "Sidebar not found: {$sidebar_id}. Available: " . implode( ', ', $available ),
			),
			404
		);
	}

	// Use the 'custom_html' widget type (available since WP 4.8.1).
	$widget_type = 'custom_html';
	$instances   = get_option( "widget_{$widget_type}", array() );

	// Find the next available instance number. WordPress stores a non-numeric '_multiwidget'
	// key in this option (and sometimes '__i__'), so the keys must be filtered to integers
	// first — max() over a mixed array returns the string, and '_multiwidget' + 1 is a fatal
	// TypeError on PHP 8, which is what made every add_widget call fail with a 500.
	$numbers     = array_filter( array_keys( $instances ), 'is_int' );
	$next_number = empty( $numbers ) ? 2 : max( $numbers ) + 1;

	// Save widget instance.
	$instances[ $next_number ] = array(
		'title'   => sanitize_text_field( $title ),
		'content' => $content, // Allow HTML.
	);
	update_option( "widget_{$widget_type}", $instances );

	// Add widget to sidebar.
	$sidebars_widgets = wpm_connector_sidebars_widgets_stored();
	$widget_id        = "{$widget_type}-{$next_number}";
	if ( ! isset( $sidebars_widgets[ $sidebar_id ] ) || ! is_array( $sidebars_widgets[ $sidebar_id ] ) ) {
		$sidebars_widgets[ $sidebar_id ] = array();
	}
	$sidebars_widgets[ $sidebar_id ][] = $widget_id;
	wp_set_sidebars_widgets( $sidebars_widgets );

	return rest_ensure_response(
		array(
			'success'    => true,
			'widget_id'  => $widget_id,
			'sidebar_id' => $sidebar_id,
			'title'      => $title,
			'message'    => "Widget added to {$wp_registered_sidebars[$sidebar_id]['name']}.",
		)
	);
}

/**
 * DELETE /wp-json/wp-writer/v1/widgets/{widget_id}
 * Removes a widget from its sidebar.
 *
 * @param WP_REST_Request $request The REST request.
 */
function wpm_connector_remove_widget( WP_REST_Request $request ) {
	$auth = wpm_connector_authenticate_request( $request );
	if ( is_wp_error( $auth ) ) {
		return $auth;
	}

	if ( ! current_user_can( 'edit_theme_options' ) ) {
		return new WP_Error( 'forbidden', 'Current user cannot manage widgets.', array( 'status' => 403 ) );
	}

	$widget_id = $request->get_param( 'widget_id' );
	if ( empty( $widget_id ) ) {
		return new WP_REST_Response(
			array(
				'success' => false,
				'message' => 'Missing widget_id.',
			),
			400
		);
	}

	// 1.15.0: only WordPress core widget instances are changed through the connector. Other widgets keep their own stored
	// settings, which may include credentials; remove them in wp-admin.
	$widget_type = preg_match( '/^(.+)-(\d+)$/', (string) $widget_id, $id_parts ) ? $id_parts[1] : '';
	if ( ! in_array( $widget_type, wpm_connector_core_widget_types(), true ) ) {
		return new WP_Error(
			'wpm_widget_not_core',
			'This connector changes only WordPress core widgets. Remove this widget in wp-admin (Appearance > Widgets).',
			array( 'status' => 403 )
		);
	}

	// Find and remove from sidebar.
	$sidebars_widgets = wpm_connector_sidebars_widgets_stored();
	$found_sidebar    = null;

	foreach ( $sidebars_widgets as $sidebar_id => &$widgets ) {
		if ( ! is_array( $widgets ) ) {
			continue;
		}
		$key = array_search( $widget_id, $widgets, true );
		if ( false !== $key ) {
			array_splice( $widgets, $key, 1 );
			$found_sidebar = $sidebar_id;
			break;
		}
	}
	unset( $widgets );

	if ( ! $found_sidebar ) {
		return new WP_REST_Response(
			array(
				'success' => false,
				'message' => "Widget not found: {$widget_id}",
			),
			404
		);
	}

	wp_set_sidebars_widgets( $sidebars_widgets );

	// Clean up widget instance data.
	if ( preg_match( '/^(.+)-(\d+)$/', $widget_id, $matches ) ) {
		$type      = $matches[1];
		$number    = (int) $matches[2];
		$instances = get_option( "widget_{$type}", array() );
		if ( isset( $instances[ $number ] ) ) {
			unset( $instances[ $number ] );
			update_option( "widget_{$type}", $instances );
		}
	}

	return rest_ensure_response(
		array(
			'success'    => true,
			'widget_id'  => $widget_id,
			'sidebar_id' => $found_sidebar,
			'message'    => "Widget removed from {$found_sidebar}.",
		)
	);
}

// ─── Display settings (1.15.0) ───────────────────────────────────────────────

/**
 * Who may use the display settings through the proxy's wp/v2/settings (after token authentication): reading needs
 * edit_posts; changing needs the paired
 * user to REALLY hold manage_options (recorded before the token capability limit applies).
 *
 * @param string $method HTTP method.
 * @return true|WP_Error
 */
function wpm_connector_display_settings_gate( $method ) {
	if ( in_array( strtoupper( (string) $method ), array( 'GET', 'HEAD' ), true ) ) {
		if ( ! current_user_can( 'edit_posts' ) ) {
			return new WP_Error(
				'wpm_forbidden',
				'The WordPress user this site is connected with does not have the "edit_posts" capability needed for this request.',
				array(
					'status'     => 403,
					'capability' => 'edit_posts',
				)
			);
		}
		return true;
	}
	if ( empty( $GLOBALS['wpm_connector_paired_can_manage_options'] ) ) {
		return new WP_Error(
			'wpm_forbidden',
			'Changing the site title, tagline, homepage and other display settings needs a WordPress Administrator. Pair the site as an Administrator, or make the change in wp-admin.',
			array(
				'status'     => 403,
				'capability' => 'manage_options',
			)
		);
	}
	return true;
}

/**
 * Display setting (core REST name) => WordPress option name.
 *
 * @return array<string,string>
 */
function wpm_connector_display_settings_options() {
	return array(
		'title'                  => 'blogname',
		'description'            => 'blogdescription',
		'show_on_front'          => 'show_on_front',
		'page_on_front'          => 'page_on_front',
		'page_for_posts'         => 'page_for_posts',
		'posts_per_page'         => 'posts_per_page',
		'date_format'            => 'date_format',
		'time_format'            => 'time_format',
		'timezone'               => 'timezone_string',
		'start_of_week'          => 'start_of_week',
		'site_icon'              => 'site_icon',
		'site_logo'              => 'site_logo',
		'default_comment_status' => 'default_comment_status',
		'default_ping_status'    => 'default_ping_status',
	);
}

/**
 * The current display settings, typed as WordPress core's REST settings would type them.
 *
 * @return array
 */
function wpm_connector_display_settings_values() {
	$ints = array( 'page_on_front', 'page_for_posts', 'posts_per_page', 'start_of_week', 'site_icon', 'site_logo' );
	$out  = array();
	foreach ( wpm_connector_display_settings_options() as $key => $option ) {
		$value       = get_option( $option );
		$out[ $key ] = in_array( $key, $ints, true ) ? (int) $value : (string) $value;
	}
	return $out;
}

/**
 * The proxy's GET wp/v2/settings: the display settings only.
 *
 * @param WP_REST_Request $request The REST request.
 * @return WP_REST_Response
 */
function wpm_connector_get_site_settings( WP_REST_Request $request ) {
	return rest_ensure_response( wpm_connector_display_settings_values() );
}

/**
 * A non-negative whole number from an int or a digits-only string, else null.
 *
 * @param mixed $value Value.
 * @return int|null
 */
function wpm_connector_settings_int( $value ) {
	if ( is_int( $value ) ) {
		return $value >= 0 ? $value : null;
	}
	if ( is_string( $value ) && preg_match( '/^[0-9]{1,10}$/', $value ) ) {
		return (int) $value;
	}
	return null;
}

/**
 * Validates one display setting. Returns the value to store, or a WP_Error describing why it was refused.
 *
 * @param string $key   Core REST name.
 * @param mixed  $value Requested value.
 * @return mixed|WP_Error
 */
function wpm_connector_validate_display_setting( $key, $value ) {
	switch ( $key ) {
		case 'title':
		case 'description':
		case 'date_format':
		case 'time_format':
			$is_format = ( 'date_format' === $key || 'time_format' === $key );
			if ( ! is_string( $value ) || strlen( $value ) > 200 || ( $is_format && '' === trim( $value ) ) ) {
				return new WP_Error( 'invalid', $is_format ? 'must be non-empty text of at most 200 characters' : 'must be text of at most 200 characters' );
			}
			$options = wpm_connector_display_settings_options();
			return sanitize_option( $options[ $key ], $value );
		case 'show_on_front':
			return in_array( $value, array( 'posts', 'page' ), true ) ? $value : new WP_Error( 'invalid', 'must be "posts" or "page"' );
		case 'page_on_front':
		case 'page_for_posts':
			$id = wpm_connector_settings_int( $value );
			if ( null === $id ) {
				return new WP_Error( 'invalid', 'must be a page ID (0 for none)' );
			}
			if ( $id > 0 ) {
				$page = get_post( $id );
				if ( ! $page || 'page' !== $page->post_type || 'trash' === $page->post_status ) {
					return new WP_Error( 'invalid', 'must be the ID of an existing page' );
				}
			}
			return $id;
		case 'posts_per_page':
			$n = wpm_connector_settings_int( $value );
			return ( null !== $n && $n >= 1 && $n <= 100 ) ? $n : new WP_Error( 'invalid', 'must be a whole number from 1 to 100' );
		case 'start_of_week':
			$n = wpm_connector_settings_int( $value );
			return ( null !== $n && $n <= 6 ) ? $n : new WP_Error( 'invalid', 'must be 0 (Sunday) to 6 (Saturday)' );
		case 'timezone':
			return ( is_string( $value ) && ( 'UTC' === $value || in_array( $value, timezone_identifiers_list(), true ) ) )
				? $value : new WP_Error( 'invalid', 'must be a timezone name such as "Europe/London" or "UTC"' );
		case 'site_icon':
		case 'site_logo':
			$id = wpm_connector_settings_int( $value );
			if ( null === $id ) {
				return new WP_Error( 'invalid', 'must be a media ID (0 for none)' );
			}
			if ( $id > 0 && ! wp_attachment_is_image( $id ) ) {
				return new WP_Error( 'invalid', 'must be the ID of an image in the media library' );
			}
			return $id;
		case 'default_comment_status':
		case 'default_ping_status':
			return in_array( $value, array( 'open', 'closed' ), true ) ? $value : new WP_Error( 'invalid', 'must be "open" or "closed"' );
	}
	return new WP_Error( 'invalid', 'is not a display setting' );
}

/**
 * The proxy's POST wp/v2/settings.
 *
 * Writes ONLY the display settings. Any other key refuses the whole request; every value is validated first and nothing
 * is written unless all of them are valid.
 *
 * @param WP_REST_Request $request The REST request.
 * @return WP_REST_Response|WP_Error
 */
function wpm_connector_update_site_settings( WP_REST_Request $request ) {
	$params = $request->get_json_params();
	if ( ! is_array( $params ) || empty( $params ) ) {
		$params = (array) $request->get_body_params();
	}
	// Every key of the request BODY must be a display setting: no prefix or transport exceptions. (REST metadata such as
	// _locale or _fields belongs in the query string, which this handler never reads as settings.)
	$changes = array();
	foreach ( $params as $key => $value ) {
		$changes[ (string) $key ] = $value;
	}
	if ( empty( $changes ) ) {
		return new WP_Error( 'wpm_settings_empty', 'No display setting to change was sent.', array( 'status' => 400 ) );
	}

	$allowed = wpm_connector_display_settings_keys();
	$refused = array_values( array_diff( array_keys( $changes ), $allowed ) );
	if ( ! empty( $refused ) ) {
		$refused = array_map( 'sanitize_key', $refused );
		return new WP_Error(
			'wpm_settings_key_refused',
			sprintf(
				'This connector changes only the site display settings (%1$s). Refused: %2$s. Make other changes in wp-admin. Nothing was changed.',
				implode( ', ', $allowed ),
				implode( ', ', $refused )
			),
			array(
				'status'  => 400,
				'refused' => $refused,
			)
		);
	}

	$clean  = array();
	$errors = array();
	foreach ( $changes as $key => $value ) {
		$result = wpm_connector_validate_display_setting( $key, $value );
		if ( is_wp_error( $result ) ) {
			$errors[ $key ] = $key . ' ' . $result->get_error_message();
		} else {
			$clean[ $key ] = $result;
		}
	}
	if ( ! empty( $errors ) ) {
		return new WP_Error(
			'wpm_settings_invalid',
			'Nothing was changed: ' . implode( '; ', $errors ) . '.',
			array(
				'status'  => 400,
				'invalid' => array_keys( $errors ),
			)
		);
	}

	$options = wpm_connector_display_settings_options();
	foreach ( $clean as $key => $value ) {
		update_option( $options[ $key ], $value );
	}
	return rest_ensure_response( wpm_connector_display_settings_values() );
}
