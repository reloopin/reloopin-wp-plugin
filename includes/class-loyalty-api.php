<?php
/**
 * Loyalty API Client
 * Wraps every call to the reLoopin loyalty backend REST API.
 */

if (!defined('ABSPATH')) {
    exit;
}

class ReLoopin_Loyalty_API
{

    private string $base_url;
    private string $api_key;
    private string $merchant_id;
    private string $currency_code;

    public function __construct()
    {
        $this->base_url      = rtrim(get_option('reloopin_loyalty_api_url', ''), '/');
        $this->api_key       = get_option('reloopin_loyalty_api_key', '');
        $this->merchant_id   = get_option('reloopin_loyalty_merchant_id', '');
        $this->currency_code = get_woocommerce_currency();
    }

    // -----------------------------------------------------------------------
    // Public API methods
    // -----------------------------------------------------------------------

    /**
     * Post a transaction entry to the loyalty backend.
     *
     * Merchant and platform are derived from the API key.
     */
    public function create_transaction(array $args): array|WP_Error
    {
        $customer_ref = trim((string) ($args['customer_ref'] ?? ''));
        if ($customer_ref === '' && !empty($args['order_id'])) {
            $customer_ref = 'guest-' . (string) $args['order_id'];
        }

        $body = [
            'customer_ref' => $customer_ref,
            'order_id' => (string) ($args['order_id'] ?? ''),
            'event_type' => $args['event_type'] ?? 'product_purchase',
            'total_amount' => (string) ($args['total_amount'] ?? '1.00'),
            'currency_code' => $this->currency_code,
            'transaction_status' => $args['transaction_status'] ?? 'completed',
        ];

        if (!empty($args['customer_phone'])) {
            $body['customer_phone_number'] = $args['customer_phone'];
        }

        if (!empty($args['transaction_metadata'])) {
            $body['transaction_metadata'] = $args['transaction_metadata'];
        }

        reloopin_loyalty_debug('create_transaction → request body', $body);

        return $this->post('/api/v1/merchant/transaction-entry', $body);
    }

    /**
     * Create a customer in the reLoopin platform.
     *
     * Uses reloopin_api_key + merchant_id headers (platform auth).
     */
    public function create_platform_customer(string $email, string $first_name, string $last_name, string $phone = ''): array|WP_Error
    {
        $body = [
            'merchant_id' => $this->merchant_id,
            'email'       => $email,
            'first_name'  => $first_name ?: 'N/A',
            'last_name'   => $last_name ?: 'N/A',
            'date_of_birth' => '2026-01-01',
        ];

        if (!empty($phone)) {
            $body['phone_number'] = $phone;
        }

        reloopin_loyalty_debug('create_platform_customer → request body', $body);

        $endpoint = '/api/v1/external/merchant/' . urlencode($this->merchant_id) . '/customer';

        return $this->post($endpoint, $body, $this->platform_headers());
    }

    /**
     * Get a customer's current points balance and tier.
     *
     * Response: available_points, lifetime_points, redeemed_points, expired_points, tier, updated_at
     */
    public function get_balance(string $customer_ref): array|WP_Error
    {
        reloopin_loyalty_debug('get_balance → request', ['customer_ref' => $customer_ref]);

        $result = $this->get('/api/v1/merchant/points/customer/balance', [
            'customer_ref' => $customer_ref,
        ]);

        // 404 means the customer has no points record yet — return zero balance.
        if (is_wp_error($result)) {
            $data = $result->get_error_data('loyalty_api_error');
            if (isset($data['status']) && (int) $data['status'] === 404) {
                return [
                    'available_points' => 0,
                    'lifetime_points'  => 0,
                    'redeemed_points'  => 0,
                    'expired_points'   => 0,
                    'tier'             => '',
                ];
            }
        }

        // Guard against unexpected response shapes (e.g. empty array from edge cases).
        if (is_array($result) && !isset($result['available_points'])) {
            reloopin_loyalty_debug('get_balance → unexpected response shape', array_keys($result));
            return new WP_Error(
                'loyalty_bad_response',
                'API response missing expected fields',
                ['keys' => array_keys($result)]
            );
        }

        return $result;
    }

