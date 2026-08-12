<?php
/**
 * Situational tests for ReLoopin_Loyalty_Customers::sync_new_customer.
 *
 * Run:
 *   php bin/test-customer-sync.php
 *
 * Env:
 *   RELOOPIN_API_URL, RELOOPIN_API_KEY, RELOOPIN_MERCHANT_ID
 */

declare(strict_types=1);

$plugin_dir = dirname(__DIR__);
$GLOBALS['reloopin_plugin_dir'] = $plugin_dir;
$GLOBALS['reloopin_request_log'] = [];
$GLOBALS['reloopin_debug_log'] = [];
$GLOBALS['reloopin_wc_log'] = [];
$GLOBALS['reloopin_users'] = [];
$GLOBALS['reloopin_user_meta'] = [];
$GLOBALS['reloopin_actions'] = [];

define('ABSPATH', $plugin_dir . '/');

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

class Fake_WC_Logger
{
    public function error(string $message, array $context = []): void
    {
        $GLOBALS['reloopin_wc_log'][] = ['level' => 'error', 'message' => $message, 'context' => $context];
    }
}

$options = [
    'reloopin_loyalty_api_url'     => getenv('RELOOPIN_API_URL') ?: 'http://localhost:8000',
    'reloopin_loyalty_api_key'     => getenv('RELOOPIN_API_KEY') ?: 'rwd_dev_test_key_001',
    'reloopin_loyalty_merchant_id' => getenv('RELOOPIN_MERCHANT_ID') ?: 'bbbbbbbb-bbbb-bbbb-bbbb-bbbbbbbbbbbb',
];
$GLOBALS['reloopin_test_options'] = $options;

function get_option(string $key, $default = false)
{
    return $GLOBALS['reloopin_test_options'][$key] ?? $default;
}

function get_woocommerce_currency(): string
{
    return 'USD';
}

function is_wp_error($thing): bool
{
    return $thing instanceof WP_Error;
}

function wp_json_encode($data): string
{
    return json_encode($data, JSON_THROW_ON_ERROR);
}

function add_query_arg(array $params, string $url): string
{
    $sep = str_contains($url, '?') ? '&' : '?';
    return $url . $sep . http_build_query($params);
}

function add_action(string $hook, callable $callback, int $priority = 10, int $accepted_args = 1): void
{
    $GLOBALS['reloopin_actions'][] = compact('hook', 'callback', 'priority', 'accepted_args');
}

function get_userdata(int $user_id): object|false
{
    return $GLOBALS['reloopin_users'][$user_id] ?? false;
}

function get_user_meta(int $user_id, string $key, bool $single = false)
{
    $val = $GLOBALS['reloopin_user_meta'][$user_id][$key] ?? '';
    return $single ? $val : [$val];
}

function wc_get_logger(): Fake_WC_Logger
{
    return new Fake_WC_Logger();
}

function reloopin_loyalty_debug(string $message, mixed $context = null): void
{
    $GLOBALS['reloopin_debug_log'][] = ['message' => $message, 'context' => $context];
}

function wp_remote_retrieve_response_code(array $response): int
{
    return (int) ($response['response']['code'] ?? 0);
}

function wp_remote_retrieve_body(array $response): string
{
    return (string) ($response['body'] ?? '');
}

function reloopin_test_http(string $method, string $url, array $args = []): array|WP_Error
{
    $headers = $args['headers'] ?? [];
    $body    = $args['body'] ?? null;
    $GLOBALS['reloopin_request_log'][] = [
        'method'  => $method,
        'url'     => $url,
        'headers' => $headers,
        'body'    => is_string($body) ? json_decode($body, true) : $body,
    ];

    $ch = curl_init($url);
    $curl_headers = [];
    foreach ($headers as $k => $v) {
        $curl_headers[] = "{$k}: {$v}";
    }
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST  => $method,
        CURLOPT_HTTPHEADER     => $curl_headers,
        CURLOPT_TIMEOUT        => 15,
    ]);
    if ($method !== 'GET' && $body !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, is_string($body) ? $body : json_encode($body));
    }
    $raw = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);

    if ($raw === false) {
        return new WP_Error('http_request_failed', $err ?: 'curl failed');
    }

    return [
        'response' => ['code' => $code],
        'body'     => $raw,
    ];
}

function wp_remote_get(string $url, array $args = []): array|WP_Error
{
    return reloopin_test_http('GET', $url, $args);
}

