<?php
/**
 * Plugin Name: GoHighLevel Gravity Add-On
 * Plugin URI:  https://github.com/rajurayhan/lh-ghl-gravity-addon
 * Description: Gravity Forms Add-On that syncs form submissions to GoHighLevel (LeadConnector API) — creates/updates Contacts and optionally creates Opportunities.
 * Version:     1.0.0
 * Author:      RakaAITech
 * Author URI:  https://rakaaitech.com
 * Text Domain: lh-ghl-gravity-addon
 * License:     GPL-2.0+
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 *
 * Requires PHP: 8.1
 * Requires at least: 6.0
 *
 * @package RAKAAITECH_GHL_Gravity_Addon
 * @author  RakaAITech <https://rakaaitech.com>
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/*--------------------------------------------------------------------------
 * Plugin Constants
 *-------------------------------------------------------------------------*/
define( 'RAKAAITECH_GHL_ADDON_VERSION', '1.0.0' );
define( 'RAKAAITECH_GHL_ADDON_MIN_GF_VERSION', '2.5' );
define( 'RAKAAITECH_GHL_ADDON_PLUGIN_FILE', __FILE__ );
define( 'RAKAAITECH_GHL_ADDON_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'RAKAAITECH_GHL_ADDON_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

/*--------------------------------------------------------------------------
 * Autoload includes
 *-------------------------------------------------------------------------*/

/**
 * Load required class files.
 *
 * @return void
 */
function lh_ghl_addon_load_includes(): void {
    $includes_dir = RAKAAITECH_GHL_ADDON_PLUGIN_DIR . 'includes/';

    require_once $includes_dir . 'helpers.php';
    require_once $includes_dir . 'class-lh-ghl-logger.php';
    require_once $includes_dir . 'class-lh-ghl-api.php';
    require_once $includes_dir . 'class-lh-ghl-background.php';
    require_once $includes_dir . 'class-lh-ghl-addon.php';
}

/*--------------------------------------------------------------------------
 * Bootstrap the Add-On with Gravity Forms
 *-------------------------------------------------------------------------*/

add_action( 'gform_loaded', 'lh_ghl_addon_bootstrap', 5 );

/**
 * Bootstrap the add-on after Gravity Forms has loaded.
 *
 * @return void
 */
function lh_ghl_addon_bootstrap(): void {

    // Gravity Forms Add-On Framework is required.
    if ( ! method_exists( 'GFForms', 'include_addon_framework' ) ) {
        return;
    }

    GFForms::include_addon_framework();

    // Load plugin files.
    lh_ghl_addon_load_includes();

    // Register the add-on.
    GFAddOn::register( 'LH_GHL_Addon' );

    // Register Test Connection AJAX handler early so it runs on admin-ajax.php requests.
    // GF addon init() does not call init_admin() when RG_CURRENT_PAGE is admin-ajax.php, so the
    // action would otherwise never be registered and WordPress would return 400.
    add_action( 'wp_ajax_lh_ghl_test_connection', 'lh_ghl_addon_ajax_test_connection' );

    // Add Settings link on the Plugins list when the add-on is active.
    add_filter( 'plugin_action_links_' . plugin_basename( RAKAAITECH_GHL_ADDON_PLUGIN_FILE ), 'lh_ghl_addon_plugin_settings_link', 10, 2 );
}

/**
 * AJAX callback for Test Connection (registered in bootstrap so it runs on admin-ajax.php).
 *
 * @return void
 */
function lh_ghl_addon_ajax_test_connection(): void {
    $addon = lh_ghl_addon_get_instance();
    if ( $addon ) {
        $addon->ajax_test_connection();
    }
}

/**
 * Add a "Settings" link to the plugin action links when the add-on is active.
 *
 * @param array  $links An array of plugin action links.
 * @param string $file  Path to the plugin file relative to the plugins directory.
 * @return array Filtered links.
 */
function lh_ghl_addon_plugin_settings_link( array $links, string $file ): array {
    if ( plugin_basename( RAKAAITECH_GHL_ADDON_PLUGIN_FILE ) !== $file ) {
        return $links;
    }
    $addon = lh_ghl_addon_get_instance();
    if ( ! $addon || ! method_exists( $addon, 'get_plugin_settings_url' ) ) {
        return $links;
    }
    if ( ! $addon->current_user_can_plugin_settings() ) {
        return $links;
    }
    $settings_url = $addon->get_plugin_settings_url();
    array_unshift( $links, '<a href="' . esc_url( $settings_url ) . '">' . esc_html__( 'Settings', 'lh-ghl-gravity-addon' ) . '</a>' );
    return $links;
}

/**
 * Helper to retrieve the add-on instance.
 *
 * @return LH_GHL_Addon|null
 */
function lh_ghl_addon_get_instance(): ?LH_GHL_Addon {
    return LH_GHL_Addon::get_instance();
}
