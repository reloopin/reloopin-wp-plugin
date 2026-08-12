<?php
/**
 * Points Estimate Widget
 *
 * Gutenberg block + shortcode that shows logged-in shoppers how many points
 * a product purchase would earn. Updates live on qty / variation changes.
 */

if (!defined('ABSPATH')) {
    exit;
}

class ReLoopin_Loyalty_Points_Estimate
{
    private const RATE_LIMIT_MAX = 30;

    private ReLoopin_Loyalty_API $api;

    /** @var bool Set when a badge is rendered so assets can be enqueued late if needed. */
    private bool $needs_assets = false;

    public function __construct(ReLoopin_Loyalty_API $api)
    {
        $this->api = $api;

        add_action('init', [$this, 'register']);
        add_action('wp_enqueue_scripts', [$this, 'enqueue_assets']);
        add_action('wp_footer', [$this, 'maybe_enqueue_late'], 5);

        add_action('wp_ajax_reloopin_points_estimate', [$this, 'ajax_estimate']);
    }

    public function register(): void
    {
        add_shortcode('reloopin_points_estimate', [$this, 'shortcode']);

        $editor_script = 'reloopin-points-estimate-editor';
        wp_register_script(
            $editor_script,
            RELOOPIN_LOYALTY_PLUGIN_URL . 'blocks/points-estimate/edit.js',
            ['wp-blocks', 'wp-element', 'wp-components', 'wp-block-editor', 'wp-i18n'],
            RELOOPIN_LOYALTY_VERSION,
            true
        );

        register_block_type(
            RELOOPIN_LOYALTY_PLUGIN_DIR . 'blocks/points-estimate',
            [
                'render_callback' => [$this, 'render_block'],
                'editor_script'   => $editor_script,
            ]
        );
    }

    /**
     * @param array<string, mixed> $atts
     */
    public function shortcode($atts = []): string
    {
        $atts = shortcode_atts(
            [
                'product_id' => 0,
            ],
            $atts,
            'reloopin_points_estimate'
        );

        return $this->render([
            'product_id' => absint($atts['product_id']),
        ]);
    }

    /**
     * @param array<string, mixed> $attributes
     */
    public function render_block(array $attributes = [], string $content = ''): string
    {
        return $this->render([
            'product_id' => absint($attributes['productId'] ?? 0),
        ]);
    }

    /**
     * @param array{product_id?: int, qty?: int, variation_id?: int} $args
     */
    public function render(array $args = []): string
    {
        if (!is_user_logged_in()) {
            return '';
        }

        $qty = max(1, absint($args['qty'] ?? 1));

        $context = $this->resolve_context(
            absint($args['product_id'] ?? 0),
            $qty,
            absint($args['variation_id'] ?? 0)
        );
        if (!$context) {
            return '';
        }

        // A variable product with no variation chosen has no single price yet.
        // Render an empty shell so the script can fill it in once one is picked.
        if ($context['amount'] === null) {
            return $context['is_variable']
                ? $this->badge_markup($context['product'], 0, null, true)
                : '';
        }

        $user = wp_get_current_user();
        $customer_ref = (string) $user->user_email;
        if ($customer_ref === '') {
            return '';
        }

        $result = $this->api->estimate_points($customer_ref, $context['amount'], 'product_purchase');
        if (is_wp_error($result)) {
            reloopin_loyalty_debug('points_estimate: API error', $result->get_error_message());
            return '';
        }

        return $this->badge_markup(
            $context['product'],
            $context['variation_id'],
            (int) ($result['points_earned'] ?? 0),
            $context['is_variable']
        );
    }

    public function enqueue_assets(): void
    {
        if (!is_user_logged_in()) {
            return;
        }

        if (!$this->should_enqueue()) {
            return;
        }

        $this->do_enqueue();
    }

    /**
     * If a badge rendered after wp_enqueue_scripts (e.g. late shortcode), enqueue in footer.
     */
    public function maybe_enqueue_late(): void
    {
        if (!$this->needs_assets || !is_user_logged_in()) {
            return;
        }

        if (wp_script_is('reloopin-points-estimate', 'enqueued')) {
            return;
        }

        $this->do_enqueue();
    }

    public function ajax_estimate(): void
    {
        check_ajax_referer('reloopin_points_estimate', 'nonce');

        if (!is_user_logged_in()) {
            wp_send_json_error(['message' => 'not_logged_in'], 401);
        }

        if (!$this->check_rate_limit()) {
            wp_send_json_error(['message' => 'rate_limited'], 429);
        }

        $product_id   = absint($_POST['product_id'] ?? 0);
        $variation_id = absint($_POST['variation_id'] ?? 0);
        $qty          = max(1, absint($_POST['qty'] ?? 1));

        $context = $this->resolve_context($product_id, $qty, $variation_id);
        if (!$context) {
            wp_send_json_error(['message' => 'product_not_found'], 404);
        }

        if ($context['amount'] === null) {
            wp_send_json_error(['message' => 'invalid_amount'], 400);
        }

        $user = wp_get_current_user();
        $customer_ref = (string) $user->user_email;
        if ($customer_ref === '') {
            wp_send_json_error(['message' => 'missing_email'], 400);
        }

        $result = $this->api->estimate_points($customer_ref, $context['amount'], 'product_purchase');
        if (is_wp_error($result)) {
            wp_send_json_error([
                'message' => $result->get_error_message(),
                'code'    => $result->get_error_code(),
            ]);
        }

        $points = (int) ($result['points_earned'] ?? 0);

        wp_send_json_success([
            'points_earned' => $points,
            'text'          => $this->format_text($points),
            'amount'        => $context['amount'],
        ]);
    }

