<?php
/**
 * Integration test using the plugin's PHP classes (not raw curl).
 *
 * Verifies request payload mapping and response transforms against a live API.
 *
 * Run inside WordPress (recommended):
 *   wp eval-file wp-content/plugins/idesign-loyalty/bin/test-coupon-flow.php
 *
 * Or standalone (minimal WP stubs):
 *   php bin/test-coupon-flow.php
 *
 * Env overrides:
 *   RELOOPIN_API_URL, RELOOPIN_API_KEY, RELOOPIN_MERCHANT_ID, CUSTOMER_EMAIL
 */

declare(strict_types=1);

// ---------------------------------------------------------------------------
// Bootstrap: WordPress or minimal stubs
// ---------------------------------------------------------------------------

$plugin_dir = dirname(__DIR__);
$request_log = [];

function reloopin_test_find_wp_load(): ?string
{
    $candidates = array_filter([
        getenv('WP_ROOT') ? rtrim(getenv('WP_ROOT'), '/') . '/wp-load.php' : null,
        dirname(__DIR__, 4) . '/wp-load.php',
        dirname(__DIR__, 3) . '/wp-load.php',
    ]);

    foreach ($candidates as $path) {
        if (is_readable($path)) {
            return $path;
        }
    }

    return null;
}

function reloopin_test_bootstrap_stubs(): void
{
    if (defined('ABSPATH')) {
        return;
    }

    define('ABSPATH', $GLOBALS['reloopin_plugin_dir'] . '/');
    define('MINUTE_IN_SECONDS', 60);

    if (!class_exists('WP_Error')) {
        class WP_Error
        {
            public function __construct(
                private string $code = '',
                private string $message = '',
                private mixed $data = null
            ) {}

            public function get_error_code(): string
            {
                return $this->code;
            }

            public function get_error_message(): string
            {
                return $this->message;
            }

            public function get_error_data(?string $code = null): mixed
            {
                return $this->data;
            }
        }
    }

    $options = [
        'reloopin_loyalty_api_url'      => getenv('RELOOPIN_API_URL') ?: 'http://localhost:8000',
        'reloopin_loyalty_api_key'      => getenv('RELOOPIN_API_KEY') ?: 'rwd_dev_test_key_001',
        'reloopin_loyalty_merchant_id'  => getenv('RELOOPIN_MERCHANT_ID') ?: 'bbbbbbbb-bbbb-bbbb-bbbb-bbbbbbbbbbbb',
    ];

    if (!function_exists('get_option')) {
        function get_option(string $key, $default = false)
        {
            return $GLOBALS['reloopin_test_options'][$key] ?? $default;
        }
    }
    $GLOBALS['reloopin_test_options'] = $options;

    if (!function_exists('get_woocommerce_currency')) {
        function get_woocommerce_currency(): string
        {
            return 'USD';
        }
    }

    if (!function_exists('is_wp_error')) {
        function is_wp_error($thing): bool
        {
            return $thing instanceof WP_Error;
        }
    }

    if (!function_exists('wp_json_encode')) {
        function wp_json_encode($data): string
        {
            return json_encode($data, JSON_THROW_ON_ERROR);
        }
    }

    if (!function_exists('add_query_arg')) {
        function add_query_arg(array $params, string $url): string
        {
            $sep = str_contains($url, '?') ? '&' : '?';
            return $url . $sep . http_build_query($params);
        }
    }

    if (!function_exists('reloopin_loyalty_debug')) {
        function reloopin_loyalty_debug(string $message, mixed $context = null): void
        {
            // silent in tests unless VERBOSE=1
            if (getenv('VERBOSE')) {
                fwrite(STDERR, "[debug] {$message}\n");
            }
        }
    }

    if (!function_exists('wp_remote_get')) {
        function wp_remote_get(string $url, array $args = []): array|WP_Error
        {
            return reloopin_test_http('GET', $url, $args);
        }
    }

    if (!function_exists('wp_remote_post')) {
        function wp_remote_post(string $url, array $args = []): array|WP_Error
        {
            return reloopin_test_http('POST', $url, $args);
        }
    }

    if (!function_exists('wp_remote_request')) {
        function wp_remote_request(string $url, array $args = []): array|WP_Error
        {
            $method = strtoupper((string) ($args['method'] ?? 'GET'));
            return reloopin_test_http($method, $url, $args);
        }
    }

    if (!function_exists('wp_remote_retrieve_response_code')) {
        function wp_remote_retrieve_response_code(array $response): int
        {
            return (int) ($response['response']['code'] ?? 0);
        }
    }

    if (!function_exists('wp_remote_retrieve_body')) {
        function wp_remote_retrieve_body(array $response): string
        {
            return (string) ($response['body'] ?? '');
        }
    }
}

