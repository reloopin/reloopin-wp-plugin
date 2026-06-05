<?php
/**
 * Loyalty Launcher Widget
 *
 * Renders the floating launcher widget matching the reloopin_widget.html design.
 * Features: tabbed Earn/Redeem/History, tier badge, modals, toast notifications.
 *
 * Dynamic data (balance, history, rules) is loaded via AJAX on first open.
 * Guest users see a sign-up CTA linking to WP login/register pages.
 */

if (!defined('ABSPATH')) {
    exit;
}

class ReLoopin_Loyalty_Launcher
{
    private const CACHE_TTL_SHORT  = 5 * MINUTE_IN_SECONDS;
    private const CACHE_TTL_LONG   = 15 * MINUTE_IN_SECONDS;
    private const HISTORY_PAGE_SIZE = 10;
    private const ALLOWED_ENTRY_TYPES = ['earn', 'redeem', 'bonus', 'expire', 'void', 'adjust'];
    private const MAX_DAYS_BY_MONTH = [0, 31, 29, 31, 30, 31, 30, 31, 31, 30, 31, 30, 31];
    private const RATE_LIMIT_MAX = 30;

    private ReLoopin_Loyalty_API $api;

    public function __construct(ReLoopin_Loyalty_API $api)
    {
        $this->api = $api;

        add_action('wp_enqueue_scripts', [$this, 'enqueue_assets']);
        add_action('wp_footer', [$this, 'render_launcher']);

        // AJAX endpoints
        add_action('wp_ajax_reloopin_launcher_data', [$this, 'ajax_launcher_data']);
        add_action('wp_ajax_nopriv_reloopin_launcher_data', [$this, 'ajax_launcher_data']);
        add_action('wp_ajax_reloopin_launcher_history', [$this, 'ajax_launcher_history']);
        add_action('wp_ajax_reloopin_launcher_rules', [$this, 'ajax_launcher_rules']);
        add_action('wp_ajax_nopriv_reloopin_launcher_rules', [$this, 'ajax_launcher_rules']);

        // Campaigns + coupon generation (logged-in only)
        add_action('wp_ajax_reloopin_launcher_campaigns', [$this, 'ajax_launcher_campaigns']);
        add_action('wp_ajax_nopriv_reloopin_launcher_campaigns', [$this, 'ajax_launcher_campaigns_guest']);
        add_action('wp_ajax_reloopin_generate_coupon',    [$this, 'ajax_generate_coupon']);

        // Earn status + birthday save (logged-in only)
        add_action('wp_ajax_reloopin_launcher_earn_status', [$this, 'ajax_launcher_earn_status']);
        add_action('wp_ajax_reloopin_save_birthday',        [$this, 'ajax_save_birthday']);

        // Cache invalidation
        add_action('woocommerce_payment_complete', [$this, 'invalidate_user_cache']);
        add_action('woocommerce_order_status_processing', [$this, 'invalidate_user_cache']);
    }

    // -----------------------------------------------------------------------
    // Private helpers
    // -----------------------------------------------------------------------

    private function cache_key(string $prefix, string ...$parts): string
    {
        return 'reloopin_' . $prefix . '_' . implode('_', $parts);
    }

    private function get_user_display_info(\WP_User $user): array
    {
        $display_name = $user->display_name ?: $user->first_name ?: $user->user_login;
        $first_name   = $user->first_name ?: $display_name;
        $parts        = explode(' ', trim($display_name));
        $initials     = strtoupper(mb_substr($parts[0], 0, 1));
        if (count($parts) > 1) {
            $initials .= strtoupper(mb_substr(end($parts), 0, 1));
        }
        return [
            'initials'     => $initials,
            'first_name'   => $first_name,
            'display_name' => $display_name,
        ];
    }

    /**
     * Normalize API list responses — handles both direct arrays and { results: [...] }.
     */
    private function normalize_api_list(array $data): array
    {
        $list = is_array($data) && isset($data[0]) ? $data : ($data['results'] ?? $data);
        return is_array($list) ? $list : [];
    }

    /**
     * Generic AJAX handler: nonce → login check → rate limit → cache → fetch → transform → cache → respond.
     *
     * @param string        $action        Semantic action name for rate limiting (e.g. 'rules', 'tiers').
     * @param string        $cache_key     Transient key.
     * @param int           $ttl           Cache TTL in seconds.
     * @param callable      $fetch         Returns array|WP_Error (raw API call).
     * @param callable      $transform     Maps raw API result to payload shape.
     * @param bool          $require_login Whether login is required.
     * @param array|null    $guest_default If set, return this as success for guests instead of error.
     */
    private function ajax_cached(
        string   $action,
        string   $cache_key,
        int      $ttl,
        callable $fetch,
        callable $transform,
        bool     $require_login = false,
        ?array   $guest_default = null
    ): void {
        check_ajax_referer('reloopin_launcher', 'nonce');

        if ($require_login && !is_user_logged_in()) {
            if ($guest_default !== null) {
                wp_send_json_success($guest_default);
            }
            wp_send_json_error(['message' => 'not_logged_in']);
        }

        if (!$this->check_rate_limit($action)) {
            wp_send_json_error(['message' => 'rate_limited'], 429);
        }

        $cached = get_transient($cache_key);
        if ($cached !== false) {
            wp_send_json_success($cached);
        }

        $result = $fetch();
        if (is_wp_error($result)) {
            wp_send_json_error([
                'message' => $result->get_error_message(),
                'code'    => $result->get_error_code(),
            ]);
        }

        $payload = $transform($result);
        set_transient($cache_key, $payload, $ttl);
        wp_send_json_success($payload);
    }

    private function check_rate_limit(string $action): bool
    {
        $user_key = is_user_logged_in()
            ? (string) get_current_user_id()
            : sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR'] ?? ''));
        $key   = 'reloopin_rl_' . md5($action . $user_key);
        $count = (int) get_transient($key);
        if ($count >= self::RATE_LIMIT_MAX) {
            return false;
        }
        set_transient($key, $count + 1, MINUTE_IN_SECONDS);
        return true;
    }

