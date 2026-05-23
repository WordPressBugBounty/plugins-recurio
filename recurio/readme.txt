=== Recurio – Ultimate Subscription for WooCommerce ===
Contributors: devitemsllc, zenaulislam, aslamhasib
Tags: subscriptions, recurring payments, woocommerce subscriptions, recurring billing, subscription management, subscription box, reorder, revenue, Analytics, Report
Requires at least: 5.8
Tested up to: 7.0
Requires PHP: 7.4
Stable tag: 1.1.1
License: GPL v2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

A powerful and comprehensive WooCommerce subscription management plugin with advanced analytics, automated billing, and customer portal.

== Description ==

**Recurio** is a complete subscription management solution for WooCommerce that helps you create, manage, and grow your recurring revenue business. With a modern Vue.js dashboard, automated billing, and comprehensive analytics, Recurio makes subscription management effortless.

🎬 **[Live Demo](https://wprecurio.com/?utm_source=wprepo&utm_medium=freeplugin&utm_campaign=demo)** - See Recurio in action
📚 **[Documentation](https://help.wprecurio.com/docs/?utm_source=wprepo&utm_medium=freeplugin&utm_campaign=doc)** - Complete setup guides & tutorials
💎 **[Get Pro Version](https://wprecurio.com/pricing/?utm_source=wprepo&utm_medium=freeplugin&utm_campaign=purchasepro)** - Unlock all premium features
💬 **[Support](https://wprecurio.com/contact-us/?utm_source=wprepo&utm_medium=freeplugin&utm_campaign=support)** - Get help from our team

https://youtu.be/sylqtuZx-TA

= Key Features =

**📊 Advanced Analytics Dashboard**
* Real-time subscription metrics and KPIs
* Revenue tracking and forecasting
* Cohort analysis and retention rates
* Customer lifetime value calculations
* Churn rate monitoring

**💳 Automated Billing & Payments**
* Automatic recurring payment processing
* Support for multiple payment gateways (Stripe, PayPal, etc.)
* Smart retry logic for failed payments
* Dunning management
* Customizable billing cycles

**👥 Customer Portal**
* Self-service subscription management
* Pause, resume, and cancel subscriptions
* Payment method updates
* Billing history and invoices
* WooCommerce My Account integration

**🎯 Subscription Management**
* Flexible billing periods (daily, weekly, monthly, yearly)
* Free trial periods
* Sign-up fees
* Subscription length limits
* Pause and resume functionality
* Split payments / Installments
* Early renewal option

**📧 Email Notifications**
* Automated email triggers for subscription events
* Renewal reminders
* Payment failure notifications
* Subscription status updates
* Customizable email templates

**🔧 Developer Friendly**
* REST API for external integrations
* Extensive hooks and filters
* Clean, documented code
* Translation ready

= Pro Features =

Unlock the full potential of Recurio with Pro features designed for growing subscription businesses.

**🛒 Subscribe & Save**
Offer customers the choice between one-time purchase or subscription with automatic discounts. Boost recurring revenue by showing savings and encouraging subscription purchases.

**📦 Variable Product Subscriptions**
Set different subscription settings for each product variation. Configure unique pricing, trial periods, billing cycles, and sign-up fees per variation - perfect for tiered subscription plans.

**⏱️ Custom Billing Periods**
Create flexible billing intervals like "every 2 weeks" or "every 3 months". Go beyond standard periods with fully customizable day, week, month, or year intervals.

**📅 Extended Billing Periods**
Access Daily, Weekly, and Quarterly billing periods. Ideal for premium content subscriptions, weekly meal kits, or quarterly membership plans.

**🔄 Subscription Switching**
Let customers upgrade or downgrade their subscriptions seamlessly. Automatic prorated billing ensures fair pricing during plan changes.

= Why Choose Recurio? =

* **Modern Interface**: Built with Vue.js for a fast, responsive experience
* **Performance Optimized**: Efficient database queries and caching
* **Secure**: Follows WordPress coding standards and security best practices
* **Regular Updates**: Actively maintained with new features and improvements
* **Great Support**: Responsive support team ready to help

= Perfect For =

* SaaS businesses
* Membership sites
* Digital product subscriptions
* Box subscriptions
* Service subscriptions
* Content subscriptions
* Any recurring billing needs

= Video created by the community =

https://youtu.be/VrdG_gYP7gQ

== Installation ==

1. Upload the `recurio` folder to the `/wp-content/plugins/` directory
2. Activate the plugin through the 'Plugins' menu in WordPress
3. Go to **Recurio** in your WordPress admin menu
4. Configure your subscription settings
5. Start creating subscription products in WooCommerce

= Requirements =

* WordPress 5.8 or higher
* WooCommerce 6.0 or higher
* PHP 7.4 or higher
* MySQL 5.7 or higher

== Frequently Asked Questions ==

= Does this plugin work with my payment gateway? =

Recurio supports all major WooCommerce payment gateways that support tokenization, including Stripe, PayPal, Square, and more.

= Can customers manage their own subscriptions? =

Yes! Recurio includes a complete customer portal where customers can pause, resume, cancel, and update their subscriptions.

= Is there a limit on the number of subscriptions? =

No, there are no limits on the number of subscriptions you can create or manage.

= Can I offer free trials? =

Yes, you can set up free trial periods for any subscription product.

= Does it support variable subscriptions? =

Yes, Recurio supports both simple and variable subscription products.

= Can I customize the email notifications? =

Yes, all email notifications can be customized through the settings panel.

= Is it compatible with other WooCommerce extensions? =

Recurio is designed to work seamlessly with most WooCommerce extensions. If you encounter any compatibility issues, please contact support.

== Screenshots ==

1. Real-time subscription dashboard with MRR, ARR, and churn analytics
2. Create and manage subscription plans across all your products instantly
3. Powerful subscription management with bulk operations and export
4. Customer segmentation and lifetime value tracking
5. Revenue analytics with goal tracking and performance charts
6. Beautiful self-service customer portal reduces support tickets
7. Automated email notifications for all subscription events
8. Flexible payment gateway configuration with smart retry logic
9. Seamless WooCommerce integration with subscription product type
10. Reduce churn with a smart cancellation retention flow

== Changelog ==

= 1.1.1 - 2026-05-23 =
* Solved: Payment method filter caused persistent checkout error notices that prevented orders from completing — wc_add_notice removed from the gateway filter and moved to a dedicated woocommerce_after_checkout_validation handler.
* Solved: Payment gateway allowlist logic was duplicated across two code paths; consolidated into a single static helper (Recurio_Payment_Methods::are_gateways_allowed_for_subscriptions) to ensure consistent behaviour at checkout and in the admin.
* Solved: Admin notice warning for missing subscription-compatible gateways was being stripped by the Recurio admin notice cleanup; re-attached after the bulk removal so it reliably reaches the screen.
* Improved: Admin gateway warning now only displays when at least one subscription product exists, is restricted to users with manage_options capability, and is dismissible.
* Improved: Subscription-product existence check behind the gateway warning is now cached with a 12-hour transient (recurio_has_subscription_products) and invalidated automatically when any product is saved, trashed, or its status changes.
* Improved: Chart render in dashboard.

= 1.1.0 - 2026-05-17 =
* Added: Variable product subscriptions — set unique pricing, trial periods, billing cycles, and sign-up fees per product variation.
* Added: Subscribe & Save — customers can choose between one-time purchase or subscription with automatic discounts on the product page.
* Added: Skip a billing cycle — customers can skip their next renewal payment from the customer portal.
* Added: Mixed cart support — subscription and one-time purchase products can now be purchased together in a single order.
* Added: Enhanced product page widget — improved subscription option display with configurable badge, pricing breakdown text, and colour picker.
* Added: Shipping charge for renewal orders — plans and products now include an "Include shipping on renewals" toggle. When enabled, the original order's shipping amount and method are snapshotted at subscription creation and automatically added as a shipping line item to every renewal order.
* Improved: Installment / split payment support fully implemented with configurable product access timing and duration settings.

= 1.0.2 - 2026-04-15 =
* Solved: Offer text showing issue in product page.
* Solved: Checkout page order summary showing issue with subscription product.
* Solved: Subscribe status showing issue with non subscription product.
* Solved: Email template wrong billing periods showing issue with custom billing type.

= 1.0.1 - 2026-01-08 =
* Added support for variable products
* Added support for custom billing periods
* Added support for early renewal
* Added support for split payments (installments)
* Added support for subscription switching
* Added support for one-time purchase and subscribe & save

= 1.0.0 - 2025-10-27 =
* Initial release

== Upgrade Notice ==

= 1.0.0 =
Initial release of Recurio - WooCommerce Subscriptions Management plugin.