    /**
     * Resolve product, variation and line amount from a request or page context.
     *
     * A variation is normalised to its parent so markup always carries the parent ID.
     *
     * @return array{product: WC_Product, variation_id: int, is_variable: bool, amount: ?string}|null
     */
    private function resolve_context(int $product_id, int $qty, int $variation_id): ?array
    {
        $product = $this->resolve_product($product_id > 0 ? $product_id : null);
        if (!$product) {
            return null;
        }

        if ($product->is_type('variation')) {
            $variation_id = (int) $product->get_id();
            $parent       = wc_get_product($product->get_parent_id());
            if ($parent instanceof WC_Product) {
                $product = $parent;
            }
        }

        $is_variable = $product->is_type('variable');

        if ($is_variable && $variation_id === 0) {
            $variation_id = $this->resolve_default_variation_id($product);
        }

        return [
            'product'      => $product,
            'variation_id' => $variation_id,
            'is_variable'  => $is_variable,
            'amount'       => $this->resolve_amount($product, $qty, $variation_id),
        ];
    }

    /**
     * Variation matching the product's default attributes, mirroring what the
     * storefront pre-selects. Returns 0 when the defaults are partial or unset.
     */
    private function resolve_default_variation_id(WC_Product $product): int
    {
        if (!$product instanceof WC_Product_Variable) {
            return 0;
        }

        $defaults = $product->get_default_attributes();
        if (empty($defaults) || count($defaults) < count($product->get_variation_attributes())) {
            return 0;
        }

        $match_attributes = [];
        foreach ($defaults as $attribute => $value) {
            $match_attributes['attribute_' . $attribute] = $value;
        }

        $data_store = WC_Data_Store::load('product');

        return absint($data_store->find_matching_product_variation($product, $match_attributes));
    }

    private function badge_markup(WC_Product $product, int $variation_id, ?int $points, bool $is_variable): string
    {
        $this->needs_assets = true;

        $classes = 'reloopin-points-estimate';
        $extra   = '';
        $text    = '';

        if ($points === null) {
            $classes .= ' reloopin-points-estimate--awaiting';
            $extra    = ' hidden';
        } else {
            $extra = sprintf(' data-points="%d"', $points);
            $text  = esc_html($this->format_text($points));
        }

        return sprintf(
            '<div class="%s" data-product-id="%d" data-variation-id="%d" data-is-variable="%d" aria-live="polite"%s>%s</div>',
            esc_attr($classes),
            (int) $product->get_id(),
            $variation_id,
            $is_variable ? 1 : 0,
            $extra,
            $text
        );
    }

    private function resolve_product(?int $product_id): ?WC_Product
    {
        if ($product_id && $product_id > 0) {
            $product = wc_get_product($product_id);
            return $product instanceof WC_Product ? $product : null;
        }

        global $product;
        if ($product instanceof WC_Product) {
            return $product;
        }

        if (is_singular('product')) {
            $from_id = wc_get_product(get_the_ID());
            return $from_id instanceof WC_Product ? $from_id : null;
        }

        return null;
    }

    /**
     * Line total: unit_price × quantity, in the same tax display mode as the shop.
     *
     * Returns null when no priceable product is available — notably a variable
     * parent before the shopper picks a variation.
     */
    private function resolve_amount(WC_Product $product, int $qty = 1, int $variation_id = 0): ?string
    {
        $priced = $product;

        if ($variation_id > 0) {
            $variation = wc_get_product($variation_id);
            if (!$variation instanceof WC_Product) {
                return null;
            }

            $belongs = (int) $variation->get_parent_id() === (int) $product->get_id()
                || (int) $variation->get_id() === (int) $product->get_id();
            if (!$belongs) {
                return null;
            }

            $priced = $variation;
        } elseif ($product->is_type('variable')) {
            return null;
        }

        if ($priced->get_price() === '' || $priced->get_price() === null) {
            return null;
        }

        $unit = (float) wc_get_price_to_display($priced);
        if ($unit <= 0) {
            return null;
        }

        return number_format($unit * max(1, $qty), 2, '.', '');
    }

    private function format_text(int $points): string
    {
        return sprintf(
            /* translators: %s: number of points */
            __('Earn %s points', 'reloopin-loyalty'),
            number_format_i18n($points)
        );
    }

    private function should_enqueue(): bool
    {
        if (is_singular('product')) {
            return true;
        }

        if (is_singular() && has_block('reloopin/points-estimate')) {
            return true;
        }

        if (is_singular()) {
            $post = get_post();
            if ($post && has_shortcode((string) $post->post_content, 'reloopin_points_estimate')) {
                return true;
            }
        }

        return false;
    }

    private function do_enqueue(): void
    {
        wp_enqueue_style(
            'reloopin-points-estimate',
            RELOOPIN_LOYALTY_PLUGIN_URL . 'assets/css/points-estimate.css',
            [],
            RELOOPIN_LOYALTY_VERSION
        );

        wp_enqueue_script(
            'reloopin-points-estimate',
            RELOOPIN_LOYALTY_PLUGIN_URL . 'assets/js/points-estimate.js',
            ['jquery', 'wc-add-to-cart-variation'],
            RELOOPIN_LOYALTY_VERSION,
            true
        );

        wp_localize_script('reloopin-points-estimate', 'reloopinPointsEstimate', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce'   => wp_create_nonce('reloopin_points_estimate'),
            'action'  => 'reloopin_points_estimate',
        ]);
    }

    private function check_rate_limit(): bool
    {
        $user_key = (string) get_current_user_id();
        $key      = 'reloopin_rl_' . md5('points_estimate' . $user_key);
        $count    = (int) get_transient($key);

        if ($count >= self::RATE_LIMIT_MAX) {
            return false;
        }

        set_transient($key, $count + 1, MINUTE_IN_SECONDS);
        return true;
    }
}