function wp_remote_post(string $url, array $args = []): array|WP_Error
{
    return reloopin_test_http('POST', $url, $args);
}

function wp_remote_request(string $url, array $args = []): array|WP_Error
{
    $method = strtoupper((string) ($args['method'] ?? 'GET'));
    return reloopin_test_http($method, $url, $args);
}

require_once $plugin_dir . '/includes/class-loyalty-api.php';
require_once $plugin_dir . '/includes/class-loyalty-customers.php';

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

function reset_logs(): void
{
    $GLOBALS['reloopin_request_log'] = [];
    $GLOBALS['reloopin_debug_log'] = [];
    $GLOBALS['reloopin_wc_log'] = [];
}

function register_user(int $id, string $email, array $meta = []): void
{
    $GLOBALS['reloopin_users'][$id] = (object) [
        'ID'         => $id,
        'user_email' => $email,
    ];
    $GLOBALS['reloopin_user_meta'][$id] = $meta;
}

function last_create_request(): ?array
{
    foreach (array_reverse($GLOBALS['reloopin_request_log']) as $req) {
        if (($req['method'] ?? '') === 'POST' && str_contains((string) ($req['url'] ?? ''), '/customer')) {
            return $req;
        }
    }
    return null;
}

function instantiate_customers(ReLoopin_Loyalty_API $api): ReLoopin_Loyalty_Customers
{
    $GLOBALS['reloopin_actions'] = [];
    return new ReLoopin_Loyalty_Customers($api);
}

$api = new ReLoopin_Loyalty_API();
$ts  = time();

echo "=== ReLoopin_Loyalty_Customers situational checks ===\n";
echo "API: " . get_option('reloopin_loyalty_api_url') . "\n\n";

// ---------------------------------------------------------------------------
// 1. Hook registration
// ---------------------------------------------------------------------------
echo "--- 1. Constructor registers WooCommerce hook ---\n";
$customers = instantiate_customers($api);
$hooks = array_column($GLOBALS['reloopin_actions'], 'hook');
assert_true(in_array('woocommerce_created_customer', $hooks, true), 'registers woocommerce_created_customer');

// ---------------------------------------------------------------------------
// 2. Missing WP user → no API call
// ---------------------------------------------------------------------------
echo "\n--- 2. Missing WP user (early return) ---\n";
reset_logs();
$customers->sync_new_customer(999001, [], false);
assert_true(count($GLOBALS['reloopin_request_log']) === 0, 'no HTTP request when user missing');
assert_true(count($GLOBALS['reloopin_wc_log']) === 0, 'no WC error log');
assert_true(count($GLOBALS['reloopin_debug_log']) === 0, 'no debug sync log');

// ---------------------------------------------------------------------------
// 3. Empty email → no API call
// ---------------------------------------------------------------------------
echo "\n--- 3. Empty email (early return) ---\n";
reset_logs();
register_user(1001, '', ['first_name' => 'No', 'last_name' => 'Email']);
$customers->sync_new_customer(1001, ['user_email' => ''], true);
assert_true(count($GLOBALS['reloopin_request_log']) === 0, 'no HTTP request when email empty');

// ---------------------------------------------------------------------------
// 4. Happy path — full profile
// ---------------------------------------------------------------------------
echo "\n--- 4. Happy path: full profile sync ---\n";
reset_logs();
$email4 = "cust-full-{$ts}@example.com";
register_user(1002, $email4, [
    'first_name'     => 'Ada',
    'last_name'      => 'Lovelace',
    'billing_phone'  => '+15550001111',
]);
$customers->sync_new_customer(1002, ['user_email' => $email4], false);
$req4 = last_create_request();
assert_true($req4 !== null, 'POST /customer sent');
assert_true(($req4['body']['email'] ?? null) === $email4, 'email mapped');
assert_true(($req4['body']['first_name'] ?? null) === 'Ada', 'first_name mapped');
assert_true(($req4['body']['last_name'] ?? null) === 'Lovelace', 'last_name mapped');
assert_true(($req4['body']['phone_number'] ?? null) === '+15550001111', 'phone mapped');
assert_true(($req4['body']['date_of_birth'] ?? null) === '2026-01-01', 'date_of_birth default sent');
$synced = array_filter($GLOBALS['reloopin_debug_log'], static fn($e) => $e['message'] === 'Customer synced to platform');
assert_true(count($synced) === 1, 'debug: Customer synced to platform');
assert_true(count($GLOBALS['reloopin_wc_log']) === 0, 'no WC error on success');