    private function set_reloopin_meta(\WC_Coupon $coupon, array $api_data, string $code): void
    {
        $coupon->update_meta_data('_reloopin_coupon', '1');
        $coupon->update_meta_data('_reloopin_campaign_id', (int) ($api_data['campaign_id'] ?? 0));
        $coupon->update_meta_data('_reloopin_original_code', $code);
    }

    // -----------------------------------------------------------------------
    // Transform helpers
    // -----------------------------------------------------------------------

    private function transform_balance(array $data, \WP_User $user): array
    {
        $info = $this->get_user_display_info($user);
        return [
            'logged_in'        => true,
            'name'             => $info['first_name'],
            'initials'         => $info['initials'],
            'available_points' => (int) ($data['available_points'] ?? 0),
            'lifetime_points'  => (int) ($data['lifetime_points'] ?? 0),
            'redeemed_points'  => (int) ($data['redeemed_points'] ?? 0),
            'expired_points'   => (int) ($data['expired_points'] ?? 0),
            'tier'             => $data['tier'] ?? '',
            'referral_url'     => add_query_arg('ref', get_current_user_id(), home_url('/')),
        ];
    }

    private function transform_rules(array $data): array
    {
        return array_map(fn(array $rule): array => [
            'id'             => (int) ($rule['id'] ?? 0),
            'name'           => $rule['name'] ?? '',
            'description'    => $rule['description'] ?? '',
            'rule_type'      => $rule['rule_type'] ?? '',
            'event_type'     => $rule['event_type'] ?? '',
            'earn_rate'      => (float) ($rule['earn_rate'] ?? 0),
            'conditions'     => $rule['conditions'] ?? null,
        ], $this->normalize_api_list($data));
    }

    private function transform_history(array $data, int $page): array
    {
        $results = array_map(function (array $entry): array {
            $date_raw = $entry['created_at'] ?? $entry['timestamp'] ?? '';
            return [
                'date'          => $date_raw ? date_i18n(get_option('date_format'), strtotime($date_raw)) : '',
                'date_raw'      => $date_raw,
                'entry_type'    => $entry['entry_type'] ?? $entry['type'] ?? '',
                'points'        => (int) ($entry['points'] ?? 0),
                'balance_after' => (int) ($entry['balance_after'] ?? 0),
                'notes'         => $entry['notes'] ?? $entry['note'] ?? '',
            ];
        }, $data['results'] ?? []);

        return [
            'results'   => $results,
            'total'     => (int) ($data['total'] ?? 0),
            'page_size' => (int) ($data['page_size'] ?? self::HISTORY_PAGE_SIZE),
            'page'      => $page,
        ];
    }

    private function transform_campaigns(array $data): array
    {
        $campaigns = $data['eligible_campaigns'] ?? $this->normalize_api_list($data);

        return array_map(fn(array $c): array => [
            'id'             => (int)    ($c['id']               ?? 0),
            'name'           => (string) ($c['name']             ?? ''),
            'description'    => (string) ($c['description']      ?? ''),
            'campaign_type'  => (string) ($c['campaign_type']    ?? ''),
            'points_cost'    => (int)    ($c['redeemable_points'] ?? 0),
            'discount_type'  => (string) ($c['coupon_type']      ?? ''),
            'discount_value' => (string) ($c['discount_value']   ?? '0'),
        ], $campaigns);
    }

    // -----------------------------------------------------------------------
    // Cache invalidation
    // -----------------------------------------------------------------------

    public function invalidate_user_cache(int $order_id): void
    {
        $order = wc_get_order($order_id);
        if (!$order) {
            return;
        }
        $user_id = (int) $order->get_customer_id();
        if ($user_id <= 0) {
            return;
        }

        $uid = (string) $user_id;
        delete_transient($this->cache_key('bal', $uid));
        delete_transient($this->cache_key('earn_status', $uid));
        delete_transient($this->cache_key('camps', $uid));

        // Bump generation counter so all history cache keys become stale.
        // Old transients expire naturally via TTL (5 min).
        $gen_key = 'reloopin_hist_gen_' . $user_id;
        $current = (int) get_option($gen_key, 0);
        update_option($gen_key, $current + 1, false);
    }

    // -----------------------------------------------------------------------
    // Assets
    // -----------------------------------------------------------------------

