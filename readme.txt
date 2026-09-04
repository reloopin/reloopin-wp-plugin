=== reLoopin Loyalty ===
Contributors: reloopin
Tags: woocommerce, loyalty, rewards, points, referral
Requires at least: 6.0
Tested up to: 7.1
Stable tag: 1.3.2
Requires PHP: 8.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Connect your WooCommerce store to the reLoopin loyalty backend. Customers earn points on every purchase and redeem them for rewards.

== Description ==

**reLoopin Loyalty** lets you run a fully-featured loyalty and rewards programme for your WooCommerce store, powered by the reLoopin backend.

**Key features:**

* Customers earn points automatically on every completed order.
* Bonus points for special events: first orders, featured-product purchases, coupon campaigns, and free-shipping orders.
* Floating launcher widget — a clean, animated panel that shows each customer their balance, tier progress, earn rules, and transaction history without leaving your store.
* Points estimate badge — a Gutenberg block (and shortcode) you can place anywhere in the theme editor to show logged-in shoppers how many points a product purchase would earn. Updates live when quantity or variation changes.
* Tier system — display Bronze / Silver / Gold (or your custom tiers) with progress bars so shoppers always know what they're working towards.
* Referral links — customers share a unique URL and both parties earn points when the friend completes their first order.
* Points history tab with pagination and filter by earn / redeem entry type.
* Transient caching of API responses to keep your store fast.
* WooCommerce HPOS (High-Performance Order Storage) compatible.

**Third-party service notice:**

This plugin connects to the reLoopin loyalty platform to store and retrieve points data. An active reLoopin merchant account and API Key are required.

* Service website: [https://reloopin.com](https://reloopin.com)
* Terms of Use: [https://reloopin.com/terms](https://reloopin.com/terms)
* Privacy Policy: [https://reloopin.com/privacy](https://reloopin.com/privacy)

Customer data sent to reLoopin includes: email address, order total, order number, and billing phone number (optional). No payment card data is transmitted.

== Installation ==

1. Upload the `reloopin-loyalty` folder to the `/wp-content/plugins/` directory, or install via **Plugins > Add New** in your WordPress admin.
2. Activate the plugin through the **Plugins** menu.
3. Go to **WooCommerce > Settings > Loyalty** and enter your API Base URL and API Key,
Merchant ID (provided by reLoopin).
4. Optionally configure the launcher widget position and branding under the **Launcher Widget** section of the same settings page.

== Frequently Asked Questions ==

= Do I need a reLoopin account? =

Yes. This plugin is a WooCommerce integration for the reLoopin loyalty service. You will need an API Key and API Base URL from your reLoopin merchant dashboard.

= Does the plugin work without WooCommerce? =

No. reLoopin Loyalty requires WooCommerce to be installed and active.

= Will customer data leave my server? =

Yes — order and customer data (email address, order total, order ID, billing phone) is sent to the reLoopin API in order to award and manage loyalty points. Please refer to reLoopin's Privacy Policy linked above.

= Can I hide the "Powered by reLoopin" branding? =

Yes. The branding footer is disabled by default and can be toggled under **WooCommerce > Settings > Loyalty > Launcher Widget**.

= How do I show estimated points on a product page? =

Add the **Points Estimate** block in the theme editor (Appearance → Editor), or use the shortcode `[reloopin_points_estimate]`. Optionally pass `product_id="123"`. The badge is shown only to logged-in customers and updates when quantity or variation changes.

= Which PHP version is required? =

PHP 8.0 or later.

== Screenshots ==

1. The floating loyalty launcher widget shown on the frontend.
2. The Earn tab displaying available points-earning rules.
3. The Redeem tab showing reward options.
4. The History tab with paginated transaction entries.
5. The WooCommerce Loyalty settings page.

== Changelog ==

= 1.3.2 =
* Restyled the Points Estimate badge as a rounded banner using the launcher primary color for text and border (no icon).

= 1.3.1 =
* Points Estimate now supports variable products: the badge follows the selected variation and stays hidden until one is chosen.

= 1.3.0 =
* Added Points Estimate block and shortcode for product purchase point previews (logged-in customers).

= 1.2.0 =
* New: Standalone reLoopin admin menu (replaces WooCommerce settings tab).
* New: Settings use WordPress native register_setting() API with per-field sanitization.
* New: Primary and accent color pickers; font selection for launcher widget.

= 1.1.0 =
* New: Auto-sync new WooCommerce customers to the reLoopin platform.
* New: Track reLoopin-generated coupon redemptions at checkout.
* New: Campaigns tab, birthday bonus, rate limiting on AJAX endpoints.

= 1.0.0 =
* Initial release.

== Upgrade Notice ==

= 1.3.2 =
Restyled the Points Estimate badge. Includes WordPress.org compliance fixes (sanitization, changelog continuity).

= 1.0.0 =
Initial release — no upgrade required.
