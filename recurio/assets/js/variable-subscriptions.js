/**
 * Variable Product Subscriptions - Admin JavaScript
 *
 * Handles the variation subscription settings UI in WooCommerce product edit page
 *
 * @package Recurio
 * @since 1.0.0
 */

(function ($) {
	'use strict';

	var RecurioVariableSubscriptions = {

		/**
		 * Initialize
		 */
		init: function () {
			this.bindEvents();
			this.initExistingVariations();
			this.syncParentSubscriptionState();
		},

		/**
		 * Bind events
		 */
		bindEvents: function () {
			var self = this;

			// Handle parent subscription checkbox toggle - real-time show/hide of variation settings
			$(document).on('change', '#_recurio_subscription_enabled', function () {
				self.toggleVariationSubscriptionSettings($(this).is(':checked'));
			});

			// Handle override checkbox toggle
			$(document).on('change', '.recurio-override-subscription', function () {
				self.toggleSubscriptionOptions($(this));
			});

			// Handle custom period checkbox toggle
			$(document).on('change', '.recurio-variation-custom-period', function () {
				self.toggleCustomPeriod($(this));
			});

			// Handle variation added - WooCommerce triggers this when variations are loaded/added
			$(document).on('woocommerce_variations_loaded', function () {
				self.initExistingVariations();
				self.syncParentSubscriptionState();
			});

			$(document).on('woocommerce_variations_added', function () {
				self.initExistingVariations();
				self.syncParentSubscriptionState();
			});

			// Also listen for the variation panel being opened
			$(document).on('click', '.woocommerce_variation h3', function () {
				setTimeout(function () {
					self.initExistingVariations();
					self.syncParentSubscriptionState();
				}, 100);
			});
		},

		/**
		 * Sync variation subscription settings visibility with parent checkbox state
		 */
		syncParentSubscriptionState: function () {
			var $parentCheckbox = $('#_recurio_subscription_enabled');
			if ($parentCheckbox.length) {
				this.toggleVariationSubscriptionSettings($parentCheckbox.is(':checked'));
			}
		},

		/**
		 * Toggle all variation subscription settings visibility
		 *
		 * @param {boolean} isEnabled Whether parent subscription is enabled
		 */
		toggleVariationSubscriptionSettings: function (isEnabled) {
			var $variationSettings = $('.recurio-variation-subscription-settings');

			if (isEnabled) {
				$variationSettings.removeClass('recurio-hidden');
			} else {
				$variationSettings.addClass('recurio-hidden');
			}
		},

		/**
		 * Initialize existing variations
		 */
		initExistingVariations: function () {
			var self = this;

			// Process each override checkbox
			$('.recurio-override-subscription').each(function () {
				self.toggleSubscriptionOptions($(this));
			});

			// Process each custom period checkbox
			$('.recurio-variation-custom-period').each(function () {
				self.toggleCustomPeriod($(this));
			});
		},

		/**
		 * Toggle subscription options visibility
		 *
		 * @param {jQuery} $checkbox Override checkbox
		 */
		toggleSubscriptionOptions: function ($checkbox) {
			var $container = $checkbox.closest('.recurio-variation-subscription-settings');
			var $options = $container.find('.recurio-variation-subscription-options');

			if ($checkbox.is(':checked')) {
				$options.removeClass('recurio-hidden');
			} else {
				$options.addClass('recurio-hidden');
			}
		},

		/**
		 * Toggle custom period fields visibility
		 *
		 * @param {jQuery} $checkbox Custom period checkbox
		 */
		toggleCustomPeriod: function ($checkbox) {
			var $container = $checkbox.closest('.recurio-variation-subscription-options');
			var $standardPeriod = $container.find('.recurio-variation-standard-period');
			var $customPeriod = $container.find('.recurio-variation-custom-period-fields');

			if ($checkbox.is(':checked')) {
				$standardPeriod.addClass('recurio-hidden');
				$customPeriod.removeClass('recurio-hidden');
			} else {
				$standardPeriod.removeClass('recurio-hidden');
				$customPeriod.addClass('recurio-hidden');
			}
		}
	};

	// Initialize on document ready
	$(document).ready(function () {
		RecurioVariableSubscriptions.init();
	});

})(jQuery);