    public function enqueue_assets(): void
    {
        if (get_option('reloopin_launcher_enabled', 'yes') !== 'yes') {
            return;
        }

        $base = RELOOPIN_LOYALTY_PLUGIN_URL;

        $font_url = str_replace(',', '%2C', 'https://fonts.googleapis.com/css2?family=Instrument+Serif:ital@0;1&family=Plus+Jakarta+Sans:wght@300;400;500;600;700&display=swap');
        wp_enqueue_style('reloopin-google-fonts', $font_url, [], RELOOPIN_LOYALTY_VERSION);

        wp_enqueue_style(
            'reloopin-launcher',
            $base . 'assets/css/launcher.css',
            ['reloopin-google-fonts'],
            RELOOPIN_LOYALTY_VERSION
        );

        wp_enqueue_script(
            'reloopin-launcher',
            $base . 'assets/js/launcher.js',
            [],
            RELOOPIN_LOYALTY_VERSION,
            true
        );

        $initials   = '';
        $first_name = '';
        $preloaded  = null;

        if (is_user_logged_in()) {
            $user = wp_get_current_user();
            $info = $this->get_user_display_info($user);
            $initials   = $info['initials'];
            $first_name = $info['first_name'];

            // Preload balance from transient (zero API calls — cached data only).
            $cached = get_transient($this->cache_key('bal', (string) $user->ID));
            if ($cached !== false) {
                $preloaded = $cached;
            }
        }

        wp_localize_script('reloopin-launcher', 'reloopinLauncher', [
            'ajax_url'        => admin_url('admin-ajax.php'),
            'nonce'           => wp_create_nonce('reloopin_launcher'),
            'is_logged_in'    => is_user_logged_in(),
            'user_initials'   => $initials,
            'user_first_name' => $first_name,
            'preloaded_data'  => $preloaded,
            'i18n'            => [
                /* translators: %s: customer first name */
                'welcome_back'       => __('Welcome back, %s!', 'reloopin-loyalty'),
                /* translators: %s: points amount */
                'pts_per_dollar'     => __('%s pts per $1', 'reloopin-loyalty'),
                'one_time_bonus'     => __('One-time bonus', 'reloopin-loyalty'),
                /* translators: %s: points multiplier, e.g. "2x" */
                'x_pts'              => __('%sx pts', 'reloopin-loyalty'),
                'add_now'            => __('Add now', 'reloopin-loyalty'),
                'get_link'           => __('Get link', 'reloopin-loyalty'),
                'available'          => __('Available', 'reloopin-loyalty'),
                /* translators: %s: points balance */
                'redeem_your_points' => __('Redeem your points — %s pts', 'reloopin-loyalty'),
                'unknown'            => __('Unknown', 'reloopin-loyalty'),
                'bday_saved'         => __('Birthday saved! You\'ll earn bonus points every year.', 'reloopin-loyalty'),
                'referral_copied'    => __('Referral link copied to clipboard!', 'reloopin-loyalty'),
                'earn_error'         => __('Could not load earn rules.', 'reloopin-loyalty'),
                'no_earn_rules'      => __('No earn rules available.', 'reloopin-loyalty'),
                'already_earned'     => __('Already earned', 'reloopin-loyalty'),
                'ready_to_earn'      => __('Ready to earn', 'reloopin-loyalty'),
                'collected'          => __('Collected', 'reloopin-loyalty'),
                'annual_bonus'       => __('Annual bonus active', 'reloopin-loyalty'),
                'campaigns_error'    => __('Could not load rewards.', 'reloopin-loyalty'),
                'no_campaigns'       => __('No rewards available yet. Keep earning points!', 'reloopin-loyalty'),
                'generating'         => __('Generating…', 'reloopin-loyalty'),
                'coupon_generated'   => __('Coupon generated!', 'reloopin-loyalty'),
                /* translators: %s: coupon code */
                'coupon_copied'      => __('Coupon %s copied!', 'reloopin-loyalty'),
                'coupon_error'       => __('Could not generate coupon. Please try again.', 'reloopin-loyalty'),
                'pts_required'       => __('pts required', 'reloopin-loyalty'),
                'copy_code'          => __('Copy', 'reloopin-loyalty'),
                'copied'             => __('Copied!', 'reloopin-loyalty'),
                'discount_expires'   => __('Expires', 'reloopin-loyalty'),
                'discount_off'       => __('off', 'reloopin-loyalty'),
                'auto_applied'       => __('Applied automatically at checkout', 'reloopin-loyalty'),
                'no_redeem_options'  => __('No redeem options available yet.', 'reloopin-loyalty'),
            ],
        ]);
    }

    // -----------------------------------------------------------------------
    // AJAX: Balance + user data
    // -----------------------------------------------------------------------

    public function ajax_launcher_data(): void
    {
        check_ajax_referer('reloopin_launcher', 'nonce');

        if (!is_user_logged_in()) {
            wp_send_json_success(['logged_in' => false]);
        }

        if (!$this->check_rate_limit('launcher_data')) {
            wp_send_json_error(['message' => 'rate_limited'], 429);
        }

        $user_id   = get_current_user_id();
        $cache_key = $this->cache_key('bal', (string) $user_id);
        $cached    = get_transient($cache_key);

        if ($cached !== false) {
            wp_send_json_success($cached);
        }

        $user         = wp_get_current_user();
        $balance_data = $this->api->get_balance($user->user_email);

        if (is_wp_error($balance_data)) {
            wp_send_json_error([
                'message' => __('Could not fetch points balance.', 'reloopin-loyalty'),
                'code'    => $balance_data->get_error_code(),
            ]);
        }

        $payload = $this->transform_balance($balance_data, $user);
        set_transient($cache_key, $payload, self::CACHE_TTL_SHORT);
        wp_send_json_success($payload);
    }

    // -----------------------------------------------------------------------
    // AJAX: History
    // -----------------------------------------------------------------------

    public function ajax_launcher_history(): void
    {
        check_ajax_referer('reloopin_launcher', 'nonce');

        if (!is_user_logged_in()) {
            wp_send_json_error(['message' => 'not_logged_in']);
        }

        if (!$this->check_rate_limit('launcher_history')) {
            wp_send_json_error(['message' => 'rate_limited'], 429);
        }

        $user_id    = get_current_user_id();
        $page       = max(1, absint(wp_unslash($_POST['page'] ?? 1)));
        $entry_type = isset($_POST['entry_type']) ? sanitize_text_field(wp_unslash($_POST['entry_type'])) : null;

        if ($entry_type !== null && $entry_type !== '' && !in_array($entry_type, self::ALLOWED_ENTRY_TYPES, true)) {
            $entry_type = null;
        }

        $gen       = (int) get_option('reloopin_hist_gen_' . $user_id, 0);
        $cache_key = $this->cache_key('hist', (string) $user_id, (string) $page, $entry_type ?: 'all', (string) $gen);
        $cached    = get_transient($cache_key);

        if ($cached !== false) {
            wp_send_json_success($cached);
        }

        $user         = wp_get_current_user();
        $history_data = $this->api->get_history($user->user_email, $page, self::HISTORY_PAGE_SIZE, $entry_type ?: null);

        if (is_wp_error($history_data)) {
            wp_send_json_error([
                'message' => $history_data->get_error_message(),
                'code'    => $history_data->get_error_code(),
            ]);
        }

        $payload = $this->transform_history($history_data, $page);
        set_transient($cache_key, $payload, self::CACHE_TTL_SHORT);
        wp_send_json_success($payload);
    }

    // -----------------------------------------------------------------------
    // AJAX: Rules
    // -----------------------------------------------------------------------