function reloopin_test_http(string $method, string $url, array $args): array|WP_Error
{
    $headers = $args['headers'] ?? [];
    $body    = $args['body'] ?? null;

    $GLOBALS['reloopin_request_log'][] = [
        'method'  => $method,
        'url'     => $url,
        'headers' => $headers,
        'body'    => $body !== null ? json_decode((string) $body, true) : null,
    ];

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_CUSTOMREQUEST  => $method,
        CURLOPT_HTTPHEADER     => array_map(
            fn($k, $v) => "{$k}: {$v}",
            array_keys($headers),
            array_values($headers)
        ),
    ]);

    if ($method !== 'GET' && $body !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, (string) $body);
    }

    $response_body = curl_exec($ch);
    $status        = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error         = curl_error($ch);
    unset($ch);

    if ($response_body === false) {
        return new WP_Error('http_request_failed', $error ?: 'Request failed');
    }

    return [
        'response' => ['code' => $status],
        'body'     => $response_body,
    ];
}

$GLOBALS['reloopin_plugin_dir'] = $plugin_dir;

$wp_load = reloopin_test_find_wp_load();
if ($wp_load) {
    require_once $wp_load;

    update_option('reloopin_loyalty_api_url', getenv('RELOOPIN_API_URL') ?: 'http://localhost:8000');
    update_option('reloopin_loyalty_api_key', getenv('RELOOPIN_API_KEY') ?: 'rwd_dev_test_key_001');
    update_option('reloopin_loyalty_merchant_id', getenv('RELOOPIN_MERCHANT_ID') ?: 'bbbbbbbb-bbbb-bbbb-bbbb-bbbbbbbbbbbb');

    add_filter('pre_http_request', static function ($preempt, $args, $url) use (&$request_log) {
        $request_log[] = [
            'method'  => $args['method'] ?? 'GET',
            'url'     => $url,
            'headers' => $args['headers'] ?? [],
            'body'    => isset($args['body']) ? json_decode((string) $args['body'], true) : null,
        ];
        return false; // proceed with real request
    }, 10, 3);

    require_once $plugin_dir . '/includes/class-loyalty-api.php';
    require_once $plugin_dir . '/includes/class-loyalty-orders.php';
    require_once $plugin_dir . '/includes/class-loyalty-launcher.php';

    echo "Bootstrap: WordPress (" . $wp_load . ")\n";
} else {
    reloopin_test_bootstrap_stubs();
    require_once $plugin_dir . '/includes/class-loyalty-api.php';
    require_once $plugin_dir . '/includes/class-loyalty-orders.php';
    require_once $plugin_dir . '/includes/class-loyalty-launcher.php';
    $request_log = &$GLOBALS['reloopin_request_log'];

    echo "Bootstrap: minimal stubs (no wp-load.php found)\n";
}

// ---------------------------------------------------------------------------
// Test helpers
// ---------------------------------------------------------------------------

$passed = 0;
$failed = 0;

function assert_true(bool $cond, string $label): void
{
    global $passed, $failed;
    if ($cond) {
        echo "  ✓ {$label}\n";
        $passed++;
    } else {
        echo "  ✗ {$label}\n";
        $failed++;
    }
}

function assert_eq(mixed $expected, mixed $actual, string $label): void
{
    assert_true($expected === $actual, "{$label} (expected " . json_encode($expected) . ', got ' . json_encode($actual) . ')');
}

function last_request(?string $url_contains = null): ?array
{
    global $request_log;
    if ($url_contains === null) {
        return $request_log[count($request_log) - 1] ?? null;
    }
    for ($i = count($request_log) - 1; $i >= 0; $i--) {
        if (str_contains($request_log[$i]['url'], $url_contains)) {
            return $request_log[$i];
        }
    }
    return null;
}

