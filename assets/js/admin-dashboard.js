jQuery(document).ready(function($) {

    // 1. Settings Section Sub-Tabs
    $('.tab-sub-btn').on('click', function() {
        var target = $(this).data('target');
        
        $('.tab-sub-btn').removeClass('active');
        $(this).addClass('active');
        
        $('.settings-panel-section').removeClass('active');
        $('#' + target).addClass('active');
    });

    // Toggle API Key field visibility depending on auth_type
    function toggleAuthFields() {
        var authType = $('#field_auth_type').val();
        if (authType === 'apikey') {
            $('#row-api_key').slideDown();
        } else {
            $('#row-api_key').slideUp();
        }
    }
    $('#field_auth_type').on('change', toggleAuthFields);
    toggleAuthFields();

    // 2. Test Connection
    $('#btn-test-connection').on('click', function(e) {
        e.preventDefault();
        var $btn = $(this);
        var $result = $('#connection-test-result');
        
        $btn.prop('disabled', true).addClass('loading');
        $result.slideUp().removeClass('success error');
        
        $.ajax({
            url: wcErpSyncLocal.ajax_url,
            type: 'POST',
            data: {
                action: 'wc_erp_sync_test_connection',
                security: wcErpSyncLocal.nonce
            },
            success: function(response) {
                $btn.prop('disabled', false).removeClass('loading');
                if (response.success) {
                    $result.addClass('success-alert').html('<strong>✓ ' + response.data.message + '</strong>').slideDown();
                    // Add background color for alerts
                    $result.css({
                        'background': 'var(--success-bg)',
                        'color': 'var(--success-color)',
                        'border': '1px solid var(--success-border)',
                        'padding': '12px',
                        'border-radius': '6px',
                        'margin-top': '12px'
                    });
                } else {
                    $result.addClass('error-alert').html('<strong>✗ ' + response.data.message + '</strong>').slideDown();
                    $result.css({
                        'background': 'var(--danger-bg)',
                        'color': 'var(--danger-color)',
                        'border': '1px solid var(--danger-border)',
                        'padding': '12px',
                        'border-radius': '6px',
                        'margin-top': '12px'
                    });
                }
            },
            error: function() {
                $btn.prop('disabled', false).removeClass('loading');
                $result.html('<strong>✗ AJAX Error. Check server logs.</strong>').slideDown();
            }
        });
    });

    // 3. Save Settings Options
    $('#erp-sync-settings-form').on('submit', function(e) {
        e.preventDefault();
        var $spinner = $('#settings-save-spinner');
        var $msg = $('#settings-save-message');
        
        $spinner.show();
        $msg.slideUp();
        
        $.ajax({
            url: wcErpSyncLocal.ajax_url,
            type: 'POST',
            data: $(this).serialize() + '&action=wc_erp_sync_save_settings&security=' + wcErpSyncLocal.nonce,
            success: function(response) {
                $spinner.hide();
                if (response.success) {
                    $msg.html(response.data.message).slideDown();
                    $msg.css({
                        'background': 'var(--success-bg)',
                        'color': 'var(--success-color)',
                        'padding': '12px',
                        'border-radius': '6px',
                        'margin-top': '12px'
                    });
                    setTimeout(function() { $msg.slideUp(); }, 3000);
                } else {
                    $msg.html(response.data.message).slideDown();
                }
            },
            error: function() {
                $spinner.hide();
                $msg.html('AJAX saving error. Please try again.').slideDown();
            }
        });
    });

    // 4. Manual Synchronization Runner
    var syncTimer = null;
    var syncStartSec = 0;
    var syncPoller = null;

    $('#btn-start-manual-sync').on('click', function(e) {
        e.preventDefault();
        if (!confirm(wcErpSyncLocal.strings.confirm)) {
            return;
        }

        var $btn = $(this);
        var $progBox = $('#sync-progress-box');
        var $summary = $('#sync-complete-summary');
        
        $btn.prop('disabled', true);
        $summary.hide();
        $progBox.slideDown();

        // Start Timer Counter
        syncStartSec = 0;
        $('#sync-timer-counter').text('0s');
        if (syncTimer) clearInterval(syncTimer);
        syncTimer = setInterval(function() {
            syncStartSec++;
            $('#sync-timer-counter').text(syncStartSec + 's');
        }, 1000);

        var syncLimit = parseInt($('#select-sync-limit').val()) || 50;

        // Run AJAX kickoff (initializes job & syncs categories/brands in 1-2s)
        $.ajax({
            url: wcErpSyncLocal.ajax_url,
            type: 'POST',
            data: {
                action: 'wc_erp_sync_run_manual_sync',
                limit: syncLimit,
                security: wcErpSyncLocal.nonce
            },
            success: function(response) {
                if (response.success && response.data.history_id) {
                    var historyId = response.data.history_id;
                    // Run batch chunks iteratively
                    runNextBatch(historyId, 1, syncLimit);
                } else {
                    clearInterval(syncTimer);
                    $btn.prop('disabled', false);
                    alert(response.data ? response.data.message : 'Failed to initialize sync.');
                }
            },
            error: function() {
                clearInterval(syncTimer);
                $btn.prop('disabled', false);
                alert('Failed to initialize sync.');
            }
        });
    });

    var manualBatchRetries = 0;
    var maxManualRetries = 2;

    function runNextBatch(historyId, page, syncLimit) {
        $.ajax({
            url: wcErpSyncLocal.ajax_url,
            type: 'POST',
            data: {
                action: 'wc_erp_sync_process_manual_batch',
                history_id: historyId,
                page: page,
                limit: syncLimit,
                batch_size: 5,
                security: wcErpSyncLocal.nonce
            },
            success: function(response) {
                manualBatchRetries = 0; // Reset retry counter on success
                if (response.success && response.data) {
                    var res = response.data;

                    $('#stat-processed').text(res.processed);
                    $('#stat-success').text(res.success);
                    $('#stat-failed').text(res.failed);

                    var targetTotal = (syncLimit > 0) ? syncLimit : 4234;
                    var pct         = Math.min(100, Math.round((res.processed / targetTotal) * 100));

                    $('#sync-progress-bar-inner').css('width', pct + '%');
                    $('#sync-progress-status').text('Syncing records: ' + pct + '% (' + res.processed + '/' + targetTotal + ')');

                    if (!res.completed) {
                        // Process next chunk with 1.5s pacing delay
                        setTimeout(function() {
                            runNextBatch(historyId, res.page, syncLimit);
                        }, 1500);
                    } else {
                        // All chunks completed
                        clearInterval(syncTimer);
                        $('#sync-progress-box').slideUp();
                        $('#btn-start-manual-sync').prop('disabled', false);

                        if (res.next_page) {
                            $('#sync-page-pointer-display').text(res.next_page);
                        }

                        var sumHtml = '';
                        var createdCount = res.created || 0;
                        var updatedCount = res.updated || 0;

                        if (createdCount > 0 && updatedCount > 0) {
                            sumHtml = '🎉 <strong>' + createdCount + ' New Products Created</strong> & ' + updatedCount + ' Existing Products Updated/Verified in WooCommerce.<br>';
                        } else if (createdCount > 0) {
                            sumHtml = '🎉 <strong>' + createdCount + ' New Products Created</strong> in WooCommerce.<br>';
                        } else if (updatedCount > 0) {
                            sumHtml = 'ℹ️ <strong>' + updatedCount + ' Existing Products Verified/Updated</strong> (No new products on this page).<br>';
                        } else {
                            sumHtml = 'Processed: ' + res.processed + ' items. Success: ' + res.success + ', Failed: ' + res.failed + '.<br>';
                        }

                        sumHtml += '<div style="margin-top: 10px; padding: 10px; background: #f1f5f9; border-radius: 6px; font-size: 13px; color: #334155; border-left: 4px solid #3b82f6;">';
                        if (createdCount === 0 && updatedCount > 0) {
                            sumHtml += '💡 <strong>Notice:</strong> All ' + updatedCount + ' products in this batch already existed in your store and were verified. Click <strong>"Run Synchronization Now"</strong> again to fetch the <strong>NEXT batch of new products</strong> (starting from Page ' + (res.next_page || 1) + ')!';
                        } else {
                            sumHtml += '💡 <strong>Next Batch Ready:</strong> Page pointer updated to <strong>Page ' + (res.next_page || 1) + '</strong>. Click <strong>"Run Synchronization Now"</strong> anytime to fetch the next batch of new products!';
                        }
                        sumHtml += '</div>';

                        $('#sync-summary-text').html(sumHtml);
                        $('#sync-complete-summary').slideDown();
                    }
                } else {
                    clearInterval(syncTimer);
                    $('#btn-start-manual-sync').prop('disabled', false);
                    alert('Sync batch failed.');
                }
            },
            error: function(xhr, status, error) {
                console.warn('Manual batch sync network notice:', status, error, xhr.status);

                // Handle Hostinger CDN / Cloudflare 403 rate-limit challenge
                if (xhr.status === 403) {
                    $('#sync-progress-status').text('Hostinger server rate-limiting (403) detected. Cooldown pause (6s) before resuming...');
                    setTimeout(function() {
                        runNextBatch(historyId, page, syncLimit);
                    }, 6000);
                    return;
                }

                if (manualBatchRetries < maxManualRetries) {
                    manualBatchRetries++;
                    setTimeout(function() {
                        runNextBatch(historyId, page, syncLimit);
                    }, 3000);
                } else {
                    manualBatchRetries = 0;
                    setTimeout(function() {
                        runNextBatch(historyId, page + 1, syncLimit);
                    }, 1500);
                }
            }
        });
    }

    // Reset Page Pointer click
    $(document).on('click', '#btn-reset-sync-page', function(e) {
        e.preventDefault();
        var $link = $(this);
        $link.text('Resetting...');

        $.ajax({
            url: wcErpSyncLocal.ajax_url,
            type: 'POST',
            data: {
                action: 'wc_erp_sync_reset_sync_page',
                security: wcErpSyncLocal.nonce
            },
            success: function(response) {
                if (response.success) {
                    $('#sync-page-pointer-display').text('1');
                    $link.text('Reset to Page 1');
                    alert('Page pointer reset to Page 1. Next sync will start from the beginning.');
                }
            }
        });
    });

    function querySyncStatus() {
        $.ajax({
            url: wcErpSyncLocal.ajax_url,
            type: 'POST',
            data: {
                action: 'wc_erp_sync_get_sync_status',
                security: wcErpSyncLocal.nonce
            },
            success: function(response) {
                if (response.success && response.data.latest_job) {
                    var job = response.data.latest_job;
                    
                    $('#stat-processed').text(job.records_processed);
                    $('#stat-success').text(job.records_success);
                    $('#stat-failed').text(job.records_failed);

                    // Dynamic progress calculation
                    var selectedLimit = parseInt($('#select-sync-limit').val());
                    var targetTotal   = (selectedLimit > 0) ? selectedLimit : 4200;
                    var pct           = Math.min(100, Math.round((job.records_processed / targetTotal) * 100));
                    
                    $('#sync-progress-bar-inner').css('width', pct + '%');
                    $('#sync-progress-status').text('Syncing records: ' + pct + '% (' + job.records_processed + '/' + targetTotal + ')');

                    if (job.status !== 'running') {
                        // Complete
                        clearInterval(syncTimer);
                        clearInterval(syncPoller);
                        
                        $('#sync-progress-box').slideUp();
                        $('#btn-start-manual-sync').prop('disabled', false);
                        
                        var sumText = 'Processed: ' + job.records_processed + ' items. Success: ' + job.records_success + ', Failed: ' + job.records_failed + '. Time taken: ' + job.duration + ' seconds.';
                        $('#sync-summary-text').text(sumText);
                        $('#sync-complete-summary').slideDown();
                    }
                }
            }
        });
    }

    // 5. Activity log loading and filters
    function loadLogRecords(pageNumber) {
        var levelFilter = $('#log-level-filter').val();
        var searchVal   = $('#log-search-input').val();
        var $tbody      = $('#logs-tbody');
        var $pagination = $('#logs-pagination');

        $tbody.html('<tr><td colspan="5" class="center-text"><span class="spinner is-active inline-spinner"></span> Loading logs...</td></tr>');

        $.ajax({
            url: wcErpSyncLocal.ajax_url,
            type: 'POST',
            data: {
                action: 'wc_erp_sync_load_logs',
                level: levelFilter,
                search: searchVal,
                page: pageNumber || 1,
                security: wcErpSyncLocal.nonce
            },
            success: function(response) {
                if (response.success) {
                    var logs = response.data.logs;
                    var total = response.data.total;
                    
                    if (logs.length === 0) {
                        $tbody.html('<tr><td colspan="5" class="center-text">No matching logs found.</td></tr>');
                        $pagination.hide();
                        return;
                    }

                    var html = '';
                    logs.forEach(function(log) {
                        var ctxBtn = log.context ? '<button class="btn btn-xs btn-outline btn-view-context" data-json=\'' + escapeHtml(log.context) + '\'>View Data</button>' : '-';
                        html += '<tr class="log-row-' + log.level + '">';
                        html += '<td>' + log.created_at_formatted + '</td>';
                        html += '<td><span class="badge badge-' + log.level + '">' + log.level.toUpperCase() + '</span></td>';
                        html += '<td><code>' + log.module + '</code></td>';
                        html += '<td>' + log.message + '</td>';
                        html += '<td>' + ctxBtn + '</td>';
                        html += '</tr>';
                    });
                    $tbody.html(html);

                    // Render pagination links
                    if (response.data.pages > 1) {
                        var pagHtml = '';
                        for (var i = 1; i <= response.data.pages; i++) {
                            var currentClass = (pageNumber || 1) === i ? 'current' : '';
                            pagHtml += '<button class="page-numbers ' + currentClass + '" data-page="' + i + '">' + i + '</button>';
                        }
                        $pagination.html(pagHtml).show();
                    } else {
                        $pagination.hide();
                    }
                }
            }
        });
    }

    // Trigger log filter click
    $('#btn-filter-logs').on('click', function(e) {
        e.preventDefault();
        loadLogRecords(1);
    });

    $(document).on('click', '#logs-pagination button', function(e) {
        e.preventDefault();
        var pg = $(this).data('page');
        loadLogRecords(pg);
    });

    // View JSON Modal trigger
    $(document).on('click', '.btn-view-context', function(e) {
        e.preventDefault();
        var jsonStr = $(this).data('json');
        
        try {
            var formatted = JSON.stringify(jsonStr, null, 4);
            $('#log-context-json-viewer').text(formatted);
        } catch(err) {
            $('#log-context-json-viewer').text(jsonStr);
        }
        
        $('#log-context-modal').show();
    });

    // Initial logs load if on Logs tab
    if ($('#logs-tbody').length) {
        loadLogRecords(1);
    }

    // Clear logs wipe database
    $('#btn-clear-logs').on('click', function(e) {
        e.preventDefault();
        if (!confirm('Are you sure you want to delete all activity logs from the database?')) {
            return;
        }

        $.ajax({
            url: wcErpSyncLocal.ajax_url,
            type: 'POST',
            data: {
                action: 'wc_erp_sync_clear_logs',
                security: wcErpSyncLocal.nonce
            },
            success: function(response) {
                if (response.success) {
                    loadLogRecords(1);
                }
            }
        });
    });

    // 6. Manual mapping link creator
    $('#add-manual-mapping-form').on('submit', function(e) {
        e.preventDefault();
        var $form = $(this);
        var $alert = $('#mapping-result-alert');

        $alert.slideUp().removeClass('success error');

        $.ajax({
            url: wcErpSyncLocal.ajax_url,
            type: 'POST',
            data: $form.serialize() + '&action=wc_erp_sync_save_mapping&security=' + wcErpSyncLocal.nonce,
            success: function(response) {
                if (response.success) {
                    $alert.addClass('success').html(response.data.message).slideDown();
                    $alert.css({
                        'background': 'var(--success-bg)',
                        'color': 'var(--success-color)',
                        'padding': '10px',
                        'border-radius': '4px',
                        'margin-top': '10px'
                    });
                    setTimeout(function() {
                        window.location.reload();
                    }, 1500);
                } else {
                    $alert.addClass('error').html(response.data.message).slideDown();
                    $alert.css({
                        'background': 'var(--danger-bg)',
                        'color': 'var(--danger-color)',
                        'padding': '10px',
                        'border-radius': '4px',
                        'margin-top': '10px'
                    });
                }
            }
        });
    });

    // Delete mapping click
    $('.btn-delete-mapping').on('click', function(e) {
        e.preventDefault();
        if (!confirm('Are you sure you want to delete this mapping association?')) {
            return;
        }

        var $row  = $(this).closest('tr');
        var type  = $(this).data('type');
        var extId = $(this).data('ext-id');

        $.ajax({
            url: wcErpSyncLocal.ajax_url,
            type: 'POST',
            data: {
                action: 'wc_erp_sync_delete_mapping',
                entity_type: type,
                external_id: extId,
                security: wcErpSyncLocal.nonce
            },
            success: function(response) {
                if (response.success) {
                    $row.fadeOut(function() { $(this).remove(); });
                }
            }
        });
    });

    // 7. Queue Management Retries & Purges
    $('#btn-retry-failed').on('click', function(e) {
        e.preventDefault();
        
        $.ajax({
            url: wcErpSyncLocal.ajax_url,
            type: 'POST',
            data: {
                action: 'wc_erp_sync_retry_queue',
                security: wcErpSyncLocal.nonce
            },
            success: function(response) {
                if (response.success) {
                    alert(response.data.message);
                    window.location.reload();
                }
            }
        });
    });

    $('#btn-clear-queue').on('click', function(e) {
        e.preventDefault();
        if (!confirm('Warning: This will delete all items currently in the sync queue! Proceed?')) {
            return;
        }

        $.ajax({
            url: wcErpSyncLocal.ajax_url,
            type: 'POST',
            data: {
                action: 'wc_erp_sync_clear_queue',
                security: wcErpSyncLocal.nonce
            },
            success: function(response) {
                if (response.success) {
                    window.location.reload();
                }
            }
        });
    });

    $('.btn-delete-queue-item').on('click', function(e) {
        e.preventDefault();
        var $row = $(this).closest('tr');
        var id   = $(this).data('id');

        $.ajax({
            url: wcErpSyncLocal.ajax_url,
            type: 'POST',
            data: {
                action: 'wc_erp_sync_clear_queue', // Uses same endpoint routing if parameter provided, or delete item handler
                security: wcErpSyncLocal.nonce
            },
            success: function(response) {
                // For simplicity, we trigger reload on queue actions
                window.location.reload();
            }
        });
    });

    // Utility escape html
    function escapeHtml(text) {
        if (!text) return '';
        if (typeof text === 'object') {
            text = JSON.stringify(text);
        }
        return text
            .replace(/&/g, "&amp;")
            .replace(/</g, "&lt;")
            .replace(/>/g, "&gt;")
            .replace(/"/g, "&quot;")
            .replace(/'/g, "&#039;");
    }

    // 8. Refresh Live API Stats Banner
    $('#btn-refresh-api-stats').on('click', function(e) {
        e.preventDefault();
        var $btn = $(this);
        $btn.prop('disabled', true).addClass('loading');

        $.ajax({
            url: wcErpSyncLocal.ajax_url,
            type: 'POST',
            data: {
                action: 'wc_erp_sync_get_product_stats',
                security: wcErpSyncLocal.nonce
            },
            success: function(response) {
                $btn.prop('disabled', false).removeClass('loading');
                if (response.success && response.data) {
                    $('#api-stat-products').text(response.data.total_products.toLocaleString());
                    $('#api-stat-categories').text(response.data.total_categories.toLocaleString());
                    $('#api-stat-brands').text(response.data.total_brands.toLocaleString());
                    if (response.data.last_sync) {
                        $('#api-stat-last-sync').text(response.data.last_sync);
                    }
                }
            },
            error: function() {
                $btn.prop('disabled', false).removeClass('loading');
            }
        });
    });

    // 9. Single SKU Quick Sync Tool
    $('#btn-sync-single-sku').on('click', function(e) {
        e.preventDefault();
        var skuVal = $('#input-single-sku').val().trim();
        var $btn   = $(this);
        var $res   = $('#sku-sync-result');

        if (!skuVal) {
            alert('Please enter a product SKU.');
            return;
        }

        $btn.prop('disabled', true);
        $res.slideUp().removeClass('success-alert error-alert');

        $.ajax({
            url: wcErpSyncLocal.ajax_url,
            type: 'POST',
            data: {
                action: 'wc_erp_sync_sync_single_sku',
                sku: skuVal,
                security: wcErpSyncLocal.nonce
            },
            success: function(response) {
                $btn.prop('disabled', false);
                if (response.success) {
                    $res.addClass('success-alert').html('<strong>✓ ' + response.data.message + '</strong>').slideDown();
                    $res.css({
                        'background': 'var(--success-bg)',
                        'color': 'var(--success-color)',
                        'padding': '10px',
                        'border-radius': '4px'
                    });
                } else {
                    $res.addClass('error-alert').html('<strong>✗ ' + response.data.message + '</strong>').slideDown();
                    $res.css({
                        'background': 'var(--danger-bg)',
                        'color': 'var(--danger-color)',
                        'padding': '10px',
                        'border-radius': '4px'
                    });
                }
            },
            error: function() {
                $btn.prop('disabled', false);
                $res.html('<strong>✗ AJAX Error. Check server logs.</strong>').slideDown();
            }
        });
    });

    // 10. Trigger Remote FastAPI Scraper Job
    $('#btn-trigger-remote-sync').on('click', function(e) {
        e.preventDefault();
        var $btn  = $(this);
        var $res  = $('#remote-trigger-result');
        var isInc = $('#chk-remote-incremental').is(':checked') ? 1 : 0;

        $btn.prop('disabled', true);
        $res.slideUp();

        $.ajax({
            url: wcErpSyncLocal.ajax_url,
            type: 'POST',
            data: {
                action: 'wc_erp_sync_trigger_sync',
                incremental: isInc,
                security: wcErpSyncLocal.nonce
            },
            success: function(response) {
                $btn.prop('disabled', false);
                if (response.success && response.data) {
                    var msg = response.data.message || ('Scraper Job #' + response.data.job_id + ' Status: ' + response.data.status);
                    $res.html('<strong>✓ ' + msg + '</strong>').slideDown();
                    $res.css({
                        'background': 'var(--success-bg)',
                        'color': 'var(--success-color)',
                        'padding': '10px',
                        'border-radius': '4px'
                    });
                } else {
                    var errMsg = (response.data && response.data.message) ? response.data.message : 'Failed to trigger remote scrape.';
                    $res.html('<strong>✗ ' + errMsg + '</strong>').slideDown();
                    $res.css({
                        'background': 'var(--danger-bg)',
                        'color': 'var(--danger-color)',
                        'padding': '10px',
                        'border-radius': '4px'
                    });
                }
            },
            error: function() {
                $btn.prop('disabled', false);
                $res.html('<strong>✗ AJAX Error. Check server connection.</strong>').slideDown();
            }
        });
    });

    // 11. Sync Changed Products Since Date
    $('#btn-sync-incremental-date').on('click', function(e) {
        e.preventDefault();
        var sinceVal = $('#input-changed-since').val();
        var $btn     = $(this);
        var $res     = $('#incremental-sync-result');

        if (!sinceVal) {
            alert('Please select a date.');
            return;
        }

        $btn.prop('disabled', true);
        $res.slideUp();

        $.ajax({
            url: wcErpSyncLocal.ajax_url,
            type: 'POST',
            data: {
                action: 'wc_erp_sync_sync_changed_products',
                since: sinceVal,
                security: wcErpSyncLocal.nonce
            },
            success: function(response) {
                $btn.prop('disabled', false);
                if (response.success && response.data) {
                    $res.html('<strong>✓ ' + response.data.message + '</strong>').slideDown();
                    $res.css({
                        'background': 'var(--success-bg)',
                        'color': 'var(--success-color)',
                        'padding': '10px',
                        'border-radius': '4px'
                    });
                } else {
                    $res.html('<strong>✗ Failed to query changed products.</strong>').slideDown();
                }
            },
            error: function() {
                $btn.prop('disabled', false);
                $res.html('<strong>✗ AJAX Error.</strong>').slideDown();
            }
        });
    });

    // 12. Category-Based Product Sync Logic
    var rawCategoriesData = window.wcErpInitialCategories || [];

    // Helper to recursively flatten category tree in JS
    function flattenCategoriesJS(categories, depth, prefix) {
        depth = depth || 0;
        prefix = prefix || '';
        var flat = [];
        if (!Array.isArray(categories)) return flat;

        categories.forEach(function(cat) {
            var catId   = (cat.id !== undefined && cat.id !== null && cat.id !== '') ? cat.id : (cat.slug || cat);
            var catName = cat.name || cat.title || (typeof cat === 'string' ? cat : '');
            var catSlug = cat.slug || '';
            if (!catName) return;

            var hasChildren = Array.isArray(cat.children) && cat.children.length > 0;
            var path        = prefix ? (prefix + ' > ' + catName) : catName;
            var indent      = '    '.repeat(depth) + (depth > 0 ? '— ' : '');

            flat.push({
                id: catId,
                name: catName,
                slug: catSlug,
                path: path,
                displayName: indent + catName,
                depth: depth,
                hasChildren: hasChildren,
                children: cat.children || [],
                parentId: cat.parent_id || null,
                raw: cat
            });

            if (hasChildren) {
                var subFlat = flattenCategoriesJS(cat.children, depth + 1, path);
                flat = flat.concat(subFlat);
            }
        });

        return flat;
    }

    function populateCategoriesDropdowns(cats) {
        if (!cats || !cats.length) return;
        var flatCats = flattenCategoriesJS(cats);

        // 1. Single Category Select (Option 2 - ALL Categories)
        var $singleSelect = $('#select-cat-single');
        if ($singleSelect.length) {
            var currentSingle = $singleSelect.val();
            $singleSelect.empty().append('<option value="">-- Select Category --</option>');
            flatCats.forEach(function(item) {
                $singleSelect.append('<option value="' + item.id + '" data-slug="' + item.slug + '">' + escapeHtml(item.displayName) + '</option>');
            });
            if (currentSingle) {
                $singleSelect.val(currentSingle);
            }
        }

        // 2. Parent Category Select (Option 1 - Categories with children)
        var $parentSelect = $('#select-cat-parent');
        if ($parentSelect.length) {
            var currentParent = $parentSelect.val();
            $parentSelect.empty().append('<option value="">-- Select Parent Category --</option>');
            flatCats.forEach(function(item) {
                if (item.hasChildren) {
                    $parentSelect.append('<option value="' + item.id + '" data-slug="' + item.slug + '">' + escapeHtml(item.path) + '</option>');
                }
            });
            if (currentParent) {
                $parentSelect.val(currentParent);
            }
        }
    }

    // Populate immediately if initial data loaded in PHP
    if (rawCategoriesData && rawCategoriesData.length) {
        populateCategoriesDropdowns(rawCategoriesData);
    }

    function loadSyncCategoriesDropdown() {
        var $parentSelect = $('#select-cat-parent');
        if (!$parentSelect.length) return;

        $.ajax({
            url: wcErpSyncLocal.ajax_url,
            type: 'POST',
            data: {
                action: 'wc_erp_sync_get_categories',
                security: wcErpSyncLocal.nonce
            },
            success: function(response) {
                if (response.success && response.data.categories && response.data.categories.length) {
                    rawCategoriesData = response.data.categories;
                    populateCategoriesDropdowns(rawCategoriesData);

                    // Trigger change if parent category is already selected
                    if ($parentSelect.val()) {
                        $parentSelect.trigger('change');
                    }
                }
            }
        });
    }

    if ($('#select-cat-parent').length) {
        loadSyncCategoriesDropdown();
    }

    // Helper to get all descendant categories for a selected parent category
    function getDescendantsForParent(selectedParentId, cats) {
        var flatCats = flattenCategoriesJS(cats);
        var targetParent = null;

        flatCats.forEach(function(item) {
            if ((item.id + '') === (selectedParentId + '') || (item.slug && item.slug === selectedParentId + '')) {
                targetParent = item;
            }
        });

        if (!targetParent || !targetParent.children || !targetParent.children.length) {
            return [];
        }

        return flattenCategoriesJS(targetParent.children, 0, '');
    }

    // Toggle Category Sync Mode UI (Option 1 vs Option 2)
    function syncCategorySyncModeUI() {
        var mode = $('input[name="cat_sync_mode"]:checked').val() || 'parent_child';
        var $btn = $('#btn-start-category-sync');

        if (mode === 'parent_child') {
            $('#cat-mode-parent-child').css('display', 'flex');
            $('#cat-mode-single').css('display', 'none');
            
            var childVal = $('#select-cat-child').val();
            $btn.prop('disabled', !childVal);
        } else {
            $('#cat-mode-parent-child').css('display', 'none');
            $('#cat-mode-single').css('display', 'block');
            
            var singleVal = $('#select-cat-single').val();
            $btn.prop('disabled', !singleVal);
        }
    }

    // Radio Mode Switch Handler (Option 1 vs Option 2)
    $(document).on('change', 'input[name="cat_sync_mode"]', function() {
        syncCategorySyncModeUI();
    });

    // Run mode UI toggle immediately on load
    syncCategorySyncModeUI();

    // On Parent Category Select Change (Option 1)
    $(document).on('change', '#select-cat-parent', function() {
        var parentVal    = $(this).val();
        var $childSelect = $('#select-cat-child');
        var $btnSync     = $('#btn-start-category-sync');

        $childSelect.empty();
        $btnSync.prop('disabled', true);

        if (!parentVal) {
            $childSelect.append('<option value="">-- Select Parent Category First --</option>');
            $childSelect.prop('disabled', true);
            return;
        }

        var catsList = (rawCategoriesData && rawCategoriesData.length) ? rawCategoriesData : (window.wcErpInitialCategories || []);
        var childrenList = getDescendantsForParent(parentVal, catsList);

        if (childrenList.length > 0) {
            $childSelect.append('<option value="">-- Select Child Category --</option>');
            childrenList.forEach(function(child) {
                $childSelect.append('<option value="' + child.id + '" data-slug="' + child.slug + '">' + escapeHtml(child.displayName) + '</option>');
            });
            $childSelect.prop('disabled', false);
        } else {
            $childSelect.append('<option value="">-- No Child Categories Found --</option>');
            $childSelect.prop('disabled', true);
        }
    });

    // On Child Category Select Change (Option 1)
    $(document).on('change', '#select-cat-child', function() {
        var childVal = $(this).val();
        $('#btn-start-category-sync').prop('disabled', !childVal);
    });

    // On Single Category Select Change (Option 2)
    $(document).on('change', '#select-cat-single', function() {
        var singleVal = $(this).val();
        $('#btn-start-category-sync').prop('disabled', !singleVal);
    });

    // Category Sync Runner
    var catSyncTimer = null;
    var catSyncStartSec = 0;

    $('#btn-start-category-sync').on('click', function(e) {
        e.preventDefault();
        var mode        = $('input[name="cat_sync_mode"]:checked').val();
        var targetCatId = '';
        var selectedName = '';

        if (mode === 'parent_child') {
            var $opt     = $('#select-cat-child option:selected');
            targetCatId  = $opt.data('slug') || $opt.val();
            selectedName = $opt.text();
        } else {
            var $opt     = $('#select-cat-single option:selected');
            targetCatId  = $opt.data('slug') || $opt.val();
            selectedName = $opt.text();
        }

        if (!targetCatId) {
            alert('Please select a category first.');
            return;
        }

        if (!confirm('Start importing products for selected category: "' + selectedName + '"?')) {
            return;
        }

        var $btn     = $(this);
        var $progBox = $('#cat-sync-progress-box');
        var $summary = $('#cat-sync-complete-summary');

        $btn.prop('disabled', true);
        $summary.hide();
        $progBox.slideDown();

        catSyncStartSec = 0;
        $('#cat-sync-timer-counter').text('0s');
        if (catSyncTimer) clearInterval(catSyncTimer);
        catSyncTimer = setInterval(function() {
            catSyncStartSec++;
            $('#cat-sync-timer-counter').text(catSyncStartSec + 's');
        }, 1000);

        $.ajax({
            url: wcErpSyncLocal.ajax_url,
            type: 'POST',
            data: {
                action: 'wc_erp_sync_run_category_sync',
                category_id: targetCatId,
                security: wcErpSyncLocal.nonce
            },
            success: function(response) {
                if (response.success && response.data.history_id) {
                    var historyId = response.data.history_id;
                    runNextCategoryBatch(historyId, targetCatId, 1, 0);
                } else {
                    clearInterval(catSyncTimer);
                    $btn.prop('disabled', false);
                    alert(response.data ? response.data.message : 'Failed to initialize category sync.');
                }
            },
            error: function() {
                clearInterval(catSyncTimer);
                $btn.prop('disabled', false);
                alert('Failed to initialize category sync.');
            }
        });
    });

    var catBatchRetries = 0;
    var maxCatRetries = 2;

    function runNextCategoryBatch(historyId, categoryId, page, includeChildren) {
        $.ajax({
            url: wcErpSyncLocal.ajax_url,
            type: 'POST',
            data: {
                action: 'wc_erp_sync_process_category_batch',
                history_id: historyId,
                category_id: categoryId,
                page: page,
                include_children: includeChildren,
                batch_size: 3,
                security: wcErpSyncLocal.nonce
            },
            success: function(response) {
                catBatchRetries = 0; // Reset retry counter on success
                if (response.success && response.data) {
                    var res = response.data;

                    $('#cat-stat-processed').text(res.processed);
                    $('#cat-stat-success').text(res.success);
                    $('#cat-stat-failed').text(res.failed);

                    var totalTarget = (res.total && res.total > 0) ? res.total : 0;
                    var pct = 60;
                    if (totalTarget > 0) {
                        pct = Math.min(100, Math.max(5, Math.round((res.processed / totalTarget) * 100)));
                        $('#cat-sync-progress-status').text('Imported ' + res.processed + ' of ' + totalTarget + ' products (' + pct + '%)...');
                    } else {
                        $('#cat-sync-progress-status').text('Imported ' + res.processed + ' products for category...');
                    }
                    $('#cat-sync-progress-bar-inner').css('width', res.completed ? '100%' : (pct + '%'));

                    if (!res.completed) {
                        setTimeout(function() {
                            runNextCategoryBatch(historyId, categoryId, res.page, includeChildren);
                        }, 1500);
                    } else {
                        clearInterval(catSyncTimer);
                        $('#cat-sync-progress-box').slideUp();
                        $('#btn-start-category-sync').prop('disabled', false);

                        var createdCount = res.created || 0;
                        var updatedCount = res.updated || 0;
                        var sumHtml = '🎉 <strong>Category Sync Completed!</strong> Processed: ' + res.processed + ' items (Created: ' + createdCount + ', Updated: ' + updatedCount + ', Failed: ' + res.failed + ').';

                        $('#cat-sync-summary-text').html(sumHtml);
                        $('#cat-sync-complete-summary').slideDown();
                    }
                } else {
                    clearInterval(catSyncTimer);
                    $('#btn-start-category-sync').prop('disabled', false);
                    alert(response.data && response.data.message ? response.data.message : 'Category batch sync failed.');
                }
            },
            error: function(xhr, status, error) {
                console.warn('Category batch sync network notice:', status, error, xhr.status);

                // Handle Hostinger CDN / Cloudflare 403 rate-limit challenge
                if (xhr.status === 403) {
                    $('#cat-sync-progress-status').text('Hostinger server rate-limiting (403) detected. Cooldown pause (6s) before resuming...');
                    setTimeout(function() {
                        runNextCategoryBatch(historyId, categoryId, page, includeChildren);
                    }, 6000);
                    return;
                }

                if (catBatchRetries < maxCatRetries) {
                    catBatchRetries++;
                    $('#cat-sync-progress-status').text('Batch encountered network delay. Retrying attempt ' + catBatchRetries + ' of ' + maxCatRetries + '...');
                    setTimeout(function() {
                        runNextCategoryBatch(historyId, categoryId, page, includeChildren);
                    }, 3000);
                } else {
                    // Prevent permanent freeze: skip delayed batch and continue to next page
                    catBatchRetries = 0;
                    $('#cat-sync-progress-status').text('Skipping delayed batch and continuing next items...');
                    setTimeout(function() {
                        runNextCategoryBatch(historyId, categoryId, page + 1, includeChildren);
                    }, 1500);
                }
            }
        });
    }
});