    public function ajax_launcher_rules(): void
    {
        $merchant_id = get_option('reloopin_loyalty_merchant_id', '');
        $this->ajax_cached(
            'rules',
            $this->cache_key('rules', $merchant_id),
            self::CACHE_TTL_LONG,
            fn() => $this->api->get_rules(),
            fn(array $data) => $this->transform_rules($data),
        );
    }

    // -----------------------------------------------------------------------
    // AJAX: Campaigns (logged-in only)
    // -----------------------------------------------------------------------

    public function ajax_launcher_campaigns(): void
    {
        check_ajax_referer('reloopin_launcher', 'nonce');

        if (!is_user_logged_in()) {
            wp_send_json_error(['message' => 'not_logged_in']);
        }

        if (!$this->check_rate_limit('launcher_campaigns')) {
            wp_send_json_error(['message' => 'rate_limited'], 429);
        }

        $user_id   = get_current_user_id();
        $cache_key = $this->cache_key('camps', (string) $user_id);
        $cached    = get_transient($cache_key);

        if ($cached !== false) {
            wp_send_json_success($cached);
        }

        $user = wp_get_current_user();
        $data = $this->api->get_campaigns($user->user_email);

        if (is_wp_error($data)) {
            wp_send_json_error([
                'message' => $data->get_error_message(),
                'code'    => $data->get_error_code(),
            ]);
        }

        $payload = $this->transform_campaigns($data);
        set_transient($cache_key, $payload, self::CACHE_TTL_SHORT);
        wp_send_json_success($payload);
    }

    // -----------------------------------------------------------------------
    // AJAX: Campaigns for guests (no login required)
    // -----------------------------------------------------------------------

    public function ajax_launcher_campaigns_guest(): void
    {
        check_ajax_referer('reloopin_launcher', 'nonce');

        if (!$this->check_rate_limit('launcher_campaigns_guest')) {
            wp_send_json_error(['message' => 'rate_limited'], 429);
        }

        $cache_key = $this->cache_key('camps', 'guest');
        $cached    = get_transient($cache_key);

        if ($cached !== false) {
            wp_send_json_success($cached);
        }

        $data = $this->api->get_campaigns('');

        if (is_wp_error($data)) {
            wp_send_json_error([
                'message' => $data->get_error_message(),
                'code'    => $data->get_error_code(),
            ]);
        }

        $payload = $this->transform_campaigns($data);
        set_transient($cache_key, $payload, self::CACHE_TTL_LONG);
        wp_send_json_success($payload);
    }

    // -----------------------------------------------------------------------
    // AJAX: Generate coupon (logged-in only)
    // -----------------------------------------------------------------------

    public function ajax_generate_coupon(): void
    {
        check_ajax_referer('reloopin_launcher', 'nonce');

        if (!is_user_logged_in()) {
            wp_send_json_error(['message' => 'not_logged_in']);
        }

        $campaign_id = absint(wp_unslash($_POST['campaign_id'] ?? 0));
        if ($campaign_id <= 0) {
            wp_send_json_error(['message' => 'invalid_campaign']);
        }

        $user         = wp_get_current_user();
        $customer_ref = $user->user_email;

        $result = $this->api->generate_coupon($campaign_id, $customer_ref);

        if (is_wp_error($result)) {
            wp_send_json_error([
                'message' => $result->get_error_message(),
                'code'    => $result->get_error_code(),
            ]);
        }

        $code = sanitize_text_field($result['code'] ?? '');
        if (empty($code)) {
            wp_send_json_error(['message' => 'no_code']);
        }

        $this->apply_wc_coupon($code, $result, $customer_ref);

        // Invalidate campaigns + balance cache — points deducted after generation.
        $uid = (string) get_current_user_id();
        delete_transient($this->cache_key('camps', $uid));
        delete_transient($this->cache_key('bal', $uid));

        wp_send_json_success([
            'code'           => $code,
            'discount_type'  => $result['discount_type']  ?? '',
            'discount_value' => $result['discount_value'] ?? '',
            'expires_at'     => $result['expires_at']     ?? '',
        ]);
    }

    /**
     * Create a WooCommerce coupon post for a generated reLoopin coupon code.
     */
    private function apply_wc_coupon(string $code, array $api_data, string $customer_ref): void
    {
        if (!function_exists('wc_get_coupon_id_by_code')) {
            reloopin_loyalty_debug('apply_wc_coupon → wc_get_coupon_id_by_code not available');
            return;
        }

        $existing = wc_get_coupon_id_by_code($code);
        if ($existing > 0) {
            $coupon = new WC_Coupon($existing);
            if ($coupon->get_meta('_reloopin_coupon') !== '1') {
                $this->set_reloopin_meta($coupon, $api_data, $code);
                $coupon->save();
            }
            return;
        }

        $wc_type = in_array($api_data['discount_type'] ?? '', ['percentage', 'percent'], true)
            ? 'percent'
            : 'fixed_cart';

        $coupon = new WC_Coupon();
        $coupon->set_code($code);
        $coupon->set_discount_type($wc_type);
        $coupon->set_amount((float) ($api_data['discount_value'] ?? 0));
        $coupon->set_usage_limit(1);
        $coupon->set_usage_limit_per_user(1);
        $coupon->set_email_restrictions([$customer_ref]);

        if (!empty($api_data['expires_at'])) {
            $expires = strtotime($api_data['expires_at']);
            if ($expires > 0) {
                $coupon->set_date_expires($expires);
            }
        }

        // Set meta before first save — WC persists pending meta on save().
        $this->set_reloopin_meta($coupon, $api_data, $code);
        $coupon_id = $coupon->save();

        reloopin_loyalty_debug('apply_wc_coupon → created', [
            'coupon_id' => $coupon_id,
            'code'      => $code,
        ]);
    }

    // -----------------------------------------------------------------------
    // AJAX: Earn status (logged-in only)
    // -----------------------------------------------------------------------

