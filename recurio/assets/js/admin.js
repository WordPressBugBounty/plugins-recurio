/**
 * Recurio - Admin JavaScript
 * @package Recurio
 * @since 1.0.0
 */

(function($) {
    'use strict';

    // Recurio Admin object
    window.RecurioAdmin = {
        
        // Initialize
        init: function() {
            this.bindEvents();
            this.initTooltips();
            this.initDatePickers();
        },
        
        // Bind events
        bindEvents: function() {
            // Bulk actions
            $(document).on('click', '.recurio-bulk-action', this.handleBulkAction);
            
            // Quick actions
            $(document).on('click', '.recurio-quick-action', this.handleQuickAction);
            
            // Settings form
            $(document).on('submit', '#recurio-settings-form', this.handleSettingsSubmit);
            
            // Export buttons
            $(document).on('click', '.recurio-export-btn', this.handleExport);
            
            // Import buttons
            $(document).on('click', '.recurio-import-btn', this.handleImport);
        },
        
        // Initialize tooltips
        initTooltips: function() {
            if ($.fn.tooltip) {
                $('.recurio-tooltip').tooltip();
            }
        },
        
        // Initialize date pickers
        initDatePickers: function() {
            if ($.fn.datepicker) {
                $('.recurio-datepicker').datepicker({
                    dateFormat: 'yy-mm-dd',
                    changeMonth: true,
                    changeYear: true
                });
            }
        },
        
        // Handle bulk action
        handleBulkAction: function(e) {
            e.preventDefault();
            
            var action = $('#bulk-action-selector').val();
            var selected = [];
            
            $('.recurio-subscription-checkbox:checked').each(function() {
                selected.push($(this).val());
            });
            
            if (selected.length === 0) {
                alert(recurioAdmin.i18n.noItemsSelected || 'No items selected');
                return;
            }
            
            if (!confirm(recurioAdmin.i18n.confirmBulkAction || 'Are you sure?')) {
                return;
            }
            
            RecurioAdmin.processBulkAction(action, selected);
        },
        
        // Process bulk action
        processBulkAction: function(action, ids) {
            var data = {
                action: 'recurio_bulk_action',
                nonce: recurioAdmin.nonce,
                bulk_action: action,
                ids: ids
            };
            
            $.post(recurioAdmin.ajaxUrl, data, function(response) {
                if (response.success) {
                    RecurioAdmin.showNotice('success', response.data.message);
                    location.reload();
                } else {
                    RecurioAdmin.showNotice('error', response.data.message);
                }
            });
        },
        
        // Handle quick action
        handleQuickAction: function(e) {
            e.preventDefault();
            
            var $button = $(this);
            var action = $button.data('action');
            var subscriptionId = $button.data('subscription-id');
            
            if (!confirm(recurioAdmin.i18n['confirm' + action.charAt(0).toUpperCase() + action.slice(1)] || 'Are you sure?')) {
                return;
            }
            
            RecurioAdmin.processQuickAction(action, subscriptionId, $button);
        },
        
        // Process quick action
        processQuickAction: function(action, subscriptionId, $button) {
            $button.prop('disabled', true);
            
            var data = {
                action: 'recurio_' + action + '_subscription',
                nonce: recurioAdmin.nonce,
                subscription_id: subscriptionId
            };
            
            // Add reason for cancellation
            if (action === 'cancel') {
                var reason = prompt(recurioAdmin.i18n.cancellationReason || 'Please provide a reason for cancellation:');
                if (reason === null) {
                    $button.prop('disabled', false);
                    return;
                }
                data.reason = reason;
            }
            
            $.post(recurioAdmin.ajaxUrl, data, function(response) {
                if (response.success) {
                    RecurioAdmin.showNotice('success', response.data);
                    location.reload();
                } else {
                    RecurioAdmin.showNotice('error', response.data);
                    $button.prop('disabled', false);
                }
            });
        },
        
        // Handle settings submit
        handleSettingsSubmit: function(e) {
            e.preventDefault();
            
            var $form = $(this);
            var data = $form.serialize();
            
            $.post(recurioAdmin.ajaxUrl, data + '&action=recurio_save_settings&nonce=' + recurioAdmin.nonce, function(response) {
                if (response.success) {
                    RecurioAdmin.showNotice('success', recurioAdmin.i18n.settingsSaved || 'Settings saved successfully');
                } else {
                    RecurioAdmin.showNotice('error', response.data.message);
                }
            });
        },
        
        // Handle export
        handleExport: function(e) {
            e.preventDefault();
            
            var exportType = $(this).data('export-type');
            var url = recurioAdmin.ajaxUrl + '?action=recurio_export&type=' + exportType + '&nonce=' + recurioAdmin.nonce;
            
            window.location.href = url;
        },
        
        // Handle import
        handleImport: function(e) {
            e.preventDefault();
            
            var $fileInput = $('#recurio-import-file');
            
            if ($fileInput.get(0).files.length === 0) {
                alert(recurioAdmin.i18n.selectFile || 'Please select a file to import');
                return;
            }
            
            var formData = new FormData();
            formData.append('action', 'recurio_import');
            formData.append('nonce', recurioAdmin.nonce);
            formData.append('import_file', $fileInput.get(0).files[0]);
            
            $.ajax({
                url: recurioAdmin.ajaxUrl,
                type: 'POST',
                data: formData,
                processData: false,
                contentType: false,
                success: function(response) {
                    if (response.success) {
                        RecurioAdmin.showNotice('success', response.data.message);
                        location.reload();
                    } else {
                        RecurioAdmin.showNotice('error', response.data.message);
                    }
                }
            });
        },
        
        // Show notice
        showNotice: function(type, message) {
            var noticeClass = 'notice-' + type;
            var $notice = $('<div class="notice ' + noticeClass + ' is-dismissible"><p>' + message + '</p></div>');
            
            $('.wrap h1').after($notice);
            
            // Auto dismiss after 5 seconds
            setTimeout(function() {
                $notice.fadeOut(function() {
                    $(this).remove();
                });
            }, 5000);
        },
        
        // Load dashboard stats
        loadDashboardStats: function() {
            $.get(recurioAdmin.ajaxUrl, {
                action: 'recurio_get_dashboard_stats',
                nonce: recurioAdmin.nonce
            }, function(response) {
                if (response.success) {
                    RecurioAdmin.updateDashboardStats(response.data);
                }
            });
        },
        
        // Update dashboard stats
        updateDashboardStats: function(stats) {
            $('#recurio-active-subscriptions').text(stats.active);
            $('#recurio-total-subscriptions').text(stats.total);
            $('#recurio-mrr').text(stats.mrr_formatted);
            $('#recurio-arr').text(stats.arr_formatted);
            $('#recurio-churn-rate').text(stats.churn_rate + '%');
            $('#recurio-ltv').text(stats.ltv_formatted);
        },
        
        // Load recent activity
        loadRecentActivity: function() {
            $.get(recurioAdmin.ajaxUrl, {
                action: 'recurio_get_recent_activity',
                nonce: recurioAdmin.nonce
            }, function(response) {
                if (response.success) {
                    RecurioAdmin.displayRecentActivity(response.data);
                }
            });
        },
        
        // Display recent activity
        displayRecentActivity: function(events) {
            var $container = $('#recurio-recent-activity');
            $container.empty();
            
            if (events.length === 0) {
                $container.html('<p>No recent activity</p>');
                return;
            }
            
            var $list = $('<ul class="recurio-activity-list"></ul>');
            
            $.each(events, function(index, event) {
                var $item = $('<li></li>');
                $item.html('<span class="activity-type">' + event.event_type + '</span> ' +
                          '<span class="activity-date">' + event.created_at + '</span>');
                $list.append($item);
            });
            
            $container.append($list);
        }
    };
    
    // Initialize when document is ready
    $(document).ready(function() {
        RecurioAdmin.init();
        
        // Load dashboard stats if on dashboard page
        if ($('#recurio-dashboard-widget').length > 0) {
            RecurioAdmin.loadDashboardStats();
            RecurioAdmin.loadRecentActivity();
        }
    });
    
})(jQuery);