    /**
     * Get paginated ledger history for a customer.
     *
     * @param string|null $entry_type  EARN or REDEEM
     */
    public function get_history(string $customer_ref, int $page = 1, int $page_size = 10, ?string $entry_type = null): array|WP_Error
    {
        $params = [
            'customer_ref' => $customer_ref,
            'page' => $page,
            'page_size' => $page_size,
        ];

        if ($entry_type !== null && $entry_type !== '') {
            $params['entry_type'] = strtoupper($entry_type);
        }

        reloopin_loyalty_debug('get_history → request', $params);

        return $this->get('/api/v1/external/points/history', $params);
    }

    /**
     * Get all active points earning/spending rules for the merchant.
     */
    public function get_rules(): array|WP_Error
    {
        reloopin_loyalty_debug('get_rules → request');

        return $this->get('/api/v1/external/points/rules');
    }

    /**
     * Get eligible campaigns for a customer.
     *
     * @param string $customer_ref Customer email address.
     */
    public function get_campaigns(string $customer_ref): array|WP_Error
    {
        $params = ['customer_ref' => $customer_ref];

        reloopin_loyalty_debug('get_campaigns → request', $params);

        $result = $this->get('/api/v1/external/customers/eligible-campaigns', $params);

        // 404 means no campaigns are configured for this merchant yet — return empty.
        if (is_wp_error($result)) {
            $data = $result->get_error_data('loyalty_api_error');
            if (isset($data['status']) && (int) $data['status'] === 404) {
                return [];
            }
        }

        return $result;
    }

    /**
     * Get a customer's active coupons.
     *
     * @param string $customer_ref Customer email or phone.
     * @param string $status       One of: active, redeemed, expired, voided.
     */
    public function get_customer_coupons(string $customer_ref, string $status = 'active'): array|WP_Error
    {
        $endpoint = '/api/v1/external/customers/' . urlencode($customer_ref) . '/coupons';
        $params   = [];
        if ($status) {
            $params['status'] = $status;
        }

        reloopin_loyalty_debug('get_customer_coupons → request', [
            'customer_ref' => $customer_ref,
            'status'       => $status,
        ]);

        $result = $this->get($endpoint, $params);

        // 404 means the customer has no coupons — return empty.
        if (is_wp_error($result)) {
            $data = $result->get_error_data('loyalty_api_error');
            if (isset($data['status']) && (int) $data['status'] === 404) {
                return [];
            }
        }

        return $result;
    }

    /**
     * Generate a coupon code for a campaign.
     *
     * Response: code, campaign_id, customer_ref, expires_at, discount_type, discount_value, platform_sync
     */
    public function generate_coupon(int $campaign_id, string $customer_ref): array|WP_Error
    {
        reloopin_loyalty_debug('generate_coupon → request', [
            'campaign_id'  => $campaign_id,
            'customer_ref' => $customer_ref,
        ]);

        $result = $this->post('/api/v1/external/coupons/generate', [
            'campaign_id'  => $campaign_id,
            'customer_ref' => $customer_ref,
        ]);

        return $this->validate_platform_sync($result, 'generate_coupon');
    }

    /**
     * Notify the backend that a generated coupon was used at checkout.
     */
    public function redeem_coupon(
        string $code,
        string $customer_ref,
        string $order_ref,
        string $order_total,
        string $currency_code
    ): array|WP_Error {
        reloopin_loyalty_debug('redeem_coupon → request', [
            'code'          => $code,
            'customer_ref'  => $customer_ref,
            'order_ref'     => $order_ref,
            'order_total'   => $order_total,
            'currency_code' => $currency_code,
        ]);

        $result = $this->post('/api/v1/external/coupons/redeem', [
            'code'          => $code,
            'customer_ref'  => $customer_ref,
            'order_ref'     => $order_ref,
            'order_total'   => $order_total,
            'currency_code' => $currency_code,
        ]);

        return $this->validate_platform_sync($result, 'redeem_coupon');
    }

    // -----------------------------------------------------------------------
    // Private HTTP helpers
    // -----------------------------------------------------------------------

