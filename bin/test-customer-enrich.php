<?php
/**
 * Unit tests for platform customer enrich (PATCH) + order billing wiring.
 *
 * Run:
 *   php bin/test-customer-enrich.php
 *
 * Uses canned HTTP responses (no live API required).
 */

declare(strict_types=1);

$plugin_dir = dirname(__DIR__);
$GLOBALS['reloopin_plugin_dir'] = $plugin_dir;
$GLOBALS['reloopin_request_log'] = [];
$GLOBALS['reloopin_debug_log'] = [];
$GLOBALS['reloopin_wc_log'] = [];
$GLOBALS['reloopin_users'] = [];
$GLOBALS['reloopin_http_queue'] = [];
$GLOBALS['reloopin_as_queue'] = [];
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

interface WC_Logger_Interface
{
    public function error(string $message, array $context = []): void;
}

class Fake_WC_Logger implements WC_Logger_Interface
{
    public function error(string $message, array $context = []): void
    {
        $GLOBALS['reloopin_wc_log'][] = ['level' => 'error', 'message' => $message, 'context' => $context];
    }
}
/** Minimal WC_Order stand-in for unit tests. */
class WC_Order
{
    public function __construct(private array $data) {}

    public function get_id(): int
    {
        return (int) ($this->data['id'] ?? 0);
    }

    public function get_customer_id(): int
    {
        return (int) ($this->data['customer_id'] ?? 0);
    }

    public function get_billing_email(): string
    {
        return (string) ($this->data['billing_email'] ?? '');
    }

    public function get_billing_first_name(): string
    {
        return (string) ($this->data['billing_first_name'] ?? '');
    }

    public function get_billing_last_name(): string
    {
        return (string) ($this->data['billing_last_name'] ?? '');
    }

    public function get_billing_phone(): string
    {
        return (string) ($this->data['billing_phone'] ?? '');
    }

    public function get_billing_city(): string
    {
        return (string) ($this->data['billing_city'] ?? '');
    }

    public function get_billing_state(): string
    {
        return (string) ($this->data['billing_state'] ?? '');
    }

    public function get_billing_postcode(): string
    {
        return (string) ($this->data['billing_postcode'] ?? '');
    }

    public function get_billing_country(): string
    {
        return (string) ($this->data['billing_country'] ?? '');
    }

    public function get_meta(string $key, bool $single = true): string
    {
        return (string) ($this->data['meta'][$key] ?? '');
    }

    public function update_meta_data(string $key, $value): void
    {
        $this->data['meta'][$key] = $value;
    }

    public function save_meta_data(): void {}

    public function get_items(): array
    {
        return [];
    }

    public function get_order_number(): string
    {
        return (string) ($this->data['id'] ?? 0);
    }

    public function get_total(): float
    {
        return (float) ($this->data['total'] ?? 0);
    }

    public function get_shipping_total(): string
    {
        return '0';
    }

    public function get_coupon_codes(): array
    {
        return [];
    }

    public function get_shipping_methods(): array
    {
        return [];
    }

    public function add_order_note(string $note): void
    {
        $this->data['notes'][] = $note;
    }
}

$GLOBALS['reloopin_test_options'] = [
    'reloopin_loyalty_api_url'     => 'http://loyalty.test',
    'reloopin_loyalty_api_key'     => 'test_key',
    'reloopin_loyalty_merchant_id' => 'aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa',
];

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
    return json_encode($data, JSON_UNESCAPED_SLASHES) ?: '{}';
}

function add_query_arg($args, string $url): string
{
    return $url . (str_contains($url, '?') ? '&' : '?') . http_build_query($args);
}

function is_email(string $email): bool
{
    return (bool) filter_var($email, FILTER_VALIDATE_EMAIL);
}

function __(string $text, string $domain = 'default'): string
{
    return $text;
}

