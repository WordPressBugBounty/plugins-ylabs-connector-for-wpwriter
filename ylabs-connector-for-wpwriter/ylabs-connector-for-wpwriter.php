<?php
/**
 * Plugin Name: YLabs Connector for WPWriter
 * Description: Connect WordPress to WPWriter for AI content, images, SEO, and scheduled auto-blogging.
 * Version: 1.12.1
 * Author: YLabs
 * Author URI: https://www.wpwriter.com
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: ylabs-connector-for-wpwriter
 * Requires at least: 5.6
 * Requires PHP: 7.4
 */

if (!defined('ABSPATH')) {
    exit;
}

define('WPM_CONNECTOR_VERSION', '1.12.1');

// Storage keys
const WPM_CONNECTOR_OPTION_CONNECTIONS = 'wpm_connector_connections';
const WPM_CONNECTOR_TRANSIENT_PAIRING = 'wpm_connector_pairing';
const WPM_CONNECTOR_OPTION_HUB_URL = 'wpm_connector_hub_url';

// Legacy keys (for migration from v1.4.0 and earlier)
const WPM_CONNECTOR_LEGACY_TOKEN_HASH = 'wpm_connector_token_hash';
const WPM_CONNECTOR_LEGACY_USER_ID = 'wpm_connector_user_id';

function wpm_connector_get_hub_url() {
    return get_option(WPM_CONNECTOR_OPTION_HUB_URL, 'https://www.wpwriter.com');
}

function wpm_connector_set_hub_url($url) {
    $url = esc_url_raw(trim($url));
    if ($url === '') {
        $url = 'https://www.wpwriter.com';
    }
    update_option(WPM_CONNECTOR_OPTION_HUB_URL, $url);
}

function wpm_connector_base64url($bytes) {
    return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
}

function wpm_connector_generate_id() {
    return substr(bin2hex(random_bytes(8)), 0, 12);
}

/**
 * Get all connections, migrating from legacy single-token format if needed.
 */
function wpm_connector_get_connections() {
    $connections = get_option(WPM_CONNECTOR_OPTION_CONNECTIONS, null);

    // If we have connections array, return it
    if (is_array($connections)) {
        return $connections;
    }

    // Check for legacy single-token format and migrate
    $legacy_hash = get_option(WPM_CONNECTOR_LEGACY_TOKEN_HASH, '');
    $legacy_user_id = (int) get_option(WPM_CONNECTOR_LEGACY_USER_ID, 0);

    if (!empty($legacy_hash) && $legacy_user_id > 0) {
        // Migrate to new format
        $connections = array(
            array(
                'id' => wpm_connector_generate_id(),
                'token_hash' => $legacy_hash,
                'wp_user_id' => $legacy_user_id,
                'label' => 'Migrated Connection',
                'created_at' => time(),
            )
        );

        // Save in new format
        update_option(WPM_CONNECTOR_OPTION_CONNECTIONS, $connections);

        // Remove legacy options
        delete_option(WPM_CONNECTOR_LEGACY_TOKEN_HASH);
        delete_option(WPM_CONNECTOR_LEGACY_USER_ID);

        return $connections;
    }

    // No connections
    return array();
}

/**
 * Save connections array.
 */
function wpm_connector_save_connections($connections) {
    update_option(WPM_CONNECTOR_OPTION_CONNECTIONS, $connections);
}

/**
 * Issue a new token and add to connections.
 */
function wpm_connector_issue_token($wp_user_id, $label) {
    $token = wpm_connector_base64url(random_bytes(32));
    $hash = hash('sha256', $token);

    $connections = wpm_connector_get_connections();
    $connections[] = array(
        'id' => wpm_connector_generate_id(),
        'token_hash' => $hash,
        'wp_user_id' => (int) $wp_user_id,
        'label' => sanitize_text_field($label),
        'created_at' => time(),
    );

    wpm_connector_save_connections($connections);

    return $token;
}

/**
 * Generate a pairing code for the current user.
 */
function wpm_connector_generate_pairing_code($user_id, $label = '') {
    $code = strtoupper(bin2hex(random_bytes(16))); // 32 chars
    $expires_in = 600; // 10 minutes

    $state = array(
        'code' => $code,
        'code_hash' => hash('sha256', $code),
        'user_id' => (int) $user_id,
        'label' => sanitize_text_field($label),
        'expires_at' => time() + $expires_in,
    );

    set_transient(WPM_CONNECTOR_TRANSIENT_PAIRING, $state, $expires_in);

    return $state;
}

/**
 * Verify a pairing code.
 */
function wpm_connector_verify_pairing_code($code) {
    if (!is_string($code) || trim($code) === '') {
        return new WP_Error('wpm_invalid_code', 'Invalid pairing code.', array('status' => 400));
    }

    $state = get_transient(WPM_CONNECTOR_TRANSIENT_PAIRING);
    if (!$state || !isset($state['code_hash'])) {
        return new WP_Error('wpm_code_expired', 'Pairing code expired or not found. Generate a new one.', array('status' => 400));
    }

    $candidate = hash('sha256', strtoupper(trim($code)));
    if (!hash_equals($state['code_hash'], $candidate)) {
        return new WP_Error('wpm_code_mismatch', 'Pairing code does not match. Check for typos or generate a new one.', array('status' => 400));
    }

    if (isset($state['expires_at']) && time() > $state['expires_at']) {
        delete_transient(WPM_CONNECTOR_TRANSIENT_PAIRING);
        return new WP_Error('wpm_code_expired', 'Pairing code has expired. Generate a new one.', array('status' => 400));
    }

    return $state;
}

/**
 * Get the current pairing state (if any).
 */
function wpm_connector_get_pairing_state() {
    $state = get_transient(WPM_CONNECTOR_TRANSIENT_PAIRING);
    if (!$state) {
        return null;
    }
    if (isset($state['expires_at']) && time() > $state['expires_at']) {
        delete_transient(WPM_CONNECTOR_TRANSIENT_PAIRING);
        return null;
    }
    return $state;
}

/**
 * Get connection count.
 */
function wpm_connector_get_connection_count() {
    return count(wpm_connector_get_connections());
}

/**
 * Disconnect a specific connection by ID.
 */
function wpm_connector_disconnect_by_id($connection_id) {
    $connections = wpm_connector_get_connections();
    $found = false;

    $connections = array_filter($connections, function($conn) use ($connection_id, &$found) {
        if (isset($conn['id']) && $conn['id'] === $connection_id) {
            $found = true;
            return false;
        }
        return true;
    });

    if ($found) {
        wpm_connector_save_connections(array_values($connections));
    }

    return $found;
}

/**
 * Disconnect all connections.
 */
function wpm_connector_disconnect_all() {
    wpm_connector_save_connections(array());
}

/**
 * Authenticate a request by checking the token against all connections.
 */
function wpm_connector_authenticate_request(WP_REST_Request $request) {
    $token = $request->get_header('x-wp-manager-token');

    if (!is_string($token) || trim($token) === '') {
        return new WP_Error('wpm_missing_token', 'Missing connector token.', array('status' => 401));
    }

    $connections = wpm_connector_get_connections();
    if (empty($connections)) {
        return new WP_Error('wpm_not_connected', 'Connector is not configured. Pair the site from the plugin page.', array('status' => 401));
    }

    $candidate_hash = hash('sha256', trim($token));
    $matched_connection = null;

    foreach ($connections as $conn) {
        if (isset($conn['token_hash']) && hash_equals($conn['token_hash'], $candidate_hash)) {
            $matched_connection = $conn;
            break;
        }
    }

    if (!$matched_connection) {
        return new WP_Error('wpm_invalid_token', 'Invalid connector token.', array('status' => 401));
    }

    $user_id = isset($matched_connection['wp_user_id']) ? (int) $matched_connection['wp_user_id'] : 0;
    if ($user_id <= 0 || !get_user_by('id', $user_id)) {
        return new WP_Error('wpm_invalid_user', 'Connector user is missing or invalid. Re-pair the site from the plugin page.', array('status' => 401));
    }

    wp_set_current_user($user_id);
    return $matched_connection;
}

/**
 * REST endpoint: Exchange pairing code for token.
 */
function wpm_connector_rest_pair(WP_REST_Request $request) {
    $data = $request->get_json_params();
    $pairing_code = '';

    if (is_array($data) && isset($data['pairingCode'])) {
        $pairing_code = $data['pairingCode'];
    }

    if ($pairing_code === '') {
        $pairing_code = $request->get_param('pairingCode');
    }

    $verified = wpm_connector_verify_pairing_code($pairing_code);
    if (is_wp_error($verified)) {
        return $verified;
    }

    $user_id = (int) $verified['user_id'];
    $label = isset($verified['label']) ? $verified['label'] : '';

    $token = wpm_connector_issue_token($user_id, $label);

    delete_transient(WPM_CONNECTOR_TRANSIENT_PAIRING);

    $user = get_user_by('id', $user_id);

    return new WP_REST_Response(array(
        'success' => true,
        'token' => $token,
        'user' => array(
            'username' => $user ? $user->user_login : null,
        ),
        'connectionCount' => wpm_connector_get_connection_count(),
    ), 200);
}

/**
 * REST endpoint: Proxy requests to WordPress REST API.
 */