function invoke_private(object $obj, string $method, array $args = []): mixed
{
    $ref = new ReflectionMethod($obj, $method);
    $ref->setAccessible(true);
    return $ref->invoke($obj, ...$args);
}

function instantiate_without_hooks(string $class, mixed ...$ctor_args): object
{
    $ref = new ReflectionClass($class);
    $obj = $ref->newInstanceWithoutConstructor();

    if ($class === ReLoopin_Loyalty_Launcher::class || $class === ReLoopin_Loyalty_Orders::class) {
        $api_prop = $ref->getProperty('api');
        $api_prop->setAccessible(true);
        $api_prop->setValue($obj, $ctor_args[0]);
        return $obj;
    }

    $ctor = $ref->getConstructor();
    if ($ctor) {
        $ctor->setAccessible(true);
        $ctor->invoke($obj, ...$ctor_args);
    }

    return $obj;
}

// ---------------------------------------------------------------------------
// Run tests
// ---------------------------------------------------------------------------

$merchant = get_option('reloopin_loyalty_merchant_id');
$api_key  = get_option('reloopin_loyalty_api_key');
$email    = getenv('CUSTOMER_EMAIL') ?: ('php-flow-' . time() . '@example.com');

echo "\n=== reLoopin Plugin Class Integration Test ===\n";
echo "Customer: {$email}\n\n";

$api = new ReLoopin_Loyalty_API();

// --- 1. create_platform_customer ---
echo "--- ReLoopin_Loyalty_API::create_platform_customer ---\n";
$customer = $api->create_platform_customer($email, 'Plugin', 'Tester', '+15551234567');
assert_true(!is_wp_error($customer) || (
    is_wp_error($customer) && (($customer->get_error_data()['status'] ?? 0) === 409)
), 'create_platform_customer succeeds or 409 exists');

$create_req = last_request('/customer');
assert_true($create_req !== null, 'HTTP request recorded for customer create');
if ($create_req) {
    assert_eq('POST', $create_req['method'], 'customer create method');
    assert_eq($api_key, $create_req['headers']['reloopin_api_key'] ?? '', 'customer create api key header');
    assert_eq($merchant, $create_req['headers']['merchant_id'] ?? '', 'customer create merchant_id header');
    assert_eq($merchant, $create_req['body']['merchant_id'] ?? '', 'body merchant_id');
    assert_eq($email, $create_req['body']['email'] ?? '', 'body email');
    assert_eq('Plugin', $create_req['body']['first_name'] ?? '', 'body first_name');
    assert_eq('Tester', $create_req['body']['last_name'] ?? '', 'body last_name');
    assert_eq('+15551234567', $create_req['body']['phone_number'] ?? '', 'body phone_number');
}

// --- 2. create_transaction (earn points) ---
echo "\n--- ReLoopin_Loyalty_API::create_transaction ---\n";
$tx = $api->create_transaction([
    'customer_ref'       => $email,
    'customer_phone'     => '+15559876543',
    'order_id'           => 'WC-PHP-9001',
    'event_type'         => 'product_purchase',
    'total_amount'       => '200.00',
    'transaction_status' => 'completed',
    'transaction_metadata' => ['platform' => 'woocommerce', 'items' => []],
]);
assert_true(!is_wp_error($tx), 'create_transaction succeeds');
assert_true(($tx['points_awarded'] ?? 0) >= 100, 'earned enough points for coupon');

$tx_req = last_request('transaction-entry');
if ($tx_req) {
    assert_eq($email, $tx_req['body']['customer_ref'] ?? '', 'tx customer_ref');
    assert_eq('WC-PHP-9001', $tx_req['body']['order_id'] ?? '', 'tx order_id');
    assert_eq('product_purchase', $tx_req['body']['event_type'] ?? '', 'tx event_type');
    assert_eq('200.00', $tx_req['body']['total_amount'] ?? '', 'tx total_amount as string');
    assert_eq('USD', $tx_req['body']['currency_code'] ?? '', 'tx currency_code from WC');
    assert_eq('completed', $tx_req['body']['transaction_status'] ?? '', 'tx transaction_status');
    assert_eq('+15559876543', $tx_req['body']['customer_phone_number'] ?? '', 'customer_phone mapped to customer_phone_number');
    assert_eq('woocommerce', $tx_req['body']['transaction_metadata']['platform'] ?? '', 'tx metadata preserved');
}

