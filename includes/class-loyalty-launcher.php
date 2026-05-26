<?php
/**
 * Loyalty Launcher Widget
 *
 * Renders the floating launcher widget matching the reloopin_widget.html design.
 * Features: tabbed Earn/Redeem/History, tier progress, modals, toast notifications.
 *
 * Dynamic data (balance, history, rules, tiers) is loaded via AJAX on first open.
 * Guest users see a sign-up CTA linking to WP login/register pages.
 */

if (!defined('ABSPATH')) {
    exit;
}

class ReLoopin_Loyalty_Launcher
{

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
        add_action('wp_ajax_reloopin_launcher_tiers', [$this, 'ajax_launcher_tiers']);
        add_action('wp_ajax_nopriv_reloopin_launcher_tiers', [$this, 'ajax_launcher_tiers']);
        // Earn status + birthday save (logged-in only)
        add_action('wp_ajax_reloopin_launcher_earn_status', [$this, 'ajax_launcher_earn_status']);
        add_action('wp_ajax_reloopin_save_birthday',        [$this, 'ajax_save_birthday']);

        // Cache invalidation
        add_action('woocommerce_payment_complete', [$this, 'invalidate_user_cache']);
        add_action('woocommerce_order_status_processing', [$this, 'invalidate_user_cache']);
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
        if ($user_id > 0) {
            delete_transient('reloopin_bal_' . $user_id);
            delete_transient('reloopin_hist_' . $user_id . '_1');
        }
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

        // Google Fonts — encode commas per WP docs to prevent URL stripping
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

        // Build user initials for logged-in users
        $initials = '';
        $first_name = '';
        if (is_user_logged_in()) {
            $user = wp_get_current_user();
            $first_name = $user->first_name ?: $user->display_name;
            $parts = explode(' ', trim($user->display_name ?: $user->user_login));
            $initials = strtoupper(mb_substr($parts[0], 0, 1));
            if (count($parts) > 1) {
                $initials .= strtoupper(mb_substr(end($parts), 0, 1));
            }
        }