    public function ajax_launcher_earn_status(): void
    {
        $guest_default = ['completed' => [], 'birthday_set' => false];

        $this->ajax_cached(
            'earn_status',
            is_user_logged_in()
                ? $this->cache_key('earn_status', (string) get_current_user_id())
                : 'reloopin_earn_status_guest',
            self::CACHE_TTL_SHORT,
            function (): array {
                $user_id   = get_current_user_id();
                $completed = ['signup'];

                if (function_exists('wc_get_customer_order_count') && wc_get_customer_order_count($user_id) >= 1) {
                    $completed[] = 'first_order';
                }

                $birthday     = get_user_meta($user_id, '_reloopin_birthday', true);
                $birthday_set = !empty($birthday);
                if ($birthday_set) {
                    $completed[] = 'birthday';
                }

                return [
                    'completed'    => $completed,
                    'birthday_set' => $birthday_set,
                ];
            },
            fn(array $data) => $data,
            true,
            $guest_default,
        );
    }

    // -----------------------------------------------------------------------
    // AJAX: Save birthday
    // -----------------------------------------------------------------------

    public function ajax_save_birthday(): void
    {
        check_ajax_referer('reloopin_launcher', 'nonce');

        if (!is_user_logged_in()) {
            wp_send_json_error(['message' => 'not_logged_in']);
        }

        $month = isset($_POST['month']) ? (int) $_POST['month'] : 0;
        $day   = isset($_POST['day'])   ? (int) $_POST['day']   : 0;

        if ($month < 1 || $month > 12 || $day < 1 || $day > self::MAX_DAYS_BY_MONTH[$month]) {
            wp_send_json_error(['message' => 'invalid_date']);
        }

        $user_id  = get_current_user_id();
        $birthday = sprintf('0000-%02d-%02d', $month, $day);
        update_user_meta($user_id, '_reloopin_birthday', $birthday);
        delete_transient($this->cache_key('earn_status', (string) $user_id));

        wp_send_json_success();
    }

    // -----------------------------------------------------------------------
    // Render
    // -----------------------------------------------------------------------

