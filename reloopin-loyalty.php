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
    require_once RELOOPIN_LOYALTY_PLUGIN_DIR . 'includes/class-loyalty-customers.php';

    $api = new ReLoopin_Loyalty_API();

    new ReLoopin_Loyalty_Orders($api);
    new ReLoopin_Loyalty_Launcher($api);
    new ReLoopin_Loyalty_Customers($api);
}

// ---------------------------------------------------------------------------
// Settings: "reLoopin" admin menu
// ---------------------------------------------------------------------------

add_action('admin_menu', 'reloopin_loyalty_admin_menu');
function reloopin_loyalty_admin_menu()
{
    add_menu_page(
        __('reLoopin Loyalty', 'reloopin-loyalty'),
        __('reLoopin', 'reloopin-loyalty'),
        'manage_woocommerce',
        'reloopin-loyalty',
        'reloopin_loyalty_settings_page',
        'dashicons-awards',
        56 // After WooCommerce
    );
}

add_action('admin_init', 'reloopin_loyalty_register_settings');
function reloopin_loyalty_register_settings()
{
    $fields = reloopin_loyalty_get_settings();

    foreach ($fields as $field) {
        if (!isset($field['id']) || $field['type'] === 'title' || $field['type'] === 'sectionend') {
            continue;
        }
        register_setting('reloopin_loyalty_settings', $field['id']);
    }
}

function reloopin_loyalty_settings_page()
{
    if (!current_user_can('manage_woocommerce')) {
        return;
    }

    if (isset($_GET['settings-updated']) && $_GET['settings-updated']) { // phpcs:ignore WordPress.Security.NonceVerification
        add_settings_error('reloopin_loyalty', 'settings_updated', __('Settings saved.', 'reloopin-loyalty'), 'updated');
    }

    settings_errors('reloopin_loyalty');

    $fields = reloopin_loyalty_get_settings();
    ?>
    <div class="wrap">
        <h1><?php esc_html_e('reLoopin Loyalty Settings', 'reloopin-loyalty'); ?></h1>
        <form method="post" action="options.php">
            <?php settings_fields('reloopin_loyalty_settings'); ?>
            <table class="form-table" role="presentation">
            <?php foreach ($fields as $field) :
                if ($field['type'] === 'sectionend') {
                    continue;
                }
                if ($field['type'] === 'title') : ?>
                    </table>
                    <h2><?php echo esc_html($field['title']); ?></h2>
                    <?php if (!empty($field['desc'])) : ?>
                        <p><?php echo esc_html($field['desc']); ?></p>
                    <?php endif; ?>
                    <table class="form-table" role="presentation">
                <?php continue;
                endif;

                $value   = get_option($field['id'], $field['default'] ?? '');
                $field_id = esc_attr($field['id']);
                ?>
                <tr>
                    <th scope="row"><label for="<?php echo $field_id; ?>"><?php echo esc_html($field['title']); ?></label></th>
                    <td>
                        <?php if ($field['type'] === 'text') : ?>
                            <input type="text" id="<?php echo $field_id; ?>" name="<?php echo $field_id; ?>" value="<?php echo esc_attr($value); ?>" class="regular-text" />
                        <?php elseif ($field['type'] === 'password') : ?>
                            <input type="password" id="<?php echo $field_id; ?>" name="<?php echo $field_id; ?>" value="<?php echo esc_attr($value); ?>" class="regular-text" />
                        <?php elseif ($field['type'] === 'checkbox') : ?>
                            <label>
                                <input type="checkbox" id="<?php echo $field_id; ?>" name="<?php echo $field_id; ?>" value="yes" <?php checked($value, 'yes'); ?> />
                                <?php echo esc_html($field['desc'] ?? ''); ?>
                            </label>
                        <?php elseif ($field['type'] === 'select') : ?>
                            <select id="<?php echo $field_id; ?>" name="<?php echo $field_id; ?>">
                                <?php foreach (($field['options'] ?? []) as $opt_val => $opt_label) : ?>
                                    <option value="<?php echo esc_attr($opt_val); ?>" <?php selected($value, $opt_val); ?>><?php echo esc_html($opt_label); ?></option>
                                <?php endforeach; ?>
                            </select>
                        <?php endif; ?>
                        <?php if ($field['type'] !== 'checkbox' && !empty($field['desc'])) : ?>
                            <p class="description"><?php echo esc_html($field['desc']); ?></p>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </table>
            <?php submit_button(); ?>
        </form>
    </div>
    <?php
}

function reloopin_loyalty_get_settings()
{
    return [
        [
            'title' => __('API Connection', 'reloopin-loyalty'),
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
            'desc' => __('Sent as the reloopin_api_key header to authenticate with the loyalty API.', 'reloopin-loyalty'),
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
            'title'   => __('Program name', 'reloopin-loyalty'),
            'desc'    => __('Displayed in the guest hero banner. Defaults to the site name.', 'reloopin-loyalty'),
            'id'      => 'reloopin_launcher_program_name',
            'type'    => 'text',
            'default' => '',
        ],
        [
            'title'   => __('Program icon', 'reloopin-loyalty'),
            'desc'    => __('Icon shown next to the program name in the guest hero. Choose a preset or leave as default.', 'reloopin-loyalty'),
            'id'      => 'reloopin_launcher_program_icon',
            'type'    => 'select',
            'default' => 'layers',
            'options' => [
                'layers' => __('Layers (default)', 'reloopin-loyalty'),
                'star'   => __('Star', 'reloopin-loyalty'),
                'heart'  => __('Heart', 'reloopin-loyalty'),
                'gem'    => __('Gem', 'reloopin-loyalty'),
                'gift'   => __('Gift', 'reloopin-loyalty'),
                'crown'  => __('Crown', 'reloopin-loyalty'),
            ],
        ],
        [
            'type' => 'sectionend',
            'id'   => 'reloopin_launcher_tab_end',
        ],
    ];
}
