<?php
/**
 * Plugin Name: reLoopin Loyalty
 * Plugin URI:  https://reloopin.com
 * Description: Integrates a custom loyalty points backend with WooCommerce.
 * Version:     1.0.0
 * Author:      reLoopin
 * Requires at least: 6.0
 * Requires PHP: 8.0
 * WC requires at least: 7.0
 * WC tested up to: 9.4
 * Text Domain: reloopin-loyalty
 * License:     GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 */

if (!defined('ABSPATH')) {
    exit;
}

define('RELOOPIN_LOYALTY_VERSION', '1.0.0');
define('RELOOPIN_LOYALTY_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('RELOOPIN_LOYALTY_PLUGIN_URL', plugin_dir_url(__FILE__));

/** Platform integer for WooCommerce in the loyalty backend. */
define('RELOOPIN_LOYALTY_PLATFORM', 1);

/**
 * Write a debug message to wp-content/debug.log.
 *
 * Only fires when both WP_DEBUG and WP_DEBUG_LOG are true.
 * Each line is prefixed with [reLoopin Loyalty] and a timestamp for easy grepping.
 *
 * @param string $message
 * @param mixed  $context  Optional value (array/object) to dump alongside the message.
 */
function reloopin_loyalty_debug(string $message, mixed $context = null): void
{
    if (!(defined('WP_DEBUG') && WP_DEBUG && defined('WP_DEBUG_LOG') && WP_DEBUG_LOG)) {
        return;
    }

    $timestamp = gmdate('Y-m-d H:i:s');
    $entry = "[reLoopin Loyalty] [{$timestamp}] {$message}";

    if ($context !== null) {
        $entry .= ' | ' . (is_string($context) ? $context : wp_json_encode($context));
    }

    error_log($entry); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
}

/**
 * Declare compatibility with WooCommerce High-Performance Order Storage (HPOS).
 */
add_action('before_woocommerce_init', function () {
    if (class_exists(\Automattic\WooCommerce\Utilities\FeaturesUtil::class)) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('custom_order_tables', __FILE__, true);
    }
});

/**
 * Check WooCommerce is active before doing anything.
 */
add_action('plugins_loaded', 'reloopin_loyalty_init', 20);

function reloopin_loyalty_init()
{
    if (!class_exists('WooCommerce')) {
        add_action('admin_notices', function () {
            echo '<div class="notice notice-error"><p><strong>' .
                esc_html__('reLoopin Loyalty', 'reloopin-loyalty') .
                '</strong> ' .
                esc_html__('requires WooCommerce to be installed and active.', 'reloopin-loyalty') .
                '</p></div>';
        });
        return;
    }

    require_once RELOOPIN_LOYALTY_PLUGIN_DIR . 'includes/class-loyalty-api.php';
    require_once RELOOPIN_LOYALTY_PLUGIN_DIR . 'includes/class-loyalty-orders.php';
    require_once RELOOPIN_LOYALTY_PLUGIN_DIR . 'includes/class-loyalty-launcher.php';

    $api = new ReLoopin_Loyalty_API();

    new ReLoopin_Loyalty_Orders($api);
    new ReLoopin_Loyalty_Launcher($api);
}

// ---------------------------------------------------------------------------
// Settings: WooCommerce > Settings > Loyalty tab
// ---------------------------------------------------------------------------

add_filter('woocommerce_settings_tabs_array', 'reloopin_loyalty_add_settings_tab', 50);
function reloopin_loyalty_add_settings_tab($tabs)
{
    $tabs['reloopin_loyalty'] = __('Loyalty', 'reloopin-loyalty');
    return $tabs;
}

add_action('woocommerce_settings_tabs_reloopin_loyalty', 'reloopin_loyalty_settings_tab');
function reloopin_loyalty_settings_tab()
{
    woocommerce_admin_fields(reloopin_loyalty_get_settings());
}

add_action('woocommerce_update_options_reloopin_loyalty', 'reloopin_loyalty_update_settings');
function reloopin_loyalty_update_settings()
{
    woocommerce_update_options(reloopin_loyalty_get_settings());
}

function reloopin_loyalty_get_settings()
{
    return [
        [
            'title' => __('Loyalty System Settings', 'reloopin-loyalty'),
            'type' => 'title',
            'id' => 'reloopin_loyalty_section_title',
        ],
        [
            'title' => __('API Base URL', 'reloopin-loyalty'),
            'desc' => __('Base URL of your loyalty backend — no trailing slash. e.g. https://loyalty.yourdomain.com', 'reloopin-loyalty'),
            'id' => 'reloopin_loyalty_api_url',
            'type' => 'text',
            'default' => '',
        ],
        [
            'title' => __('API Key', 'reloopin-loyalty'),
            'desc' => __('Bearer token used to authenticate with the loyalty API.', 'reloopin-loyalty'),
            'id' => 'reloopin_loyalty_api_key',
            'type' => 'password',
            'default' => '',
        ],
        [
            'title' => __('Merchant ID', 'reloopin-loyalty'),
            'desc' => __('Your merchant UUID from the loyalty backend, e.g. 3fa85f64-5717-4562-b3fc-2c963f66afa6', 'reloopin-loyalty'),
            'id' => 'reloopin_loyalty_merchant_id',
            'type' => 'text',
            'default' => '',
        ],
        [
            'title' => __('Merchant Code', 'reloopin-loyalty'),
            'desc' => __('Sent as the merchant_code header on transaction-entry requests.', 'reloopin-loyalty'),
            'id' => 'reloopin_loyalty_merchant_code',
            'type' => 'text',
            'default' => '',
        ],
        [
            'type' => 'sectionend',
            'id' => 'reloopin_loyalty_section_end',
        ],

        // ── LAUNCHER WIDGET ─────────────────────────────────────────────────
        [
            'title' => __('Launcher Widget', 'reloopin-loyalty'),
            'type'  => 'title',
            'id'    => 'reloopin_launcher_tab_title',
            'desc'  => __('Control where and how the floating launcher widget appears.', 'reloopin-loyalty'),
        ],
        [
            'title'   => __('Enable launcher widget', 'reloopin-loyalty'),
            'desc'    => __('Show the floating loyalty launcher on the frontend.', 'reloopin-loyalty'),
            'id'      => 'reloopin_launcher_enabled',
            'type'    => 'checkbox',
            'default' => 'yes',
        ],
        [
            'title'   => __('Button position', 'reloopin-loyalty'),
            'id'      => 'reloopin_launcher_position',
            'type'    => 'select',
            'default' => 'bottom-right',
            'options' => [
                'bottom-right' => __('Bottom right', 'reloopin-loyalty'),
                'bottom-left'  => __('Bottom left', 'reloopin-loyalty'),
            ],
        ],
        [
            'title'   => __('Show "Powered by reLoopin"', 'reloopin-loyalty'),
            'desc'    => __('Display branding and footer in the launcher panel.', 'reloopin-loyalty'),
            'id'      => 'reloopin_launcher_branding',
            'type'    => 'checkbox',
            'default' => 'no',
        ],
        [
            'type' => 'sectionend',
            'id'   => 'reloopin_launcher_tab_end',
        ],
    ];
}