function wpm_connector_rest_proxy(WP_REST_Request $request) {
    $auth = wpm_connector_authenticate_request($request);
    if (is_wp_error($auth)) {
        return $auth;
    }

    $path = $request->get_param('path');
    $method = $request->get_method();

    $wp_request = new WP_REST_Request($method, '/wp/v2/' . ltrim($path, '/'));

    foreach ($request->get_query_params() as $key => $value) {
        if ($key !== 'path') {
            $wp_request->set_param($key, $value);
        }
    }

    $body = $request->get_body();
    if ($body) {
        $wp_request->set_body($body);
        $ct = $request->get_header('content-type');
        if ($ct) {
            $wp_request->set_header('content-type', $ct);
        }
        // Forward Content-Disposition header for media uploads
        $cd = $request->get_header('content-disposition');
        if ($cd) {
            $wp_request->set_header('content-disposition', $cd);
        }
        $json = $request->get_json_params();
        if ($json) {
            foreach ($json as $key => $value) {
                $wp_request->set_param($key, $value);
            }
        }
    }

    $response = rest_do_request($wp_request);
    $server = rest_get_server();

    // Process _embed parameter to include related resources (e.g., featured images)
    // This is normally done by the REST server at the HTTP level, but we need to do it
    // manually when using rest_do_request() internally.
    $embed = $request->get_param('_embed');
    if ($embed !== null) {
        // Convert response to data with embedded links
        $data = $server->response_to_data($response, $embed);
    } else {
        $data = $response->get_data();
    }

    $status = $response->get_status();
    $headers = $response->get_headers();

    $out = new WP_REST_Response($data, $status);
    foreach ($headers as $key => $value) {
        $out->header($key, $value);
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
 */
function wpm_connector_rest_wc_proxy(WP_REST_Request $request) {
    $auth = wpm_connector_authenticate_request($request);
    if (is_wp_error($auth)) {
        return $auth;
    }

    if (!class_exists('WooCommerce')) {
        return new WP_Error('woocommerce_not_active', 'WooCommerce is not active on this site.', array('status' => 404));
    }

    $path = $request->get_param('path');
    $method = $request->get_method();

    $wc_request = new WP_REST_Request($method, '/wc/v3/' . ltrim($path, '/'));

    foreach ($request->get_query_params() as $key => $value) {
        if ($key !== 'path') {
            $wc_request->set_param($key, $value);
        }
    }

    $body = $request->get_body();
    if ($body) {
        $wc_request->set_body($body);
        $ct = $request->get_header('content-type');
        if ($ct) {
            $wc_request->set_header('content-type', $ct);
        }
        $json = $request->get_json_params();
        if ($json) {
            foreach ($json as $key => $value) {
                $wc_request->set_param($key, $value);
            }
        }
    }

    $response = rest_do_request($wc_request);
    $server = rest_get_server();
    $embed = $request->get_param('_embed');
    $data = $embed !== null ? $server->response_to_data($response, $embed) : $response->get_data();
    $status = $response->get_status();
    $headers = $response->get_headers();

    $out = new WP_REST_Response($data, $status);
    foreach ($headers as $key => $value) {
        $out->header($key, $value);
    }

    return $out;
}

/**
 * REST endpoint: Simple ping/health check.
 */
function wpm_connector_rest_ping(WP_REST_Request $request) {
    return new WP_REST_Response(array(
        'success' => true,
        'message' => 'WPWriter Connector is active.',
        'connectorVersion' => WPM_CONNECTOR_VERSION,
    ), 200);
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
 */
function wpm_connector_rest_status(WP_REST_Request $request) {
    $auth = wpm_connector_authenticate_request($request);
    if (is_wp_error($auth)) {
        return $auth;
    }

    $user = wp_get_current_user();
    return new WP_REST_Response(array(
        'success'          => true,
        'connectorVersion' => WPM_CONNECTOR_VERSION,
        'wpVersion'        => get_bloginfo('version'),
        'user'             => array(
            'id'       => $user->ID,
            'name'     => $user->display_name,
            'username' => $user->user_login,
            'roles'    => array_values($user->roles),
        ),
    ), 200);
}

/**
 * REST endpoint: Returns status information about the connector and detected SEO plugins.
 * Endpoint: GET /wp-json/wp-writer/v1/seo-meta-status
 */
function wpm_connector_seo_meta_status(WP_REST_Request $request) {
    $detected_plugins = array();

    // Check for Yoast SEO
    if (defined('WPSEO_VERSION') || class_exists('WPSEO_Options')) {
        $detected_plugins[] = array(
            'slug'    => 'yoast',
            'name'    => 'Yoast SEO',
            'version' => defined('WPSEO_VERSION') ? WPSEO_VERSION : 'unknown',
        );
    }

    // Check for Rank Math
    if (defined('RANK_MATH_VERSION') || class_exists('RankMath')) {
        $detected_plugins[] = array(
            'slug'    => 'rankmath',
            'name'    => 'Rank Math',
            'version' => defined('RANK_MATH_VERSION') ? RANK_MATH_VERSION : 'unknown',
        );
    }

    // Check for All in One SEO Pack (legacy version)
    if (defined('AIOSEOP_VERSION') || class_exists('All_in_One_SEO_Pack')) {
        $detected_plugins[] = array(
            'slug'    => 'aioseo',
            'name'    => 'All in One SEO',
            'version' => defined('AIOSEOP_VERSION') ? AIOSEOP_VERSION : 'unknown',
        );
    }

    // Check for All in One SEO (new version 4.x+)
    if (defined('AIOSEO_VERSION') || class_exists('AIOSEO\Plugin\AIOSEO')) {
        // Avoid duplicates if both old and new detected
        $already_added = false;
        foreach ($detected_plugins as $p) {
            if ($p['slug'] === 'aioseo') {
                $already_added = true;
                break;
            }
        }
        if (!$already_added) {
            $detected_plugins[] = array(
                'slug'    => 'aioseo',
                'name'    => 'All in One SEO',
                'version' => defined('AIOSEO_VERSION') ? AIOSEO_VERSION : 'unknown',
            );
        }
    }

    return rest_ensure_response(array(
        'connector_installed' => true,
        'connector_version'   => WPM_CONNECTOR_VERSION,
        'seo_plugins'         => $detected_plugins,
        'has_seo_plugin'      => !empty($detected_plugins),
    ));
}

/**
 * =====================================================
 * SEO META FIELDS - Expose Yoast/Rank Math/AIOSEO to REST API
 * =====================================================
 */

/**
 * Auth callback for SEO meta fields - only allow if user can edit the post.
 */
function wpm_connector_seo_meta_auth_callback($allowed, $meta_key, $post_id, $user_id, $cap, $caps) {
    return current_user_can('edit_post', $post_id);
}

/**
 * Register SEO meta fields for a post type to make them available via REST API.
 */
function wpm_connector_register_seo_meta_for_post_type($post_type) {
    $seo_keys = array(
        // Yoast SEO
        '_yoast_wpseo_title',
        '_yoast_wpseo_metadesc',
        '_yoast_wpseo_focuskw',
        // Rank Math
        'rank_math_title',
        'rank_math_description',
        'rank_math_focus_keyword',
        // All in One SEO
        '_aioseop_title',
        '_aioseop_description',
    );

    foreach ($seo_keys as $key) {
        register_post_meta($post_type, $key, array(
            'type'              => 'string',
            'single'            => true,
            'show_in_rest'      => true,
            'sanitize_callback' => 'sanitize_text_field',
            'auth_callback'     => 'wpm_connector_seo_meta_auth_callback',
        ));
    }
}

// Register SEO meta fields for all public post types on init
add_action('init', function () {
    $post_types = get_post_types(array('public' => true), 'names');
    if (empty($post_types) || !is_array($post_types)) {
        $post_types = array('post', 'page');
    }
    foreach ($post_types as $post_type) {
        wpm_connector_register_seo_meta_for_post_type($post_type);
    }
}, 20); // Priority 20 to run after post types are registered

/**
 * REST endpoint: Convert post type (post to page or vice versa).
 * Endpoint: POST /wp-json/wp-writer/v1/convert-post-type/{post_id}
 * Body: { "targetType": "post" | "page" }
 */
function wpm_connector_convert_post_type(WP_REST_Request $request) {
    $auth = wpm_connector_authenticate_request($request);
    if (is_wp_error($auth)) {
        return $auth;
    }

    $post_id = (int) $request->get_param('post_id');
    $target_type = $request->get_param('targetType');

    // Validate post_id
    if ($post_id <= 0) {
        return new WP_Error('invalid_post_id', 'Invalid post ID.', array('status' => 400));
    }

    // Validate target type
    if (!in_array($target_type, array('post', 'page'), true)) {
        return new WP_Error('invalid_target_type', 'Invalid target type. Must be "post" or "page".', array('status' => 400));
    }

    // Get the post
    $post = get_post($post_id);
    if (!$post) {
        return new WP_Error('post_not_found', 'Post not found.', array('status' => 404));
    }

    // Check permissions
    if (!current_user_can('edit_post', $post_id)) {
        return new WP_Error('forbidden', 'You do not have permission to edit this post.', array('status' => 403));
    }

    $previous_type = $post->post_type;

    // Check if already the target type
    if ($previous_type === $target_type) {
        return rest_ensure_response(array(
            'success' => true,
            'postId' => $post_id,
            'previousType' => $previous_type,
            'postType' => $target_type,
            'message' => sprintf('Content is already a %s.', $target_type),
        ));
    }

    // Only allow conversion between post and page
    if (!in_array($previous_type, array('post', 'page'), true)) {
        return new WP_Error(
            'invalid_conversion',
            sprintf('Cannot convert "%s" to "%s". Only posts and pages can be converted.', $previous_type, $target_type),
            array('status' => 400)
        );
    }

    // Update the post type using wp_update_post
    $result = wp_update_post(array(
        'ID' => $post_id,
        'post_type' => $target_type,
    ), true);

    if (is_wp_error($result)) {
        return new WP_Error(
            'update_failed',
            sprintf('Failed to convert post type: %s', $result->get_error_message()),
            array('status' => 500)
        );
    }

    return rest_ensure_response(array(
        'success' => true,
        'postId' => $post_id,
        'previousType' => $previous_type,
        'postType' => $target_type,
        'message' => sprintf('Successfully converted from %s to %s.', $previous_type, $target_type),
    ));
}

add_action('rest_api_init', function () {
    register_rest_route('wp-manager/v1', '/pair', array(
        'methods' => 'POST',
        'callback' => 'wpm_connector_rest_pair',
        'permission_callback' => '__return_true',
    ));

    register_rest_route('wp-manager/v1', '/ping', array(
        'methods' => 'GET',
        'callback' => 'wpm_connector_rest_ping',
        'permission_callback' => '__return_true',
    ));

    register_rest_route('wp-manager/v1', '/status', array(
        'methods' => 'GET',
        'callback' => 'wpm_connector_rest_status',
        // Auth happens inside the callback via the connector token header,
        // same pattern as the proxy routes.
        'permission_callback' => '__return_true',
    ));

    register_rest_route('wp-manager/v1', '/proxy/wp/v2/(?P<path>.*)', array(
        'methods' => WP_REST_Server::ALLMETHODS,
        'callback' => 'wpm_connector_rest_proxy',
        'permission_callback' => '__return_true',
        'args' => array(
            'path' => array(
                'required' => true,
                'type' => 'string',
            ),
        ),
    ));

    register_rest_route('wp-manager/v1', '/proxy/wc/v3/(?P<path>.*)', array(
        'methods' => WP_REST_Server::ALLMETHODS,
        'callback' => 'wpm_connector_rest_wc_proxy',
        'permission_callback' => '__return_true',
        'args' => array(
            'path' => array(
                'required' => true,
                'type' => 'string',
            ),
        ),
    ));

    // SEO Meta Status endpoint - detects installed SEO plugins
    // Public endpoint - only returns which SEO plugins are installed (not sensitive)
    register_rest_route('wp-writer/v1', '/seo-meta-status', array(
        'methods' => 'GET',
        'callback' => 'wpm_connector_seo_meta_status',
        'permission_callback' => '__return_true',
    ));

    // Convert post type endpoint - convert post to page or vice versa
    register_rest_route('wp-writer/v1', '/convert-post-type/(?P<post_id>\d+)', array(
        'methods' => 'POST',
        'callback' => 'wpm_connector_convert_post_type',
        'permission_callback' => '__return_true',
        'args' => array(
            'post_id' => array(
                'required' => true,
                'type' => 'integer',
                'sanitize_callback' => 'absint',
            ),
        ),
    ));

    // Theme status endpoint - returns active theme info for design recommendations
    register_rest_route('wp-writer/v1', '/theme-status', array(
        'methods' => 'GET',
        'callback' => 'wpm_connector_theme_status',
        'permission_callback' => '__return_true',
    ));

    // Install whitelisted plugin from WordPress.org
    register_rest_route('wp-writer/v1', '/install-plugin', array(
        'methods' => 'POST',
        'callback' => 'wpm_connector_install_plugin',
        'permission_callback' => '__return_true',
        'args' => array(
            'slug' => array(
                'required' => true,
                'type' => 'string',
                'sanitize_callback' => 'sanitize_text_field',
            ),
        ),
    ));

    // Install whitelisted theme (WordPress.org or remote zip)
    register_rest_route('wp-writer/v1', '/install-theme', array(
        'methods' => 'POST',
        'callback' => 'wpm_connector_install_theme',
        'permission_callback' => '__return_true',
        'args' => array(
            'slug' => array(
                'required' => true,
                'type' => 'string',
                'sanitize_callback' => 'sanitize_text_field',
            ),
        ),
    ));

    // Menu locations — list available theme locations and their assigned menus
    register_rest_route('wp-writer/v1', '/menu-locations', array(
        array(
            'methods' => 'GET',
            'callback' => 'wpm_connector_list_menu_locations',
            'permission_callback' => '__return_true',
        ),
        array(
            'methods' => 'POST',
            'callback' => 'wpm_connector_assign_menu_location',
            'permission_callback' => '__return_true',
        ),
    ));

    register_rest_route('wp-writer/v1', '/custom-css', array(
        array(
            'methods' => 'GET',
            'callback' => 'wpm_connector_get_custom_css',
            'permission_callback' => '__return_true',
        ),
        array(
            'methods' => 'POST',
            'callback' => 'wpm_connector_update_custom_css',
            'permission_callback' => '__return_true',
            'args' => array(
                'css' => array(
                    'required' => true,
                    'type' => 'string',
                ),
                'mode' => array(
                    'required' => false,
                    'type' => 'string',
                    'default' => 'replace',
                    'sanitize_callback' => 'sanitize_text_field',
                ),
            ),
        ),
    ));

    // Permalink structure
    register_rest_route('wp-writer/v1', '/permalink-structure', array(
        array(
            'methods' => 'GET',
            'callback' => 'wpm_connector_get_permalink_structure',
            'permission_callback' => '__return_true',
        ),
        array(
            'methods' => 'POST',
            'callback' => 'wpm_connector_set_permalink_structure',
            'permission_callback' => '__return_true',
        ),
    ));

    // Plugin management
    register_rest_route('wp-writer/v1', '/plugins', array(
        'methods' => 'GET',
        'callback' => 'wpm_connector_list_plugins',
        'permission_callback' => '__return_true',
    ));

    register_rest_route('wp-writer/v1', '/plugin-status', array(
        'methods' => 'POST',
        'callback' => 'wpm_connector_toggle_plugin',
        'permission_callback' => '__return_true',
    ));

    // Widget / sidebar management
    register_rest_route('wp-writer/v1', '/sidebars', array(
        'methods' => 'GET',
        'callback' => 'wpm_connector_list_sidebars',
        'permission_callback' => '__return_true',
    ));

    register_rest_route('wp-writer/v1', '/widgets', array(
        array(
            'methods' => 'GET',
            'callback' => 'wpm_connector_list_widgets',
            'permission_callback' => '__return_true',
        ),
        array(
            'methods' => 'POST',
            'callback' => 'wpm_connector_add_widget',
            'permission_callback' => '__return_true',
        ),
    ));

    register_rest_route('wp-writer/v1', '/widgets/(?P<widget_id>[a-zA-Z0-9_-]+)', array(
        'methods' => 'DELETE',
        'callback' => 'wpm_connector_remove_widget',
        'permission_callback' => '__return_true',
    ));

    // WP 7.0 Connectors — discover/import AI provider keys saved in Settings → Connectors
    register_rest_route('wp-writer/v1', '/connector-keys', array(
        'methods' => 'GET',
        'callback' => 'wpm_connector_connector_keys',
        'permission_callback' => '__return_true',
    ));

    register_rest_route('wp-writer/v1', '/connector-keys/reveal', array(
        'methods' => 'POST',
        'callback' => 'wpm_connector_connector_keys_reveal',
        'permission_callback' => '__return_true',
        'args' => array(
            'provider' => array(
                'required' => true,
                'type' => 'string',
                'sanitize_callback' => 'sanitize_text_field',
            ),
        ),
    ));
});

/**
 * REST endpoint: Returns information about the active WordPress theme.
 * Endpoint: GET /wp-json/wp-writer/v1/theme-status
 * Public endpoint - only returns theme name and type (not sensitive).
 */
function wpm_connector_theme_status(WP_REST_Request $request) {
    $theme = wp_get_theme();
    $parent = $theme->parent();

    $result = array(
        'active_theme'   => $theme->get('Name'),
        'theme_slug'     => $theme->get_stylesheet(),
        'theme_version'  => $theme->get('Version'),
        'is_child_theme' => $parent !== false,
    );

    if ($parent !== false) {
        $result['parent_theme']      = $parent->get('Name');
        $result['parent_theme_slug'] = $parent->get_stylesheet();
    }

    // Detect if WPWriter theme is active
    $stylesheet = strtolower($theme->get_stylesheet());
    $parent_stylesheet = $parent ? strtolower($parent->get_stylesheet()) : '';
    $result['is_wpwriter_theme'] = (
        strpos($stylesheet, 'wpwriter') !== false ||
        strpos($stylesheet, 'wp-writer') !== false
    );
    $result['is_astra'] = (
        $stylesheet === 'astra' || $parent_stylesheet === 'astra'
    );

    // Detect if it's a default/basic WordPress theme
    $default_themes = array(
        'twentytwentyfive', 'twentytwentyfour', 'twentytwentythree',
        'twentytwentytwo', 'twentytwentyone', 'twentytwenty',
        'twentynineteen', 'twentyseventeen', 'twentysixteen',
        'twentyfifteen', 'twentyfourteen', 'twentythirteen',
        'twentytwelve', 'twentyeleven', 'twentyten',
    );
    $check_slug = $parent ? $parent_stylesheet : $stylesheet;
    $result['is_default_theme'] = in_array($check_slug, $default_themes, true);

    $result['connector_version'] = WPM_CONNECTOR_VERSION;

    return new WP_REST_Response($result, 200);
}

/**
 * Whitelisted plugins that can be installed via the connector.
 * Key = slug used in API request, Value = slug on WordPress.org.
 */
function wpm_connector_get_plugin_whitelist() {
    return array(
        'wordpress-seo'   => 'wordpress-seo',   // Yoast SEO
        'classic-editor'  => 'classic-editor',   // Classic Editor
        'simple-lightbox' => 'simple-lightbox',  // Simple Lightbox
        'wordfence'       => 'wordfence',        // Wordfence Security
        'wp-mail-smtp'    => 'wp-mail-smtp',     // WP Mail SMTP
    );
}

/**
 * Whitelisted themes that can be installed via the connector.
 * 'source' is 'directory' (WordPress.org) or 'url' (remote zip).
 */
function wpm_connector_get_theme_whitelist() {
    $hub_url = wpm_connector_get_hub_url();
    return array(
        'astra' => array(
            'name'   => 'Astra',
            'source' => 'directory',
        ),
        'wpwriter' => array(
            'name'   => 'WPWriter Theme',
            'source' => 'url',
            'url'    => $hub_url . '/assets/wpwriter-theme.zip',
        ),
        'wpwriter-theme' => array(
            'name'        => 'WPWriter Theme',
            'source'      => 'url',
            'url'         => $hub_url . '/assets/wpwriter-theme.zip',
            'actual_slug' => 'wpwriter',
        ),
    );
}

/**
 * REST endpoint: Install a whitelisted plugin from WordPress.org.
 * Endpoint: POST /wp-json/wp-writer/v1/install-plugin
 * Body: { "slug": "wordpress-seo", "activate": true }
 */
function wpm_connector_install_plugin(WP_REST_Request $request) {
    $auth = wpm_connector_authenticate_request($request);
    if (is_wp_error($auth)) {
        return $auth;
    }

    // Require install_plugins capability
    if (!current_user_can('install_plugins')) {
        return new WP_Error('forbidden', 'Current user cannot install plugins.', array('status' => 403));
    }

    $data = $request->get_json_params();
    $slug = isset($data['slug']) ? sanitize_text_field($data['slug']) : '';
    $activate = isset($data['activate']) ? (bool) $data['activate'] : true;

    if ($slug === '') {
        return new WP_Error('missing_slug', 'Plugin slug is required.', array('status' => 400));
    }

    // Any plugin from WordPress.org can be installed (no whitelist)
    // Check if plugin is already installed
    if (!function_exists('get_plugins')) {
        require_once ABSPATH . 'wp-admin/includes/plugin.php';
    }
    $all_plugins = get_plugins();
    $plugin_file = null;
    foreach ($all_plugins as $file => $info) {
        if (strpos($file, $slug . '/') === 0) {
            $plugin_file = $file;
            break;
        }
    }

    if ($plugin_file) {
        $is_active = is_plugin_active($plugin_file);
        if ($activate && !$is_active) {
            $result = activate_plugin($plugin_file);
            if (is_wp_error($result)) {
                return new WP_Error('activation_failed', $result->get_error_message(), array('status' => 500));
            }
            return rest_ensure_response(array(
                'success' => true,
                'slug' => $slug,
                'action' => 'activated',
                'message' => sprintf('Plugin "%s" was already installed. Activated successfully.', $slug),
            ));
        }
        return rest_ensure_response(array(
            'success' => true,
            'slug' => $slug,
            'action' => 'already_installed',
            'active' => $is_active,
            'message' => sprintf('Plugin "%s" is already installed%s.', $slug, $is_active ? ' and active' : ''),
        ));
    }

    // Install from WordPress.org
    // In REST API context, wp-admin includes are not loaded — bootstrap the full admin environment
    require_once ABSPATH . 'wp-admin/includes/admin.php';
    require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
    require_once ABSPATH . 'wp-admin/includes/plugin-install.php';

    $api = plugins_api('plugin_information', array(
        'slug'   => $slug,
        'fields' => array('sections' => false),
    ));
    if (is_wp_error($api)) {
        return new WP_Error('api_error', 'Could not fetch plugin info: ' . $api->get_error_message(), array('status' => 502));
    }

    $skin = new WP_Ajax_Upgrader_Skin();
    $upgrader = new Plugin_Upgrader($skin);
    $result = $upgrader->install($api->download_link);

    if (is_wp_error($result)) {
        return new WP_Error('install_failed', $result->get_error_message(), array('status' => 500));
    }
    if ($result === false) {
        $errors = $skin->get_errors();
        $msg = is_wp_error($errors) ? $errors->get_error_message() : 'Unknown installation error.';
        return new WP_Error('install_failed', $msg, array('status' => 500));
    }

    // Re-scan plugins to find the installed file
    wp_cache_delete('plugins', 'plugins');
    $all_plugins = get_plugins();
    $plugin_file = null;
    foreach ($all_plugins as $file => $info) {
        if (strpos($file, $slug . '/') === 0) {
            $plugin_file = $file;
            break;
        }
    }

    if ($activate && $plugin_file) {
        $act = activate_plugin($plugin_file);
        if (is_wp_error($act)) {
            return rest_ensure_response(array(
                'success' => true,
                'slug' => $slug,
                'action' => 'installed',
                'active' => false,
                'message' => sprintf('Plugin "%s" installed but activation failed: %s', $slug, $act->get_error_message()),
            ));
        }
    }

    return rest_ensure_response(array(
        'success' => true,
        'slug' => $slug,
        'action' => 'installed',
        'active' => $activate && $plugin_file,
        'message' => sprintf('Plugin "%s" installed%s.', $slug, ($activate && $plugin_file) ? ' and activated' : ''),
    ));
}

/**
 * REST endpoint: Install a whitelisted theme.
 * Endpoint: POST /wp-json/wp-writer/v1/install-theme
 * Body: { "slug": "astra", "activate": false }
 */
function wpm_connector_install_theme(WP_REST_Request $request) {
    $auth = wpm_connector_authenticate_request($request);
    if (is_wp_error($auth)) {
        return $auth;
    }

    if (!current_user_can('install_themes')) {
        return new WP_Error('forbidden', 'Current user cannot install themes.', array('status' => 403));
    }

    $data = $request->get_json_params();
    $slug = isset($data['slug']) ? sanitize_text_field($data['slug']) : '';
    $activate = isset($data['activate']) ? (bool) $data['activate'] : false;

    if ($slug === '') {
        return new WP_Error('missing_slug', 'Theme slug is required.', array('status' => 400));
    }

    // WPWriter theme has special handling (installed from our server)
    $wpwriter_themes = wpm_connector_get_theme_whitelist();
    $is_wpwriter_theme = isset($wpwriter_themes[$slug]);
    $theme_info = $is_wpwriter_theme ? $wpwriter_themes[$slug] : array('name' => $slug, 'source' => 'directory');

    // Resolve the actual filesystem slug for themes where the requested slug
    // differs from the zip folder name (e.g., "wpwriter-theme" → "wpwriter")
    $actual_slug = isset($theme_info['actual_slug']) ? $theme_info['actual_slug'] : $slug;

    // Check if theme is already installed
    $installed_theme = wp_get_theme($actual_slug);
    if ($installed_theme->exists()) {
        if ($activate) {
            switch_theme($actual_slug);
            return rest_ensure_response(array(
                'success' => true,
                'slug' => $actual_slug,
                'action' => 'activated',
                'message' => sprintf('Theme "%s" was already installed. Activated successfully.', $theme_info['name']),
            ));
        }
        $current = wp_get_theme();
        return rest_ensure_response(array(
            'success' => true,
            'slug' => $actual_slug,
            'action' => 'already_installed',
            'active' => ($current->get_stylesheet() === $actual_slug),
            'message' => sprintf('Theme "%s" is already installed.', $theme_info['name']),
        ));
    }

    // Install the theme
    // In REST API context, wp-admin includes are not loaded — bootstrap the full admin environment
    require_once ABSPATH . 'wp-admin/includes/admin.php';
    require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
    require_once ABSPATH . 'wp-admin/includes/theme-install.php';

    $skin = new WP_Ajax_Upgrader_Skin();
    $upgrader = new Theme_Upgrader($skin);

    if ($theme_info['source'] === 'directory') {
        $api = themes_api('theme_information', array(
            'slug'   => $actual_slug,
            'fields' => array('sections' => false),
        ));
        if (is_wp_error($api)) {
            return new WP_Error('api_error', 'Could not fetch theme info: ' . $api->get_error_message(), array('status' => 502));
        }
        $download_url = $api->download_link;
    } else {
        $download_url = $theme_info['url'];
    }

    $result = $upgrader->install($download_url);

    if (is_wp_error($result)) {
        return new WP_Error('install_failed', $result->get_error_message(), array('status' => 500));
    }
    if ($result === false) {
        $errors = $skin->get_errors();
        $msg = is_wp_error($errors) ? $errors->get_error_message() : 'Unknown installation error.';
        return new WP_Error('install_failed', $msg, array('status' => 500));
    }

    // Detect the actual installed directory from the upgrader result
    $installed_slug = $actual_slug;
    if (isset($upgrader->result) && !empty($upgrader->result['destination_name'])) {
        $installed_slug = $upgrader->result['destination_name'];
    }

    if ($activate) {
        switch_theme($installed_slug);
    }

    return rest_ensure_response(array(
        'success' => true,
        'slug' => $installed_slug,
        'action' => 'installed',
        'active' => $activate,
        'message' => sprintf('Theme "%s" installed%s.', $theme_info['name'], $activate ? ' and activated' : ''),
    ));
}

/**
 * Admin page.
 */
function wpm_connector_admin_page() {
    if (!current_user_can('manage_options')) {
        return;
    }

    $notice = '';
    $notice_type = 'updated';

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['wpm_action'])) {
        check_admin_referer('wpm_connector_admin');
        $action = sanitize_text_field(wp_unslash($_POST['wpm_action']));

        if ($action === 'save_settings') {
            $hub_url = isset($_POST['wpm_hub_url']) ? sanitize_text_field(wp_unslash($_POST['wpm_hub_url'])) : '';
            wpm_connector_set_hub_url($hub_url);
            $notice = 'Settings saved.';
        } elseif ($action === 'generate_pairing') {
            $label = isset($_POST['wpm_label']) ? trim(sanitize_text_field(wp_unslash($_POST['wpm_label']))) : '';
            if ($label === '') {
                $notice = 'Connection name is required.';
                $notice_type = 'error';
            } else {
                wpm_connector_generate_pairing_code(get_current_user_id(), $label);
                $notice = 'Pairing code generated. It expires in 10 minutes.';
            }
        } elseif ($action === 'cancel_pairing') {
            delete_transient(WPM_CONNECTOR_TRANSIENT_PAIRING);

            // Also remove any connection created from this pairing attempt.
            // The label is passed via hidden form field (the transient may already
            // be consumed if the pairing code was exchanged).
            $cancel_label = isset($_POST['pairing_label']) ? sanitize_text_field(wp_unslash($_POST['pairing_label'])) : '';
            $cancel_user = get_current_user_id();
            $removed_stale = false;

            if ($cancel_label !== '') {
                $connections = wpm_connector_get_connections();
                $filtered = array();
                foreach ($connections as $conn) {
                    $conn_label = isset($conn['label']) ? $conn['label'] : '';
                    $conn_user = isset($conn['wp_user_id']) ? (int) $conn['wp_user_id'] : 0;

                    if ($conn_label === $cancel_label && $conn_user === $cancel_user) {
                        $removed_stale = true;
                        continue; // remove this connection
                    }
                    $filtered[] = $conn;
                }

                if ($removed_stale) {
                    wpm_connector_save_connections(array_values($filtered));
                }
            }

            $notice = $removed_stale
                ? 'Pairing cancelled and connection removed.'
                : 'Pairing code cancelled.';
        } elseif ($action === 'disconnect_all') {
            wpm_connector_disconnect_all();
            $notice = 'All connections disconnected.';
        } elseif ($action === 'disconnect_one') {
            $conn_id = isset($_POST['connection_id']) ? sanitize_text_field(wp_unslash($_POST['connection_id'])) : '';
            if ($conn_id !== '' && wpm_connector_disconnect_by_id($conn_id)) {
                $notice = 'Connection disconnected.';
            } else {
                $notice = 'Connection not found.';
                $notice_type = 'error';
            }
        }
    }

    $hub_url = wpm_connector_get_hub_url();
    $pairing = wpm_connector_get_pairing_state();
    $connections = wpm_connector_get_connections();
    $connection_count = count($connections);

    echo '<div class="wrap">';
    echo '<h1>WPWriter Connector</h1>';

    if ($notice) {
        echo '<div class="notice ' . esc_attr($notice_type) . ' is-dismissible"><p>' . esc_html($notice) . '</p></div>';
    }

    // Status Section
    echo '<h2>Status</h2>';
    echo '<div style="display: flex; align-items: center; gap: 15px; margin-bottom: 10px;">';
    if ($connection_count === 0) {
        echo '<span><strong style="color: #999;">Not connected</strong></span>';
    } elseif ($connection_count === 1) {
        echo '<span><strong style="color: #46b450;">Connected</strong> — 1 WPWriter account</span>';
    } else {
        echo '<span><strong style="color: #46b450;">Connected</strong> — ' . esc_html($connection_count) . ' WPWriter accounts</span>';
    }
    echo '<a href="' . esc_url(admin_url('admin.php?page=wpm-connector')) . '" class="button button-small">↻ Refresh Status</a>';
    echo '</div>';

    // Connected Accounts Table
    if ($connection_count > 0) {
        echo '<table class="wp-list-table widefat fixed striped" style="max-width: 700px; margin-top: 15px;">';
        echo '<thead><tr>';
        echo '<th style="width: 35%;">Account</th>';
        echo '<th style="width: 25%;">WordPress User</th>';
        echo '<th style="width: 25%;">Connected</th>';
        echo '<th style="width: 15%;">Action</th>';
        echo '</tr></thead><tbody>';

        foreach ($connections as $conn) {
            $conn_id = isset($conn['id']) ? $conn['id'] : '';
            $label = isset($conn['label']) ? $conn['label'] : 'Unknown';
            $wp_user_id = isset($conn['wp_user_id']) ? (int) $conn['wp_user_id'] : 0;
            $created_at = isset($conn['created_at']) ? (int) $conn['created_at'] : 0;

            $wp_user = $wp_user_id > 0 ? get_user_by('id', $wp_user_id) : null;
            $wp_username = $wp_user ? $wp_user->user_login : '<em>unknown</em>';
            $created_str = $created_at > 0 ? human_time_diff($created_at, time()) . ' ago' : 'Unknown';

            echo '<tr>';
            echo '<td><strong>' . esc_html($label) . '</strong></td>';
            echo '<td><code>' . wp_kses($wp_username, array('em' => array())) . '</code></td>';
            echo '<td>' . esc_html($created_str) . '</td>';
            echo '<td>';
            echo '<form method="post" style="display:inline;">';
            wp_nonce_field('wpm_connector_admin');
            echo '<input type="hidden" name="wpm_action" value="disconnect_one">';
            echo '<input type="hidden" name="connection_id" value="' . esc_attr($conn_id) . '">';
            echo '<button type="submit" class="button button-small" onclick="return confirm(\'Disconnect this account?\');">Disconnect</button>';
            echo '</form>';
            echo '</td>';
            echo '</tr>';
        }

        echo '</tbody></table>';

        if ($connection_count > 1) {
            echo '<form method="post" style="margin-top: 10px;">';
            wp_nonce_field('wpm_connector_admin');
            echo '<input type="hidden" name="wpm_action" value="disconnect_all">';
            echo '<button type="submit" class="button" onclick="return confirm(\'Disconnect ALL accounts?\');">Disconnect All</button>';
            echo '</form>';
        }
    }

    // Connect New Account Section
    echo '<hr style="margin: 25px 0;">';
    echo '<h2>Connect New Account</h2>';
    echo '<p>Generate a pairing code and paste it in WPWriter to connect.</p>';

    echo '<form method="post" style="margin-bottom: 15px;">';
    wp_nonce_field('wpm_connector_admin');
    echo '<input type="hidden" name="wpm_action" value="generate_pairing">';
    echo '<div style="display: flex; gap: 10px; align-items: center; margin-bottom: 10px;">';
    echo '<input type="text" name="wpm_label" placeholder="e.g., My Laptop, Office PC" style="width: 250px;" required>';
    echo '<button type="submit" class="button button-primary">Generate Pairing Code</button>';
    echo '</div>';
    echo '<p class="description">Name helps identify this connection in the list above (required).</p>';
    echo '</form>';

    if ($pairing && isset($pairing['code'])) {
        $expires_in = isset($pairing['expires_at']) ? max(0, $pairing['expires_at'] - time()) : 0;
        $expires_min = ceil($expires_in / 60);
        $label_display = isset($pairing['label']) ? $pairing['label'] : '';

        echo '<div style="background: #f0f0f1; padding: 15px; border-left: 4px solid #2271b1; margin: 15px 0; max-width: 500px;">';
        echo '<p style="margin: 0 0 5px;"><strong>Connection name:</strong> ' . esc_html($label_display) . '</p>';
        echo '<p style="margin: 0 0 10px;"><strong>Pairing code</strong> (expires in ~' . esc_html($expires_min) . ' min):</p>';
        echo '<div style="display: flex; gap: 10px; align-items: center;">';
        echo '<input type="text" id="wpm-pairing-code" value="' . esc_attr($pairing['code']) . '" readonly style="font-family: monospace; font-size: 14px; padding: 8px; width: 320px;">';
        echo '<button type="button" class="button" onclick="navigator.clipboard.writeText(document.getElementById(\'wpm-pairing-code\').value); this.textContent=\'Copied!\'; setTimeout(() => this.textContent=\'Copy\', 2000);">Copy</button>';
        echo '<form method="post" style="display:inline; margin:0;">';
        wp_nonce_field('wpm_connector_admin');
        echo '<input type="hidden" name="wpm_action" value="cancel_pairing">';
        echo '<input type="hidden" name="pairing_label" value="' . esc_attr($label_display) . '">';
        echo '<button type="submit" class="button" style="color: #b32d2e;">Cancel</button>';
        echo '</form>';
        echo '</div>';
        echo '<p style="margin: 10px 0 0; color: #666; font-size: 13px;">💡 After pasting the code in WPWriter, click <strong>Refresh Status</strong> above to see your new connection.</p>';
        echo '</div>';
    }

    // Settings Section
    echo '<hr style="margin: 25px 0;">';
    echo '<h2>Settings</h2>';
    echo '<table class="form-table"><tbody>';
    echo '<tr><th scope="row">App URL</th>';
    echo '<td><code>' . esc_html($hub_url) . '</code>';
    echo '<p class="description">Where the WPWriter app is hosted.</p></td></tr>';
    echo '</tbody></table>';

    // Technical Section
    echo '<hr style="margin: 25px 0;">';
    echo '<h2>Technical</h2>';
    echo '<p><strong>Version:</strong> ' . esc_html(WPM_CONNECTOR_VERSION) . '</p>';
    echo '<p><strong>API endpoints:</strong></p>';
    echo '<ul style="list-style: disc; margin-left: 20px;">';
    echo '<li><code>' . esc_html(home_url('/wp-json/wp-manager/v1/pair')) . '</code> (POST)</li>';
    echo '<li><code>' . esc_html(home_url('/wp-json/wp-manager/v1/ping')) . '</code> (GET)</li>';
    echo '<li><code>' . esc_html(home_url('/wp-json/wp-manager/v1/proxy/wp/v2/...')) . '</code> (ALL)</li>';
    echo '</ul>';

    echo '</div>';
}

add_action('admin_menu', function () {
    add_menu_page(
        'WPWriter Connector',
        'WPWriter',
        'manage_options',
        'wpm-connector',
        'wpm_connector_admin_page',
        'dashicons-admin-links',
        80
    );
});

add_filter('plugin_action_links_' . plugin_basename(__FILE__), function ($links) {
    $settings_link = '<a href="' . admin_url('admin.php?page=wpm-connector') . '">Settings</a>';
    array_unshift($links, $settings_link);
    return $links;
});

// ─── WP360 Product Spin Viewer Shortcode ───────────────────────────────────────
//
// [wp360 ids="101,102,103" speed="100" autoplay="true" reverse="false"]
//   ids      = comma-separated WP media attachment IDs (required)
//   urls     = comma-separated image URLs (alternative to ids)
//   speed    = ms between frames (default 100)
//   autoplay = auto-rotate on load (default true)
//   reverse  = rotation direction (default false)
//
// The viewer JS & CSS live in the WPWriter theme (js/wp360-viewer.js, style-base.css).
// This shortcode renders the HTML container that the theme JS initializes.

add_shortcode('wp360', 'wpm_connector_wp360_shortcode');

function wpm_connector_wp360_shortcode($atts) {
    $atts = shortcode_atts(array(
        'ids'      => '',
        'urls'     => '',
        'speed'    => '100',
        'autoplay' => 'true',
        'reverse'  => 'false',
        'fade'     => '0',
    ), $atts, 'wp360');

    $image_urls = array();

    // Resolve from attachment IDs
    if (!empty($atts['ids'])) {
        $ids = array_map('intval', array_filter(explode(',', $atts['ids'])));
        foreach ($ids as $id) {
            $url = wp_get_attachment_image_url($id, 'large');
            if ($url) {
                $image_urls[] = $url;
            }
        }
    }

    // Or use direct URLs
    if (empty($image_urls) && !empty($atts['urls'])) {
        $image_urls = array_map('esc_url', array_filter(explode(',', $atts['urls'])));
    }

    if (count($image_urls) < 2) {
        return '<!-- wp360: need at least 2 images -->';
    }

    $json = wp_json_encode($image_urls);
    $speed = intval($atts['speed']);
    $autoplay = ($atts['autoplay'] === 'true') ? 'true' : 'false';
    $reverse = ($atts['reverse'] === 'true') ? 'true' : 'false';
    $fade = intval($atts['fade']);

    return sprintf(
        '<div class="wp360-viewer" data-wp360-images=\'%s\' data-wp360-speed="%d" data-wp360-autoplay="%s" data-wp360-reverse="%s" data-wp360-fade="%d"></div>',
        esc_attr($json),
        $speed,
        esc_attr($autoplay),
        esc_attr($reverse),
        $fade
    );
}

/**
 * REST endpoint: List available theme menu locations and their assigned menus.
 * Endpoint: GET /wp-json/wp-writer/v1/menu-locations
 */
function wpm_connector_list_menu_locations(WP_REST_Request $request) {
    $auth = wpm_connector_authenticate_request($request);
    if (is_wp_error($auth)) {
        return $auth;
    }

    $registered = get_registered_nav_menus();
    $assignments = get_nav_menu_locations();
    $locations = array();

    foreach ($registered as $slug => $description) {
        $menu_id = isset($assignments[$slug]) ? (int) $assignments[$slug] : 0;
        $menu_name = '';
        if ($menu_id > 0) {
            $menu_obj = wp_get_nav_menu_object($menu_id);
            if ($menu_obj) {
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

    return rest_ensure_response(array(
        'locations' => $locations,
        'theme'     => wp_get_theme()->get('Name'),
    ));
}

/**
 * REST endpoint: Assign a menu to a theme location.
 * Endpoint: POST /wp-json/wp-writer/v1/menu-locations
 * Body: { "location": "primary", "menu_id": 123 }
 * Set menu_id to 0 to unassign a menu from a location.
 */
function wpm_connector_assign_menu_location(WP_REST_Request $request) {
    $auth = wpm_connector_authenticate_request($request);
    if (is_wp_error($auth)) {
        return $auth;
    }

    if (!current_user_can('edit_theme_options')) {
        return new WP_Error('forbidden', 'Current user cannot manage menu locations.', array('status' => 403));
    }

    $data = $request->get_json_params();
    $location = isset($data['location']) ? sanitize_text_field($data['location']) : '';
    $menu_id  = isset($data['menu_id']) ? (int) $data['menu_id'] : -1;

    if ($location === '') {
        return new WP_Error('missing_location', 'Location slug is required.', array('status' => 400));
    }
    if ($menu_id < 0) {
        return new WP_Error('missing_menu_id', 'menu_id is required (0 to unassign).', array('status' => 400));
    }

    $registered = get_registered_nav_menus();
    if (!isset($registered[$location])) {
        return new WP_Error(
            'invalid_location',
            sprintf('Location "%s" is not registered by the current theme. Available: %s', $location, implode(', ', array_keys($registered))),
            array('status' => 400)
        );
    }

    if ($menu_id > 0) {
        $menu_obj = wp_get_nav_menu_object($menu_id);
        if (!$menu_obj) {
            return new WP_Error('invalid_menu', sprintf('Menu ID %d does not exist.', $menu_id), array('status' => 404));
        }
    }

    $assignments = get_nav_menu_locations();
    $assignments[$location] = $menu_id;
    set_theme_mod('nav_menu_locations', $assignments);

    $menu_name = '';
    if ($menu_id > 0) {
        $menu_obj = wp_get_nav_menu_object($menu_id);
        if ($menu_obj) {
            $menu_name = $menu_obj->name;
        }
    }

    return rest_ensure_response(array(
        'success'     => true,
        'location'    => $location,
        'description' => $registered[$location],
        'menu_id'     => $menu_id,
        'menu_name'   => $menu_name,
    ));
}

/**
 * REST endpoint: Returns the site's current Additional CSS (Customizer CSS).
 * Endpoint: GET /wp-json/wp-writer/v1/custom-css
 * Uses WordPress's built-in wp_get_custom_css() which works with any theme.
 */
function wpm_connector_get_custom_css(WP_REST_Request $request) {
    $css = wp_get_custom_css();
    $theme = wp_get_theme();

    return rest_ensure_response(array(
        'css'              => $css,
        'length'           => strlen($css),
        'active_theme'     => $theme->get('Name'),
        'theme_slug'       => $theme->get_stylesheet(),
    ));
}

/**
 * REST endpoint: Updates the site's Additional CSS (Customizer CSS).
 * Endpoint: POST /wp-json/wp-writer/v1/custom-css
 *
 * Parameters:
 *   css  (string, required) - The CSS content
 *   mode (string, optional) - "replace" (default) replaces all CSS, "append" adds to existing
 *
 * Uses WordPress's built-in wp_update_custom_css_post() which works with any theme.
 * This is the same CSS that appears in Appearance → Customize → Additional CSS.
 */
function wpm_connector_update_custom_css(WP_REST_Request $request) {
    $auth = wpm_connector_authenticate_request($request);
    if (is_wp_error($auth)) {
        return $auth;
    }

    if (!current_user_can('edit_theme_options')) {
        return new WP_Error('forbidden', 'Current user cannot edit theme options.', array('status' => 403));
    }

    $css  = $request->get_param('css');
    $mode = $request->get_param('mode') ?: 'replace';

    if ($mode !== 'replace' && $mode !== 'append') {
        return new WP_REST_Response(array(
            'success' => false,
            'message' => 'Invalid mode. Use "replace" or "append".',
        ), 400);
    }

    // For append mode, prepend existing CSS
    if ($mode === 'append') {
        $existing = wp_get_custom_css();
        if (!empty($existing)) {
            $css = $existing . "\n\n/* --- WPWriter addition --- */\n" . $css;
        }
    }

    // Use WordPress core function to update Additional CSS
    $result = wp_update_custom_css_post($css);

    if (is_wp_error($result)) {
        return new WP_REST_Response(array(
            'success' => false,
            'message' => $result->get_error_message(),
        ), 500);
    }

    $final_css = wp_get_custom_css();
    $theme = wp_get_theme();

    return rest_ensure_response(array(
        'success'      => true,
        'mode'         => $mode,
        'css_length'   => strlen($final_css),
        'active_theme' => $theme->get('Name'),
        'message'      => 'Custom CSS updated successfully. Changes are live immediately.',
    ));
}

// ─── Permalink Structure ─────────────────────────────────────────────

/**
 * GET /wp-json/wp-writer/v1/permalink-structure
 * Returns the current permalink structure.
 */
function wpm_connector_get_permalink_structure(WP_REST_Request $request) {
    $structure = get_option('permalink_structure', '');
    $common = array(
        ''            => 'Plain (default)',
        '/%year%/%monthnum%/%day%/%postname%/' => 'Day and name',
        '/%year%/%monthnum%/%postname%/'       => 'Month and name',
        '/archives/%post_id%'                  => 'Numeric',
        '/%postname%/'                         => 'Post name (recommended)',
        '/%category%/%postname%/'              => 'Category and name',
    );

    return rest_ensure_response(array(
        'structure'   => $structure,
        'label'       => isset($common[$structure]) ? $common[$structure] : 'Custom',
        'is_plain'    => empty($structure),
        'is_postname' => $structure === '/%postname%/',
    ));
}

/**
 * POST /wp-json/wp-writer/v1/permalink-structure
 * Body: { "structure": "/%postname%/" }
 * Changes the site-wide permalink structure and flushes rewrite rules.
 */
function wpm_connector_set_permalink_structure(WP_REST_Request $request) {
    $auth = wpm_connector_authenticate_request($request);
    if (is_wp_error($auth)) return $auth;

    if (!current_user_can('manage_options')) {
        return new WP_Error('forbidden', 'Current user cannot manage permalink settings.', array('status' => 403));
    }

    $structure = $request->get_param('structure');
    if (!is_string($structure)) {
        return new WP_REST_Response(array('success' => false, 'message' => 'Missing structure parameter.'), 400);
    }

    $allowed = array(
        '/%postname%/',
        '/%year%/%monthnum%/%day%/%postname%/',
        '/%year%/%monthnum%/%postname%/',
        '/archives/%post_id%',
        '/%category%/%postname%/',
    );

    if (!in_array($structure, $allowed, true)) {
        return new WP_REST_Response(array(
            'success' => false,
            'message' => 'Invalid permalink structure. Allowed: ' . implode(', ', $allowed),
        ), 400);
    }

    $old = get_option('permalink_structure', '');
    update_option('permalink_structure', $structure);

    // Flush rewrite rules
    global $wp_rewrite;
    $wp_rewrite->set_permalink_structure($structure);
    $wp_rewrite->flush_rules(true);

    return rest_ensure_response(array(
        'success'       => true,
        'old_structure' => $old,
        'new_structure' => $structure,
        'message'       => 'Permalink structure updated and rewrite rules flushed.',
    ));
}

// ─── Plugin Management ──────────────────────────────────────────────

/**
 * GET /wp-json/wp-writer/v1/plugins
 * Lists all installed plugins with status.
 */
function wpm_connector_list_plugins(WP_REST_Request $request) {
    if (!function_exists('get_plugins')) {
        require_once ABSPATH . 'wp-admin/includes/plugin.php';
    }

    $all_plugins    = get_plugins();
    $active_plugins = get_option('active_plugins', array());
    $result         = array();

    foreach ($all_plugins as $file => $data) {
        $result[] = array(
            'file'        => $file,
            'name'        => $data['Name'],
            'version'     => $data['Version'],
            'description' => wp_strip_all_tags($data['Description']),
            'author'      => wp_strip_all_tags($data['Author']),
            'is_active'   => in_array($file, $active_plugins, true),
        );
    }

    return rest_ensure_response(array(
        'plugins' => $result,
        'total'   => count($result),
        'active'  => count(array_filter($result, function($p) { return $p['is_active']; })),
    ));
}

/**
 * POST /wp-json/wp-writer/v1/plugin-status
 * Body: { "plugin": "plugin-dir/plugin-file.php", "action": "activate" | "deactivate" }
 */
function wpm_connector_toggle_plugin(WP_REST_Request $request) {
    $auth = wpm_connector_authenticate_request($request);
    if (is_wp_error($auth)) return $auth;

    if (!current_user_can('activate_plugins')) {
        return new WP_Error('forbidden', 'Current user cannot manage plugins.', array('status' => 403));
    }

    if (!function_exists('get_plugins')) {
        require_once ABSPATH . 'wp-admin/includes/plugin.php';
    }

    $plugin = $request->get_param('plugin');
    $action = $request->get_param('action');

    if (empty($plugin) || empty($action)) {
        return new WP_REST_Response(array('success' => false, 'message' => 'Missing plugin or action parameter.'), 400);
    }

    if (!in_array($action, array('activate', 'deactivate'), true)) {
        return new WP_REST_Response(array('success' => false, 'message' => 'Invalid action. Use "activate" or "deactivate".'), 400);
    }

    // Verify plugin exists
    $all_plugins = get_plugins();
    if (!isset($all_plugins[$plugin])) {
        return new WP_REST_Response(array('success' => false, 'message' => "Plugin not found: {$plugin}"), 404);
    }

    // Prevent deactivating the connector itself
    $connector_file = plugin_basename(__FILE__);
    if ($plugin === $connector_file && $action === 'deactivate') {
        return new WP_REST_Response(array(
            'success' => false,
            'message' => 'Cannot deactivate the WPWriter connector plugin through this endpoint.',
        ), 400);
    }

    if ($action === 'activate') {
        $result = activate_plugin($plugin);
        if (is_wp_error($result)) {
            return new WP_REST_Response(array(
                'success' => false,
                'message' => $result->get_error_message(),
            ), 500);
        }
    } else {
        deactivate_plugins($plugin);
    }

    return rest_ensure_response(array(
        'success' => true,
        'plugin'  => $plugin,
        'name'    => $all_plugins[$plugin]['Name'],
        'action'  => $action . 'd',
        'message' => $all_plugins[$plugin]['Name'] . ' has been ' . $action . 'd.',
    ));
}

// ─── Widget / Sidebar Management ─────────────────────────────────────

/**
 * GET /wp-json/wp-writer/v1/sidebars
 * Lists all registered sidebar/widget areas with their widgets.
 */
function wpm_connector_list_sidebars(WP_REST_Request $request) {
    global $wp_registered_sidebars;

    $sidebars_widgets = wp_get_sidebars_widgets();
    $result = array();

    foreach ($wp_registered_sidebars as $id => $sidebar) {
        $widgets = isset($sidebars_widgets[$id]) ? $sidebars_widgets[$id] : array();
        $result[] = array(
            'id'           => $id,
            'name'         => $sidebar['name'],
            'description'  => $sidebar['description'],
            'widget_count' => count($widgets),
            'widget_ids'   => $widgets,
        );
    }

    return rest_ensure_response(array(
        'sidebars' => $result,
        'total'    => count($result),
    ));
}

/**
 * GET /wp-json/wp-writer/v1/widgets
 * Lists all active widgets across all sidebars with their settings.
 */
function wpm_connector_list_widgets(WP_REST_Request $request) {
    $sidebars_widgets = wp_get_sidebars_widgets();
    $result = array();

    foreach ($sidebars_widgets as $sidebar_id => $widget_ids) {
        if ($sidebar_id === 'wp_inactive_widgets' || !is_array($widget_ids)) continue;

        foreach ($widget_ids as $widget_id) {
            // Parse widget type and instance number (e.g., "text-2" → type="text", number=2)
            if (!preg_match('/^(.+)-(\d+)$/', $widget_id, $matches)) continue;
            $type   = $matches[1];
            $number = (int) $matches[2];

            // Get widget instance data
            $instances = get_option("widget_{$type}", array());
            $settings  = isset($instances[$number]) ? $instances[$number] : array();

            $result[] = array(
                'widget_id'  => $widget_id,
                'type'       => $type,
                'sidebar_id' => $sidebar_id,
                'title'      => isset($settings['title']) ? $settings['title'] : '',
                'content'    => isset($settings['text']) ? wp_strip_all_tags(substr($settings['text'], 0, 200)) : '',
                'settings'   => $settings,
            );
        }
    }

    return rest_ensure_response(array(
        'widgets' => $result,
        'total'   => count($result),
    ));
}

/**
 * POST /wp-json/wp-writer/v1/widgets
 * Adds an HTML/text widget to a sidebar.
 * Body: { "sidebar_id": "sidebar-1", "title": "My Widget", "content": "<p>Hello</p>" }
 */
function wpm_connector_add_widget(WP_REST_Request $request) {
    $auth = wpm_connector_authenticate_request($request);
    if (is_wp_error($auth)) return $auth;

    if (!current_user_can('edit_theme_options')) {
        return new WP_Error('forbidden', 'Current user cannot manage widgets.', array('status' => 403));
    }

    $sidebar_id = $request->get_param('sidebar_id');
    $title      = $request->get_param('title') ?: '';
    $content    = $request->get_param('content');

    if (empty($sidebar_id) || empty($content)) {
        return new WP_REST_Response(array('success' => false, 'message' => 'Missing sidebar_id or content parameter.'), 400);
    }

    // Verify sidebar exists
    global $wp_registered_sidebars;
    if (!isset($wp_registered_sidebars[$sidebar_id])) {
        $available = array_keys($wp_registered_sidebars);
        return new WP_REST_Response(array(
            'success'   => false,
            'message'   => "Sidebar not found: {$sidebar_id}. Available: " . implode(', ', $available),
        ), 404);
    }

    // Use the 'custom_html' widget type (available since WP 4.8.1)
    $widget_type = 'custom_html';
    $instances = get_option("widget_{$widget_type}", array());

    // Find the next available instance number. WordPress stores a non-numeric '_multiwidget'
    // key in this option (and sometimes '__i__'), so the keys must be filtered to integers
    // first — max() over a mixed array returns the string, and '_multiwidget' + 1 is a fatal
    // TypeError on PHP 8, which is what made every add_widget call fail with a 500.
    $numbers     = array_filter(array_keys($instances), 'is_int');
    $next_number = empty($numbers) ? 2 : max($numbers) + 1;

    // Save widget instance
    $instances[$next_number] = array(
        'title'   => sanitize_text_field($title),
        'content' => $content, // Allow HTML
    );
    update_option("widget_{$widget_type}", $instances);

    // Add widget to sidebar
    $sidebars_widgets = wp_get_sidebars_widgets();
    $widget_id = "{$widget_type}-{$next_number}";
    $sidebars_widgets[$sidebar_id][] = $widget_id;
    wp_set_sidebars_widgets($sidebars_widgets);

    return rest_ensure_response(array(
        'success'    => true,
        'widget_id'  => $widget_id,
        'sidebar_id' => $sidebar_id,
        'title'      => $title,
        'message'    => "Widget added to {$wp_registered_sidebars[$sidebar_id]['name']}.",
    ));
}

/**
 * DELETE /wp-json/wp-writer/v1/widgets/{widget_id}
 * Removes a widget from its sidebar.
 */
function wpm_connector_remove_widget(WP_REST_Request $request) {
    $auth = wpm_connector_authenticate_request($request);
    if (is_wp_error($auth)) return $auth;

    if (!current_user_can('edit_theme_options')) {
        return new WP_Error('forbidden', 'Current user cannot manage widgets.', array('status' => 403));
    }

    $widget_id = $request->get_param('widget_id');
    if (empty($widget_id)) {
        return new WP_REST_Response(array('success' => false, 'message' => 'Missing widget_id.'), 400);
    }

    // Find and remove from sidebar
    $sidebars_widgets = wp_get_sidebars_widgets();
    $found_sidebar = null;

    foreach ($sidebars_widgets as $sidebar_id => &$widgets) {
        if (!is_array($widgets)) continue;
        $key = array_search($widget_id, $widgets, true);
        if ($key !== false) {
            array_splice($widgets, $key, 1);
            $found_sidebar = $sidebar_id;
            break;
        }
    }
    unset($widgets);

    if (!$found_sidebar) {
        return new WP_REST_Response(array('success' => false, 'message' => "Widget not found: {$widget_id}"), 404);
    }

    wp_set_sidebars_widgets($sidebars_widgets);

    // Clean up widget instance data
    if (preg_match('/^(.+)-(\d+)$/', $widget_id, $matches)) {
        $type   = $matches[1];
        $number = (int) $matches[2];
        $instances = get_option("widget_{$type}", array());
        if (isset($instances[$number])) {
            unset($instances[$number]);
            update_option("widget_{$type}", $instances);
        }
    }

    return rest_ensure_response(array(
        'success'    => true,
        'widget_id'  => $widget_id,
        'sidebar_id' => $found_sidebar,
        'message'    => "Widget removed from {$found_sidebar}.",
    ));
}

// ─── WP 7.0 Connectors — AI Provider Key Import ──────────────────────
//
// WordPress 7.0 added Settings → Connectors, where a user stores their AI
// provider API keys (OpenAI, Anthropic/Claude, Google/Gemini) once for all
// plugins. These endpoints let WPWriter IMPORT a key the user already saved
// there, so they don't have to enter the same key twice.
//
// DB-SCOPED ONLY: we read the value WordPress saved to the database via
// get_option(). WordPress resolves an "effective" credential in the order
// env var -> PHP constant -> database, but that resolution happens in WP's AI
// Client layer, not in get_option(). Reading the option therefore returns ONLY
// the key the user typed on the Connectors screen. Env/constant-supplied
// secrets are intentionally NOT importable (moving deployment-managed secrets
// off the host is a different consent boundary).

/**
 * Map WPWriter provider names to WordPress 7.0 connector provider ids.
 * WP stores keys in the option: connectors_ai_{provider_id}_api_key
 */
function wpm_connector_wpwriter_provider_map() {
    return array(
        'openai' => 'openai',
        'claude' => 'anthropic',
        'gemini' => 'google',
    );
}

/**
 * Get the DB option name for a registered connector API key.
 *
 * WordPress generates connectors_ai_{type}_{provider}_api_key by default, but
 * connector registration may override the DB setting name.
 */
function wpm_connector_get_connector_key_option_name($wp_provider_id) {
    if (!function_exists('wp_get_connector')) {
        return '';
    }

    $connector = wp_get_connector($wp_provider_id);
    if (!is_array($connector)) {
        return '';
    }

    $authentication = isset($connector['authentication']) && is_array($connector['authentication'])
        ? $connector['authentication']
        : array();

    if (!isset($authentication['method']) || $authentication['method'] !== 'api_key') {
        return '';
    }

    if (isset($authentication['setting_name']) && is_string($authentication['setting_name'])) {
        $custom_name = trim($authentication['setting_name']);
        if ($custom_name !== '') {
            return $custom_name;
        }
    }

    return 'connectors_ai_' . $wp_provider_id . '_api_key';
}

/**
 * Read a registered connector's DB-stored API key. Returns '' if none.
 */
function wpm_connector_read_connector_key($wp_provider_id) {
    $option_name = wpm_connector_get_connector_key_option_name($wp_provider_id);
    if ($option_name === '') {
        return '';
    }

    $value = get_option($option_name, '');
    return is_string($value) ? trim($value) : '';
}

/**
 * Mask an API key for display (ASCII-safe): last 4 chars only.
 */
function wpm_connector_mask_key($key) {
    $len = strlen($key);
    if ($len <= 4) {
        return str_repeat('*', $len);
    }
    return '...' . substr($key, -4);
}

/**
 * GET /wp-json/wp-writer/v1/connector-keys
 * Discovery — reports which AI providers have a DB-stored key in
 * Settings -> Connectors. Returns NO secrets (masked preview only).
 */
function wpm_connector_connector_keys(WP_REST_Request $request) {
    $auth = wpm_connector_authenticate_request($request);
    if (is_wp_error($auth)) return $auth;

    // WordPress 7.0+ feature. On older WP, report supported=false so the client
    // hides the import UI and degrades gracefully.
    $supported = function_exists('wp_get_connectors');

    $providers = array();
    foreach (wpm_connector_wpwriter_provider_map() as $wpwriter_provider => $wp_provider_id) {
        $key = $supported ? wpm_connector_read_connector_key($wp_provider_id) : '';
        $has_key = ($key !== '');
        $providers[] = array(
            'provider'       => $wpwriter_provider,
            'wp_provider_id' => $wp_provider_id,
            'has_key'        => $has_key,
            'masked'         => $has_key ? wpm_connector_mask_key($key) : '',
        );
    }

    return rest_ensure_response(array(
        'supported' => $supported,
        'providers' => $providers,
    ));
}

/**
 * POST /wp-json/wp-writer/v1/connector-keys/reveal
 * Body: { "provider": "openai" | "claude" | "gemini" }
 * Returns the FULL DB-stored secret for one provider. Requires the connected
 * user to have manage_options (these are admin-level secrets).
 */
function wpm_connector_connector_keys_reveal(WP_REST_Request $request) {
    $auth = wpm_connector_authenticate_request($request);
    if (is_wp_error($auth)) return $auth;

    if (!current_user_can('manage_options')) {
        return new WP_Error('forbidden', 'Current user cannot read connector credentials.', array('status' => 403));
    }

    if (!function_exists('wp_get_connectors')) {
        return new WP_Error(
            'wpm_connectors_unsupported',
            'The WordPress Connectors API is not available (requires WordPress 7.0+).',
            array('status' => 400)
        );
    }

    $data = $request->get_json_params();
    $provider = '';
    if (is_array($data) && isset($data['provider'])) {
        $provider = sanitize_text_field($data['provider']);
    }
    if ($provider === '') {
        $provider = sanitize_text_field((string) $request->get_param('provider'));
    }

    // Accept either the WPWriter name (openai/claude/gemini) or the WP id
    // (openai/anthropic/google).
    $map = wpm_connector_wpwriter_provider_map();
    $wp_provider_id = null;
    $wpwriter_provider = null;
    if (isset($map[$provider])) {
        $wpwriter_provider = $provider;
        $wp_provider_id = $map[$provider];
    } else {
        $flip = array_flip($map);
        if (isset($flip[$provider])) {
            $wp_provider_id = $provider;
            $wpwriter_provider = $flip[$provider];
        }
    }

    if ($wp_provider_id === null) {
        return new WP_Error(
            'wpm_invalid_provider',
            'Unknown provider. Use one of: ' . implode(', ', array_keys($map)),
            array('status' => 400)
        );
    }

    $key = wpm_connector_read_connector_key($wp_provider_id);
    if ($key === '') {
        return new WP_Error(
            'wpm_no_key',
            sprintf('No database-stored %s key found in WordPress Settings -> Connectors. Keys supplied through server configuration are not importable.', $wpwriter_provider),
            array('status' => 404)
        );
    }

    // Best-effort audit (provider only — never log the key value).
    $current = wp_get_current_user();
    error_log(sprintf(
        '[wpwriter-connector] connector key revealed for import: provider=%s user=%s',
        $wpwriter_provider,
        $current ? $current->user_login : 'unknown'
    ));

    return rest_ensure_response(array(
        'provider'       => $wpwriter_provider,
        'wp_provider_id' => $wp_provider_id,
        'api_key'        => $key,
        'masked'         => wpm_connector_mask_key($key),
    ));
}
