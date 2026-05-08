<?php
/**
 * reLoopin Loyalty Uninstall
 *
 * Fired when the plugin is deleted via the WordPress admin.
 */

if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

// Delete plugin options
delete_option('reloopin_loyalty_api_url');
delete_option('reloopin_loyalty_api_key');
delete_option('reloopin_loyalty_merchant_id');
delete_option('reloopin_loyalty_merchant_code');
delete_option('reloopin_launcher_enabled');
delete_option('reloopin_launcher_position');
delete_option('reloopin_launcher_branding');

// Delete WooCommerce section-title marker options
delete_option('reloopin_loyalty_section_title');
delete_option('reloopin_launcher_tab_title');
delete_option('reloopin_loyalty_section_end');
delete_option('reloopin_launcher_tab_end');

// Delete all plugin transients using safe parameterised queries
global $wpdb;

// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
$wpdb->query(
    $wpdb->prepare(
        "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
        $wpdb->esc_like('_transient_rl_') . '%',
        $wpdb->esc_like('_transient_timeout_rl_') . '%'
    )
);
