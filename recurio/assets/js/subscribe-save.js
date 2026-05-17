/**
 * Recurio Subscribe & Save Frontend JavaScript
 *
 * @package Recurio
 * @since 1.2.0
 */

(function($) {
	'use strict';

	var RecurioSubscribeSave = {
		showSavings: function() {
			return recurioSubscribeSave.showSavings !== false;
		},

		init: function() {
			this.applyWidgetColor();
			this.bindEvents();
			this.updateButtonText();
		},

		applyWidgetColor: function() {
			var $container = $('.recurio-purchase-options');
			if (!$container.length) return;
			// Prefer localized script value; fall back to data attribute (already set server-side)
			var color = (recurioSubscribeSave.widgetColor || $container.data('widget-color') || '').trim();
			if (!color) return;
			$container[0].style.setProperty('--recurio-primary', color);
		},

		bindEvents: function() {
			var self = this;

			// Handle purchase type selection change
			$(document).on('change', 'input[name="recurio_purchase_type"]', function() {
				self.updateButtonText();
				self.updatePriceDisplay();
			});

			// Handle variation changes for variable products
			$(document).on('found_variation', function(event, variation) {
				self.handleVariationChange(variation);
			});

			$(document).on('reset_data', function() {
				self.resetVariationData();
			});
		},

		updateButtonText: function() {
			// Only update button text if Subscribe & Save options are present on the page
			var $purchaseTypeInputs = $('input[name="recurio_purchase_type"]');
			if (!$purchaseTypeInputs.length) {
				// No Subscribe & Save options - don't modify button text
				return;
			}

			var purchaseType = $purchaseTypeInputs.filter(':checked').val();
			var $button = $('.single_add_to_cart_button');

			if (!$button.length) {
				return;
			}

			if (purchaseType === 'subscription') {
				$button.text(recurioSubscribeSave.i18n.subscribe || 'Subscribe Now');
			} else if (purchaseType === 'one-time') {
				$button.text(recurioSubscribeSave.i18n.addToCart || 'Add to cart');
			}
			// If purchaseType is undefined/empty, don't change the button text
		},

		updatePriceDisplay: function() {
			// This can be extended to update the main price display
			// when switching between one-time and subscription
		},

		/**
		 * When a variation overrides subscription price, use recurio_subscription from
		 * woocommerce_available_variation — do not stack parent Subscribe % on that amount.
		 */
		handleVariationChange: function(variation) {

			if (!variation || variation.display_price === undefined || variation.display_price === null || variation.display_price === '') {
				return;
			}

			var regularPrice = parseFloat(variation.display_regular_price);
			if (isNaN(regularPrice) || regularPrice < 0) {
				regularPrice = parseFloat(variation.display_price);
			}
			if (isNaN(regularPrice)) {
				return;
			}

			var rs = variation.recurio_subscription;
			var subscriptionPrice;
			var savingsAmount;
			var savingsPercentRounded;
			var billingText = '';

			if (rs && rs.enabled && rs.override_parent && rs.price !== undefined && rs.price !== null && rs.price !== '') {
				subscriptionPrice = parseFloat(rs.price);
				if (isNaN(subscriptionPrice)) {
					subscriptionPrice = regularPrice;
				}

				subscriptionPrice = Math.max(0, subscriptionPrice);
				savingsAmount = Math.max(0, regularPrice - subscriptionPrice);
				savingsPercentRounded = regularPrice > 0
					? Math.round((savingsAmount / regularPrice) * 100)
					: 0;

				if (typeof rs.billing_text === 'string' && rs.billing_text.trim().length > 0) {
					billingText = $.trim(rs.billing_text);
				}
			} else {
				var discountType = recurioSubscribeSave.discountType || 'percentage';
				var discountValue = parseFloat(recurioSubscribeSave.discountValue) || 0;

				subscriptionPrice = regularPrice;
				savingsAmount = 0;
				savingsPercentRounded = Math.round(discountValue);

				if (discountValue > 0) {
					if (discountType === 'percentage') {
						savingsAmount = regularPrice * (discountValue / 100);
					} else {
						savingsAmount = discountValue;
					}
					subscriptionPrice = Math.max(0, regularPrice - savingsAmount);
					if (discountType !== 'percentage' && regularPrice > 0) {
						savingsPercentRounded = Math.round((savingsAmount / regularPrice) * 100);
					}
				}
			}

			this.updateOptionPrices(regularPrice, subscriptionPrice, savingsAmount, savingsPercentRounded, billingText);
		},

		updateOptionPrices: function(regularPrice, subscriptionPrice, savingsAmount, savingsPercentRounded, billingText) {
			var $options = $('.recurio-purchase-options');

			if (!$options.length) {
				return;
			}

			var formattedRegular = this.formatPrice(regularPrice);
			var formattedSubscription = this.formatPrice(subscriptionPrice);
			var formattedSavings = this.formatPrice(savingsAmount);

			var showSav = this.showSavings();

			// Preserve existing billing suffix if none passed (simple products server-rendered markup)
			var billingEscaped = billingText !== undefined && billingText !== null && billingText !== ''
				? $('<div/>').text(billingText).html()
				: '';
			var $subscriptionPriceBefore = $options.find('.recurio-option-subscription .recurio-option-price');
			if (!billingEscaped && $subscriptionPriceBefore.length) {
				billingEscaped = $('<div/>').text($subscriptionPriceBefore.find('.recurio-billing-period').first().text() || '').html();
			}

			// Update one-time option
			$options.find('.recurio-option:not(.recurio-option-subscription) .recurio-option-price')
				.html(formattedRegular);

			var $subscriptionPrice = $options.find('.recurio-option-subscription .recurio-option-price');

			if (showSav && savingsAmount > 0) {
				$subscriptionPrice.html(
					'<del>' + formattedRegular + '</del> ' +
					formattedSubscription +
					'<span class="recurio-billing-period">' + billingEscaped + '</span>'
				);
				var savingsText = (recurioSubscribeSave.i18n.savePrefix || 'Save') + ' ' + formattedSavings;
				$options.find('.recurio-savings').show().html(savingsText);

				var $badge = $options.find('.recurio-save-badge');
				if ($badge.length) {
					$badge.show().text(
						(recurioSubscribeSave.i18n.savePrefix || 'Save') + ' ' +
						Math.round(savingsPercentRounded) + '%'
					);
				}
			} else {
				$subscriptionPrice.html(
					formattedSubscription +
					'<span class="recurio-billing-period">' + billingEscaped + '</span>'
				);
				$options.find('.recurio-savings').hide().empty();
				$options.find('.recurio-save-badge').hide().empty();
			}
		},

		formatPrice: function(price) {
			// Basic price formatting — matches prior behaviour
			var formatted = parseFloat(price).toFixed(2);

			// Get currency symbol from the page if available
			var currencySymbol = '$';
			var $existingPrice = $('.woocommerce-Price-currencySymbol').first();
			if ($existingPrice.length) {
				currencySymbol = $existingPrice.text();
			}

			return '<span class="woocommerce-Price-amount amount">' +
				'<bdi><span class="woocommerce-Price-currencySymbol">' + currencySymbol + '</span>' +
				formatted + '</bdi></span>';
		},

		resetVariationData: function() {
			// Optionally extend: restore placeholder prices when variation cleared
		}
	};

	// Initialize on DOM ready
	$(document).ready(function() {
		RecurioSubscribeSave.init();
	});

})(jQuery);
