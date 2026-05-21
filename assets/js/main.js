/**
 * SalesNexus Lead Extractor — main.js (v2.3)
 * Changes: Always-on polling for live log updates + live auto sync countdown timer
 */

jQuery(function ($) {

    // =========================================================
    // State
    // =========================================================
    var pollInterval          = null;
    var syncCountdownInterval = null;
    var currentPollSpeed      = null; // Tracks active interval speed to avoid unnecessary restarts

    var POLL_FAST_MS = 3000;  // Used when queue is actively running
    var POLL_SLOW_MS = 15000; // Used when idle/paused/completed — light enough for auto sync detection

    // =========================================================
    // Settings Save
    // =========================================================
    $('#saveBtn').on('click', function () {
        var $btn    = $(this);
        var $status = $('#saveStatus');

        $btn.prop('disabled', true).text('Saving...');
        $status.text('');

        $.post(le_ajax_obj.ajax_url, {
            action:                  'le_save_settings',
            _wpnonce:                le_ajax_obj.nonce,
            le_source_sheet_id:      $('#le_source_sheet_id').val(),
            le_target_sheet_id:      $('#le_target_sheet_id').val(),
            le_api_key:              $('#le_api_key').val(),
            le_column_name:          $('#le_column_name').val(),
            le_job_titles:           $('#le_job_titles').val(),
            le_batch_size:           $('#le_batch_size').val(),
            le_person_limit:         $('#le_person_limit').val(),
            le_sync_interval:        $('#le_sync_interval').val(),
            le_input_source:              $('#le_input_source').val(),
            le_domain_retention_days:     $('#le_domain_retention_days').val(),
            le_output_destination:        $('#le_output_destination').val(),
            le_salesnexus_webhook_token:  $('#le_salesnexus_webhook_token').val(),
            le_salesnexus_api_url:        $('#le_salesnexus_api_url').val(),
            le_salesnexus_lead_source:    $('#le_salesnexus_lead_source').val(),
            le_salesnexus_id_status:      $('#le_salesnexus_id_status').val(),
        }, function (res) {
            if (res.success) {
                $status.css('color', 'green').text('✅ ' + res.data.message);
                $('#currentPersonLimit').text($('#le_person_limit').val());
                $('#statPersonLimit').text($('#le_person_limit').val());
            } else {
                $status.css('color', 'red').text('❌ ' + (res.data.message || 'Error'));
            }
        }).fail(function () {
            $status.css('color', 'red').text('❌ Connection failed.');
        }).always(function () {
            $btn.prop('disabled', false).text('💾 Save Settings');
        });
    });

    // =========================================================
    // API Key Show/Hide (Profile API)
    // =========================================================
    $('#toggleApiKey').on('click', function (e) {
        e.preventDefault();
        var $input = $('#le_api_key');
        $input.attr('type', $input.attr('type') === 'password' ? 'text' : 'password');
    });

    // =========================================================
    // SalesNexus Webhook Token Show/Hide
    // =========================================================
    $('#toggleSnxToken').on('click', function (e) {
        e.preventDefault();
        var $input = $('#le_salesnexus_webhook_token');
        $input.attr('type', $input.attr('type') === 'password' ? 'text' : 'password');
    });

    // =========================================================
    // Output Destination — show/hide conditional fields
    // =========================================================
    function applyDestinationToggle(val) {
        if (val === 'salesnexus_api') {
            $('#snxFields').show();
            $('#targetSheetFields').hide();
        } else {
            $('#snxFields').hide();
            $('#targetSheetFields').show();
        }
    }

    $('#le_output_destination').on('change', function () {
        applyDestinationToggle($(this).val());
    });

    // Apply on page load in case PHP-rendered value differs
    applyDestinationToggle($('#le_output_destination').val());

    // =========================================================
    // Run Queue
    // =========================================================
    $('#runBtn').on('click', function () {
        if (! confirm('Start new queue? This will process new domains while keeping current history.')) return;

        setStatus('starting');
        addLogEntry('🚀 Manual run starting...');

        $.post(le_ajax_obj.ajax_url, { action: 'le_run_process' }, function (res) {
            if (res.success) {
                updateUI(res.data.queue);
                showNotice(res.data.message, 'success');
            } else {
                showNotice(res.data.message || 'Error', 'error');
                setStatus('idle');
            }
        });
    });

    // =========================================================
    // Pause
    // =========================================================
    // Input Source show/hide
    // =========================================================
    function applyInputSource(source) {
        var isSheet   = (source === 'google_sheet');
        var isWebhook = (source === 'salesnexus_webhook');

        $('#googleSheetInputFields').toggle(isSheet);
        $('#snxWebhookInputFields').toggle(isWebhook);
        $('#emailColumnField').toggle(isSheet);
        $('#syncIntervalField').toggle(isSheet);
        $('#autoSyncCard').toggle(isSheet);

        // When SalesNexus Webhook is input, output must be SalesNexus API — hide the choice
        if (isWebhook) {
            $('#le_output_destination').val('salesnexus_api').trigger('change');
            $('#le_output_destination').closest('.le-field-group').hide();
        } else {
            $('#le_output_destination').closest('.le-field-group').show();
        }
    }

    $('#le_input_source').on('change', function () {
        applyInputSource($(this).val());
    });

    // Copy webhook URL to clipboard
    $('#copyIncomingUrl').on('click', function () {
        var url = $('#le_incoming_url').val();
        if (navigator.clipboard) {
            navigator.clipboard.writeText(url).then(function () {
                showNotice('URL copied!', 'success');
            });
        } else {
            $('#le_incoming_url').select();
            document.execCommand('copy');
            showNotice('URL copied!', 'success');
        }
    });

    // Regenerate / Generate incoming webhook token
    $('#regenerateIncomingToken').on('click', function () {
        var hasExisting = $('#le_incoming_url').length > 0;
        if (hasExisting && ! confirm('Regenerate URL? The old URL will stop working — update SalesNexus trigger too.')) return;

        var $btn = $(this).prop('disabled', true).text('⏳ Generating...');

        $.post(le_ajax_obj.ajax_url, { action: 'le_regenerate_incoming_token' }, function (res) {
            if (res.success) {
                var url = res.data.url;

                if ($('#le_incoming_url').length > 0) {
                    // Already exists — just update value
                    $('#le_incoming_url').val(url);
                } else {
                    // First time — remove "No URL yet" message and inject the URL row
                    $('#snxNoUrlMsg').remove();
                    var $row = $(
                        '<div class="le-field-row" id="snxUrlRow" style="margin-bottom:6px">' +
                            '<input type="text" id="le_incoming_url" readonly class="le-input-full" />' +
                            '<button type="button" id="copyIncomingUrl" class="button">📋 Copy</button>' +
                        '</div>'
                    );
                    $btn.before($row);
                    $('#le_incoming_url').val(url);
                }

                $btn.text('🔄 Regenerate URL').prop('disabled', false);
                showNotice('✅ URL generated! Copy it to SalesNexus.', 'success');
            } else {
                showNotice('Failed to generate URL', 'error');
                $btn.prop('disabled', false).text('🔄 Generate URL');
            }
        }).fail(function () {
            showNotice('Connection error', 'error');
            $btn.prop('disabled', false).text('🔄 Generate URL');
        });
    });

    // =========================================================
    $('#pauseBtn').on('click', function () {
        $.post(le_ajax_obj.ajax_url, { action: 'le_pause_queue' }, function (res) {
            if (res.success) {
                setStatus('paused');
                addLogEntry('⏸ Queue paused');
                showNotice(res.data.message, 'info');
            }
        });
    });

    // =========================================================
    // Resume
    // =========================================================
    $('#resumeBtn').on('click', function () {
        $.post(le_ajax_obj.ajax_url, { action: 'le_resume_queue' }, function (res) {
            if (res.success) {
                setStatus('running');
                addLogEntry('▶️ Queue resumed');
                showNotice(res.data.message, 'success');
            }
        });
    });

    // =========================================================
    // Reset
    // =========================================================
    $('#resetBtn').on('click', function () {
        if (! confirm('⚠️ All queue and processed domain history will be deleted. Confirm?')) return;

        $.post(le_ajax_obj.ajax_url, { action: 'le_reset_queue' }, function (res) {
            if (res.success) {
                resetUI();
                showNotice(res.data.message, 'success');
            }
        });
    });

    // =========================================================
    // Toggle Auto Sync
    // =========================================================
    $('#toggleSyncBtn').on('click', function () {
        $.post(le_ajax_obj.ajax_url, { action: 'le_toggle_sync' }, function (res) {
            if (res.success) {
                var enabled = res.data.enabled;
                $('#syncStatus')
                    .text(enabled ? 'Enabled' : 'Disabled')
                    .removeClass('le-badge-running le-badge-idle')
                    .addClass(enabled ? 'le-badge-running' : 'le-badge-idle');
                $('#toggleSyncBtn')
                    .text(enabled ? '⏸ Disable Sync' : '▶️ Enable Sync')
                    .removeClass('button-primary button-secondary')
                    .addClass(enabled ? 'button-secondary' : 'button-primary');
                showNotice(res.data.message, enabled ? 'success' : 'info');

                // Restart countdown after toggling — next_sync will update on next poll
                if (! enabled) {
                    stopSyncCountdown();
                    $('.le-sync-countdown').text('Not scheduled');
                }
            }
        });
    });

    // =========================================================
    // POLLING — Adaptive speed + Page Visibility API
    // Fast (3s) when running, slow (15s) when idle — stops when tab is hidden
    // =========================================================

    // Start (or restart) polling at the given speed; skips restart if speed unchanged
    function startPolling(fast) {
        var speed = fast ? POLL_FAST_MS : POLL_SLOW_MS;

        // Avoid clearing and re-setting if already running at the correct speed
        if (pollInterval && currentPollSpeed === speed) return;

        stopPolling();
        currentPollSpeed = speed;
        fetchProgress(); // Immediate fetch on start
        pollInterval = setInterval(fetchProgress, speed);
    }

    function stopPolling() {
        if (pollInterval) {
            clearInterval(pollInterval);
            pollInterval     = null;
            currentPollSpeed = null;
        }
    }

    function fetchProgress() {
        $.post(le_ajax_obj.ajax_url, { action: 'le_get_progress' }, function (res) {
            if (res.success) {
                updateUI(res.data);
            }
        });
    }

    // Pause all timers when tab is hidden; resume immediately when tab is visible again
    document.addEventListener('visibilitychange', function () {
        if (document.hidden) {
            stopPolling();
            stopSyncCountdown();
        } else {
            // Resume polling at the right speed based on current badge status
            var isRunning = $('#queueStatus').hasClass('le-badge-running');
            startPolling(isRunning);
            // Countdown will be restarted automatically by the next updateUI call
        }
    });

    // =========================================================
    // LIVE COUNTDOWN — Updates every second using next_sync Unix timestamp
    // =========================================================
    function startSyncCountdown(nextSyncTs) {
        // Clear any existing countdown before starting a new one
        stopSyncCountdown();

        if (! nextSyncTs) {
            $('.le-sync-countdown').text('Not scheduled');
            return;
        }

        function tick() {
            var nowTs    = Math.floor(Date.now() / 1000);
            var diff     = nextSyncTs - nowTs;

            if (diff <= 0) {
                $('.le-sync-countdown').text('Running soon...');
                stopSyncCountdown();
                return;
            }

            var hours   = Math.floor(diff / 3600);
            var minutes = Math.floor((diff % 3600) / 60);
            var seconds = diff % 60;

            var display = '';
            if (hours > 0) {
                display = hours + 'h ' + pad(minutes) + 'm ' + pad(seconds) + 's';
            } else if (minutes > 0) {
                display = minutes + 'm ' + pad(seconds) + 's';
            } else {
                display = seconds + 's';
            }

            $('.le-sync-countdown').text('in ' + display);
        }

        tick(); // Run immediately
        syncCountdownInterval = setInterval(tick, 1000);
    }

    function stopSyncCountdown() {
        if (syncCountdownInterval) {
            clearInterval(syncCountdownInterval);
            syncCountdownInterval = null;
        }
    }

    // =========================================================
    // UPDATE UI — Update all elements from polled data
    // =========================================================
    function updateUI(data) {
        // Status badge
        setStatus(data.status);

        // Adjust polling speed — fast only when actively running, slow otherwise
        startPolling(data.status === 'running');

        // Current domain — show real-time
        if (data.current_domain) {
            $('#currentDomainBox').show();
            $('#currentDomainText').text(data.current_domain);
        } else {
            $('#currentDomainBox').hide();
            $('#currentDomainText').text('');
        }

        // Progress bar
        $('#progressFill').css('width', data.percent + '%');
        $('#progressLabel').text(data.done + ' / ' + data.total + ' (' + data.percent + '%)');

        // Stats
        $('#statPersons').text(data.summary.total_persons);
        $('#statSuccess').text(data.summary.success_domains);
        $('#statFailed').text(data.summary.failed_domains);
        $('#statPending').text(data.pending);
        $('#statAllTime').text(data.processed_all_time);

        if (data.person_limit) $('#statPersonLimit').text(data.person_limit);

        // Activity log — always replace with latest from server
        if (data.log && data.log.length > 0) {
            renderLog(data.log);
        }

        // Restart countdown with fresh timestamp from server
        // next_sync changes after each auto sync run, so we always use server value
        if (typeof data.next_sync !== 'undefined') {
            startSyncCountdown(data.next_sync);
        }

        // Error box
        if (data.summary.errors && data.summary.errors.length > 0) {
            var $list = $('#errorList').empty();
            data.summary.errors.forEach(function (err) {
                $list.append('<li>' + escHtml(err) + '</li>');
            });
            $('#errorBox').show();
        }
    }

    // =========================================================
    // RENDER LOG — Replace log body with latest entries from server
    // =========================================================
    function renderLog(entries) {
        var $body = $('#logBody').empty();

        if (entries.length === 0) {
            $body.append('<div class="le-log-empty">No activity</div>');
            return;
        }

        entries.forEach(function (entry) {
            var $div = $('<div class="le-log-entry"></div>');
            $div.append('<span class="le-log-time">' + escHtml(entry.time) + '</span>');
            $div.append('<span class="le-log-msg">'  + escHtml(entry.msg)  + '</span>');
            $body.append($div);
        });
    }

    // Add a temporary client-side log entry (before next poll overwrites)
    function addLogEntry(msg) {
        var now  = new Date();
        var time = now.getHours() + ':' + pad(now.getMinutes()) + ':' + pad(now.getSeconds());
        var $body = $('#logBody');

        $body.find('.le-log-empty').remove();

        var $div = $('<div class="le-log-entry new-entry"></div>');
        $div.append('<span class="le-log-time">' + time + '</span>');
        $div.append('<span class="le-log-msg">'  + escHtml(msg) + '</span>');
        $body.prepend($div);

        $body.find('.le-log-entry').slice(20).remove();
    }

    // =========================================================
    // STATUS BADGE UPDATE
    // =========================================================
    function setStatus(status) {
        var labels = {
            'idle':      'IDLE',
            'running':   'RUNNING',
            'paused':    'PAUSED',
            'completed': 'COMPLETED',
            'error':     'ERROR',
            'starting':  'STARTING...',
        };

        $('#queueStatus')
            .text(labels[status] || status.toUpperCase())
            .attr('class', 'le-badge le-badge-' + status);
    }

    // =========================================================
    // RESET UI
    // =========================================================
    function resetUI() {
        setStatus('idle');
        $('#currentDomainBox').hide();
        $('#currentDomainText').text('');
        $('#progressFill').css('width', '0%');
        $('#progressLabel').text('0 / 0 (0%)');
        $('#statPersons, #statSuccess, #statFailed, #statPending').text('0');
        $('#errorBox').hide();
        $('#logBody').html('<div class="le-log-empty">No activity</div>');
        addLogEntry('🗑 Queue and history reset');
    }

    // =========================================================
    // NOTICE
    // =========================================================
    function showNotice(message, type) {
        var colorMap  = { success: '#d4edda', error: '#f8d7da', info: '#d1ecf1' };
        var borderMap = { success: '#28a745', error: '#dc3545', info: '#17a2b8' };

        var $notice = $('<div></div>').text(message).css({
            position:     'fixed',
            top:          '40px',
            right:        '20px',
            background:   colorMap[type]  || colorMap.info,
            border:       '1px solid ' + (borderMap[type] || borderMap.info),
            padding:      '12px 20px',
            borderRadius: '5px',
            zIndex:       99999,
            fontSize:     '14px',
            maxWidth:     '350px',
            boxShadow:    '0 2px 8px rgba(0,0,0,0.15)',
        });

        $('body').append($notice);
        setTimeout(function () { $notice.fadeOut(400, function () { $(this).remove(); }); }, 3500);
    }

    // =========================================================
    // Helpers
    // =========================================================
    function escHtml(str) {
        return String(str)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }

    function pad(n) { return n < 10 ? '0' + n : n; }

    // =========================================================
    // Init — Start polling and countdown on page load
    // Fast if queue is already running, slow otherwise
    // =========================================================
    startPolling(le_ajax_obj.queue_status === 'running');
    startSyncCountdown(le_ajax_obj.next_sync);
    // Apply correct field visibility on page load
    applyInputSource($('#le_input_source').val());
});
