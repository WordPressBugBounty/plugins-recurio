/**
 * Recurio Admin Product Scripts
 * JavaScript for WooCommerce product admin interface
 */
jQuery(function($) {
    // Ensure panel is only visible when its tab is active
    $('#recurio_subscription_data').addClass('hidden');

    // Handle tab switching
    $('.product_data_tabs .recurio_subscription_tab').click(function() {
        $('#recurio_subscription_data').removeClass('hidden');
    });

    // Hide when other tabs are clicked
    $('.product_data_tabs li:not(.recurio_subscription_tab) a').click(function() {
        $('#recurio_subscription_data').addClass('hidden');
    });

    // Show/hide subscription options based on enabled checkbox
    $('#_recurio_subscription_enabled').change(function() {
        if ($(this).is(':checked')) {
            $('.subscription_options').removeClass('recurio-hidden');
        } else {
            $('.subscription_options').addClass('recurio-hidden');
        }
    }).trigger('change');

    // ===== Subscribe & Save Toggle =====
    // Show/hide discount options based on one-time purchase checkbox
    $('#_recurio_allow_one_time_purchase').change(function() {
        if ($(this).is(':checked')) {
            $('.recurio-subscribe-save-options').removeClass('recurio-hidden');
        } else {
            $('.recurio-subscribe-save-options').addClass('recurio-hidden');
        }
    }).trigger('change');

    // ===== Custom Billing Period Toggle =====
    // Toggle between standard and custom period options
    $('#_recurio_use_custom_period').change(function() {
        if ($(this).is(':checked')) {
            $('.recurio-standard-period').addClass('recurio-hidden');
            $('.recurio-custom-period').removeClass('recurio-hidden');
        } else {
            $('.recurio-standard-period').removeClass('recurio-hidden');
            $('.recurio-custom-period').addClass('recurio-hidden');
        }
    }).trigger('change');

    // ===== Split Payments Toggle =====
    // Toggle split payment options based on payment type selection
    $('input[name="_recurio_payment_type"]').change(function() {
        if ($(this).val() === 'split') {
            $('.recurio-split-payment-options').removeClass('recurio-hidden');
            // Enable the max payments field when split is selected
            $('#_recurio_max_payments').prop('disabled', false);
        } else {
            $('.recurio-split-payment-options').addClass('recurio-hidden');
            // Disable the max payments field to prevent validation errors
            $('#_recurio_max_payments').prop('disabled', true);
        }
    });
    // Trigger on page load
    $('input[name="_recurio_payment_type"]:checked').trigger('change');

    // ===== Custom Access Duration Toggle =====
    // Toggle custom duration options based on access timing selection
    $('#_recurio_access_timing').change(function() {
        if ($(this).val() === 'custom_duration') {
            $('.recurio-custom-duration-options').removeClass('recurio-hidden');
        } else {
            $('.recurio-custom-duration-options').addClass('recurio-hidden');
        }
    }).trigger('change');

    // WooCommerce's built-in tab handling should also work
    $(document).on('woocommerce_variations_loaded', function() {
        $('#recurio_subscription_data').addClass('hidden');
    });

    // Handle clicks on disabled PRO-only billing periods
    $('.recurio-period-disabled input[type="radio"]').on('click', function(e) {
        e.preventDefault();

        // Get upgrade URL from localized data
        var upgradeUrl = typeof recurioProductData !== 'undefined' ? recurioProductData.proUpgradeUrl : '';

        // Show alert with upgrade message
        var periodLabel = $(this).closest('label').text().trim().replace('PRO', '').trim();
        var message = periodLabel + ' billing period is a PRO feature.\n\n' +
                     'Upgrade to Recurio Pro to unlock Daily, Weekly, and Quarterly billing periods.\n\n' +
                     'Click OK to visit the upgrade page.';

        if (confirm(message)) {
            if (upgradeUrl) {
                window.open(upgradeUrl, '_blank');
            }
        }

        return false;
    });

    // Also prevent label clicks
    $('.recurio-period-disabled').on('click', function(e) {
        var input = $(this).find('input[type="radio"]');
        if (input.length) {
            input.trigger('click');
            e.preventDefault();
            return false;
        }
    });
});