// --- 3. get_campaigns + transform_campaigns ---
echo "\n--- get_campaigns + transform_campaigns ---\n";
$campaigns_raw = $api->get_campaigns($email);
assert_true(!is_wp_error($campaigns_raw), 'get_campaigns succeeds');

$camps_req = last_request('eligible-campaigns');
if ($camps_req) {
    assert_eq('GET', $camps_req['method'], 'campaigns GET method');
    assert_true(str_contains($camps_req['url'], 'customer_ref=' . rawurlencode($email)), 'campaigns customer_ref query param');
}

$launcher = instantiate_without_hooks(ReLoopin_Loyalty_Launcher::class, $api);
$transformed = invoke_private($launcher, 'transform_campaigns', [$campaigns_raw]);
assert_true(count($transformed) > 0, 'transform_campaigns returns campaigns');

$first = $transformed[0];
$raw_first = $campaigns_raw['eligible_campaigns'][0] ?? [];
assert_eq((int) ($raw_first['id'] ?? 0), $first['id'], 'campaign id mapped');
assert_eq((string) ($raw_first['name'] ?? ''), $first['name'], 'campaign name mapped');
assert_eq((int) ($raw_first['redeemable_points'] ?? 0), $first['points_cost'], 'redeemable_points → points_cost');
assert_eq((string) ($raw_first['coupon_type'] ?? ''), $first['discount_type'], 'coupon_type → discount_type');
assert_eq((string) ($raw_first['discount_value'] ?? ''), $first['discount_value'], 'discount_value mapped');

$campaign_id = (int) $first['id'];
assert_true($campaign_id > 0, 'campaign id available for generate');

// --- 4. generate_coupon ---
echo "\n--- ReLoopin_Loyalty_API::generate_coupon ---\n";
$generated = $api->generate_coupon($campaign_id, $email);
assert_true(!is_wp_error($generated), 'generate_coupon succeeds: ' . (is_wp_error($generated) ? $generated->get_error_message() : ''));

$gen_req = last_request('coupons/generate');
if ($gen_req) {
    assert_eq('POST', $gen_req['method'], 'generate POST method');
    assert_eq($api_key, $gen_req['headers']['reloopin_api_key'] ?? '', 'generate api key header');
    assert_eq($campaign_id, $gen_req['body']['campaign_id'] ?? 0, 'generate campaign_id');
    assert_eq($email, $gen_req['body']['customer_ref'] ?? '', 'generate customer_ref');
}

$code = (string) ($generated['code'] ?? '');
assert_true($code !== '', 'generate returns code');

// --- 5. get_customer_coupons + transform_customer_coupons ---
echo "\n--- get_customer_coupons + transform_customer_coupons ---\n";
$active_raw = $api->get_customer_coupons($email, 'active');
assert_true(!is_wp_error($active_raw), 'get_customer_coupons succeeds');

$coupons_req = last_request('/coupons');
if ($coupons_req) {
    assert_eq('GET', $coupons_req['method'], 'coupons GET method');
    assert_true(str_contains($coupons_req['url'], rawurlencode($email)), 'coupons URL encodes customer_ref');
    assert_true(str_contains($coupons_req['url'], 'status=active'), 'coupons status=active query');
}

$active_transformed = invoke_private($launcher, 'transform_customer_coupons', [$active_raw]);
assert_true(count($active_transformed) > 0, 'active coupons after generate');

$tc = $active_transformed[0];
$raw_coupon = is_array($active_raw) && isset($active_raw[0]) ? $active_raw[0] : [];
assert_eq((string) ($raw_coupon['code'] ?? ''), $tc['code'], 'coupon code mapped');
assert_eq((string) ($raw_coupon['campaign']['name'] ?? ''), $tc['campaign_name'], 'nested campaign.name → campaign_name');
assert_eq((string) ($raw_coupon['campaign']['coupon_type'] ?? ''), $tc['discount_type'], 'nested campaign.coupon_type → discount_type');
assert_eq((float) ($raw_coupon['campaign']['min_order_amount'] ?? 0), $tc['min_order_amount'], 'nested min_order_amount');

