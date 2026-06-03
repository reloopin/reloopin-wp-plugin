<?php
/**
 * Loyalty Customers
 *
 * Syncs newly registered WooCommerce customers to the reLoopin platform.
 */

if (!defined('ABSPATH')) {
    exit;
}

class ReLoopin_Loyalty_Customers
{
    private ReLoopin_Loyalty_API $api;

    public function __construct(ReLoopin_Loyalty_API $api)
    {
        $this->api = $api;
        add_action('woocommerce_created_customer', [$this, 'sync_new_customer'], 10, 3);
    }

    /**
     * Fires after WooCommerce creates a new customer (checkout or My Account registration).
     *
     * @param int   $customer_id       New WordPress user ID.
     * @param array $new_customer_data Data used to create the account.
     * @param bool  $password_generated Whether a password was auto-generated.
     */
    public function sync_new_customer(int $customer_id, array $new_customer_data, bool $password_generated): void
    {
        $user = get_userdata($customer_id);
        if (!$user || empty($user->user_email)) {
            return;
        }

        $first_name = get_user_meta($customer_id, 'first_name', true) ?: '';
        $last_name  = get_user_meta($customer_id, 'last_name', true) ?: '';
        $phone      = get_user_meta($customer_id, 'billing_phone', true) ?: '';

        $result = $this->api->create_platform_customer(
            $user->user_email,
            $first_name,
            $last_name,
            $phone
        );

        if (is_wp_error($result)) {
            $data   = $result->get_error_data('loyalty_api_error');
            $status = $data['status'] ?? 0;

            // 409 = customer already exists on the platform — not an error.
            if ($status !== 409) {
                $logger = wc_get_logger();
                $logger->error(
                    sprintf(
                        'reLoopin: Failed to sync customer #%d (%s): %s',
                        $customer_id,
                        $user->user_email,
                        $result->get_error_message()
                    ),
                    ['source' => 'reloopin-loyalty']
                );
            }
            return;
        }

        reloopin_loyalty_debug('Customer synced to platform', [
            'customer_id' => $customer_id,
            'email'       => $user->user_email,
        ]);
    }
}