    private function get(string $endpoint, array $query_params = []): array|WP_Error
    {
        if (empty($this->base_url)) {
            reloopin_loyalty_debug('GET aborted — API URL not configured', $endpoint);
            return new WP_Error('loyalty_no_url', 'Loyalty API URL is not configured.');
        }

        $url = add_query_arg($query_params, $this->base_url . $endpoint);

        reloopin_loyalty_debug("GET {$url}");

        $response = wp_remote_get($url, [
            'headers' => $this->api_headers(),
            'timeout' => 10,
        ]);

        return $this->parse_response($response, 'GET', $endpoint);
    }

    private function post(string $endpoint, array $body, array $headers = []): array|WP_Error
    {
        if (empty($this->base_url)) {
            reloopin_loyalty_debug('POST aborted — API URL not configured', $endpoint);
            return new WP_Error('loyalty_no_url', 'Loyalty API URL is not configured.');
        }

        reloopin_loyalty_debug("POST {$this->base_url}{$endpoint}");

        $response = wp_remote_post($this->base_url . $endpoint, [
            'headers' => $headers ?: $this->api_headers(),
            'body' => wp_json_encode($body),
            'timeout' => 10,
        ]);

        return $this->parse_response($response, 'POST', $endpoint);
    }

    /** All documented endpoints authenticate via reloopin_api_key; merchant is derived from the key. */
    private function api_headers(): array
    {
        return [
            'reloopin_api_key' => $this->api_key,
            'Content-Type'     => 'application/json',
            'Accept'           => 'application/json',
        ];
    }

    /** Headers for undocumented legacy endpoints that still require merchant_id. */
    private function platform_headers(): array
    {
        return [
            'reloopin_api_key' => $this->api_key,
            'merchant_id'      => $this->merchant_id,
            'Content-Type'     => 'application/json',
            'Accept'           => 'application/json',
        ];
    }

    /**
     * Log platform_sync results and surface hard failures.
     *
     * @param array|WP_Error $result
     */
    private function validate_platform_sync(array|WP_Error $result, string $context): array|WP_Error
    {
        if (is_wp_error($result)) {
            return $result;
        }

        $sync = $result['platform_sync'] ?? null;
        if (!is_array($sync)) {
            return $result;
        }

        foreach ($sync as $entry) {
            if (!is_array($entry)) {
                continue;
            }

            $status   = $entry['status'] ?? '';
            $platform = $entry['platform'] ?? 'unknown';
            $error    = $entry['error'] ?? null;

            if ($status === 'failed') {
                reloopin_loyalty_debug("{$context} → platform_sync failed", $entry);
                return new WP_Error(
                    'loyalty_platform_sync_failed',
                    is_string($error) && $error !== '' ? $error : "Platform sync failed for {$platform}",
                    ['platform_sync' => $sync]
                );
            }

            if ($status === 'skipped') {
                reloopin_loyalty_debug("{$context} → platform_sync skipped", $entry);
            }
        }

        return $result;
    }

    private function parse_response(array|WP_Error $response, string $method, string $endpoint): array|WP_Error
    {
        if (is_wp_error($response)) {
            reloopin_loyalty_debug("{$method} {$endpoint} → WP_Error", $response->get_error_message());
            return $response;
        }

        $status_code = wp_remote_retrieve_response_code($response);
        $raw_body    = wp_remote_retrieve_body($response);
        $body        = json_decode($raw_body, true);

        if ($status_code < 200 || $status_code >= 300) {
            $message = $body['detail'] ?? $body['error'] ?? $body['message'] ?? 'HTTP ' . $status_code;
            reloopin_loyalty_debug("{$method} {$endpoint} → HTTP {$status_code} error", $message);
            return new WP_Error('loyalty_api_error', $message, ['status' => $status_code]);
        }

        if ($body === null && json_last_error() !== JSON_ERROR_NONE) {
            reloopin_loyalty_debug("{$method} {$endpoint} → JSON decode failed", [
                'error'        => json_last_error_msg(),
                'body_preview' => substr($raw_body, 0, 500),
            ]);
            return new WP_Error('loyalty_json_error', 'Invalid JSON response: ' . json_last_error_msg());
        }

        reloopin_loyalty_debug("{$method} {$endpoint} → HTTP {$status_code} OK", substr($raw_body, 0, 500));

        return is_array($body) ? $body : [];
    }
}
