/**
 * Recurio Subscribe & Save Frontend JavaScript
 *
 * @package Recurio
 * @since 1.2.0
 */

(function($) {
	'use strict';

	var RecurioSubscribeSave = {
		init: function() {
			this.bindEvents();
			this.updateButtonText();
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

		handleVariationChange: function(variation) {
			var self = this;

			// Update prices in the Subscribe & Save options when variation changes
			if (variation && variation.display_price) {
				var regularPrice = parseFloat(variation.display_regular_price || variation.display_price);
				var discountType = recurioSubscribeSave.discountType || 'percentage';
				var discountValue = parseFloat(recurioSubscribeSave.discountValue) || 0;

				// Calculate subscription price
				var subscriptionPrice = regularPrice;
				var savingsAmount = 0;

				if (discountValue > 0) {
					if (discountType === 'percentage') {
						savingsAmount = regularPrice * (discountValue / 100);
					} else {
						savingsAmount = discountValue;
					}
					subscriptionPrice = Math.max(0, regularPrice - savingsAmount);
				}

				// Update the option prices
				self.updateOptionPrices(regularPrice, subscriptionPrice, savingsAmount, discountValue);
			}
		},

		updateOptionPrices: function(regularPrice, subscriptionPrice, savingsAmount, discountPercent) {
			var $options = $('.recurio-purchase-options');

			if (!$options.length) {
				return;
			}

			// Format prices using WooCommerce format (basic formatting)
			var formattedRegular = this.formatPrice(regularPrice);
			var formattedSubscription = this.formatPrice(subscriptionPrice);
			var formattedSavings = this.formatPrice(savingsAmount);

			// Update one-time option
			$options.find('.recurio-option:not(.recurio-option-subscription) .recurio-option-price')
				.html(formattedRegular);

			// Update subscription option
			var $subscriptionPrice = $options.find('.recurio-option-subscription .recurio-option-price');
			var billingPeriod = $subscriptionPrice.find('.recurio-billing-period').text();

			if (savingsAmount > 0) {
				$subscriptionPrice.html(
					'<del>' + formattedRegular + '</del> ' +
					formattedSubscription +
					'<span class="recurio-billing-period">' + billingPeriod + '</span>'
				);
			} else {
				$subscriptionPrice.html(
					formattedSubscription +
					'<span class="recurio-billing-period">' + billingPeriod + '</span>'
				);
			}

			// Update savings display
			if (savingsAmount > 0) {
				var savingsText = (recurioSubscribeSave.i18n.savePrefix || 'You save') + ' ' + formattedSavings;
				$options.find('.recurio-savings').html(savingsText);
				$options.find('.recurio-save-badge').text(recurioSubscribeSave.i18n.savePrefix + ' ' + Math.round(discountPercent) + '%');
			}
		},

		formatPrice: function(price) {
			// Basic price formatting - could be enhanced with WooCommerce settings
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
			// Reset to default state when variation is cleared
		}
	};

	// Initialize on DOM ready
	$(document).ready(function() {
		RecurioSubscribeSave.init();
	});

})(jQuery);