        wp_localize_script('reloopin-launcher', 'reloopinLauncher', [
            'ajax_url'        => admin_url('admin-ajax.php'),
            'nonce'           => wp_create_nonce('reloopin_launcher'),
            'is_logged_in'    => is_user_logged_in(),
            'login_url'       => wp_login_url(get_permalink()),
            'register_url'    => wp_registration_url(),
            'user_initials'   => $initials,
            'user_first_name' => $first_name,
            'i18n'            => [
                /* translators: %s: customer first name */
                'welcome_back'       => __('Welcome back, %s!', 'reloopin-loyalty'),
                'ways_to_earn'       => __('Ways to earn', 'reloopin-loyalty'),
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
                'redeem_placeholder' => __('Redeem options will appear here. Use the "Apply at checkout" button below to redeem points on your next order.', 'reloopin-loyalty'),
                /* translators: 1: points required, 2: next tier name */
                'pts_to'             => __('%1$s pts to %2$s', 'reloopin-loyalty'),
                /* translators: 1: reward label, 2: points cost */
                'at_pts'             => __('%1$s at %2$s pts', 'reloopin-loyalty'),
                'unknown'            => __('Unknown', 'reloopin-loyalty'),
                'bday_saved'         => __('Birthday saved! You\'ll earn bonus points every year.', 'reloopin-loyalty'),
                'referral_copied'    => __('Referral link copied to clipboard!', 'reloopin-loyalty'),
                'apply_prompt'       => __('Choose a reward to apply at checkout.', 'reloopin-loyalty'),
                'earn_error'         => __('Could not load earn rules.', 'reloopin-loyalty'),
                'redeem_error'       => __('Could not load redeem options.', 'reloopin-loyalty'),
                'no_earn_rules'      => __('No earn rules available.', 'reloopin-loyalty'),
                'already_earned'     => __('Already earned', 'reloopin-loyalty'),
                'ready_to_earn'      => __('Ready to earn', 'reloopin-loyalty'),
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

        $user_id   = get_current_user_id();
        $cache_key = 'reloopin_bal_' . $user_id;
        $cached    = get_transient($cache_key);

        if ($cached !== false) {
            wp_send_json_success($cached);
        }

        $user         = wp_get_current_user();
        $balance_data = $this->api->get_balance($user->user_email);

        if (is_wp_error($balance_data)) {
            wp_send_json_error(['message' => __('Could not fetch points balance.', 'reloopin-loyalty')]);
        }

        $display_name = $user->display_name ?: $user->first_name ?: $user->user_login;
        $parts = explode(' ', trim($display_name));
        $initials = strtoupper(mb_substr($parts[0], 0, 1));
        if (count($parts) > 1) {
            $initials .= strtoupper(mb_substr(end($parts), 0, 1));
        }

        $payload = [
            'logged_in'        => true,
            'name'             => $user->first_name ?: $display_name,
            'initials'         => $initials,
            'available_points' => (int) ($balance_data['available_points'] ?? 0),
            'lifetime_points'  => (int) ($balance_data['lifetime_points'] ?? 0),
            'redeemed_points'  => (int) ($balance_data['redeemed_points'] ?? 0),
            'expired_points'   => (int) ($balance_data['expired_points'] ?? 0),
            'tier'             => $balance_data['tier'] ?? '',
            'referral_url'     => add_query_arg('ref', $user_id, home_url('/')),
        ];

        set_transient($cache_key, $payload, 5 * MINUTE_IN_SECONDS);
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

        $user_id    = get_current_user_id();
        $page       = max(1, absint(wp_unslash($_POST['page'] ?? 1)));
        $entry_type = isset($_POST['entry_type']) ? sanitize_text_field(wp_unslash($_POST['entry_type'])) : null;
        $cache_key  = 'reloopin_hist_' . $user_id . '_' . $page . '_' . ($entry_type ?: 'all');
        $cached     = get_transient($cache_key);

        if ($cached !== false) {
            wp_send_json_success($cached);
        }

        $user         = wp_get_current_user();
        $history_data = $this->api->get_history($user->user_email, $page, 10, $entry_type ?: null);

        if (is_wp_error($history_data)) {
            wp_send_json_error(['message' => $history_data->get_error_message()]);
        }

        $results = array_map(function (array $entry): array {
            $delta    = (int) ($entry['points'] ?? 0);
            $date_raw = $entry['created_at'] ?? $entry['timestamp'] ?? '';
            return [
                'date'          => $date_raw ? date_i18n(get_option('date_format'), strtotime($date_raw)) : '',
                'date_raw'      => $date_raw,
                'entry_type'    => $entry['entry_type'] ?? $entry['type'] ?? '',
                'points'        => $delta,
                'balance_after' => (int) ($entry['balance_after'] ?? 0),
                'notes'         => $entry['notes'] ?? $entry['note'] ?? '',
            ];
        }, $history_data['results'] ?? []);

        $payload = [
            'results'   => $results,
            'total'     => (int) ($history_data['total'] ?? 0),
            'page_size' => (int) ($history_data['page_size'] ?? 10),
            'page'      => $page,
        ];

        set_transient($cache_key, $payload, 5 * MINUTE_IN_SECONDS);
        wp_send_json_success($payload);
    }

    // -----------------------------------------------------------------------
    // AJAX: Rules
    // -----------------------------------------------------------------------

    public function ajax_launcher_rules(): void
    {
        check_ajax_referer('reloopin_launcher', 'nonce');

        $cache_key = 'reloopin_rules_' . get_option('reloopin_loyalty_merchant_id', '');
        $cached    = get_transient($cache_key);

        if ($cached !== false) {
            wp_send_json_success($cached);
        }

        $rules_data = $this->api->get_rules();

        if (is_wp_error($rules_data)) {
            wp_send_json_error(['message' => $rules_data->get_error_message()]);
        }

        // Normalize: the API may return the array directly or nested
        $rules = is_array($rules_data) && isset($rules_data[0]) ? $rules_data : ($rules_data['results'] ?? $rules_data);

        $payload = array_map(function (array $rule): array {
            return [
                'id'         => (int) ($rule['id'] ?? 0),
                'name'       => $rule['name'] ?? '',
                'rule_type'  => $rule['rule_type'] ?? '',
                'event_type' => $rule['event_type'] ?? '',
                'earn_rate'  => (float) ($rule['earn_rate'] ?? 0),
                'is_active'  => (bool) ($rule['is_active'] ?? false),
                'conditions' => $rule['conditions'] ?? null,
            ];
        }, is_array($rules) ? $rules : []);

        set_transient($cache_key, $payload, 15 * MINUTE_IN_SECONDS);
        wp_send_json_success($payload);
    }

    // -----------------------------------------------------------------------
    // AJAX: Tiers
    // -----------------------------------------------------------------------

    public function ajax_launcher_tiers(): void
    {
        check_ajax_referer('reloopin_launcher', 'nonce');

        $cache_key = 'reloopin_tiers_' . get_option('reloopin_loyalty_merchant_id', '');
        $cached    = get_transient($cache_key);

        if ($cached !== false) {
            wp_send_json_success($cached);
        }

        $tiers_data = $this->api->get_tiers();

        if (is_wp_error($tiers_data)) {
            wp_send_json_error(['message' => $tiers_data->get_error_message()]);
        }

        $tiers = is_array($tiers_data) && isset($tiers_data[0]) ? $tiers_data : ($tiers_data['results'] ?? $tiers_data);

        $payload = array_map(function (array $tier): array {
            return [
                'tier_name'  => $tier['tier_name'] ?? '',
                'min_points' => (int) ($tier['min_points'] ?? 0),
                'max_points' => isset($tier['max_points']) ? (int) $tier['max_points'] : null,
                'multiplier' => (float) ($tier['multiplier'] ?? 1),
                'benefits'   => $tier['benefits'] ?? null,
                'is_active'  => (bool) ($tier['is_active'] ?? false),
            ];
        }, is_array($tiers) ? $tiers : []);

        // Sort by min_points ascending
        usort($payload, fn($a, $b) => $a['min_points'] <=> $b['min_points']);

        set_transient($cache_key, $payload, 15 * MINUTE_IN_SECONDS);
        wp_send_json_success($payload);
    }

    // -----------------------------------------------------------------------
    // AJAX: Earn status (logged-in only)
    // -----------------------------------------------------------------------

    public function ajax_launcher_earn_status(): void
    {
        check_ajax_referer('reloopin_launcher', 'nonce');

        if (!is_user_logged_in()) {
            wp_send_json_success(['completed' => [], 'birthday_set' => false]);
        }

        $user_id   = get_current_user_id();
        $cache_key = 'reloopin_earn_status_' . $user_id;
        $cached    = get_transient($cache_key);

        if ($cached !== false) {
            wp_send_json_success($cached);
        }

        $completed = ['signup']; // always done for any registered user

        if (function_exists('wc_get_customer_order_count') && wc_get_customer_order_count($user_id) >= 1) {
            $completed[] = 'first_order';
        }

        $birthday     = get_user_meta($user_id, '_reloopin_birthday', true); // "0000-MM-DD"
        $birthday_set = !empty($birthday);
        if ($birthday_set) {
            $completed[] = 'birthday';
        }

        $payload = [
            'completed'    => $completed,
            'birthday_set' => $birthday_set,
        ];

        set_transient($cache_key, $payload, 5 * MINUTE_IN_SECONDS);
        wp_send_json_success($payload);
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

        if ($month < 1 || $month > 12 || $day < 1 || $day > 31) {
            wp_send_json_error(['message' => 'invalid_date']);
        }

        $user_id  = get_current_user_id();
        $birthday = sprintf('0000-%02d-%02d', $month, $day);
        update_user_meta($user_id, '_reloopin_birthday', $birthday);
        delete_transient('reloopin_earn_status_' . $user_id);

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

        $position = get_option('reloopin_launcher_position', 'bottom-right') === 'bottom-left' ? 'bottom-left' : 'bottom-right';
        $branding = get_option('reloopin_launcher_branding', 'yes') === 'yes';
        $pos_class = 'rl-position-' . $position;
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
            <div class="rl-prog-wrap" id="rl-prog-wrap" style="display:none">
              <div class="rl-prog-track"><div class="rl-prog-fill" id="rl-prog-fill"></div></div>
              <div class="rl-prog-lbl" id="rl-prog-lbl"></div>
            </div>
          </div>
        </div>
      </div>

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

    <!-- Guest hint bubble -->
    <div class="rl-hint-guest" id="rl-guest-hint">
      <?php esc_html_e('Join free — earn points on every purchase', 'reloopin-loyalty'); ?>
    </div>

    <!-- Guest panel -->
    <div class="rl-panel" id="rl-panel-guest">
      <div class="rl-head-guest">
        <div class="rl-head-top" style="margin-bottom:.7rem">
          <div class="rl-brand">reloopin <span class="rl-gem"></span></div>
          <button type="button" class="rl-close" id="rl-guest-close-btn" aria-label="<?php esc_attr_e('Close', 'reloopin-loyalty'); ?>">&#x2715;</button>
        </div>
        <div class="rl-guest-icon">
          <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="#6054D0" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 2L2 7l10 5 10-5-10-5z"/><path d="M2 17l10 5 10-5"/><path d="M2 12l10 5 10-5"/></svg>
        </div>
        <div class="rl-guest-title"><?php esc_html_e('Earn rewards as you shop', 'reloopin-loyalty'); ?></div>
        <div class="rl-guest-sub"><?php esc_html_e('Join our loyalty program — free forever. Earn points on every order, unlock VIP perks, and redeem for discounts.', 'reloopin-loyalty'); ?></div>

        <div class="rl-guest-earn-preview">
          <div class="rl-gep-card">
            <div class="rl-gep-pts">+2x pts</div>
            <div class="rl-gep-label"><?php esc_html_e('On every purchase', 'reloopin-loyalty'); ?></div>
          </div>
          <div class="rl-gep-card">
            <div class="rl-gep-pts" style="color:#D97706"><?php esc_html_e('Gold', 'reloopin-loyalty'); ?></div>
            <div class="rl-gep-label"><?php esc_html_e('Tier unlocks perks', 'reloopin-loyalty'); ?></div>
          </div>
          <div class="rl-gep-card">
            <div class="rl-gep-pts" style="color:#A855F7"><?php esc_html_e('$5 off', 'reloopin-loyalty'); ?></div>
            <div class="rl-gep-label"><?php esc_html_e('After just 500 pts', 'reloopin-loyalty'); ?></div>
          </div>
          <div class="rl-gep-card">
            <div class="rl-gep-pts">+250 pts</div>
            <div class="rl-gep-label"><?php esc_html_e('For each friend referred', 'reloopin-loyalty'); ?></div>
          </div>
        </div>

        <div class="rl-auth-btns">
          <a href="<?php echo esc_url(wp_login_url(get_permalink())); ?>" class="rl-btn-signin"><?php esc_html_e('Sign in to your account', 'reloopin-loyalty'); ?></a>
          <a href="<?php echo esc_url(wp_registration_url()); ?>" class="rl-btn-join">
            <?php esc_html_e('New here? Join free —', 'reloopin-loyalty'); ?> <span><?php esc_html_e('start earning today', 'reloopin-loyalty'); ?></span>
          </a>
        </div>
      </div>
    </div>

    <!-- Guest launcher -->
    <button type="button" class="rl-launcher-guest" id="rl-launcher-guest">
      <div class="rl-guest-av">
        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="#6054D0" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M12 2L2 7l10 5 10-5-10-5z"/><path d="M2 17l10 5 10-5"/><path d="M2 12l10 5 10-5"/></svg>
      </div>
      <span class="rl-launcher-guest-txt"><?php esc_html_e('Earn rewards', 'reloopin-loyalty'); ?></span>
      <span class="rl-launcher-guest-sub">&middot; <?php esc_html_e('join free', 'reloopin-loyalty'); ?></span>
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
