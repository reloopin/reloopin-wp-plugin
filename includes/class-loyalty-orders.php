<?php
/**
 * Loyalty Orders
 *
 * Posts a transaction to the loyalty backend when an order is completed.
 * All qualifying event types are posted as separate transactions:
 *   first_order, featured_product_purchase, campaign_coupons, free_shipping
 * product_purchase is the fallback when none of the above apply.
 */

if (!defined('ABSPATH')) {
    exit;
}

class ReLoopin_Loyalty_Orders
{

    private ReLoopin_Loyalty_API $api;
    private WC_Logger_Interface $logger;

    public function __construct(ReLoopin_Loyalty_API $api)
    {
        $this->api = $api;
        $this->logger = wc_get_logger();

        add_action('woocommerce_payment_complete', [$this, 'post_transaction'], 10, 1);
        add_action('woocommerce_order_status_processing', [$this, 'post_transaction'], 10, 1);
    }

    public function post_transaction(int $order_id): void
    {
        reloopin_loyalty_debug("orders: post_transaction triggered", ['order_id' => $order_id]);

        $order = wc_get_order($order_id);

        if (!$order) {
            reloopin_loyalty_debug("orders: order #{$order_id} not found — skipping");
            return;
        }

        $posted_events = json_decode($order->get_meta('_loyalty_events_posted') ?: '[]', true);
        if (!is_array($posted_events)) {
            $posted_events = [];
        }

        $event_types = $this->resolve_event_types($order);
        $pending     = array_values(array_diff($event_types, $posted_events));

        if (empty($pending)) {
            reloopin_loyalty_debug("orders: order #{$order_id} all events already posted — skipping");
            return;
        }

        $customer_id = (int) $order->get_customer_id();
        if ($customer_id > 0) {
            $user = get_user_by('id', $customer_id);
            $customer_email = $user ? $user->user_email : $order->get_billing_email();
        } else {
            $customer_email = $order->get_billing_email();
        }

        if (empty($customer_email)) {
            reloopin_loyalty_debug("orders: order #{$order_id} has no email — skipping");
            return;
        }

        reloopin_loyalty_debug("orders: pending events for order #{$order_id}", $pending);

        // Build line-item metadata once — shared across all event transactions.
        $items = [];
        foreach ($order->get_items() as $item) {
            /** @var WC_Order_Item_Product $item */
            $product = $item->get_product();
            $items[] = [
                'sku'      => $product ? ($product->get_sku() ?: (string) $product->get_id()) : '',
                'name'     => $item->get_name(),
                'qty'      => $item->get_quantity(),
                'price'    => (float) $order->get_item_subtotal($item, false, true),
                'featured' => $product ? $product->is_featured() : false,
            ];
        }

        $base_order_number = (string) $order->get_order_number();
        $succeeded         = []; // event_type → transaction_id
        $failed            = []; // event_type → error_message

        foreach ($pending as $event_type) {
            $result = $this->api->create_transaction([
                'customer_email'       => $customer_email,
                'customer_phone'       => $order->get_billing_phone(),
                'order_id'             => $base_order_number . '-' . $event_type,
                'event_type'           => $event_type,
                'total_amount'         => number_format((float) $order->get_total(), 2, '.', ''),
                'transaction_status'   => 'completed',
                'transaction_metadata' => [
                    'items'    => $items,
                    'platform' => 'woocommerce',
                ],
            ]);

            if (is_wp_error($result)) {
                $failed[$event_type] = $result->get_error_message();
                reloopin_loyalty_debug("orders: transaction failed for order #{$order_id} event {$event_type}", $result->get_error_message());
                $this->logger->error(
                    sprintf('Loyalty: transaction failed for order #%d event %s — %s', $order_id, $event_type, $result->get_error_message()),
                    ['source' => 'reloopin-loyalty']
                );
                continue;
            }

            $tx_id                   = $result['id'] ?? 'n/a';
            $succeeded[$event_type]  = $tx_id;
            $posted_events[]         = $event_type;

            reloopin_loyalty_debug("orders: transaction posted for order #{$order_id}", [
                'event_type'     => $event_type,
                'transaction_id' => $tx_id,
            ]);

            // Save after each success so a mid-loop crash doesn't lose progress.
            $order->update_meta_data('_loyalty_events_posted', json_encode($posted_events));
            $order->save_meta_data();
        }

        // Store the full event → tx_id map.
        if (!empty($succeeded)) {
            $existing_ids = json_decode($order->get_meta('_loyalty_transaction_ids') ?: '{}', true);
            if (!is_array($existing_ids)) {
                $existing_ids = [];
            }
            $order->update_meta_data('_loyalty_transaction_ids', json_encode(array_merge($existing_ids, $succeeded)));
            $order->save_meta_data();
        }

        // Consolidated order note.
        $note_lines = [];
        foreach ($succeeded as $et => $tx_id) {
            $note_lines[] = sprintf('  - %s (tx: %s)', $et, $tx_id);
        }
        foreach ($failed as $et => $err) {
            $note_lines[] = sprintf('  [FAILED: %s — %s]', $et, $err);
        }
        $order->add_order_note(sprintf(
            /* translators: 1: number of transactions posted, 2: newline-separated list of transaction results */
            __('reloopin Loyalty: %1$d transaction(s) posted.%2$s', 'reloopin-loyalty'),
            count($succeeded),
            "\n" . implode("\n", $note_lines)
        ));
        $order->save_meta_data();
    }

    // -----------------------------------------------------------------------

    private function resolve_event_types(WC_Order $order): array
    {
        $events = [];

        // first_order (guests never qualify — customer_id must be > 0)
        $customer_id = (int) $order->get_customer_id();
        if ($customer_id > 0 && wc_get_customer_order_count($customer_id) === 1) {
            reloopin_loyalty_debug("orders: event_type=first_order (customer #{$customer_id})");
            $events[] = 'first_order';
        }

        // featured_product_purchase
        foreach ($order->get_items() as $item) {
            /** @var WC_Order_Item_Product $item */
            $product = $item->get_product();
            if ($product && $product->is_featured()) {
                reloopin_loyalty_debug("orders: event_type=featured_product_purchase (product #{$product->get_id()})");
                $events[] = 'featured_product_purchase';
                break;
            }
        }

        // campaign_coupons
        if (count($order->get_coupon_codes()) > 0) {
            reloopin_loyalty_debug('orders: event_type=campaign_coupons', $order->get_coupon_codes());
            $events[] = 'campaign_coupons';
        }

        // free_shipping
        foreach ($order->get_shipping_methods() as $shipping_method) {
            /** @var WC_Order_Item_Shipping $shipping_method */
            if ($shipping_method->get_method_id() === 'free_shipping') {
                reloopin_loyalty_debug('orders: event_type=free_shipping');
                $events[] = 'free_shipping';
                break;
            }
        }

        // fallback — only when no qualifying events were found
        if (empty($events)) {
            reloopin_loyalty_debug('orders: event_type=product_purchase (fallback)');
            $events[] = 'product_purchase';
        }

        return $events;
    }
}