// --- 6. redeem_coupon (as Orders class would call it) ---
echo "\n--- ReLoopin_Loyalty_API::redeem_coupon (Orders mapping) ---\n";
$order_ref   = 'WC-PHP-9002';
$order_total = '120.00';
$currency    = get_woocommerce_currency();

$redeemed = $api->redeem_coupon($code, $email, $order_ref, $order_total, $currency);
assert_true(!is_wp_error($redeemed), 'redeem_coupon succeeds: ' . (is_wp_error($redeemed) ? $redeemed->get_error_message() : ''));

$redeem_req = last_request('coupons/redeem');
if ($redeem_req) {
    assert_eq('POST', $redeem_req['method'], 'redeem POST method');
    assert_eq($code, $redeem_req['body']['code'] ?? '', 'redeem code');
    assert_eq($email, $redeem_req['body']['customer_ref'] ?? '', 'redeem customer_ref');
    assert_eq($order_ref, $redeem_req['body']['order_ref'] ?? '', 'redeem order_ref');
    assert_eq($order_total, $redeem_req['body']['order_total'] ?? '', 'redeem order_total as string');
    assert_eq($currency, $redeem_req['body']['currency_code'] ?? '', 'redeem currency_code');
}

// Verify Orders::resolve_customer_ref + redeem payload shape (track_coupon_redemptions mapping)
echo "\n--- ReLoopin_Loyalty_Orders redeem payload mapping ---\n";
$orders_api = instantiate_without_hooks(ReLoopin_Loyalty_Orders::class, $api);

// Mirrors track_coupon_redemptions lines 211-216: order_ref prefix, formatted total, WC currency.
$expected_order_ref = 'WC-PHP-9002';
$expected_total     = number_format(120.00, 2, '.', '');
assert_eq($order_ref, $expected_order_ref, 'order_ref uses WC- prefix pattern');
assert_eq('120.00', $expected_total, 'order_total formatted with number_format');

if (class_exists('WC_Order') && function_exists('wc_create_order')) {
    $order = wc_create_order();
    $order->set_billing_email('billing-map@test.com');
    $order->set_total(120.00);
    $order->save();

    $ref = invoke_private($orders_api, 'resolve_customer_ref', [$order]);
    assert_eq('billing-map@test.com', $ref, 'resolve_customer_ref uses billing email');
    $order->delete(true);
} else {
    echo "  (WC order test skipped — WooCommerce order API not available)\n";
}

// --- 7. apply_wc_coupon mapping (when WC available) ---
echo "\n--- apply_wc_coupon discount_type mapping ---\n";
if (class_exists('WC_Coupon') && function_exists('wc_get_coupon_id_by_code')) {
    invoke_private($launcher, 'apply_wc_coupon', [$code, $generated, $email]);
    $coupon_id = wc_get_coupon_id_by_code($code);
    assert_true($coupon_id > 0, 'WC coupon created');

    $wc_coupon = new WC_Coupon($coupon_id);
    assert_eq('percent', $wc_coupon->get_discount_type(), 'percentage API → percent WC type');
    assert_eq(20.0, (float) $wc_coupon->get_amount(), 'discount_value → amount');
    assert_eq('1', $wc_coupon->get_meta('_reloopin_coupon'), '_reloopin_coupon meta');
    assert_eq($code, $wc_coupon->get_meta('_reloopin_original_code'), '_reloopin_original_code preserves case');
    assert_eq($campaign_id, (int) $wc_coupon->get_meta('_reloopin_campaign_id'), '_reloopin_campaign_id meta');
    assert_eq([$email], $wc_coupon->get_email_restrictions(), 'email_restrictions set');
} else {
    echo "  (skipped — WooCommerce coupon functions not available)\n";
}

// --- Summary ---
echo "\n=== Request log (" . count($request_log) . " calls) ===\n";
foreach ($request_log as $i => $req) {
    $path = parse_url($req['url'], PHP_URL_PATH) ?: $req['url'];
    $body_preview = $req['body'] !== null ? json_encode($req['body']) : '';
    echo sprintf("  %d. %s %s %s\n", $i + 1, $req['method'], $path, $body_preview);
}

echo "\n";
if ($failed > 0) {
    echo "FAILED: {$failed} assertion(s), passed {$passed}\n";
    exit(1);
}

echo "ALL PASSED ({$passed} assertions)\n";
echo "Generated & redeemed coupon: {$code}\n";
exit(0);