    public function render_launcher(): void
    {
        if (get_option('reloopin_launcher_enabled', 'yes') !== 'yes') {
            return;
        }

        $position      = get_option('reloopin_launcher_position', 'bottom-right') === 'bottom-left' ? 'bottom-left' : 'bottom-right';
        $branding      = get_option('reloopin_launcher_branding', 'yes') === 'yes';
        $program_name  = get_option('reloopin_launcher_program_name', '') ?: get_bloginfo('name');
        $program_icon  = get_option('reloopin_launcher_program_icon', 'layers');
        $pos_class     = 'rl-position-' . $position;

        $hero_icons = [
            'layers' => '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="rgba(255,255,255,.85)" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 2L2 7l10 5 10-5-10-5z"/><path d="M2 17l10 5 10-5"/><path d="M2 12l10 5 10-5"/></svg>',
            'star'   => '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="rgba(255,255,255,.85)" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/></svg>',
            'heart'  => '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="rgba(255,255,255,.85)" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20.84 4.61a5.5 5.5 0 0 0-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 0 0-7.78 7.78l1.06 1.06L12 21.23l7.78-7.78 1.06-1.06a5.5 5.5 0 0 0 0-7.78z"/></svg>',
            'gem'    => '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="rgba(255,255,255,.85)" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polygon points="12 2 2 7 12 12 22 7 12 2"/><polygon points="2 17 12 22 22 17"/><polygon points="2 12 12 17 22 12"/></svg>',
            'gift'   => '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="rgba(255,255,255,.85)" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 12 20 22 4 22 4 12"/><rect x="2" y="7" width="20" height="5"/><line x1="12" y1="22" x2="12" y2="7"/><path d="M12 7H7.5a2.5 2.5 0 0 1 0-5C11 2 12 7 12 7z"/><path d="M12 7h4.5a2.5 2.5 0 0 0 0-5C13 2 12 7 12 7z"/></svg>',
            'crown'  => '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="rgba(255,255,255,.85)" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M2 20h20"/><path d="M4 20V9l4 3 4-7 4 7 4-3v11"/></svg>',
        ];
        $hero_icon_svg = $hero_icons[$program_icon] ?? $hero_icons['layers'];
        ?>

<div id="rl-root" class="<?php echo esc_attr($pos_class); ?>">

  <!-- ── LOGGED-IN STATE ── -->
  <div id="rl-loggedin" style="display:none">

    <!-- Hint bubble -->
    <div class="rl-hint" id="rl-hint">
      <span class="rl-hint-dot"></span>
      <?php esc_html_e('You have', 'reloopin-loyalty'); ?> <strong class="rl-hint-pts">…</strong> <?php esc_html_e('to redeem', 'reloopin-loyalty'); ?>
    </div>

    <!-- Panel -->
    <div class="rl-panel" id="rl-panel">

      <div class="rl-head">
        <div class="rl-head-top">
          <div class="rl-brand">reloopin <span class="rl-gem"></span></div>
          <button type="button" class="rl-close" id="rl-close-btn" aria-label="<?php esc_attr_e('Close', 'reloopin-loyalty'); ?>">&#x2715;</button>
        </div>
        <div class="rl-user">
          <div class="rl-user-av" id="rl-user-av"></div>
          <div>
            <div class="rl-user-name" id="rl-user-name"></div>
            <div class="rl-user-sub"><?php esc_html_e('Your rewards are waiting', 'reloopin-loyalty'); ?></div>
          </div>
        </div>
        <div class="rl-pts-card">
          <div>
            <div class="rl-pts-num" id="rl-pts-num">…</div>
            <div class="rl-pts-lbl"><?php esc_html_e('Points balance', 'reloopin-loyalty'); ?></div>
          </div>
          <div style="text-align:right">
            <div class="rl-tier-badge" id="rl-tier-badge" style="display:none">
              <svg width="9" height="9" viewBox="0 0 24 24" fill="#D97706" stroke="none"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/></svg>
              <span id="rl-tier-name"></span>
            </div>
            <?php // TODO(next-tier UI): the balance endpoint only returns the current tier name (rendered in #rl-tier-badge above). ?>
            <?php // The progress-to-next-tier bar below needs per-tier min_points thresholds, which the tiers endpoint used to ?>
            <?php // provide. Re-wire #rl-prog-wrap / #rl-tier-row (and the at_pts / pts_to i18n strings) once that data is ?>
            <?php // exposed (e.g. next_tier + points_to_next on the balance response). Kept hidden until then. ?>
            <div class="rl-prog-wrap" id="rl-prog-wrap" style="display:none">
              <div class="rl-prog-track"><div class="rl-prog-fill" id="rl-prog-fill"></div></div>
              <div class="rl-prog-lbl" id="rl-prog-lbl"></div>
            </div>
          </div>
        </div>
      </div>

      <?php // TODO(next-tier UI): hidden until per-tier min_points thresholds are available again — see note above. ?>
      <div class="rl-tier-row" id="rl-tier-row" style="display:none">
        <div class="rl-tier-labels">
          <span id="rl-tier-current-label"></span>
          <span id="rl-tier-next-label"></span>
        </div>
        <div class="rl-tier-track"><div class="rl-tier-fill" id="rl-tier-fill"></div></div>
      </div>

      <div class="rl-tabs">
        <button type="button" class="rl-tab active" data-tab="earn"><?php esc_html_e('Earn', 'reloopin-loyalty'); ?> <span class="rl-tab-badge" id="rl-earn-badge"></span></button>
        <button type="button" class="rl-tab" data-tab="redeem"><?php esc_html_e('Redeem', 'reloopin-loyalty'); ?></button>
        <button type="button" class="rl-tab" data-tab="history"><?php esc_html_e('History', 'reloopin-loyalty'); ?></button>
      </div>

      <div class="rl-body">

        <!-- EARN TAB -->
        <div class="rl-pane active" id="rl-earn">
          <div class="rl-state-loading" id="rl-earn-loading"><div class="rl-spinner"></div></div>
          <div id="rl-earn-content" style="display:none"></div>
        </div>

        <!-- REDEEM TAB -->
        <div class="rl-pane" id="rl-redeem">
          <!-- TODO: Wire redeem rules from API. Currently shows static placeholder. -->
          <div class="rl-state-loading" id="rl-redeem-loading"><div class="rl-spinner"></div></div>
          <div id="rl-redeem-content" style="display:none"></div>
        </div>

        <!-- HISTORY TAB -->
        <div class="rl-pane" id="rl-history">
          <div class="rl-hist-summary" id="rl-hist-summary">
            <div class="rl-hs-card"><div class="rl-hs-num earn-c" id="rl-hs-earned">…</div><div class="rl-hs-lbl"><?php esc_html_e('Total earned', 'reloopin-loyalty'); ?></div></div>
            <div class="rl-hs-card"><div class="rl-hs-num spend-c" id="rl-hs-redeemed">…</div><div class="rl-hs-lbl"><?php esc_html_e('Redeemed', 'reloopin-loyalty'); ?></div></div>
            <div class="rl-hs-card"><div class="rl-hs-num" id="rl-hs-balance">…</div><div class="rl-hs-lbl"><?php esc_html_e('Balance', 'reloopin-loyalty'); ?></div></div>
          </div>
          <div class="rl-filter-row" id="rl-filter-row">
            <button type="button" class="rl-filter on" data-filter=""><?php esc_html_e('All', 'reloopin-loyalty'); ?></button>
            <button type="button" class="rl-filter" data-filter="earn"><?php esc_html_e('Earned', 'reloopin-loyalty'); ?></button>
            <button type="button" class="rl-filter" data-filter="redeem"><?php esc_html_e('Redeemed', 'reloopin-loyalty'); ?></button>
          </div>
          <div class="rl-state-loading" id="rl-hist-loading"><div class="rl-spinner"></div></div>
          <div id="rl-hist-list" style="display:none"></div>
          <p class="rl-hist-empty" id="rl-hist-empty" style="display:none"><?php esc_html_e('No points history yet.', 'reloopin-loyalty'); ?></p>
          <p class="rl-hist-err" id="rl-hist-err" style="display:none"><?php esc_html_e('Could not load history. Please try again later.', 'reloopin-loyalty'); ?></p>
          <div class="rl-hist-pagination" id="rl-hist-pagination" style="display:none">
            <button type="button" class="rl-hist-prev" id="rl-hist-prev" disabled>&#8592; <?php esc_html_e('Newer', 'reloopin-loyalty'); ?></button>
            <span class="rl-hist-page-info" id="rl-hist-page-info"></span>
            <button type="button" class="rl-hist-next" id="rl-hist-next"><?php esc_html_e('Older', 'reloopin-loyalty'); ?> &#8594;</button>
          </div>
        </div>

      </div>

      <?php if ($branding) : ?>
      <div class="rl-footer">
        <span class="rl-footer-note"><span class="rl-footer-dot"></span><?php esc_html_e('Powered by Reloopin', 'reloopin-loyalty'); ?></span>
        <!-- TODO: Wire "Apply at checkout" to redeem flow -->
        <button type="button" class="rl-apply-btn" id="rl-apply-btn"><?php esc_html_e('Apply at checkout', 'reloopin-loyalty'); ?></button>
      </div>
      <?php endif; ?>
    </div>

    <!-- Launcher pill — logged in -->
    <button type="button" class="rl-launcher" id="rl-launcher">
      <div class="rl-av" id="rl-launcher-av"></div>
      <?php esc_html_e('My rewards', 'reloopin-loyalty'); ?>
      <span class="rl-pts-text">&middot; <strong id="rl-launcher-pts">…</strong></span>
    </button>

  </div><!-- /rl-loggedin -->


  <!-- ── GUEST STATE ── -->
  <div id="rl-guest" style="display:none">

    <!-- Guest panel -->
    <div class="rl-panel" id="rl-panel-guest">

      <!-- Hero banner -->
      <div class="rl-guest-hero">
        <button type="button" class="rl-guest-hero-close" id="rl-guest-close-btn" aria-label="<?php esc_attr_e('Close', 'reloopin-loyalty'); ?>">&#x2715;</button>
        <div class="rl-guest-hero-inner">
          <div class="rl-guest-hero-eyebrow"><?php esc_html_e('Welcome to', 'reloopin-loyalty'); ?></div>
          <div class="rl-guest-hero-name">
            <?php echo $hero_icon_svg; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG from allowed list ?>
            <?php echo esc_html($program_name); ?>
          </div>
        </div>
      </div>

      <!-- Scrollable body -->
      <div class="rl-guest-body">

        <!-- Join CTA -->
        <div class="rl-guest-join-block">
          <div class="rl-guest-join-title"><?php esc_html_e('Become a member', 'reloopin-loyalty'); ?></div>
          <div class="rl-guest-join-sub"><?php esc_html_e('Join free and start earning points on every order. Unlock VIP tiers and redeem for real discounts.', 'reloopin-loyalty'); ?></div>
          <a href="<?php echo esc_url(function_exists('wc_get_page_permalink') ? wc_get_page_permalink('myaccount') : wp_login_url(get_permalink())); ?>" class="rl-btn-join-main"><?php esc_html_e('Join now', 'reloopin-loyalty'); ?></a>
          <div class="rl-guest-signin-link"><?php esc_html_e('Already have an account?', 'reloopin-loyalty'); ?> <a href="<?php echo esc_url(function_exists('wc_get_page_permalink') ? wc_get_page_permalink('myaccount') : wp_login_url(get_permalink())); ?>"><?php esc_html_e('Sign in', 'reloopin-loyalty'); ?></a></div>
        </div>

        <div class="rl-guest-divider"></div>

        <!-- Points section -->
        <div class="rl-guest-section">
          <div class="rl-guest-section-title">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="#6054D0" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M12 2L2 7l10 5 10-5-10-5z"/><path d="M2 17l10 5 10-5"/><path d="M2 12l10 5 10-5"/></svg>
            <?php esc_html_e('Points', 'reloopin-loyalty'); ?>
          </div>
          <div class="rl-guest-section-sub"><?php esc_html_e('Earn more Points for different actions, and turn those Points into awesome rewards!', 'reloopin-loyalty'); ?></div>

          <!-- Ways to earn accordion -->
          <div class="rl-accord-item" id="rl-guest-earn-accord">
            <div class="rl-accord-head">
              <div class="rl-accord-icon" style="background:#ECFDF5">
                <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="#059669" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M12 2L2 7l10 5 10-5-10-5z"/><path d="M2 17l10 5 10-5"/><path d="M2 12l10 5 10-5"/></svg>
              </div>
              <span class="rl-accord-label"><?php esc_html_e('Ways to earn', 'reloopin-loyalty'); ?></span>
              <svg class="rl-accord-chev" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="#9B96B0" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="9 18 15 12 9 6"/></svg>
            </div>
            <div class="rl-accord-body" id="rl-guest-earn-body">
              <div class="rl-accord-loading"><div class="rl-spinner"></div></div>
            </div>
          </div>

          <!-- Ways to redeem accordion -->
          <div class="rl-accord-item" id="rl-guest-redeem-accord">
            <div class="rl-accord-head">
              <div class="rl-accord-icon" style="background:#F5F0FF">
                <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="#A855F7" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M20.84 4.61a5.5 5.5 0 0 0-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 0 0-7.78 7.78l1.06 1.06L12 21.23l7.78-7.78 1.06-1.06a5.5 5.5 0 0 0 0-7.78z"/></svg>
              </div>
              <span class="rl-accord-label"><?php esc_html_e('Ways to redeem', 'reloopin-loyalty'); ?></span>
              <svg class="rl-accord-chev" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="#9B96B0" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="9 18 15 12 9 6"/></svg>
            </div>
            <div class="rl-accord-body" id="rl-guest-redeem-body">
              <div class="rl-accord-loading"><div class="rl-spinner"></div></div>
            </div>
          </div>

        </div>

        <!-- Footer CTA -->
        <div class="rl-guest-footer-cta">
          <a href="<?php echo esc_url(function_exists('wc_get_page_permalink') ? wc_get_page_permalink('myaccount') : wp_login_url(get_permalink())); ?>" class="rl-btn-join-main"><?php esc_html_e('Sign up', 'reloopin-loyalty'); ?></a>
          <div class="rl-guest-signin-link"><?php esc_html_e('Already have an account?', 'reloopin-loyalty'); ?> <a href="<?php echo esc_url(function_exists('wc_get_page_permalink') ? wc_get_page_permalink('myaccount') : wp_login_url(get_permalink())); ?>"><?php esc_html_e('Sign in', 'reloopin-loyalty'); ?></a></div>
        </div>

      </div><!-- /rl-guest-body -->

    </div><!-- /rl-panel-guest -->

    <!-- Guest launcher — icon only -->
    <button type="button" class="rl-launcher-icon" id="rl-launcher-guest" aria-label="<?php esc_attr_e('Earn rewards', 'reloopin-loyalty'); ?>">
      <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 2L2 7l10 5 10-5-10-5z"/><path d="M2 17l10 5 10-5"/><path d="M2 12l10 5 10-5"/></svg>
    </button>

  </div><!-- /rl-guest -->


  <!-- ── BIRTHDAY MODAL ── -->
  <div class="rl-modal" id="rl-bday-modal">
    <div class="rl-modal-box">
      <div class="rl-modal-head">
        <div class="rl-modal-icon" style="background:#F5F0FF">
          <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#A855F7" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
        </div>
        <button type="button" class="rl-modal-close" id="rl-bday-close">&#x2715;</button>
      </div>
      <div class="rl-modal-title"><?php esc_html_e("When's your birthday?", 'reloopin-loyalty'); ?></div>
      <div class="rl-modal-sub"><?php printf(
          /* translators: %s = bonus points amount */
          esc_html__("We'll send you %s every year on your birthday month. No spam — just a little gift from us.", 'reloopin-loyalty'),
          '<strong>' . esc_html__('100 bonus points', 'reloopin-loyalty') . '</strong>'
      ); ?></div>
      <div class="rl-modal-field">
        <label class="rl-field-label"><?php esc_html_e('Month', 'reloopin-loyalty'); ?></label>
        <select id="rl-bday-month" class="rl-select">
          <option value=""><?php esc_html_e('Select month', 'reloopin-loyalty'); ?></option>
          <?php for ($m = 1; $m <= 12; $m++) : ?>
            <option value="<?php echo esc_attr($m); ?>"><?php echo esc_html(date_i18n('F', mktime(0, 0, 0, $m, 1))); ?></option>
          <?php endfor; ?>
        </select>
      </div>
      <div class="rl-modal-field">
        <label class="rl-field-label"><?php esc_html_e('Day', 'reloopin-loyalty'); ?></label>
        <input type="number" id="rl-bday-day" class="rl-input" placeholder="<?php esc_attr_e('e.g. 14', 'reloopin-loyalty'); ?>" min="1" max="31">
      </div>
      <div class="rl-modal-note">
        <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
        <?php esc_html_e('We only use your birthday month — never shared.', 'reloopin-loyalty'); ?>
      </div>
      <div id="rl-bday-error" class="rl-modal-error"><?php esc_html_e('Please select a month and enter a valid day.', 'reloopin-loyalty'); ?></div>
      <!-- TODO: Wire save to API -->
      <button type="button" class="rl-modal-btn" id="rl-bday-save"><?php esc_html_e('Save my birthday', 'reloopin-loyalty'); ?></button>
    </div>
  </div>

  <!-- ── REFERRAL MODAL ── -->
  <div class="rl-modal" id="rl-ref-modal">
    <div class="rl-modal-box">
      <div class="rl-modal-head">
        <div class="rl-modal-icon" style="background:#EDE9FF">
          <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#6054D0" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
        </div>
        <button type="button" class="rl-modal-close" id="rl-ref-close">&#x2715;</button>
      </div>
      <div class="rl-modal-title"><?php esc_html_e('Refer a friend, earn 250 pts', 'reloopin-loyalty'); ?></div>
      <div class="rl-modal-sub"><?php esc_html_e('Share your unique link. When a friend makes their first purchase, you both get rewarded automatically.', 'reloopin-loyalty'); ?></div>
      <div class="rl-ref-link-wrap">
        <div class="rl-ref-link-box">
          <span class="rl-ref-link-text" id="rl-ref-link-text"></span>
          <button type="button" class="rl-ref-copy-btn" id="rl-ref-copy-btn">
            <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><rect x="9" y="9" width="13" height="13" rx="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/></svg>
            <span id="rl-ref-copy-label"><?php esc_html_e('Copy', 'reloopin-loyalty'); ?></span>
          </button>
        </div>
      </div>
      <div class="rl-ref-steps">
        <div class="rl-ref-step"><div class="rl-ref-step-n">1</div><div class="rl-ref-step-txt"><?php esc_html_e('Share the link with a friend', 'reloopin-loyalty'); ?></div></div>
        <div class="rl-ref-step"><div class="rl-ref-step-n">2</div><div class="rl-ref-step-txt"><?php esc_html_e('They sign up and place their first order', 'reloopin-loyalty'); ?></div></div>
        <div class="rl-ref-step"><div class="rl-ref-step-n">3</div><div class="rl-ref-step-txt"><?php esc_html_e('You earn 250 pts — they get 10% off', 'reloopin-loyalty'); ?></div></div>
      </div>
      <!-- TODO: Wire share buttons to actual share URLs -->
      <div class="rl-ref-share-row">
        <button type="button" class="rl-share-btn" style="background:#1877F2" data-share="facebook">
          <svg width="13" height="13" viewBox="0 0 24 24" fill="#fff" stroke="none"><path d="M18 2h-3a5 5 0 0 0-5 5v3H7v4h3v8h4v-8h3l1-4h-4V7a1 1 0 0 1 1-1h3z"/></svg>
          Facebook
        </button>
        <button type="button" class="rl-share-btn" style="background:#1DA1F2" data-share="twitter">
          <svg width="13" height="13" viewBox="0 0 24 24" fill="#fff" stroke="none"><path d="M23 3a10.9 10.9 0 0 1-3.14 1.53 4.48 4.48 0 0 0-7.86 3v1A10.66 10.66 0 0 1 3 4s-4 9 5 13a11.64 11.64 0 0 1-7 2c9 5 20 0 20-11.5a4.5 4.5 0 0 0-.08-.83A7.72 7.72 0 0 0 23 3z"/></svg>
          Twitter
        </button>
        <button type="button" class="rl-share-btn" style="background:#25D366" data-share="whatsapp">
          <svg width="13" height="13" viewBox="0 0 24 24" fill="#fff" stroke="none"><path d="M21 11.5a8.38 8.38 0 0 1-.9 3.8 8.5 8.5 0 0 1-7.6 4.7 8.38 8.38 0 0 1-3.8-.9L3 21l1.9-5.7a8.38 8.38 0 0 1-.9-3.8 8.5 8.5 0 0 1 4.7-7.6 8.38 8.38 0 0 1 3.8-.9h.5a8.48 8.48 0 0 1 8 8v.5z"/></svg>
          WhatsApp
        </button>
        <button type="button" class="rl-share-btn" style="background:#EA4335" data-share="email">
          <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/></svg>
          Email
        </button>
      </div>
    </div>
  </div>

  <div class="rl-modal-backdrop" id="rl-backdrop"></div>

</div><!-- /rl-root -->

<!-- Toast (outside rl-root for z-index stacking) -->
<div class="rl-toast" id="rl-toast">
  <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="#34D399" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>
  <span id="rl-toast-text"></span>
</div>

        <?php
    }
}
