=== reLoopin Loyalty ===
Contributors: reloopin
Tags: woocommerce, loyalty, rewards, points, referral
Requires at least: 6.0
Tested up to: 6.9
Stable tag: 1.0.0
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
* Tier system — display Bronze / Silver / Gold (or your custom tiers) with progress bars so shoppers always know what they're working towards.
* Referral links — customers share a unique URL and both parties earn points when the friend completes their first order.
* Points history tab with pagination and filter by earn / redeem entry type.
* Transient caching of API responses to keep your store fast.
* WooCommerce HPOS (High-Performance Order Storage) compatible.

**Third-party service notice:**

This plugin connects to the reLoopin loyalty platform to store and retrieve points data. An active reLoopin merchant account, API Key, and Merchant ID are required.

* Service website: [https://reloopin.com](https://reloopin.com)
* Terms of Use: [https://reloopin.com/terms](https://reloopin.com/terms)
* Privacy Policy: [https://reloopin.com/privacy](https://reloopin.com/privacy)

Customer data sent to reLoopin includes: email address, order total, order number, and billing phone number (optional). No payment card data is transmitted.

== Installation ==

1. Upload the `reloopin-loyalty` folder to the `/wp-content/plugins/` directory, or install via **Plugins > Add New** in your WordPress admin.
2. Activate the plugin through the **Plugins** menu.
3. Go to **WooCommerce > Settings > Loyalty** and enter your API Base URL, API Key, Merchant ID, and Merchant Code (all provided by reLoopin).
4. Optionally configure the launcher widget position and branding under the **Launcher Widget** section of the same settings page.

== Frequently Asked Questions ==

= Do I need a reLoopin account? =

Yes. This plugin is a WooCommerce integration for the reLoopin loyalty service. You will need a Merchant ID, API Key, and API Base URL from your reLoopin merchant dashboard.

= Does the plugin work without WooCommerce? =

No. reLoopin Loyalty requires WooCommerce to be installed and active.

= Will customer data leave my server? =

Yes — order and customer data (email address, order total, order ID, billing phone) is sent to the reLoopin API in order to award and manage loyalty points. Please refer to reLoopin's Privacy Policy linked above.

= Can I hide the "Powered by reLoopin" branding? =

Yes. The branding footer is disabled by default and can be toggled under **WooCommerce > Settings > Loyalty > Launcher Widget**.

= Which PHP version is required? =

PHP 8.0 or later.

== Screenshots ==

1. The floating loyalty launcher widget shown on the frontend.
2. The Earn tab displaying available points-earning rules.
3. The Redeem tab showing reward options.
4. The History tab with paginated transaction entries.
5. The WooCommerce Loyalty settings page.

== Changelog ==

= 1.0.0 =
* Initial release.

== Upgrade Notice ==

= 1.0.0 =
Initial release — no upgrade required.
