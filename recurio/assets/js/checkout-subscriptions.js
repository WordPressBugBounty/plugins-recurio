(function($) {
    'use strict';

    // Check if cart has subscription products
    function hasSubscriptionProducts() {
        // First check if PHP has already determined we have subscriptions
        // This is the most reliable method, especially for free trials
        if (typeof recurio_checkout_params !== 'undefined' && recurio_checkout_params.has_subscription) {
            return true;
        }

        // Fallback: Check for subscription indicator in the page DOM
        return $('.cart_item').find('.subscription-details').length > 0 ||
               $('[data-subscription="yes"]').length > 0 ||
               $('[data-recurio-subscription="yes"]').length > 0 ||
               $('.order-total .subscription-price').length > 0 ||
               $('.woocommerce-checkout').find('.subscription-details').length > 0;
    }

    // Filter payment gateways on checkout
    function filterPaymentGateways() {
        if (!hasSubscriptionProducts()) {
            return;
        }

        // Get allowed payment methods from localized data
        var allowedMethods = recurio_checkout_params.allowed_payment_methods || {};
        var offlineMethods = ['cod', 'bacs', 'cheque'];
        

        // Hide payment methods based on settings
        $('.wc_payment_method').each(function() {
            var $method = $(this);
            var methodId = $method.find('input[type="radio"]').val();
            
            if (!methodId) return;
            
            // Check if method is explicitly disabled in settings
            if (allowedMethods.hasOwnProperty(methodId)) {
                if (!allowedMethods[methodId] || allowedMethods[methodId] === 'false' || allowedMethods[methodId] === '0') {
                    $method.hide();
                }
            } else {
                // If no settings, hide offline methods by default
                if (offlineMethods.indexOf(methodId) !== -1) {
                    $method.hide();
                }
            }
        });
        
        // Select first visible payment method if none selected
        var $visibleMethods = $('.wc_payment_method:visible');
        if ($visibleMethods.length > 0 && !$('.wc_payment_method input:checked:visible').length) {
            $visibleMethods.first().find('input[type="radio"]').prop('checked', true).trigger('change');
        }
        
        // Show error if no payment methods available
        if ($visibleMethods.length === 0) {
            if (!$('#recurio-no-payment-methods-notice').length) {
                $('.woocommerce-checkout-payment').prepend(
                    '<div id="recurio-no-payment-methods-notice" class="woocommerce-error">' +
                    recurio_checkout_params.no_payment_methods_message +
                    '</div>'
                );
            }
        } else {
            $('#recurio-no-payment-methods-notice').remove();
        }
    }

    // Initialize on document ready
    $(document).ready(function() {
        filterPaymentGateways();
    });

    // Re-filter on checkout update
    // $(document.body).on('updated_checkout', function() {
    //     filterPaymentGateways();
    // });

    // Re-filter on payment method update
    // $(document.body).on('payment_method_selected', function() {
    //     setTimeout(filterPaymentGateways, 100);
    // });

})(jQuery);