// ---------------------------------------------------------------------------
// 5. Empty names → API defaults to N/A (via create_platform_customer)
// ---------------------------------------------------------------------------
echo "\n--- 5. Empty first/last name → N/A defaults ---\n";
reset_logs();
$email5 = "cust-noname-{$ts}@example.com";
register_user(1003, $email5, [
    'first_name' => '',
    'last_name'  => '',
]);
$customers->sync_new_customer(1003, [], false);
$req5 = last_create_request();
assert_true($req5 !== null, 'POST /customer sent for empty names');
assert_true(($req5['body']['first_name'] ?? null) === 'N/A', 'first_name defaults to N/A');
assert_true(($req5['body']['last_name'] ?? null) === 'N/A', 'last_name defaults to N/A');
assert_true(!array_key_exists('phone_number', $req5['body'] ?? []), 'phone_number omitted when empty');
assert_true(count(array_filter($GLOBALS['reloopin_debug_log'], static fn($e) => $e['message'] === 'Customer synced to platform')) === 1, 'sync succeeded with N/A names');

// ---------------------------------------------------------------------------
// 6. Duplicate email → 409 treated as non-error
// ---------------------------------------------------------------------------
echo "\n--- 6. Duplicate customer (409) is not an error ---\n";
reset_logs();
register_user(1004, $email4, [
    'first_name' => 'Ada',
    'last_name'  => 'Again',
]);
$customers->sync_new_customer(1004, [], false);
$req6 = last_create_request();
assert_true($req6 !== null, 'duplicate still attempts create');
assert_true(count($GLOBALS['reloopin_wc_log']) === 0, '409 does not WC-error log');
assert_true(count(array_filter($GLOBALS['reloopin_debug_log'], static fn($e) => $e['message'] === 'Customer synced to platform')) === 0, 'no success debug on 409');

// ---------------------------------------------------------------------------
// 7. API auth failure → WC error log
// ---------------------------------------------------------------------------
echo "\n--- 7. Non-409 API failure logs WC error ---\n";
reset_logs();
$bad_options = $options;
$bad_options['reloopin_loyalty_api_key'] = 'definitely_invalid_key_xxx';
$GLOBALS['reloopin_test_options'] = $bad_options;
$bad_api = new ReLoopin_Loyalty_API();
$bad_customers = instantiate_customers($bad_api);
$email7 = "cust-fail-{$ts}@example.com";
register_user(1005, $email7, ['first_name' => 'Bad', 'last_name' => 'Key']);
$bad_customers->sync_new_customer(1005, [], false);
assert_true(count($GLOBALS['reloopin_request_log']) >= 1, 'HTTP request attempted with bad key');
assert_true(count($GLOBALS['reloopin_wc_log']) === 1, 'WC error logged for non-409 failure');
$err_msg = $GLOBALS['reloopin_wc_log'][0]['message'] ?? '';
assert_true(str_contains($err_msg, 'Failed to sync customer #1005'), 'error message includes customer id');
assert_true(str_contains($err_msg, $email7), 'error message includes email');
assert_true(($GLOBALS['reloopin_wc_log'][0]['context']['source'] ?? '') === 'reloopin-loyalty', 'log source is reloopin-loyalty');

// restore good options
$GLOBALS['reloopin_test_options'] = $options;

// ---------------------------------------------------------------------------
// 8. API URL missing → WP_Error path + WC error
// ---------------------------------------------------------------------------
echo "\n--- 8. Missing API URL ---\n";
reset_logs();
$no_url = $options;
$no_url['reloopin_loyalty_api_url'] = '';
$GLOBALS['reloopin_test_options'] = $no_url;
$no_url_api = new ReLoopin_Loyalty_API();
$no_url_customers = instantiate_customers($no_url_api);
$email8 = "cust-nourl-{$ts}@example.com";
register_user(1006, $email8, ['first_name' => 'No', 'last_name' => 'Url']);
$no_url_customers->sync_new_customer(1006, [], false);
assert_true(count($GLOBALS['reloopin_request_log']) === 0, 'no HTTP when API URL empty');
assert_true(count($GLOBALS['reloopin_wc_log']) === 1, 'WC error logged when API URL missing');

$GLOBALS['reloopin_test_options'] = $options;

// ---------------------------------------------------------------------------
// Summary
// ---------------------------------------------------------------------------
echo "\n=== Summary: {$passed} passed, {$failed} failed ===\n";
exit($failed > 0 ? 1 : 0);
