jQuery(document).ready(function($) {
    'use strict';
    
    // Pause subscription - show modal
    $(document).on('click', '.recurio-pause-subscription', function(e) {
        e.preventDefault();
        
        var subscriptionId = $(this).data('subscription-id');
        $('#recurio-pause-modal').data('subscription-id', subscriptionId).fadeIn().removeClass('is-hidden');
    });
    
    // Confirm pause subscription
    $(document).on('click', '.recurio-confirm-pause', function(e) {
        e.preventDefault();
        
        var modal = $('#recurio-pause-modal');
        var subscriptionId = modal.data('subscription-id');
        var button = $(this);
        
        button.prop('disabled', true).text(recurio_portal.strings.processing);
        
        $.ajax({
            url: recurio_portal.ajax_url,
            type: 'POST',
            data: {
                action: 'recurio_pause_subscription',
                subscription_id: subscriptionId,
                nonce: recurio_portal.nonce
            },
            success: function(response) {
                if (response.success) {
                    location.reload();
                } else {
                    alert(response.data || recurio_portal.strings.error);
                    button.prop('disabled', false).text('Pause Subscription');
                }
            },
            error: function() {
                alert(recurio_portal.strings.error);
                button.prop('disabled', false).text('Pause Subscription');
            }
        });
    });
    
    // Resume subscription - show modal
    $(document).on('click', '.recurio-resume-subscription', function(e) {
        e.preventDefault();
        
        var subscriptionId = $(this).data('subscription-id');
        $('#recurio-resume-modal').data('subscription-id', subscriptionId).fadeIn().removeClass('is-hidden');
    });
    
    // Confirm resume subscription
    $(document).on('click', '.recurio-confirm-resume', function(e) {
        e.preventDefault();
        
        var modal = $('#recurio-resume-modal');
        var subscriptionId = modal.data('subscription-id');
        var button = $(this);
        
        button.prop('disabled', true).text(recurio_portal.strings.processing);
        
        $.ajax({
            url: recurio_portal.ajax_url,
            type: 'POST',
            data: {
                action: 'recurio_resume_subscription',
                subscription_id: subscriptionId,
                nonce: recurio_portal.nonce
            },
            success: function(response) {
                if (response.success) {
                    location.reload();
                } else {
                    alert(response.data || recurio_portal.strings.error);
                    button.prop('disabled', false).text('Resume Subscription');
                }
            },
            error: function() {
                alert(recurio_portal.strings.error);
                button.prop('disabled', false).text('Resume Subscription');
            }
        });
    });
    
    // Cancel subscription - show modal
    $(document).on('click', '.recurio-cancel-subscription', function(e) {
        e.preventDefault();
        
        var subscriptionId = $(this).data('subscription-id');
        $('#recurio-cancel-modal').data('subscription-id', subscriptionId).fadeIn().removeClass('is-hidden');
    });
    
    // Confirm cancel subscription
    $(document).on('click', '.recurio-confirm-cancel', function(e) {
        e.preventDefault();
        
        var modal = $('#recurio-cancel-modal');
        var subscriptionId = modal.data('subscription-id');
        var cancelAt = $('input[name="cancel_at"]:checked').val();
        var button = $(this);
        
        button.prop('disabled', true).text(recurio_portal.strings.processing);
        
        $.ajax({
            url: recurio_portal.ajax_url,
            type: 'POST',
            data: {
                action: 'recurio_cancel_subscription',
                subscription_id: subscriptionId,
                cancel_at: cancelAt,
                nonce: recurio_portal.nonce
            },
            success: function(response) {
                if (response.success) {
                    location.reload();
                } else {
                    alert(response.data || recurio_portal.strings.error);
                    button.prop('disabled', false).text('Confirm Cancellation');
                }
            },
            error: function() {
                alert(recurio_portal.strings.error);
                button.prop('disabled', false).text('Confirm Cancellation');
            }
        });
    });
    
    // Reactivate subscription
    $(document).on('click', '.recurio-reactivate-subscription', function(e) {
        e.preventDefault();

        var button = $(this);
        var subscriptionId = button.data('subscription-id');

        button.prop('disabled', true).text(recurio_portal.strings.processing);

        $.ajax({
            url: recurio_portal.ajax_url,
            type: 'POST',
            data: {
                action: 'recurio_resume_subscription',
                subscription_id: subscriptionId,
                nonce: recurio_portal.nonce
            },
            success: function(response) {
                if (response.success) {
                    location.reload();
                } else {
                    alert(response.data || recurio_portal.strings.error);
                    button.prop('disabled', false).text('Reactivate Subscription');
                }
            },
            error: function() {
                alert(recurio_portal.strings.error);
                button.prop('disabled', false).text('Reactivate Subscription');
            }
        });
    });

    // Early renewal - show modal
    $(document).on('click', '.recurio-early-renewal', function(e) {
        e.preventDefault();

        var subscriptionId = $(this).data('subscription-id');
        $('#recurio-early-renewal-modal').data('subscription-id', subscriptionId).fadeIn().removeClass('is-hidden');
    });

    // Confirm early renewal
    $(document).on('click', '.recurio-confirm-early-renewal', function(e) {
        e.preventDefault();

        var modal = $('#recurio-early-renewal-modal');
        var subscriptionId = modal.data('subscription-id');
        var button = $(this);

        button.prop('disabled', true).text(recurio_portal.strings.processing);

        $.ajax({
            url: recurio_portal.ajax_url,
            type: 'POST',
            data: {
                action: 'recurio_early_renewal',
                subscription_id: subscriptionId,
                nonce: recurio_portal.nonce
            },
            success: function(response) {
                if (response.success) {
                    // Redirect to payment page
                    if (response.data.order_url) {
                        window.location.href = response.data.order_url;
                    } else {
                        location.reload();
                    }
                } else {
                    alert(response.data || recurio_portal.strings.error);
                    button.prop('disabled', false).text('Renew Now');
                }
            },
            error: function() {
                alert(recurio_portal.strings.error);
                button.prop('disabled', false).text('Renew Now');
            }
        });
    });

    // Pay Installment - for split payment subscriptions
    $(document).on('click', '.recurio-pay-installment', function(e) {
        e.preventDefault();

        var button = $(this);
        var subscriptionId = button.data('subscription-id');
        var originalText = button.text();

        button.prop('disabled', true).text(recurio_portal.strings.processing);

        $.ajax({
            url: recurio_portal.ajax_url,
            type: 'POST',
            data: {
                action: 'recurio_pay_installment',
                subscription_id: subscriptionId,
                nonce: recurio_portal.nonce
            },
            success: function(response) {
                if (response.success) {
                    // Redirect to payment page
                    if (response.data.payment_url) {
                        window.location.href = response.data.payment_url;
                    } else {
                        location.reload();
                    }
                } else {
                    alert(response.data || recurio_portal.strings.error);
                    button.prop('disabled', false).text(originalText);
                }
            },
            error: function() {
                alert(recurio_portal.strings.error);
                button.prop('disabled', false).text(originalText);
            }
        });
    });

    // Edit address - show modal
    $(document).on('click', '.recurio-edit-address', function(e) {
        e.preventDefault();
        
        var type = $(this).data('type');
        var currentAddress = $(this).siblings('.recurio-address-content').text().trim();
        
        $('#recurio-address-modal').find('.recurio-address-input').val(currentAddress);
        $('#recurio-address-modal').find('input[name="address_type"]').val(type);
        $('#recurio-address-modal').find('h3').text('Edit ' + type.charAt(0).toUpperCase() + type.slice(1) + ' Address');
        $('#recurio-address-modal').fadeIn().removeClass('is-hidden');
    });
    
    // Save address
    $(document).on('click', '.recurio-save-address', function(e) {
        e.preventDefault();
        
        var button = $(this);
        var form = $('#recurio-address-form');
        var subscriptionId = form.find('input[name="subscription_id"]').val();
        var addressType = form.find('input[name="address_type"]').val();
        var address = form.find('textarea[name="address"]').val();
        
        button.prop('disabled', true).text(recurio_portal.strings.processing);
        
        var data = {
            action: 'recurio_update_subscription',
            subscription_id: subscriptionId,
            nonce: recurio_portal.nonce
        };
        
        if (addressType === 'billing') {
            data.billing_address = address;
        } else {
            data.shipping_address = address;
        }
        
        $.ajax({
            url: recurio_portal.ajax_url,
            type: 'POST',
            data: data,
            success: function(response) {
                if (response.success) {
                    location.reload();
                } else {
                    alert(response.data || recurio_portal.strings.error);
                    button.prop('disabled', false).text('Save Address');
                }
            },
            error: function() {
                alert(recurio_portal.strings.error);
                button.prop('disabled', false).text('Save Address');
            }
        });
    });
    
    // Update payment method
    $(document).on('click', '.recurio-update-payment', function(e) {
        e.preventDefault();
        
        var subscriptionId = $(this).data('subscription-id');
        var button = $(this);
        
        button.prop('disabled', true).text('Loading...');
        
        // Get available payment methods
        $.ajax({
            url: recurio_portal.ajax_url,
            type: 'POST',
            data: {
                action: 'recurio_update_payment_method',
                subscription_id: subscriptionId,
                action_type: 'get_methods',
                nonce: recurio_portal.nonce
            },
            success: function(response) {
                button.prop('disabled', false).text('Update Payment Method');
                
                if (response.success) {
                    var data = response.data;
                    
                    // Check if there are saved payment methods
                    if (data.saved_methods && data.saved_methods.length > 0) {
                        // Show payment method selection modal
                        showPaymentMethodModal(subscriptionId, data);
                    } else if (data.available_gateways && Object.keys(data.available_gateways).length > 0) {
                        // No saved methods, redirect to add payment method
                        if (confirm('You need to add a payment method first. Would you like to add one now?')) {
                            window.location.href = data.add_payment_url + '?subscription_id=' + subscriptionId;
                        }
                    } else {
                        alert('No payment gateways are configured for subscriptions. Please contact the store administrator.');
                    }
                } else {
                    alert(response.data || recurio_portal.strings.error);
                }
            },
            error: function() {
                button.prop('disabled', false).text('Update Payment Method');
                alert(recurio_portal.strings.error);
            }
        });
    });
    
    // Function to show payment method selection modal
    function showPaymentMethodModal(subscriptionId, data) {
        // Create modal HTML if it doesn't exist
        if ($('#recurio-payment-method-modal').length === 0) {
            var modalHtml = '<div id="recurio-payment-method-modal" class="recurio-modal is-hidden">' +
                '<div class="recurio-modal-content" style="position: relative;">' +
                '<button class="recurio-modal-close" style="position: absolute; top: 10px; right: 10px; background: none; border: none; font-size: 24px; cursor: pointer; color: #666; padding: 0; width: 30px; height: 30px; line-height: 30px;">&times;</button>' +
                '<h3>Update Payment Method</h3>' +
                '<div class="recurio-payment-methods-list"></div>' +
                '<div class="recurio-modal-actions">' +
                '<a href="' + data.add_payment_url + '" class="button">Add New Payment Method</a>' +
                '<button class="button recurio-modal-keep recurio-modal-close">Cancel</button>' +
                '<button class="button button-primary recurio-confirm-payment-update" disabled>Update Payment Method</button>' +
                '</div>' +
                '</div>' +
                '</div>';
            $('body').append(modalHtml);
        }
        
        var modal = $('#recurio-payment-method-modal');
        var methodsList = modal.find('.recurio-payment-methods-list');
        var updateButton = modal.find('.recurio-confirm-payment-update');
        
        // Clear and populate payment methods
        methodsList.empty();
        
        if (data.saved_methods && data.saved_methods.length > 0) {
            methodsList.append('<p>Select a payment method for this subscription:</p>');
            
            data.saved_methods.forEach(function(method) {
                var methodHtml = '<label class="recurio-payment-method-option" style="display: block; margin: 10px 0; padding: 10px; border: 1px solid #ddd; border-radius: 4px; cursor: pointer;">' +
                    '<input type="radio" name="payment_method" value="' + method.id + '" ' + 
                    (method.is_default ? 'checked' : '') + '> ';
                
                if (method.card_type) {
                    methodHtml += '<strong>' + method.gateway_title + '</strong> - ' +
                        method.card_type + ' ending in ' + method.last4;
                    if (method.expiry) {
                        methodHtml += ' (Expires: ' + method.expiry + ')';
                    }
                } else {
                    methodHtml += '<strong>' + method.gateway_title + '</strong>';
                }
                
                if (method.is_default) {
                    methodHtml += ' <span style="color: #28a745;">(Default)</span>';
                }
                
                methodHtml += '</label>';
                methodsList.append(methodHtml);
            });
            
            // Enable update button when a method is selected
            methodsList.find('input[type="radio"]').on('change', function() {
                updateButton.prop('disabled', false);
            });
            
            // Handle update button click
            updateButton.off('click').on('click', function() {
                var selectedTokenId = methodsList.find('input[type="radio"]:checked').val();
                
                if (!selectedTokenId) {
                    alert('Please select a payment method');
                    return;
                }
                
                updateButton.prop('disabled', true).text(recurio_portal.strings.processing);
                
                $.ajax({
                    url: recurio_portal.ajax_url,
                    type: 'POST',
                    data: {
                        action: 'recurio_update_payment_method',
                        subscription_id: subscriptionId,
                        action_type: 'update_method',
                        token_id: selectedTokenId,
                        nonce: recurio_portal.nonce
                    },
                    success: function(response) {
                        if (response.success) {
                            location.reload();
                        } else {
                            alert(response.data || recurio_portal.strings.error);
                            updateButton.prop('disabled', false).text('Update Payment Method');
                        }
                    },
                    error: function() {
                        alert(recurio_portal.strings.error);
                        updateButton.prop('disabled', false).text('Update Payment Method');
                    }
                });
            });
        } else {
            methodsList.append('<p>No saved payment methods found. Please add a payment method first.</p>');
            updateButton.hide();
        }
        
        // Store subscription ID and show modal
        modal.data('subscription-id', subscriptionId).fadeIn().removeClass('is-hidden');
    }
    
    // Close modal
    $(document).on('click', '.recurio-modal-close', function(e) {
        e.preventDefault();
        let $that = $(this);
        $that.closest('.recurio-modal').fadeOut();
        setTimeout(function() {
            $that.closest('.recurio-modal').addClass('is-hidden');
        }, 400);
    });
    
    // Close modal on background click
    $(document).on('click', '.recurio-modal', function(e) {
        if (e.target === this) {
            let $that = $(this);
            $that.fadeOut(400, "swing");
            setTimeout(function() {
                $that.addClass('is-hidden');
            }, 400);
        }
    });
    
    // Close modal on ESC key
    $(document).keydown(function(e) {
        if (e.keyCode === 27) {
            $('.recurio-modal').fadeOut(400, "swing");
            setTimeout(function() {
                $('.recurio-modal').addClass('is-hidden');
            }, 400);
        }
    });

    // ===== Switch Plan Functionality =====
    var selectedSwitchProduct = null;
    var switchSubscriptionId = null;

    // Switch Plan - show modal and load plans
    $(document).on('click', '.recurio-switch-plan', function(e) {
        e.preventDefault();

        var button = $(this);
        switchSubscriptionId = button.data('subscription-id');
        var modal = $('#recurio-switch-plan-modal');

        // Reset modal state
        modal.find('.recurio-switch-plans-loading').removeClass('is-hidden');
        modal.find('.recurio-switch-plans-list').addClass('is-hidden');
        modal.find('.recurio-switch-proration').addClass('is-hidden');
        modal.find('.recurio-confirm-switch').prop('disabled', true);
        modal.find('.recurio-plans-container').empty();
        selectedSwitchProduct = null;

        // Show modal
        modal.data('subscription-id', switchSubscriptionId).fadeIn().removeClass('is-hidden');

        // Load available plans
        $.ajax({
            url: recurio_portal.ajax_url,
            type: 'POST',
            data: {
                action: 'recurio_get_switchable_products',
                subscription_id: switchSubscriptionId,
                nonce: recurio_portal.nonce
            },
            success: function(response) {
                modal.find('.recurio-switch-plans-loading').addClass('is-hidden');

                if (response.success && response.data.products.length > 0) {
                    var plansHtml = '';
                    var currencySymbol = recurio_portal.currency_symbol || '$';

                    response.data.products.forEach(function(product) {
                        var badgeClass = product.switch_type === 'upgrade' ? 'recurio-badge-success' :
                                        (product.switch_type === 'downgrade' ? 'recurio-badge-warning' : 'recurio-badge-info');
                        var badgeText = product.switch_type.charAt(0).toUpperCase() + product.switch_type.slice(1);

                        plansHtml += '<div class="recurio-plan-option" data-product-id="' + product.product_id + '">' +
                            '<div class="recurio-plan-header">' +
                                '<span class="recurio-plan-name">' + product.product_name + '</span>' +
                                '<span class="recurio-badge ' + badgeClass + '">' + badgeText + '</span>' +
                            '</div>' +
                            '<div class="recurio-plan-price">' + currencySymbol + parseFloat(product.product_price).toFixed(2) + ' / ' + product.billing_period + '</div>' +
                            '<div class="recurio-plan-amount-due">' +
                                (product.amount_due > 0 ?
                                    'Pay today: ' + currencySymbol + parseFloat(product.amount_due).toFixed(2) :
                                    'No payment required') +
                            '</div>' +
                        '</div>';
                    });

                    modal.find('.recurio-plans-container').html(plansHtml);
                    modal.find('.recurio-switch-plans-list').removeClass('is-hidden');
                } else {
                    modal.find('.recurio-plans-container').html('<p>No other plans available for switching.</p>');
                    modal.find('.recurio-switch-plans-list').removeClass('is-hidden');
                }
            },
            error: function() {
                modal.find('.recurio-switch-plans-loading').addClass('is-hidden');
                modal.find('.recurio-plans-container').html('<p>Error loading plans. Please try again.</p>');
                modal.find('.recurio-switch-plans-list').removeClass('is-hidden');
            }
        });
    });

    // Select a plan
    $(document).on('click', '.recurio-plan-option', function() {
        var $this = $(this);
        var productId = $this.data('product-id');
        var modal = $('#recurio-switch-plan-modal');

        // Highlight selected plan
        $('.recurio-plan-option').removeClass('selected');
        $this.addClass('selected');

        selectedSwitchProduct = productId;

        // Load proration details
        modal.find('.recurio-switch-proration').addClass('is-hidden');

        $.ajax({
            url: recurio_portal.ajax_url,
            type: 'POST',
            data: {
                action: 'recurio_calculate_switch_price',
                subscription_id: switchSubscriptionId,
                product_id: productId,
                nonce: recurio_portal.nonce
            },
            success: function(response) {
                if (response.success) {
                    var data = response.data;
                    var currencySymbol = recurio_portal.currency_symbol || '$';

                    modal.find('.recurio-new-price').text(currencySymbol + parseFloat(data.new_price).toFixed(2));
                    modal.find('.recurio-credit').text('-' + currencySymbol + parseFloat(data.credit).toFixed(2));
                    modal.find('.recurio-amount-due strong').text(currencySymbol + parseFloat(data.amount_due).toFixed(2));
                    modal.find('.recurio-switch-proration').removeClass('is-hidden');
                    modal.find('.recurio-confirm-switch').prop('disabled', false);
                }
            }
        });
    });

    // Confirm switch
    $(document).on('click', '.recurio-confirm-switch', function(e) {
        e.preventDefault();

        if (!selectedSwitchProduct) {
            alert('Please select a plan first.');
            return;
        }

        var button = $(this);
        var originalText = button.text();

        button.prop('disabled', true).text(recurio_portal.strings.processing || 'Processing...');

        $.ajax({
            url: recurio_portal.ajax_url,
            type: 'POST',
            data: {
                action: 'recurio_process_switch',
                subscription_id: switchSubscriptionId,
                product_id: selectedSwitchProduct,
                nonce: recurio_portal.nonce
            },
            success: function(response) {
                if (response.success) {
                    // If there's a checkout URL (payment required), redirect to it
                    if (response.data.checkout_url) {
                        window.location.href = response.data.checkout_url;
                    } else {
                        // No payment needed, just reload
                        location.reload();
                    }
                } else {
                    alert(response.data || 'Error switching plan. Please try again.');
                    button.prop('disabled', false).text(originalText);
                }
            },
            error: function() {
                alert('Error switching plan. Please try again.');
                button.prop('disabled', false).text(originalText);
            }
        });
    });
});