function get_user_by(string $field, $value)
{
    if ($field !== 'id') {
        return false;
    }
    return $GLOBALS['reloopin_users'][(int) $value] ?? false;
}

function wc_get_logger(): Fake_WC_Logger
{
    return new Fake_WC_Logger();
}

function wc_get_order($order_id)
{
    return $GLOBALS['reloopin_orders'][$order_id] ?? false;
}

function wc_get_customer_order_count(int $customer_id): int
{
    return 1;
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

function queue_http_response(int $code, array|string $body = []): void
{
    $GLOBALS['reloopin_http_queue'][] = [
        'response' => ['code' => $code],
        'body'     => is_string($body) ? $body : (json_encode($body) ?: '{}'),
    ];
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

    if (!empty($GLOBALS['reloopin_http_queue'])) {
        return array_shift($GLOBALS['reloopin_http_queue']);
    }

    return [
        'response' => ['code' => 200],
        'body'     => '{}',
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

function add_action(string $hook, $callback, int $priority = 10, int $accepted_args = 1): void
{
    $GLOBALS['reloopin_actions'][] = compact('hook', 'callback', 'priority', 'accepted_args');
}

function as_has_scheduled_action(string $hook, ?array $args = null, string $group = ''): bool
{
    foreach ($GLOBALS['reloopin_as_queue'] as $job) {
        if ($job['hook'] === $hook && $job['args'] === $args && $job['group'] === $group) {
            return true;
        }
    }
    return false;
}

function as_enqueue_async_action(string $hook, array $args = [], string $group = ''): int
{
    $GLOBALS['reloopin_as_queue'][] = [
        'hook'  => $hook,
        'args'  => $args,
        'group' => $group,
    ];
    return count($GLOBALS['reloopin_as_queue']);
}

function wp_next_scheduled(string $hook, array $args = []): false
{
    return false;
}

function wp_schedule_single_event(int $timestamp, string $hook, array $args = []): bool
{
    $GLOBALS['reloopin_as_queue'][] = [
        'hook'  => $hook,
        'args'  => $args,
        'group' => 'wp-cron',
    ];
    return true;
}

require_once $plugin_dir . '/includes/class-loyalty-api.php';
require_once $plugin_dir . '/includes/class-loyalty-orders.php';

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
    $GLOBALS['reloopin_http_queue'] = [];
    $GLOBALS['reloopin_as_queue'] = [];
}

function last_patch_request(): ?array
{
    foreach (array_reverse($GLOBALS['reloopin_request_log']) as $req) {
        if (($req['method'] ?? '') === 'PATCH' && str_contains((string) ($req['url'] ?? ''), '/external/customer')) {
            return $req;
        }
    }
    return null;
}

function invoke_private(object $obj, string $method, array $args = []): mixed
{
    $ref = new ReflectionMethod($obj, $method);
    return $ref->invokeArgs($obj, $args);
}

$api = new ReLoopin_Loyalty_API();
$orders = new ReLoopin_Loyalty_Orders($api);

echo "=== Customer enrich (plugin) unit checks ===\n\n";

// ---------------------------------------------------------------------------
echo "--- 1. enrich_platform_customer builds PATCH body ---\n";
reset_logs();
queue_http_response(200, [
    'id'         => 1,
    'email'      => 'a@example.com',
    'first_name' => 'Ada',
    'last_name'  => 'Lovelace',
    'city'       => 'Austin',
]);
$result = $api->enrich_platform_customer('a@example.com', [
    'first_name'   => 'Ada',
    'last_name'    => 'Lovelace',
    'phone_number' => '+15550001111',
    'city'         => 'Austin',
    'region'       => 'TX',
    'postal_code'  => '78701',
    'country'      => 'US',
]);
$req = last_patch_request();
assert_true(!is_wp_error($result), 'enrich succeeds');
assert_true($req !== null, 'PATCH /external/customer sent');
assert_true(($req['body']['email'] ?? null) === 'a@example.com', 'email in body');
assert_true(($req['body']['first_name'] ?? null) === 'Ada', 'first_name mapped');
assert_true(($req['body']['region'] ?? null) === 'TX', 'region mapped');
assert_true(($req['body']['postal_code'] ?? null) === '78701', 'postal_code mapped');
assert_true(($req['headers']['reloopin_api_key'] ?? null) === 'test_key', 'api key header');
assert_true(!isset($req['headers']['merchant_id']), 'no merchant_id header (key-scoped)');
assert_true(str_ends_with((string) ($req['url'] ?? ''), '/api/v1/external/customer'), 'path has no merchant_id');

// ---------------------------------------------------------------------------
echo "\n--- 2. Empty optional fields omitted; email-only skips HTTP ---\n";
reset_logs();
$skip = $api->enrich_platform_customer('a@example.com', [
    'first_name' => '',
    'last_name'  => '  ',
]);
assert_true($skip === [], 'returns empty array when nothing to fill');
assert_true(count($GLOBALS['reloopin_request_log']) === 0, 'no HTTP when only email');

reset_logs();
queue_http_response(200, ['id' => 1, 'email' => 'a@example.com']);
$api->enrich_platform_customer('a@example.com', [
    'first_name' => 'Ada',
    'city'       => '',
    'country'    => 'N/A', // still sent — backend decides if placeholder; plugin only skips empty
]);
$req2 = last_patch_request();
assert_true($req2 !== null, 'PATCH sent when at least one field present');
assert_true(($req2['body']['first_name'] ?? null) === 'Ada', 'non-empty field sent');
assert_true(!array_key_exists('city', $req2['body'] ?? []), 'empty city omitted');
assert_true(($req2['body']['country'] ?? null) === 'N/A', 'literal N/A still sent (backend ignores)');

// ---------------------------------------------------------------------------
echo "\n--- 3. Missing email → WP_Error, no request ---\n";
reset_logs();
$err = $api->enrich_platform_customer('  ', ['first_name' => 'Ada']);
assert_true(is_wp_error($err), 'empty email is error');
assert_true(count($GLOBALS['reloopin_request_log']) === 0, 'no HTTP for empty email');

// ---------------------------------------------------------------------------
echo "\n--- 4. Orders enrich maps billing → API fields ---\n";
reset_logs();
queue_http_response(200, ['id' => 9, 'email' => 'bill@example.com', 'first_name' => 'Bill']);
$order = new WC_Order([
    'id'                 => 501,
    'customer_id'        => 0,
    'billing_email'      => 'bill@example.com',
    'billing_first_name' => 'Bill',
    'billing_last_name'  => 'Murray',
    'billing_phone'      => '+15551212',
    'billing_city'       => 'Chicago',
    'billing_state'      => 'IL',
    'billing_postcode'   => '60601',
    'billing_country'    => 'US',
]);
invoke_private($orders, 'enrich_customer_from_billing', [$order]);
$req3 = last_patch_request();
assert_true($req3 !== null, 'orders enrich sends PATCH');
assert_true(($req3['body']['email'] ?? null) === 'bill@example.com', 'billing email used');
assert_true(($req3['body']['first_name'] ?? null) === 'Bill', 'billing first_name');
assert_true(($req3['body']['last_name'] ?? null) === 'Murray', 'billing last_name');
assert_true(($req3['body']['phone_number'] ?? null) === '+15551212', 'billing phone');
assert_true(($req3['body']['city'] ?? null) === 'Chicago', 'billing city');
assert_true(($req3['body']['region'] ?? null) === 'IL', 'billing state → region');
assert_true(($req3['body']['postal_code'] ?? null) === '60601', 'billing postcode');
assert_true(($req3['body']['country'] ?? null) === 'US', 'billing country');
$dbg = array_filter($GLOBALS['reloopin_debug_log'], static fn($e) => $e['message'] === 'orders: customer enriched from billing');
assert_true(count($dbg) === 1, 'debug: enriched from billing');

// ---------------------------------------------------------------------------
echo "\n--- 5. Account email preferred over billing ---\n";
reset_logs();
queue_http_response(200, ['id' => 2]);
$GLOBALS['reloopin_users'][77] = (object) ['ID' => 77, 'user_email' => 'account@example.com'];
$order2 = new WC_Order([
    'id'            => 502,
    'customer_id'   => 77,
    'billing_email' => 'billing@example.com',
    'billing_first_name' => 'Acc',
]);
invoke_private($orders, 'enrich_customer_from_billing', [$order2]);
$req4 = last_patch_request();
assert_true(($req4['body']['email'] ?? null) === 'account@example.com', 'account email preferred');

// ---------------------------------------------------------------------------
echo "\n--- 6. 404 is soft-skip (no WC error) ---\n";
reset_logs();
queue_http_response(404, ['detail' => 'not found']);
$order3 = new WC_Order([
    'id'                 => 503,
    'billing_email'      => 'guest@example.com',
    'billing_first_name' => 'Guest',
]);
invoke_private($orders, 'enrich_customer_from_billing', [$order3]);
assert_true(count($GLOBALS['reloopin_wc_log']) === 0, '404 does not WC-error');
$skip404 = array_filter($GLOBALS['reloopin_debug_log'], static fn($e) => $e['message'] === 'orders: enrich skipped — customer not on platform');
assert_true(count($skip404) === 1, 'debug notes 404 skip');

// ---------------------------------------------------------------------------
echo "\n--- 7. post_transaction queues enrich async (does not PATCH inline) ---\n";
reset_logs();
queue_http_response(200, ['transaction' => ['id' => 'tx-1']]); // product_purchase only
$GLOBALS['reloopin_orders'][600] = new WC_Order([
    'id'                 => 600,
    'customer_id'        => 0,
    'billing_email'      => 'flow@example.com',
    'billing_first_name' => 'Flow',
    'billing_last_name'  => 'Test',
    'total'              => 25.00,
    'meta'               => [],
]);

$orders->post_transaction(600);
$methods = array_column($GLOBALS['reloopin_request_log'], 'method');
assert_true(!in_array('PATCH', $methods, true), 'no inline enrich PATCH during checkout');
assert_true(in_array('POST', $methods, true), 'transaction POST still sent');
assert_true(count($GLOBALS['reloopin_as_queue']) === 1, 'enrich job queued');
assert_true(($GLOBALS['reloopin_as_queue'][0]['hook'] ?? '') === 'reloopin_loyalty_enrich_customer', 'enrich hook name');
assert_true(($GLOBALS['reloopin_as_queue'][0]['args'][0] ?? null) === 600, 'enrich job has order_id');

// Duplicate schedule is a no-op
$orders->post_transaction(600);
assert_true(count($GLOBALS['reloopin_as_queue']) === 1, 'duplicate enrich not re-queued');

// ---------------------------------------------------------------------------
echo "\n--- 8. process_enrich_customer runs PATCH async worker ---\n";
reset_logs();
queue_http_response(200, ['id' => 3, 'first_name' => 'Flow']);
$GLOBALS['reloopin_orders'][600] = new WC_Order([
    'id'                 => 600,
    'billing_email'      => 'flow@example.com',
    'billing_first_name' => 'Flow',
    'billing_last_name'  => 'Test',
]);
$orders->process_enrich_customer(600);
$req5 = last_patch_request();
assert_true($req5 !== null, 'async worker sends PATCH');
assert_true(($req5['body']['first_name'] ?? null) === 'Flow', 'async enrich maps billing');

// ---------------------------------------------------------------------------
echo "\n";
if ($failed > 0) {
    echo "FAILED: {$failed} assertion(s), passed {$passed}\n";
    exit(1);
}

echo "ALL PASSED ({$passed} assertions)\n";
exit(0);
