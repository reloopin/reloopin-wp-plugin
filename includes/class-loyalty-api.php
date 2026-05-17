<?php
/**
 * Loyalty API Client
 *
 * Wraps every call to the reLoopin loyalty backend REST API.
 *
 * Endpoints used:
 *   POST /api/v1/merchant/transaction-entry   — post a transaction (auto-awards points)
 *   GET  /api/v1/merchant/points/customer/balance      — get customer balance + tier
 *   GET  /api/v1/merchant/points/history      — get paginated ledger
 *   POST /api/v1/merchant/points/redeem       — deduct points from balance
 */

if (!defined('ABSPATH')) {
    exit;
}

class ReLoopin_Loyalty_API
{

    private string $base_url;
    private string $api_key;
    private string $merchant_id;
    private string $merchant_code;
    private string $currency_code;

    public function __construct()
    {
        $this->base_url = rtrim(get_option('reloopin_loyalty_api_url', ''), '/');
        $this->api_key = get_option('reloopin_loyalty_api_key', '');
        $this->merchant_id = get_option('reloopin_loyalty_merchant_id', '');
        $this->merchant_code = get_option('reloopin_loyalty_merchant_code', '');
        $this->currency_code = get_woocommerce_currency();
    }

    // -----------------------------------------------------------------------
    // Public API methods
    // -----------------------------------------------------------------------

    /**
     * Post a transaction entry to the loyalty backend.
     *
     * Uses reloopin_api_key + merchant_code headers (transaction-entry auth).
     */
    public function create_transaction(array $args): array|WP_Error
    {
        $body = [
            'merchant_id' => $this->merchant_id,
            'platform' => RELOOPIN_LOYALTY_PLATFORM,
            'customer_email' => $args['customer_email'] ?? '',
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

        return $this->post('/api/v1/merchant/transaction-entry', $body, $this->transaction_headers());
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
        ];

        if (!empty($phone)) {
            $body['phone_number'] = $phone;
        }

        reloopin_loyalty_debug('create_platform_customer → request body', $body);

        return $this->post('/api/v1/merchant/platform/customer', $body, $this->platform_headers());
    }

    /**
     * Get a customer's current points balance and tier.
     *
     * Response: available_points, lifetime_points, redeemed_points, expired_points, tier, updated_at
     */
    public function get_balance(string $customer_ref): array|WP_Error
    {
        reloopin_loyalty_debug('get_balance → request', ['customer_ref' => $customer_ref]);

        return $this->get('/api/v1/merchant/points/customer/balance', [
            'merchant_id' => $this->merchant_id,
            'customer_ref' => $customer_ref,
        ]);
    }

    /**
     * Get paginated ledger history for a customer.
     *
     * @param string|null $entry_type  earn|redeem|bonus|expire|void|adjust
     */
    public function get_history(string $customer_ref, int $page = 1, int $page_size = 10, ?string $entry_type = null): array|WP_Error
    {
        $params = [
            'merchant_id' => $this->merchant_id,
            'customer_ref' => $customer_ref,
            'page' => $page,
            'page_size' => $page_size,
        ];

        if ($entry_type !== null) {
            $params['entry_type'] = $entry_type;
        }

        reloopin_loyalty_debug('get_history → request', $params);

        return $this->get('/api/v1/merchant/points/history', $params);
    }

    /**
     * Get all points earning/spending rules for the merchant.
     *
     * @param string|null $event_type  Filter by event type (e.g. product_purchase, signup, birthday, referral).
     */
    public function get_rules(?string $event_type = null): array|WP_Error
    {
        if ($event_type !== null) {
            $endpoint = '/api/v1/merchant/points/rules/event/' . urlencode($event_type);
            $params = ['merchant_id' => $this->merchant_id];
        } else {
            $endpoint = '/api/v1/merchant/points/rules/';
            $params = [
                'merchant_id' => $this->merchant_id,
                'active_only' => 'true',
            ];
        }

        reloopin_loyalty_debug('get_rules → request', $params);

        return $this->get($endpoint, $params);
    }

    /**
     * Get tier configurations for the merchant.
     */
    public function get_tiers(): array|WP_Error
    {
        reloopin_loyalty_debug('get_tiers → request');

        return $this->get('/api/v1/merchant/tiers/', [
            'merchant_id' => $this->merchant_id,
            'active_only' => 'true',
        ]);
    }

    /**
     * Redeem (deduct) points from a customer's balance.
     *
     * @param int         $points  Must be > 0.
     * @param string|null $notes   Optional note.
     */
    public function redeem_points(string $customer_ref, int $points, ?string $notes = null): array|WP_Error
    {
        $body = [
            'merchant_id' => $this->merchant_id,
            'customer_ref' => $customer_ref,
            'points' => $points,
        ];

        if ($notes !== null) {
            $body['notes'] = $notes;
        }

        reloopin_loyalty_debug('redeem_points → request', [
            'customer_ref' => $customer_ref,
            'points' => $points,
            'notes' => $notes,
        ]);

        return $this->post('/api/v1/merchant/points/redeem', $body);
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

        reloopin_loyalty_debug("GET {$endpoint}");

        $response = wp_remote_get($url, [
            'headers' => $this->bearer_headers(),
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
            'headers' => $headers ?: $this->bearer_headers(),
            'body' => wp_json_encode($body),
            'timeout' => 10,
        ]);

        return $this->parse_response($response, 'POST', $endpoint);
    }

    /** Headers for transaction-entry: reloopin_api_key + merchant_code. */
    private function transaction_headers(): array
    {
        return [
            'reloopin_api_key' => $this->api_key,
            'merchant_code' => $this->merchant_code,
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
        ];
    }

    /** Headers for platform endpoints: reloopin_api_key + merchant_id. */
    private function platform_headers(): array
    {
        return [
            'reloopin_api_key' => $this->api_key,
            'merchant_id'      => $this->merchant_id,
            'Content-Type'     => 'application/json',
            'Accept'           => 'application/json',
        ];
    }

    /** Headers for all other endpoints: standard Bearer token. */
    private function bearer_headers(): array
    {
        return [
            'Authorization' => 'Bearer ' . $this->api_key,
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
        ];
    }

    private function parse_response(array|WP_Error $response, string $method, string $endpoint): array|WP_Error
    {
        if (is_wp_error($response)) {
            reloopin_loyalty_debug("{$method} {$endpoint} → WP_Error", $response->get_error_message());
            return $response;
        }

        $status_code = wp_remote_retrieve_response_code($response);
        $body = json_decode(wp_remote_retrieve_body($response), true);

        if ($status_code < 200 || $status_code >= 300) {
            $message = $body['detail'] ?? $body['error'] ?? $body['message'] ?? 'HTTP ' . $status_code;
            reloopin_loyalty_debug("{$method} {$endpoint} → HTTP {$status_code} error", $message);
            return new WP_Error('loyalty_api_error', $message, ['status' => $status_code]);
        }

        reloopin_loyalty_debug("{$method} {$endpoint} → HTTP {$status_code} OK");

        return is_array($body) ? $body : [];
    }
}
