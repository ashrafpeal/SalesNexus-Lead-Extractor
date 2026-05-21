<?php

/**
 * Plugin Name: SalesNexus Lead Extractor
 * Description: Extracts executive leads from Google Sheet and writes to another Google Sheet using ProfileAPI.
 * Version: 1.0.0
 * Author: Ashraf Uddin
 * Author URI: https://github.com/ashrafpeal
 */

require_once __DIR__ . '/vendor/autoload.php';

class Lead_Executives_Extractor {

    // =========================================================
    // Constants
    // =========================================================

    const QUEUE_KEY             = 'le_processing_queue';
    const PROCESSED_DOMAINS_KEY = 'le_processed_domains'; // legacy — migrated to DB table
    const BATCH_CRON_HOOK       = 'le_batch_process_hook';
    const SYNC_CRON_HOOK        = 'le_auto_sync_hook';
    const CLEANUP_CRON_HOOK     = 'le_cleanup_hook';
    const DOMAINS_TABLE         = 'le_processed_domains'; // DB table name (without prefix)

    /**
     * ✅ FIX: BATCH_SIZE constant will no longer be used directly.
     * Instead, get_batch_size() method will be used which reads from settings.
     */
    const DEFAULT_BATCH_SIZE    = 3;
    const MAX_RETRY             = 3;
    const RETRY_BASE_DELAY      = 2;

    // =========================================================
    // Properties
    // =========================================================

    private $sourceSheetId;
    private $targetSheetId;
    private $api_key;
    private $sheets_service    = null;
    private $salesnexus_api_key;
    private $salesnexus_api_url = 'https://api-beta.salesnex.us';
    private $last_sheet_error  = '';

    // =========================================================
    // Constructor
    // =========================================================

    public function __construct() {
        add_action('admin_menu',            [$this, 'menu']);
        add_action('wp_ajax_le_run_process',    [$this, 'ajax_run']);
        add_action('wp_ajax_le_save_settings',  [$this, 'ajax_save_settings']);
        add_action('wp_ajax_le_get_progress',   [$this, 'ajax_get_progress']);
        add_action('wp_ajax_le_pause_queue',    [$this, 'ajax_pause_queue']);
        add_action('wp_ajax_le_resume_queue',   [$this, 'ajax_resume_queue']);
        add_action('wp_ajax_le_reset_queue',    [$this, 'ajax_reset_queue']);
        add_action('wp_ajax_le_toggle_sync',    [$this, 'ajax_toggle_sync']);

        // Async batch handler — accessible without login (auth via secret key)
        add_action('wp_ajax_nopriv_le_async_batch', [$this, 'handle_async_batch']);
        add_action('wp_ajax_le_async_batch',        [$this, 'handle_async_batch']);

        // Incoming webhook receiver — token-secured REST endpoint
        add_action('rest_api_init', [$this, 'register_incoming_endpoint']);
        add_action('wp_ajax_le_regenerate_incoming_token', [$this, 'ajax_regenerate_incoming_token']);

        add_action(self::BATCH_CRON_HOOK,   [$this, 'process_batch']);
        add_action(self::SYNC_CRON_HOOK,    [$this, 'auto_sync_check']);
        add_action(self::CLEANUP_CRON_HOOK, [$this, 'cleanup_old_domains']);

        add_action('admin_enqueue_scripts', [$this, 'le_enqueue_scripts']);
        add_filter('cron_schedules',        [$this, 'add_cron_intervals']);

        register_activation_hook(__FILE__,   [$this, 'on_activate']);
        register_deactivation_hook(__FILE__, [$this, 'on_deactivate']);

        $this->sourceSheetId      = get_option('le_source_sheet_id', '');
        $this->targetSheetId      = get_option('le_target_sheet_id', '');
        $this->api_key            = get_option('le_api_key', '');
        $this->salesnexus_api_key = get_option('le_salesnexus_api_key', '');
        $this->salesnexus_api_url = get_option('le_salesnexus_api_url', 'https://api-beta.salesnex.us');

        // Generate async secret on first load if plugin was updated without re-activation
        if (! get_option('le_async_secret')) {
            update_option('le_async_secret', bin2hex(random_bytes(16)), false);
        }
    }

    // =========================================================
    // ✅ FIX 1: Dynamic settings getters
    // Previously constants were used, now values are read from settings
    // =========================================================

    /**
     * Reads batch size from settings.
     * This will be used in process_batch().
     */
    private function get_batch_size() {
        return max(1, (int) get_option('le_batch_size', self::DEFAULT_BATCH_SIZE));
    }

    /**
     * ✅ FIX: Reads person limit from settings.
     * Previously, get_option() was used directly in get_persons() but
     * the 'le_person_limit' key was not being saved correctly during save.
     */
    private function get_person_limit() {
        return max(1, (int) get_option('le_person_limit', 10));
    }

    // =========================================================
    // Plugin Activate / Deactivate
    // =========================================================

    public function on_activate() {
        if (! wp_next_scheduled(self::SYNC_CRON_HOOK)) {
            $interval = get_option('le_sync_interval', 'le_hourly');
            wp_schedule_event(time(), $interval, self::SYNC_CRON_HOOK);
        }
        if (! get_option('le_async_secret')) {
            update_option('le_async_secret', bin2hex(random_bytes(16)), false);
        }

        // Create processed domains DB table
        $this->create_domains_table();

        // Schedule daily cleanup cron
        if (! wp_next_scheduled(self::CLEANUP_CRON_HOOK)) {
            wp_schedule_event(time(), 'daily', self::CLEANUP_CRON_HOOK);
        }
    }

    // Create the processed domains table and migrate legacy wp_options data
    private function create_domains_table() {
        global $wpdb;
        $table   = $wpdb->prefix . self::DOMAINS_TABLE;
        $charset = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE IF NOT EXISTS {$table} (
            id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            domain       VARCHAR(255) NOT NULL,
            source       VARCHAR(20)  NOT NULL DEFAULT 'manual',
            processed_at DATETIME     NOT NULL,
            UNIQUE KEY   uk_domain (domain),
            INDEX        idx_processed_at (processed_at)
        ) {$charset};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);

        // Migrate existing domains from wp_options to the new table
        $legacy = get_option(self::PROCESSED_DOMAINS_KEY, []);
        if (! empty($legacy) && is_array($legacy)) {
            foreach ($legacy as $domain) {
                $wpdb->query($wpdb->prepare(
                    "INSERT IGNORE INTO {$table} (domain, source, processed_at) VALUES (%s, %s, %s)",
                    strtolower(trim($domain)), 'migrated', current_time('mysql')
                ));
            }
            delete_option(self::PROCESSED_DOMAINS_KEY);
            error_log('LE: Migrated ' . count($legacy) . ' domains to DB table');
        }
    }

    public function on_deactivate() {
        wp_clear_scheduled_hook(self::BATCH_CRON_HOOK);
        wp_clear_scheduled_hook(self::SYNC_CRON_HOOK);
        wp_clear_scheduled_hook(self::CLEANUP_CRON_HOOK);
    }

    // =========================================================
    // Custom Cron Intervals
    // =========================================================

    public function add_cron_intervals($schedules) {
        $schedules['le_every_2_min'] = ['interval' => 120,     'display' => 'Every 2 Minutes'];
        $schedules['le_hourly']      = ['interval' => 3600,    'display' => 'Hourly'];
        $schedules['le_daily']       = ['interval' => 86400,   'display' => 'Daily'];
        $schedules['le_weekly']      = ['interval' => 604800,  'display' => 'Weekly'];
        $schedules['le_monthly']     = ['interval' => 2592000, 'display' => 'Monthly'];
        return $schedules;
    }

    // =========================================================
    // PROCESSED DOMAINS — DB table tracking (fast, scalable)
    // =========================================================

    private function domains_table() {
        global $wpdb;
        return $wpdb->prefix . self::DOMAINS_TABLE;
    }

    // Returns total count of all processed domains
    private function count_processed_domains() {
        global $wpdb;
        return (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . $this->domains_table() );
    }

    // O(1) lookup — uses UNIQUE INDEX on domain column
    private function is_domain_processed($domain) {
        global $wpdb;
        $domain = strtolower(trim($domain));
        $result = $wpdb->get_var($wpdb->prepare(
            'SELECT COUNT(*) FROM ' . $this->domains_table() . ' WHERE domain = %s',
            $domain
        ));
        return (int) $result > 0;
    }

    // INSERT IGNORE — silently skips if domain already exists
    private function mark_domain_processed($domain, $source = 'manual') {
        global $wpdb;
        $domain = strtolower(trim($domain));
        $wpdb->query($wpdb->prepare(
            'INSERT IGNORE INTO ' . $this->domains_table() . ' (domain, source, processed_at) VALUES (%s, %s, %s)',
            $domain, $source, current_time('mysql')
        ));
    }

    // Batch check — single query for multiple domains (used in init_queue)
    private function get_processed_domains_batch(array $domains) {
        if (empty($domains)) return [];
        global $wpdb;
        $placeholders = implode(',', array_fill(0, count($domains), '%s'));
        return $wpdb->get_col(
            $wpdb->prepare(
                'SELECT domain FROM ' . $this->domains_table() . " WHERE domain IN ($placeholders)",
                ...$domains
            )
        );
    }

    // Deletes processed domain records older than the configured retention period
    public function cleanup_old_domains() {
        $days = (int) get_option('le_domain_retention_days', 90);
        if ($days <= 0) {
            error_log('LE Cleanup: retention set to never — skipping');
            return;
        }

        global $wpdb;
        $deleted = $wpdb->query($wpdb->prepare(
            'DELETE FROM ' . $this->domains_table() . ' WHERE processed_at < DATE_SUB(NOW(), INTERVAL %d DAY)',
            $days
        ));

        error_log("LE Cleanup: deleted {$deleted} domain records older than {$days} days");
    }

    // =========================================================
    // ✅ QUEUE MANAGEMENT
    // =========================================================

    private function empty_queue() {
        return [
            'status'         => 'idle',
            'source'         => 'manual',
            'current_domain' => '',
            'pending'        => [],
            'processed'      => [],
            'failed'         => [],
            'total'          => 0,
            'started_at'     => null,
            'completed_at'   => null,
            'log'            => [],   // ✅ NEW: real-time log entries
            'summary'        => [
                'total_emails'    => 0,
                'skipped_domains' => 0,
                'failed_domains'  => 0,
                'success_domains' => 0,
                'total_persons'   => 0,
                'errors'          => [],
            ],
        ];
    }

    private function get_queue() {
        $stored = get_option(self::QUEUE_KEY, null);
        if (! is_array($stored)) {
            return $this->empty_queue();
        }
        if (! isset($stored['current_domain'])) $stored['current_domain'] = '';
        if (! isset($stored['log']))            $stored['log'] = [];
        return $stored;
    }

    private function update_queue($queue) {
        if (isset($queue['log'])) {
            $cutoff = time() - (48 * HOUR_IN_SECONDS);

            // Remove entries older than 48 hours
            $queue['log'] = array_values(array_filter($queue['log'], function ($entry) use ($cutoff) {
                // Entries without 'ts' (old format) are kept to avoid data loss on upgrade
                return ! isset($entry['ts']) || $entry['ts'] >= $cutoff;
            }));
        }
        update_option(self::QUEUE_KEY, $queue, false);
    }

    /**
     * ✅ FIX 2 (Auto Sync): If append_to_existing=true is given in init_queue
     * Only new/unprocessed domains will be added.
     * Processed domains will be completely skipped.
     */
    private function init_queue($rows, $source = 'manual', $append_to_existing = false) {
        $seen_domains      = [];
        $items             = [];
        $skipped           = 0;
        $total_emails      = count($rows);
        // Batch check all unique domains at once — single DB query instead of N queries
        $unique_domains = [];
        foreach ($rows as $row) {
            $email  = trim($row[0] ?? '');
            $domain = is_email($email) ? strtolower(explode('@', $email)[1] ?? '') : '';
            if ($domain) $unique_domains[] = $domain;
        }
        $already_processed = $this->get_processed_domains_batch(array_unique($unique_domains));

        foreach ($rows as $row) {
            $email = trim($row[0] ?? '');
            if (empty($email) || ! is_email($email)) { $skipped++; continue; }

            $domain = strtolower(explode('@', $email)[1] ?? '');
            if (empty($domain)) { $skipped++; continue; }

            // ✅ Exclude permanently processed domains
            if (in_array($domain, $already_processed, true)) {
                $skipped++;
                continue;
            }

            // Exclude duplicates in this batch
            if (isset($seen_domains[$domain])) { $skipped++; continue; }

            $seen_domains[$domain] = true;
            $items[] = ['domain' => $domain, 'email' => $email, 'retries' => 0];
        }

        if ($append_to_existing) {
            $queue = $this->get_queue();

            // ✅ FIX: Also remove duplicates from existing pending
            $existing_pending_domains = array_column($queue['pending'], 'domain');
            $new_items = array_filter($items, function($item) use ($existing_pending_domains) {
                return ! in_array($item['domain'], $existing_pending_domains, true);
            });
            $new_items = array_values($new_items);

            if (empty($new_items)) {
                error_log('LE: Auto sync — No new domains found.');
                return $queue;
            }

            $queue['pending'] = array_merge($queue['pending'], $new_items);
            $queue['total']   = count($queue['pending']) + count($queue['processed']);
            $queue['summary']['total_emails']    += $total_emails;
            $queue['summary']['skipped_domains'] += $skipped;

            if (in_array($queue['status'], ['idle', 'completed', 'error'])) {
                $queue['status']     = 'running';
                $queue['source']     = $source;
                $queue['started_at'] = current_time('mysql');
            }

            $this->add_log($queue, "🔄 Auto sync: " . count($new_items) . " new domains added");
            $this->update_queue($queue);

            error_log(sprintf('LE: ✅ Appended | New: %d | Skip: %d | Pending: %d',
                count($new_items), $skipped, count($queue['pending'])));

            return $queue;
        }

        // Fresh queue
        $queue                                = $this->empty_queue();
        $queue['status']                      = 'running';
        $queue['source']                      = $source;
        $queue['pending']                     = $items;
        $queue['total']                       = count($items);
        $queue['started_at']                  = current_time('mysql');
        $queue['summary']['total_emails']     = $total_emails;
        $queue['summary']['skipped_domains']  = $skipped;

        $this->add_log($queue, "🚀 Queue started: " . count($items) . " domains");
        $this->update_queue($queue);

        error_log(sprintf('LE: Queue created | Source: %s | Domains: %d | Skipped: %d',
            $source, count($items), $skipped));

        return $queue;
    }

    // =========================================================
    // ✅ LOG HELPER — real-time processing visibility
    // =========================================================

    private function add_log(&$queue, $message) {
        $queue['log'][] = [
            'time' => current_time('H:i:s'),
            'ts'   => time(), // Unix timestamp used to prune entries older than 48 hours
            'msg'  => $message,
        ];
    }

    // =========================================================
    // ✅ BATCH PROCESSOR
    // =========================================================

    private function schedule_next_batch($delay = 5) {
        // WP-Cron registered as fallback in case the async loopback request fails
        // Extra 60s buffer so it only fires if the primary async method doesn't run
        if (! wp_next_scheduled(self::BATCH_CRON_HOOK)) {
            wp_schedule_single_event(time() + $delay + 60, self::BATCH_CRON_HOOK);
            error_log("LE: WP-Cron fallback registered (fires in " . ($delay + 60) . "s)");
        }
        // Primary: trigger next batch via async loopback (works without any site visitor)
        $this->trigger_async_batch($delay);
    }

    /**
     * Fires a non-blocking HTTP request to handle_async_batch().
     * The request closes the connection immediately, then PHP sleeps $delay
     * seconds in the background before running process_batch().
     * Works on Apache, Nginx, LiteSpeed without any server configuration.
     */
    private function trigger_async_batch($delay = 5) {
        $secret = get_option('le_async_secret');
        if (! $secret) {
            // Secret missing — generate it now and fall back to WP-Cron only
            $secret = bin2hex(random_bytes(16));
            update_option('le_async_secret', $secret, false);
        }

        $response = wp_remote_post(admin_url('admin-ajax.php'), [
            'timeout'   => 0.01,  // Fire and forget — don't wait for response
            'blocking'  => false,
            'sslverify' => apply_filters('https_local_ssl_verify', false),
            'cookies'   => [],
            'body'      => [
                'action' => 'le_async_batch',
                'secret' => $secret,
                'delay'  => (int) $delay,
            ],
        ]);

        if (is_wp_error($response)) {
            error_log('LE: Async trigger failed — WP-Cron will handle it. Error: ' . $response->get_error_message());
        } else {
            error_log("LE: Async batch triggered (delay: {$delay}s)");
        }
    }

    /**
     * Handles the async batch request.
     * Immediately closes the HTTP connection so the caller is not blocked,
     * then sleeps for the specified delay and runs process_batch() in the background.
     */
    public function handle_async_batch() {
        // Authenticate using the stored secret — no user session required
        $secret = get_option('le_async_secret', '');
        if (empty($secret) || ! hash_equals($secret, sanitize_text_field($_POST['secret'] ?? ''))) {
            wp_die('', '', ['response' => 403]);
        }

        $delay = min(60, max(0, (int) ($_POST['delay'] ?? 10)));

        // Prevent double execution if WP-Cron fires at the same time
        if (get_transient('le_async_lock')) {
            error_log('LE: Async batch skipped — another instance already running');
            exit;
        }
        set_transient('le_async_lock', 1, $delay + 120);

        // Close the HTTP connection immediately so the caller gets a response
        // PHP will continue running in the background after this point
        ignore_user_abort(true);
        @set_time_limit(300);
        header('Connection: close');
        header('Content-Encoding: none');
        header('Content-Length: 0');
        header('Content-Type: text/plain');
        if (ob_get_level()) ob_end_clean();
        flush();

        // Available on PHP-FPM (Nginx, Apache with FPM, LiteSpeed)
        if (function_exists('fastcgi_finish_request'))   fastcgi_finish_request();
        if (function_exists('litespeed_finish_request')) litespeed_finish_request();

        // Sleep for the scheduled delay before running the next batch
        if ($delay > 0) sleep($delay);

        // After sleep, check if the queue was paused or stopped during the wait
        if (get_transient('le_pause_signal') || $this->get_queue()['status'] !== 'running') {
            error_log('LE: Async batch aborted after sleep — queue was paused or stopped');
            delete_transient('le_async_lock');
            exit;
        }

        // Remove WP-Cron fallback since we are now running it directly
        wp_clear_scheduled_hook(self::BATCH_CRON_HOOK);

        $this->process_batch();

        delete_transient('le_async_lock');
        exit;
    }

    /**
     * ✅ FIX 1 (Person Limit) + FIX 3 (Visibility):
     * - dynamic batch size using get_batch_size()
     * - current_domain updated correctly
     * - status of each domain written in log
     */
    public function process_batch() {
        $queue = $this->get_queue();

        if ('running' !== $queue['status']) {
            error_log('LE: Batch call happened but status: ' . $queue['status']);
            return;
        }

        if (empty($queue['pending'])) {
            $queue['status']         = 'completed';
            $queue['current_domain'] = '';
            $queue['completed_at']   = current_time('mysql');
            $this->add_log($queue, '🏁 All processing complete!');
            $this->update_queue($queue);
            error_log('LE: ✅ All batches completed!');
            return;
        }

        @set_time_limit(300);

        // ✅ FIX: batch size is being read from settings
        $batch_size = $this->get_batch_size();
        $batch      = array_splice($queue['pending'], 0, $batch_size);
        $retry_back = [];

        error_log("LE: {$batch_size} domains will be processed in this batch");

        $destination = get_option('le_output_destination', 'salesnexus_api');
        $use_sheet   = ($destination === 'target_sheet');
        $use_snx     = ($destination === 'salesnexus_api');

        foreach ($batch as $item) {
            $domain  = $item['domain'];
            $retries = $item['retries'] ?? 0;

            // Skip if domain was processed by a parallel batch or a previous run
            if ($this->is_domain_processed($domain)) {
                $this->add_log($queue, "⏭ Skip (already done): $domain");
                continue;
            }

            $queue['current_domain'] = $domain;
            $this->add_log($queue, "🎯 Processing: $domain (attempt #{$retries})");
            $this->update_queue($queue);

            // Wrap each domain in try-catch so one failure never stops the whole batch
            try {

                // ——— Finding Company ———
                $company = $this->api_call_with_retry(function () use ($domain) {
                    return $this->get_company($domain);
                });

                if (! $company) {
                    if ($retries < self::MAX_RETRY) {
                        $item['retries']++;
                        $retry_back[] = $item;
                        $this->add_log($queue, "⚠️ Company not found, will retry: $domain");
                    } else {
                        $queue['failed'][]              = $domain;
                        $queue['processed'][]           = $domain;
                        $queue['summary']['failed_domains']++;
                        $queue['summary']['errors'][]   = "Company not found: $domain";
                        $this->mark_domain_processed($domain);
                        $this->add_log($queue, "❌ Company not found (max retries): $domain");
                    }
                    continue;
                }

                $this->add_log($queue, "🏢 Company: {$company['name']}");

                // ——— Finding Persons ———
                $persons = $this->api_call_with_retry(function () use ($company) {
                    return $this->get_persons($company['id']);
                });

                if (empty($persons)) {
                    $queue['failed'][]              = $domain;
                    $queue['processed'][]           = $domain;
                    $queue['summary']['failed_domains']++;
                    $queue['summary']['errors'][]   = "No executives found: {$company['name']}";
                    $this->mark_domain_processed($domain);
                    $this->add_log($queue, "⚠️ No executives found: {$company['name']}");
                    continue;
                }

                // ——— Collect all person rows (lookup email & phone per person) ———
                // Each person is collected regardless of whether email/phone was found —
                // partial data (name, LinkedIn) is still valuable and must be inserted
                $all_rows = [];
                foreach ($persons as $person) {
                    if (empty($person['id'])) continue;

                    $person_email = $this->lookup_email($person['id']);
                    $person_phone = $this->lookup_phone($person['id']);
                    $name         = trim(($person['firstName'] ?? '') . ' ' . ($person['lastName'] ?? ''));
                    if (empty($name)) $name = $person['name'] ?? '';

                    if (empty($name) && empty($person_email['data']['email'] ?? '')) continue;

                    // Extract current job title from experiences
                    $current_title = '';
                    foreach ($person['experiences'] ?? [] as $exp) {
                        if (! empty($exp['isCurrent'])) {
                            $current_title = $exp['title'] ?? '';
                            break;
                        }
                    }
                    if (empty($current_title)) {
                        $current_title = $person['titleAtCurrentCompany'] ?? '';
                    }

                    $all_rows[] = [
                        'company_id'   => $company['id'],
                        'company_name' => $company['name'],
                        'domain'       => $domain,
                        'person_id'    => $person['id'],
                        'name'         => $name,
                        'title'        => $current_title,
                        'linkedin'     => $person['linkedInUrl'] ?? '',
                        'experiences'  => $this->format_experiences($person['experiences'] ?? []),
                        'email'        => $person_email['data']['email'] ?? '',
                        'phone'        => $person_phone['data']['phone'] ?? '',
                    ];
                }

                $written = 0;

                // ——— Google Sheet: single batch append (1 API call for all persons) ———
                if ($use_sheet && ! empty($all_rows)) {
                    $this->ensure_header();
                    $written = $this->write_rows_to_sheet($all_rows);
                    $queue['summary']['total_persons'] += $written;
                    if ($written > 0) {
                        $this->add_log($queue, "📊 Sheet: {$written} rows written for {$domain}");
                    }
                }

                // ——— SalesNexus: one POST /Contacts per person ———
                if ($use_snx && ! empty($all_rows)) {
                    $snx_ok   = 0;
                    $snx_skip = 0;
                    foreach ($all_rows as $row_data) {
                        if ($this->create_salesnexus_contact($row_data)) {
                            $written++;
                            $snx_ok++;
                            $queue['summary']['total_persons']++;
                        } else {
                            $snx_skip++;
                        }
                    }
                    // Single log line summarising all persons for this domain
                    if ($snx_ok > 0) {
                        $this->add_log($queue, "📤 SalesNexus: {$snx_ok} contact(s) sent for {$domain}" . ($snx_skip > 0 ? " ({$snx_skip} skipped)" : ''));
                    } elseif ($snx_skip > 0) {
                        $this->add_log($queue, "⚠️ SalesNexus: all {$snx_skip} contacts skipped for {$domain}");
                    }
                }

                $queue['summary']['success_domains']++;
                $queue['processed'][] = $domain;
                $this->mark_domain_processed($domain);
                $this->add_log($queue, "✅ Done: $domain | {$written} person(s) saved");
                error_log("LE: ✅ $domain | saved: $written");

            } catch (Exception $e) {
                // Log the error and continue to the next domain — never let one domain stop the batch
                $msg = $e->getMessage();
                $queue['failed'][]            = $domain;
                $queue['processed'][]         = $domain;
                $queue['summary']['failed_domains']++;
                $queue['summary']['errors'][] = "Exception on $domain: $msg";
                $this->mark_domain_processed($domain);
                $this->add_log($queue, "❌ Error on {$domain}: {$msg}");
                error_log("LE: ❌ Exception on $domain: $msg");
            }
        }

        // Put retry items in front
        if (! empty($retry_back)) {
            $queue['pending'] = array_merge($retry_back, $queue['pending']);
        }

        $queue['current_domain'] = '';

        if (! empty($queue['pending'])) {
            $this->add_log($queue, "⏳ " . count($queue['pending']) . " domains remaining...");
            $this->update_queue($queue);
            $this->schedule_next_batch(10);
        } else {
            $queue['status']       = 'completed';
            $queue['completed_at'] = current_time('mysql');
            $this->add_log($queue, '🏁 All work done!');
            $this->update_queue($queue);
            error_log('LE: 🏁 All processing completed!');
        }
    }

    // =========================================================
    // ✅ RETRY WRAPPER
    // =========================================================

    private function api_call_with_retry(callable $callable) {
        $last_error = null;
        for ($attempt = 1; $attempt <= self::MAX_RETRY; $attempt++) {
            try {
                $result = $callable();
                if ($result !== null && $result !== false && $result !== []) {
                    return $result;
                }
            } catch (Exception $e) {
                $last_error = $e->getMessage();
                error_log("LE: ⚠️ Retry attempt $attempt: $last_error");
            }
            if ($attempt < self::MAX_RETRY) {
                $delay = self::RETRY_BASE_DELAY ** $attempt;
                sleep($delay);
            }
        }
        error_log("LE: ❌ All retries exhausted. Last error: $last_error");
        return null;
    }

    // =========================================================
    // ✅ FIX 2 (Auto Sync): Solving Restart Problem
    // append_to_existing=true → only new domains will be added
    // =========================================================

    // =========================================================
    // INCOMING WEBHOOK RECEIVER
    // =========================================================

    // Register the token-secured REST endpoint
    public function register_incoming_endpoint() {
        register_rest_route('le/v1', '/receive/(?P<token>[a-f0-9]+)', [
            'methods'             => 'POST',
            'callback'            => [$this, 'handle_incoming_webhook'],
            'permission_callback' => '__return_true',
        ]);
    }

    // Handle POST request from SalesNexus Stage Trigger
    public function handle_incoming_webhook(\WP_REST_Request $request) {
        // Verify token from URL matches stored token
        $url_token    = $request->get_url_params()['token'] ?? '';
        $stored_token = get_option('le_incoming_webhook_token', '');

        if (empty($stored_token) || ! hash_equals($stored_token, $url_token)) {
            return new \WP_REST_Response(['error' => 'Invalid token'], 401);
        }

        // Extract email from payload
        $body  = $request->get_json_params() ?? [];
        $email = sanitize_email($body['email'] ?? '');

        if (empty($email) || ! is_email($email)) {
            return new \WP_REST_Response(['error' => 'Valid email required'], 400);
        }

        // Duplicate prevention — same email within 5 minutes is silently skipped
        $dedup_key = 'le_recv_' . md5($email);
        if (get_transient($dedup_key)) {
            return new \WP_REST_Response(['status' => 'skipped', 'reason' => 'duplicate'], 200);
        }
        set_transient($dedup_key, 1, 5 * MINUTE_IN_SECONDS);

        // Add domain to existing queue (reuses current processing pipeline)
        $queue = $this->init_queue([[$email]], 'webhook', true);

        // Trigger background processing immediately
        if (! empty($queue['pending']) && $queue['status'] === 'running') {
            $this->trigger_async_batch(3);
        }

        error_log('LE Webhook: Received ' . $email . ' — queued for processing');
        return new \WP_REST_Response(['status' => 'queued', 'email' => $email], 200);
    }

    // AJAX — regenerate incoming webhook token
    public function ajax_regenerate_incoming_token() {
        if (! current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Unauthorized']);
        }
        $token = bin2hex(random_bytes(16));
        update_option('le_incoming_webhook_token', $token);
        $url = rest_url('le/v1/receive/' . $token);
        wp_send_json_success(['token' => $token, 'url' => $url]);
    }

    // =========================================================
    // AUTO SYNC
    // =========================================================

    public function auto_sync_check() {
        if (! get_option('le_auto_sync_enabled', false)) {
            return;
        }

        // Auto sync only applies to Google Sheet input — webhook input is event-driven
        if (get_option('le_input_source', 'salesnexus_webhook') !== 'google_sheet') {
            return;
        }

        error_log('LE: 🔄 Auto sync running...');

        $all_rows = $this->get_sheet_data();

        if (empty($all_rows)) {
            error_log('LE: Auto sync: sheet empty');
            return;
        }

        error_log("LE: Auto sync — total " . count($all_rows) . " rows found in sheet");

        // ✅ FIX: append_to_existing=true means — keep current queue and add new domains
        // processed domains are already permanently saved — they won't come again
        $queue = $this->init_queue($all_rows, 'auto_sync', true);

        if (! empty($queue['pending']) && $queue['status'] === 'running') {
            // Only schedule new batch when no batch is running
            $this->schedule_next_batch(5);
        }
    }

    // =========================================================
    // ✅ GOOGLE SHEETS SERVICE
    // =========================================================

    private function get_sheets_service() {
        if ($this->sheets_service) return $this->sheets_service;
        try {
            $client = new Google_Client();
            $client->setApplicationName('Lead Executives Extractor');
            $client->setScopes([\Google_Service_Sheets::SPREADSHEETS]);
            $client->setAuthConfig(__DIR__ . '/credential.json');
            $this->sheets_service = new Google_Service_Sheets($client);
        } catch (Exception $e) {
            error_log('❌ Google Sheets init failed: ' . $e->getMessage());
            return null;
        }
        return $this->sheets_service;
    }

    // =========================================================
    // ✅ SHEET DATA READ
    // =========================================================

    private function get_sheet_data() {
        $this->last_sheet_error = '';

        if (empty($this->sourceSheetId)) {
            $this->last_sheet_error = 'Source Sheet ID is not configured. Please enter it in Settings and save.';
            error_log('LE: ' . $this->last_sheet_error);
            return [];
        }

        $service = $this->get_sheets_service();
        if (! $service) {
            $this->last_sheet_error = 'Could not connect to Google Sheets. Check that credential.json exists and is valid.';
            error_log('LE: ' . $this->last_sheet_error);
            return [];
        }

        try {
            $spreadsheet = $service->spreadsheets->get($this->sourceSheetId);
            $sheets      = $spreadsheet->getSheets();

            if (empty($sheets)) {
                $this->last_sheet_error = 'No sheets found inside the spreadsheet.';
                error_log('LE: ' . $this->last_sheet_error);
                return [];
            }

            $sheetName = $sheets[0]->getProperties()->getTitle();
            $col       = strtoupper(get_option('le_column_name', 'A'));
            $range     = $sheetName . '!' . $col . ':' . $col;

            $response = $service->spreadsheets_values->get($this->sourceSheetId, $range);
            $values   = $response->getValues();

            if (empty($values) || count($values) < 2) {
                $this->last_sheet_error = 'Sheet column "' . $col . '" is empty or only has a header row. Make sure email addresses are present below the header.';
                error_log('LE: ' . $this->last_sheet_error);
                return [];
            }

            return array_slice($values, 1);

        } catch (Exception $e) {
            $msg = $e->getMessage();
            // Give a friendlier message for the most common Google API errors
            if (strpos($msg, '403') !== false) {
                $this->last_sheet_error = 'Access denied (403). Share the Source Sheet with: salesnexus@salesnexus-user-sheet.iam.gserviceaccount.com';
            } elseif (strpos($msg, '404') !== false) {
                $this->last_sheet_error = 'Sheet not found (404). Double-check the Source Sheet ID in Settings.';
            } else {
                $this->last_sheet_error = $msg;
            }
            error_log('❌ Sheet read error: ' . $msg);
            return [];
        }
    }

    // =========================================================
    // ✅ ENSURE HEADER
    // =========================================================

    private function ensure_header() {
        static $header_ensured = false;
        if ($header_ensured) return;
        try {
            $service = $this->get_sheets_service();
            if (! $service) return;
            $spreadsheet = $service->spreadsheets->get($this->targetSheetId);
            $sheetName   = $spreadsheet->getSheets()[0]->getProperties()->getTitle();
            $existing    = $service->spreadsheets_values->get($this->targetSheetId, $sheetName . '!A1:I1');
            $rows        = $existing->getValues();
            if (empty($rows) || empty($rows[0]) || ($rows[0][0] ?? '') !== 'Company ID') {
                $headers = [['Company ID','Company Name','Company Domain','Person ID','Name','LinkedIn URL','Experiences','Email','Phone']];
                $body    = new Google_Service_Sheets_ValueRange(['values' => $headers]);
                $service->spreadsheets_values->update($this->targetSheetId, $sheetName . '!A1', $body, ['valueInputOption' => 'RAW']);
            }
            $header_ensured = true;
        } catch (Exception $e) {
            error_log('❌ Header error: ' . $e->getMessage());
        }
    }

    // =========================================================
    // ✅ WRITE TO SHEET
    // =========================================================

    // Writes all rows in a single append call — replaces the old per-row read+write pattern
    private function write_rows_to_sheet(array $rows) {
        if (empty($rows)) return 0;
        try {
            $service = $this->get_sheets_service();
            if (! $service) return 0;

            $spreadsheet = $service->spreadsheets->get($this->targetSheetId);
            $sheetName   = $spreadsheet->getSheets()[0]->getProperties()->getTitle();

            $values = array_map(fn($r) => [
                $r['company_id']   ?? '',
                $r['company_name'] ?? '',
                $r['domain']       ?? '',
                $r['person_id']    ?? '',
                $r['name']         ?? '',
                $r['linkedin']     ?? '',
                $r['experiences']  ?? '',
                $r['email']        ?? '',
                $r['phone']        ?? '',
            ], $rows);

            // append finds the next empty row automatically — no read needed
            $body   = new Google_Service_Sheets_ValueRange(['values' => $values]);
            $result = $service->spreadsheets_values->append(
                $this->targetSheetId,
                $sheetName . '!A:I',
                $body,
                ['valueInputOption' => 'RAW', 'insertDataOption' => 'INSERT_ROWS']
            );

            return $result->getUpdates()->getUpdatedRows() ?? count($rows);
        } catch (Exception $e) {
            error_log('LE: Sheet batch write error: ' . $e->getMessage());
            return 0;
        }
    }

    // =========================================================
    // SALESNEXUS API
    // =========================================================

    private function get_salesnexus_headers() {
        return [
            'Content-Type' => 'application/json',
            'Accept'       => 'application/json',
            'X-Api-Key'    => $this->salesnexus_api_key,
        ];
    }

    // Creates or updates a SalesNexus contact via the upsert-contact webhook.
    // Webhook auto-deduplicates by email, name auto-splits, no X-Api-Key needed.
    private function create_salesnexus_contact(array $row_data) {
        $token = get_option('le_salesnexus_webhook_token', '');
        if (empty($token)) {
            error_log('LE SalesNexus: Webhook token not configured — add it in Settings');
            return false;
        }

        $email = trim($row_data['email'] ?? '');
        if (empty($email)) {
            error_log('LE SalesNexus: Skipped (no email) — ' . ($row_data['name'] ?? 'unknown'));
            return false;
        }

        // Only include non-empty values — webhook skips empty fields without overwriting existing data
        $body = array_filter([
            'email'      => $email,
            'name'       => $row_data['name']        ?? '',
            'phone'      => $row_data['phone']        ?? '',
            'linkedin'   => $row_data['linkedin']     ?? '',
            'company'    => $row_data['company_name'] ?? '',
            'job_title'  => $row_data['title']        ?? '',
            'leadSource' => get_option('le_salesnexus_lead_source', '') ?: 'Lead Extractor',
            'idstatus'   => get_option('le_salesnexus_id_status',   '') ?: 'Suspect',
        ], fn($v) => $v !== '');

        $webhook_url = rtrim($this->salesnexus_api_url, '/') . '/api/v1/hooks/' . $token;

        $response = wp_remote_post($webhook_url, [
            'headers' => ['Content-Type' => 'application/json'],
            'body'    => wp_json_encode($body),
            'timeout' => 20,
        ]);

        if (is_wp_error($response)) {
            error_log('LE SalesNexus: ' . $response->get_error_message());
            return false;
        }

        $code = wp_remote_retrieve_response_code($response);
        if ($code === 200) {
            $data = json_decode(wp_remote_retrieve_body($response), true);
            error_log('LE SalesNexus: Contact ' . ($data['action'] ?? 'processed') . ' ID:' . ($data['contactId'] ?? '?') . ' — ' . ($row_data['name'] ?? ''));
            return true;
        }

        error_log('LE SalesNexus: HTTP ' . $code . ' — ' . wp_remote_retrieve_body($response));
        return false;
    }


    // =========================================================
    // ✅ FIX 1 (Person Limit): Use get_person_limit() in get_persons()
    // Before: get_option('le_person_limit', 10) → was working but
    // problems due to not being sure about save.
    // Now: get_person_limit() method → will always give correct value.
    // =========================================================

    private function get_company($domain) {
        $response = wp_remote_post('https://api.profileapi.com/2024-03-01/companies/find', [
            'headers' => [
                'Authorization' => 'ApiKey ' . $this->api_key,
                'Content-Type'  => 'application/json',
                'Accept'        => 'application/json',
            ],
            'body'    => wp_json_encode([
                'filters'  => ['all' => [['key' => 'website', 'value' => $domain, 'operator' => '=']]],
                'datasets' => ['all'],
                'limit'    => 1,
            ]),
            'timeout' => 20,
        ]);
        if (is_wp_error($response)) throw new Exception('WP Error: ' . $response->get_error_message());
        $body   = json_decode(wp_remote_retrieve_body($response), true);
        $result = $body['data']['results'][0] ?? null;
        if (! $result) return null;
        return ['id' => $result['id'] ?? '', 'name' => $result['name'] ?? $domain];
    }

    private function get_persons($company_id) {
        $titles       = get_option('le_job_titles', []);
        
        // ✅ FIX: Now using get_person_limit() method
        $person_limit = $this->get_person_limit();
        
        error_log("LE: Person limit: {$person_limit} (company: {$company_id})");

        $filters_any = array_map(
            fn($t) => ['key' => 'titleAtCurrentCompany', 'value' => $t, 'operator' => '~'],
            $titles
        );

        $response = wp_remote_post('https://api.profileapi.com/2024-03-01/persons/find', [
            'headers' => [
                'Authorization' => 'ApiKey ' . $this->api_key,
                'Content-Type'  => 'application/json',
                'Accept'        => 'application/json',
            ],
            'body'    => wp_json_encode([
                'filters'  => [
                    'all' => [['key' => 'currentCompanyId', 'value' => $company_id, 'operator' => '=']],
                    'any' => $filters_any,
                ],
                'datasets' => ['basic'],
                'limit'    => $person_limit,  // ✅ Now works correctly
            ]),
            'timeout' => 30,
        ]);

        if (is_wp_error($response)) throw new Exception($response->get_error_message());

        $data = json_decode(wp_remote_retrieve_body($response), true);
        if (empty($data['data']['results'])) return [];

        $full_persons = [];
        foreach ($data['data']['results'] as $person) {
            $linkedin_url = $person['linkedInUrl'] ?? '';
            if (empty($linkedin_url)) { $full_persons[] = $person; continue; }
            try {
                $full_response = wp_remote_post('https://api.profileapi.com/2024-03-01/persons/find', [
                    'headers' => [
                        'Authorization' => 'ApiKey ' . $this->api_key,
                        'Content-Type'  => 'application/json',
                        'Accept'        => 'application/json',
                    ],
                    'body'    => wp_json_encode([
                        'filters'  => ['all' => [['key' => 'linkedInUrl', 'value' => $linkedin_url, 'operator' => '=']]],
                        'datasets' => ['all'],
                        'limit'    => 1,
                    ]),
                    'timeout' => 30,
                ]);
                if (is_wp_error($full_response)) throw new Exception($full_response->get_error_message());
                $full_data      = json_decode(wp_remote_retrieve_body($full_response), true);
                $full_person    = $full_data['data']['results'][0] ?? null;
                $full_persons[] = $full_person ?? $person;
            } catch (Exception $e) {
                error_log('LE: ⚠️ Full person fetch failed: ' . $e->getMessage());
                $full_persons[] = $person;
            }
        }
        return $full_persons;
    }

    // =========================================================
    // ✅ EMAIL & PHONE LOOKUP
    // =========================================================

    private function lookup_email($person_id) {
        try {
            $response = wp_remote_post('https://api.profileapi.com/2024-03-01/email-contacts/lookup', [
                'headers' => ['Authorization' => 'ApiKey ' . $this->api_key, 'Content-Type' => 'application/json', 'Accept' => 'application/json'],
                'body'    => wp_json_encode(['type' => 'professional', 'id' => $person_id]),
                'timeout' => 30,
            ]);
            if (is_wp_error($response)) return [];
            return json_decode(wp_remote_retrieve_body($response), true) ?? [];
        } catch (Exception $e) { return []; }
    }

    private function lookup_phone($person_id) {
        try {
            $response = wp_remote_post('https://api.profileapi.com/2024-03-01/phone-contacts/lookup', [
                'headers' => ['Authorization' => 'ApiKey ' . $this->api_key, 'Content-Type' => 'application/json', 'Accept' => 'application/json'],
                'body'    => wp_json_encode(['id' => $person_id]),
                'timeout' => 30,
            ]);
            if (is_wp_error($response)) return [];
            return json_decode(wp_remote_retrieve_body($response), true) ?? [];
        } catch (Exception $e) { return []; }
    }

    // =========================================================
    // ✅ FORMAT EXPERIENCES
    // =========================================================

    private function format_experiences($experiences) {
        if (empty($experiences)) return '';
        $parts = [];
        foreach ($experiences as $exp) {
            $title   = $exp['title'] ?? '';
            $company = $exp['name']  ?? '';
            $current = ! empty($exp['isCurrent']) ? ' (Current)' : '';
            if ($title || $company) {
                $parts[] = trim("$title at $company") . $current;
            }
        }
        return implode(' | ', $parts);
    }

    // =========================================================
    // ✅ AJAX HANDLERS
    // =========================================================

    public function ajax_run() {
        $queue = $this->get_queue();
        if ('running' === $queue['status']) {
            wp_send_json_success(['message' => 'Queue is already running.', 'queue' => $this->format_progress($queue)]);
            return;
        }
        $rows = $this->get_sheet_data();
        if (empty($rows)) {
            $reason = $this->last_sheet_error ?: 'Source sheet is empty or cannot be read.';
            wp_send_json_error(['message' => '❌ ' . $reason]);
            return;
        }
        $queue = $this->init_queue($rows, 'manual', false);
        $this->schedule_next_batch(3);
        wp_send_json_success(['message' => '✅ Queue started: ' . $queue['total'] . ' unique domains.', 'queue' => $this->format_progress($queue)]);
    }

    public function ajax_get_progress() {
        $queue = $this->get_queue();
        wp_send_json_success($this->format_progress($queue));
    }

    public function ajax_pause_queue() {
        $queue = $this->get_queue();
        if ('running' === $queue['status']) {
            $queue['status']         = 'paused';
            $queue['current_domain'] = '';
            $this->add_log($queue, '⏸ Queue paused');
            $this->update_queue($queue);
            wp_clear_scheduled_hook(self::BATCH_CRON_HOOK);
            // Signal any sleeping async batch to abort when it wakes up
            set_transient('le_pause_signal', 1, 300);
        }
        wp_send_json_success(['message' => 'Queue paused.']);
    }

    public function ajax_resume_queue() {
        $queue = $this->get_queue();
        if ('paused' === $queue['status']) {
            $queue['status'] = 'running';
            $this->add_log($queue, '▶️ Queue resumed');
            $this->update_queue($queue);
            // Clear pause signal and async lock so the new batch can start immediately
            delete_transient('le_pause_signal');
            delete_transient('le_async_lock');
            $this->schedule_next_batch(3);
        }
        wp_send_json_success(['message' => 'Queue resumed.']);
    }

    public function ajax_reset_queue() {
        delete_option(self::QUEUE_KEY);
        delete_option(self::PROCESSED_DOMAINS_KEY);
        wp_clear_scheduled_hook(self::BATCH_CRON_HOOK);
        wp_send_json_success(['message' => '✅ Queue and all history deleted.']);
    }

    public function ajax_toggle_sync() {
        $current = get_option('le_auto_sync_enabled', false);
        $new_val = ! $current;
        update_option('le_auto_sync_enabled', $new_val);
        if ($new_val) {
            if (! wp_next_scheduled(self::SYNC_CRON_HOOK)) {
                $interval = get_option('le_sync_interval', 'le_hourly');
                wp_schedule_event(time(), $interval, self::SYNC_CRON_HOOK);
            }
            wp_send_json_success(['enabled' => true,  'message' => '✅ Auto Sync enabled.']);
        } else {
            wp_clear_scheduled_hook(self::SYNC_CRON_HOOK);
            wp_send_json_success(['enabled' => false, 'message' => '⏸ Auto Sync disabled.']);
        }
    }

    public function ajax_save_settings() {
        if (! check_ajax_referer('le_save_settings', '_wpnonce', false)) {
            wp_send_json_error(['message' => 'Invalid nonce']);
            return;
        }
        if (! current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Unauthorized access']);
            return;
        }

        $input_source = sanitize_text_field($_POST['le_input_source'] ?? 'salesnexus_webhook');
        if (! in_array($input_source, ['google_sheet', 'salesnexus_webhook'], true)) {
            $input_source = 'google_sheet';
        }
        update_option('le_input_source', $input_source);

        update_option('le_source_sheet_id', sanitize_text_field($_POST['le_source_sheet_id'] ?? ''));
        update_option('le_target_sheet_id', sanitize_text_field($_POST['le_target_sheet_id'] ?? ''));
        update_option('le_api_key',         sanitize_text_field($_POST['le_api_key']         ?? ''));
        update_option('le_column_name',     strtoupper(sanitize_text_field($_POST['le_column_name'] ?? 'A')));
        $batch_size = max(1, min(20, absint($_POST['le_batch_size'] ?? 0)));
        update_option('le_batch_size', $batch_size ?: self::DEFAULT_BATCH_SIZE);

        $retention = absint($_POST['le_domain_retention_days'] ?? 90);
        update_option('le_domain_retention_days', in_array($retention, [0, 30, 60, 90, 180, 365], true) ? $retention : 90);

        $person_limit = max(1, min(50, absint($_POST['le_person_limit'] ?? 0)));
        update_option('le_person_limit', $person_limit ?: 10);

        error_log("LE: Settings saved — batch_size: " . get_option('le_batch_size') . " | person_limit: " . get_option('le_person_limit'));

        $interval = sanitize_text_field($_POST['le_sync_interval'] ?? 'le_hourly');
        update_option('le_sync_interval', $interval);
        wp_clear_scheduled_hook(self::SYNC_CRON_HOOK);
        if (get_option('le_auto_sync_enabled', false)) {
            wp_schedule_event(time(), $interval, self::SYNC_CRON_HOOK);
        }

        $job_titles = array_filter(array_map('trim', explode(',', sanitize_text_field($_POST['le_job_titles'] ?? ''))));
        update_option('le_job_titles', array_values($job_titles));

        // Output destination and SalesNexus credentials
        $destination = sanitize_text_field($_POST['le_output_destination'] ?? 'salesnexus_api');
        if (! in_array($destination, ['target_sheet', 'salesnexus_api'], true)) {
            $destination = 'salesnexus_api';
        }
        update_option('le_output_destination',      $destination);
        update_option('le_salesnexus_api_key',          sanitize_text_field($_POST['le_salesnexus_api_key']          ?? ''));
        update_option('le_salesnexus_api_url',          esc_url_raw($_POST['le_salesnexus_api_url']                  ?? 'https://api-beta.salesnex.us'));
        // Accept full URL or just the token — extract token if full URL is pasted
        $raw_token = sanitize_text_field($_POST['le_salesnexus_webhook_token'] ?? '');
        if (strpos($raw_token, '/api/v1/hooks/') !== false) {
            $parts     = explode('/api/v1/hooks/', $raw_token);
            $raw_token = trim($parts[1] ?? '');
        }
        update_option('le_salesnexus_webhook_token', $raw_token);
        update_option('le_salesnexus_lead_source',  sanitize_text_field($_POST['le_salesnexus_lead_source']  ?? 'Lead Extractor'));
        update_option('le_salesnexus_id_status',    sanitize_text_field($_POST['le_salesnexus_id_status']    ?? 'Suspect'));

        wp_send_json_success(['message' => '✅ Settings saved!']);
    }

    // =========================================================
    // ✅ FORMAT PROGRESS — with log
    // =========================================================

    private function format_progress($queue) {
        $total   = max(1, $queue['total']);
        $done    = count($queue['processed']);
        $percent = min(100, round(($done / $total) * 100));
        $processed_all_time = $this->count_processed_domains();

        return [
            'status'             => $queue['status'],
            'source'             => $queue['source'] ?? 'manual',
            'current_domain'     => $queue['current_domain'] ?? '',
            'total'              => $queue['total'],
            'done'               => $done,
            'pending'            => count($queue['pending']),
            'failed'             => count($queue['failed']),
            'percent'            => $percent,
            'started_at'         => $queue['started_at'] ?? '',
            'completed_at'       => $queue['completed_at'] ?? '',
            'processed_all_time' => $processed_all_time,
            'log'                => array_slice(array_reverse($queue['log'] ?? []), 0, 20),
            'summary'            => $queue['summary'],
            'person_limit'       => $this->get_person_limit(),
            'batch_size'         => $this->get_batch_size(),
            // Unix timestamp of next scheduled auto sync — used by JS for live countdown
            'next_sync'          => (int) wp_next_scheduled(self::SYNC_CRON_HOOK),
            'sync_enabled'       => (bool) get_option('le_auto_sync_enabled', false),
        ];
    }

    // =========================================================
    // ✅ ADMIN PAGE
    // =========================================================

    public function menu() {
        add_menu_page('Lead Extractor', 'Lead Extractor', 'manage_options', 'lead-extractor-ultra', [$this, 'admin_page'], 'dashicons-clipboard', 6);
    }

    public function admin_page() {
        $source_id          = esc_attr(get_option('le_source_sheet_id', ''));
        $target_id          = esc_attr(get_option('le_target_sheet_id', ''));
        $api_key            = esc_attr(get_option('le_api_key', ''));
        $col_name           = esc_attr(get_option('le_column_name', 'A'));
        $job_titles         = esc_attr(implode(', ', get_option('le_job_titles', [])));
        $batch_size         = esc_attr(get_option('le_batch_size')   ?: self::DEFAULT_BATCH_SIZE);
        $person_limit       = esc_attr(get_option('le_person_limit') ?: 10);
        $sync_enabled       = get_option('le_auto_sync_enabled', false);
        $sync_interval      = esc_attr(get_option('le_sync_interval', 'le_hourly'));
        $next_sync          = wp_next_scheduled(self::SYNC_CRON_HOOK);
        $input_source       = get_option('le_input_source', 'salesnexus_webhook');
        $incoming_token     = get_option('le_incoming_webhook_token', '');
        $incoming_url       = $incoming_token ? rest_url('le/v1/receive/' . $incoming_token) : '';
        $output_destination = get_option('le_output_destination', 'salesnexus_api');
        $snx_api_key        = esc_attr(get_option('le_salesnexus_api_key',         ''));
        $snx_api_url        = esc_attr(get_option('le_salesnexus_api_url',         'https://api-beta.salesnex.us'));
        $snx_webhook_token  = esc_attr(get_option('le_salesnexus_webhook_token',   ''));
        $snx_lead_source    = esc_attr(get_option('le_salesnexus_lead_source', 'Lead Extractor'));
        $snx_id_status      = esc_attr(get_option('le_salesnexus_id_status',   'Suspect'));

        $queue    = $this->get_queue();
        $progress = $this->format_progress($queue);
?>
<div class="wrap le-wrap">
    <h1>🔥 SalesNexus Lead Extractor</h1>

    <div class="le-grid">
        <!-- LEFT COLUMN -->
        <div class="le-col-left">

            <!-- SETTINGS -->
            <div class="le-card">
                <h2 class="le-card-title">⚙️ Settings</h2>

                <!-- Input Source selector -->
                <div class="le-field-group">
                    <label class="le-field-label" for="le_input_source">📥 Input Source</label>
                    <select id="le_input_source">
                        <option value="google_sheet"       <?php selected($input_source, 'google_sheet'); ?>>Google Sheet</option>
                        <option value="salesnexus_webhook" <?php selected($input_source, 'salesnexus_webhook'); ?>>SalesNexus Webhook</option>
                    </select>
                </div>

                <!-- Google Sheet input fields -->
                <div id="googleSheetInputFields" style="display:<?php echo $input_source === 'google_sheet' ? 'block' : 'none'; ?>">
                    <div class="le-field-group">
                        <label class="le-field-label" for="le_source_sheet_id">📄 Source Sheet ID</label>
                        <div class="le-field-row">
                            <input type="text" id="le_source_sheet_id" value="<?php echo $source_id; ?>" placeholder="Google Sheet ID" />
                            <?php if ($source_id): ?>
                                <a class="le-open-link" href="https://docs.google.com/spreadsheets/d/<?php echo $source_id; ?>/edit" target="_blank">🔗 Open</a>
                            <?php endif; ?>
                        </div>
                        <div class="le-field-notice">
                            ⚠️ This sheet must be shared with:
                            <code>salesnexus@salesnexus-user-sheet.iam.gserviceaccount.com</code>
                        </div>
                    </div>
                </div>

                <!-- SalesNexus Webhook input fields -->
                <div id="snxWebhookInputFields" style="display:<?php echo $input_source === 'salesnexus_webhook' ? 'block' : 'none'; ?>">
                    <div class="le-field-group">
                        <label class="le-field-label">🔗 Your Webhook URL</label>
                        <p class="le-field-hint">Copy this URL into SalesNexus → Stage Trigger → Post Webhook → URL field</p>
                        <?php if ($incoming_url): ?>
                            <div class="le-field-row">
                                <input type="text" id="le_incoming_url" value="<?php echo esc_attr($incoming_url); ?>" readonly class="le-input-full" />
                                <button type="button" id="copyIncomingUrl" class="button">📋 Copy</button>
                            </div>
                            <p class="le-field-hint" style="color:#888">Payload to use in SalesNexus: <code>{"email":"{{email}}","name":"{{full_name}}","phone":"{{phone}}"}</code></p>
                        <?php else: ?>
                            <p id="snxNoUrlMsg" class="le-field-hint" style="color:#d63638">⚠️ No URL yet — click Generate below</p>
                        <?php endif; ?>
                        <button type="button" id="regenerateIncomingToken" class="button button-secondary" style="margin-top:8px">
                            🔄 <?php echo $incoming_url ? 'Regenerate URL' : 'Generate URL'; ?>
                        </button>
                        <p class="le-field-hint" style="color:#d63638">⚠️ Regenerating will break existing SalesNexus triggers using the old URL</p>
                    </div>
                </div>

                <!-- Output destination selector -->
                <div class="le-field-group">
                    <label class="le-field-label" for="le_output_destination">📤 Output Destination</label>
                    <select id="le_output_destination">
                        <option value="salesnexus_api" <?php selected($output_destination, 'salesnexus_api'); ?>>SalesNexus API</option>
                        <option value="target_sheet"   <?php selected($output_destination, 'target_sheet');   ?>>Target Sheet (Google Sheet)</option>
                    </select>
                </div>

                <!-- SalesNexus API fields — shown when SalesNexus API is selected -->
                <div id="snxFields" style="display:<?php echo $output_destination === 'salesnexus_api' ? 'block' : 'none'; ?>">
                    <div class="le-field-group">
                        <label class="le-field-label" for="le_salesnexus_webhook_token">🔗 SalesNexus Webhook Token</label>
                        <div class="le-field-row">
                            <input type="password" id="le_salesnexus_webhook_token" value="<?php echo $snx_webhook_token; ?>" placeholder="Paste token or full URL — e.g. https://api-beta.salesnex.us/api/v1/hooks/YOUR_TOKEN" class="le-input-full" />
                            <a href="#" id="toggleSnxToken" class="le-open-link">👁 Show/Hide</a>
                        </div>
                        <p class="le-field-hint">Get from SalesNexus → Settings → Webhooks → Insert or update a contact</p>
                    </div>
                    <div class="le-field-group">
                        <label class="le-field-label" for="le_salesnexus_api_url">🌐 SalesNexus API URL</label>
                        <input type="text" id="le_salesnexus_api_url" value="<?php echo $snx_api_url; ?>" class="le-input-full" />
                    </div>
                    <div class="le-field-group">
                        <label class="le-field-label" for="le_salesnexus_lead_source">📋 Lead Source</label>
                        <input type="text" id="le_salesnexus_lead_source" value="<?php echo $snx_lead_source; ?>" placeholder="Lead Extractor" class="le-input-full" />
                    </div>
                    <div class="le-field-group">
                        <label class="le-field-label" for="le_salesnexus_id_status">🏷️ ID / Status</label>
                        <input type="text" id="le_salesnexus_id_status" value="<?php echo $snx_id_status; ?>" placeholder="Suspect" class="le-input-full" />
                        <p class="le-field-hint">Predefined values: Suspect, Client, Partner, Referral, Contractor, Employee, etc.</p>
                    </div>
                </div>

                <!-- Target Sheet fields — shown when Target Sheet is selected -->
                <div id="targetSheetFields" style="display:<?php echo $output_destination === 'target_sheet' ? 'block' : 'none'; ?>">
                    <div class="le-field-group">
                        <label class="le-field-label" for="le_target_sheet_id">📝 Target Sheet ID</label>
                        <div class="le-field-row">
                            <input type="text" id="le_target_sheet_id" value="<?php echo $target_id; ?>" placeholder="Google Sheet ID" />
                            <?php if ($target_id): ?>
                                <a class="le-open-link" href="https://docs.google.com/spreadsheets/d/<?php echo $target_id; ?>/edit" target="_blank">🔗 Open</a>
                            <?php endif; ?>
                        </div>
                        <div class="le-field-notice">
                            ⚠️ Target sheet must also be shared with:
                            <code>salesnexus@salesnexus-user-sheet.iam.gserviceaccount.com</code>
                        </div>
                    </div>
                </div>

                <div class="le-field-group">
                    <label class="le-field-label" for="le_api_key">🔑 Profile API Key</label>
                    <div class="le-field-row">
                        <input type="password" id="le_api_key" value="<?php echo $api_key; ?>" placeholder="Your ProfileAPI secret key" />
                        <a href="#" id="toggleApiKey" class="le-open-link">👁 Show/Hide</a>
                    </div>
                </div>

                <div class="le-field-group" id="emailColumnField" style="display:<?php echo $input_source === 'google_sheet' ? 'block' : 'none'; ?>">
                    <label class="le-field-label" for="le_column_name">📧 Email Column</label>
                    <input type="text" id="le_column_name" value="<?php echo $col_name; ?>" maxlength="3" placeholder="A" class="le-input-short" />
                </div>

                <div class="le-field-group">
                    <label class="le-field-label" for="le_job_titles">🎯 Job Titles (comma separated)</label>
                    <input type="text" id="le_job_titles" value="<?php echo $job_titles; ?>" placeholder="ceo, cto, cfo, vp, director" class="le-input-full" />
                </div>

                <div class="le-field-group">
                    <label class="le-field-label" for="le_person_limit">👥 Person Limit per Domain</label>
                    <div class="le-field-row">
                        <input type="number" id="le_person_limit" value="<?php echo $person_limit; ?>" min="1" max="50" class="le-input-short" />
                        <span class="le-field-unit">persons / domain</span>
                    </div>
                    <p class="le-field-hint">
                        Maximum number of executives to fetch from each company. Current: <strong id="currentPersonLimit"><?php echo $person_limit; ?></strong>
                    </p>
                </div>

                <div class="le-field-group">
                    <label class="le-field-label" for="le_batch_size">⚙️ Batch Size</label>
                    <div class="le-field-row">
                        <input type="number" id="le_batch_size" value="<?php echo $batch_size; ?>" min="1" max="20" class="le-input-short" />
                        <span class="le-field-unit">domains / batch</span>
                    </div>
                </div>

                <div class="le-field-group">
                    <label class="le-field-label" for="le_domain_retention_days">🗑️ Domain History Retention</label>
                    <select id="le_domain_retention_days">
                        <?php
                        $retention = (int) get_option('le_domain_retention_days', 90);
                        $options   = [30 => '30 Days', 60 => '60 Days', 90 => '90 Days (Recommended)', 180 => '180 Days', 365 => '1 Year', 0 => 'Never Delete'];
                        foreach ($options as $val => $label): ?>
                            <option value="<?php echo $val; ?>" <?php selected($retention, $val); ?>><?php echo $label; ?></option>
                        <?php endforeach; ?>
                    </select>
                    <p class="le-field-hint">Processed domains older than this will be deleted automatically. Deleted domains can be re-processed.</p>
                </div>

                <div class="le-field-group" id="syncIntervalField" style="display:<?php echo $input_source === 'google_sheet' ? 'block' : 'none'; ?>">
                    <label class="le-field-label" for="le_sync_interval">🕐 Auto Sync Interval</label>
                    <select id="le_sync_interval">
                        <option value="le_every_2_min" <?php selected($sync_interval, 'le_every_2_min'); ?>>Every 2 Minutes</option>
                        <option value="le_hourly"      <?php selected($sync_interval, 'le_hourly'); ?>>Hourly</option>
                        <option value="le_daily"       <?php selected($sync_interval, 'le_daily'); ?>>Daily</option>
                        <option value="le_weekly"      <?php selected($sync_interval, 'le_weekly'); ?>>Weekly</option>
                        <option value="le_monthly"     <?php selected($sync_interval, 'le_monthly'); ?>>Monthly</option>
                    </select>
                </div>

                <div class="le-actions">
                    <button id="saveBtn" class="button button-primary">💾 Save Settings</button>
                    <span id="saveStatus" class="le-save-status"></span>
                </div>
            </div>

            <!-- AUTO SYNC — only shown for Google Sheet input source -->
            <div class="le-card" id="autoSyncCard" style="display:<?php echo $input_source === 'google_sheet' ? 'block' : 'none'; ?>">
                <h2 class="le-card-title">🔄 Auto Sync</h2>
                <div class="le-sync-info">
                    <div class="le-sync-row">
                        <span>Status:</span>
                        <span id="syncStatus" class="le-badge <?php echo $sync_enabled ? 'le-badge-running' : 'le-badge-idle'; ?>">
                            <?php echo $sync_enabled ? 'Enabled' : 'Disabled'; ?>
                        </span>
                        <button id="toggleSyncBtn" class="button <?php echo $sync_enabled ? 'button-secondary' : 'button-primary'; ?>">
                            <?php echo $sync_enabled ? '⏸ Disable Sync' : '▶️ Enable Sync'; ?>
                        </button>
                    </div>
                    <div class="le-sync-row">
                        <span>Next Sync:</span>
                        <strong id="syncCountdown" class="le-sync-countdown"><?php echo $next_sync ? 'in ' . human_time_diff($next_sync) : 'Not scheduled'; ?></strong>
                    </div>
                </div>
                <p class="le-note">
                    ℹ️ When Auto Sync is enabled — new domains added to Source Sheet will be automatically added to queue.
                    Already processed domains will not be processed again.
                </p>
            </div>

            <!-- MANUAL RUN -->
            <div class="le-card">
                <h2 class="le-card-title">🚀 Manual Run</h2>

                <div class="le-status-bar">
                    <span>Status:</span>
                    <span id="queueStatus" class="le-badge le-badge-<?php echo esc_attr($queue['status']); ?>">
                        <?php echo esc_html(strtoupper($queue['status'])); ?>
                    </span>
                    <span class="le-source-badge">via <?php echo esc_html($queue['source'] ?? 'manual'); ?></span>
                </div>

                <!-- ✅ Current Domain — always visible -->
                <div class="le-current-domain" id="currentDomainBox" style="<?php echo empty($progress['current_domain']) ? 'display:none;' : ''; ?>">
                    🎯 Currently processing: <strong id="currentDomainText"><?php echo esc_html($progress['current_domain']); ?></strong>
                </div>

                <!-- Progress Bar -->
                <div class="le-progress-wrap">
                    <div class="le-progress-bar">
                        <div class="le-progress-fill" id="progressFill" style="width: <?php echo $progress['percent']; ?>%"></div>
                    </div>
                    <span class="le-progress-label" id="progressLabel">
                        <?php echo $progress['done']; ?> / <?php echo $progress['total']; ?> (<?php echo $progress['percent']; ?>%)
                    </span>
                </div>

                <!-- Stats -->
                <div class="le-stats" id="statsRow">
                    <div class="le-stat">
                        <span class="le-stat-num" id="statPersons"><?php echo $progress['summary']['total_persons']; ?></span>
                        <span class="le-stat-label">Persons Written</span>
                    </div>
                    <div class="le-stat">
                        <span class="le-stat-num" id="statSuccess"><?php echo $progress['summary']['success_domains']; ?></span>
                        <span class="le-stat-label">✅ Domains OK</span>
                    </div>
                    <div class="le-stat">
                        <span class="le-stat-num" id="statFailed"><?php echo $progress['summary']['failed_domains']; ?></span>
                        <span class="le-stat-label">❌ Failed</span>
                    </div>
                    <div class="le-stat">
                        <span class="le-stat-num" id="statPending"><?php echo $progress['pending']; ?></span>
                        <span class="le-stat-label">⏳ Pending</span>
                    </div>
                </div>

                <div class="le-lifetime-stat">
                    📊 All-time processed domains: <strong id="statAllTime"><?php echo $progress['processed_all_time']; ?></strong>
                    &nbsp;|&nbsp; Person Limit: <strong id="statPersonLimit"><?php echo $progress['person_limit']; ?></strong>
                    &nbsp;|&nbsp; Batch Size: <strong><?php echo $progress['batch_size']; ?></strong>
                </div>

                <!-- Buttons -->
                <div class="le-btn-group">
                    <button id="runBtn" class="button button-primary button-large">🚀 Run All Emails</button>
                    <button id="pauseBtn" class="button button-secondary">⏸ Pause</button>
                    <button id="resumeBtn" class="button button-secondary">▶️ Resume</button>
                    <button id="resetBtn" class="button" style="color:#c0392b;">🗑 Reset Queue and History</button>
                </div>

                <!-- Auto Sync countdown shown above the activity log -->
                <?php if (get_option('le_auto_sync_enabled', false)): ?>
                <div class="le-sync-bar">
                    <span class="le-sync-bar-label">🔄 Next Auto Sync:</span>
                    <strong class="le-sync-countdown"><?php echo $next_sync ? 'in ' . human_time_diff($next_sync) : 'Not scheduled'; ?></strong>
                </div>
                <?php endif; ?>

                <!-- ✅ NEW: Real-time Activity Log -->
                <div class="le-log-box" id="activityLog">
                    <div class="le-log-header">📋 Activity Log (Latest)</div>
                    <div class="le-log-body" id="logBody">
                        <?php foreach ($progress['log'] as $entry): ?>
                            <div class="le-log-entry">
                                <span class="le-log-time"><?php echo esc_html($entry['time']); ?></span>
                                <span class="le-log-msg"><?php echo esc_html($entry['msg']); ?></span>
                            </div>
                        <?php endforeach; ?>
                        <?php if (empty($progress['log'])): ?>
                            <div class="le-log-empty">No activity</div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Error Box -->
                <div id="errorBox" class="le-error-box" style="display:none;">
                    <h4>⚠️ Errors</h4>
                    <ul id="errorList"></ul>
                </div>
            </div>

            

        </div><!-- /le-col-left -->

        <!-- RIGHT COLUMN -->
        <div class="le-col-right">
            <div class="le-card le-guide">
                <h2 class="le-card-title">� Field Details</h2>

                <div class="le-guide-section">
                    <p class="le-guide-title">📄 Source Sheet ID</p>
                    <div class="le-guide-text">
                        The Google Sheet ID containing the list of emails to process.<br>
                        Find it in the sheet URL between <code>/d/</code> and <code>/edit</code>.<br>
                        Example: <code>1BxiMVs0XRA5nFMdKvBdBZjgmUUqptlbs74OgvE2upms</code><br>
                        Must be shared with: <code>salesnexus@salesnexus-user-sheet.iam.gserviceaccount.com</code>
                    </div>
                </div>

                <div class="le-guide-section">
                    <p class="le-guide-title">📤 Output Destination</p>
                    <div class="le-guide-text">
                        Where extracted lead data will be sent after processing.<br><br>
                        <strong>SalesNexus API</strong> — Contacts are created directly in your SalesNexus CRM.
                        Each contact gets: First Name, Last Name, Email, Phone, LinkedIn URL, Experiences, Company Name, Source Domain.<br><br>
                        <strong>Target Sheet (Google Sheet)</strong> — Data is written to a second Google Sheet in a structured table format.
                    </div>
                </div>

                <div class="le-guide-section">
                    <p class="le-guide-title">🔑 SalesNexus API Key</p>
                    <div class="le-guide-text">
                        Your SalesNexus secret API key used to authenticate requests.<br>
                        Sent as <code>X-Api-Key</code> header on every API call.<br>
                        Visible only when <strong>SalesNexus API</strong> is selected as output destination.<br>
                        Keep this secure — anyone with this key can modify your CRM contacts.
                    </div>
                </div>

                <div class="le-guide-section">
                    <p class="le-guide-title">🌐 SalesNexus API URL</p>
                    <div class="le-guide-text">
                        Base URL for the SalesNexus API.<br>
                        Default: <code>https://api-beta.salesnex.us</code><br>
                        Only change this if your account uses a different API endpoint.
                    </div>
                </div>

                <div class="le-guide-section">
                    <p class="le-guide-title">📝 Target Sheet ID</p>
                    <div class="le-guide-text">
                        The Google Sheet ID where extracted leads will be written.<br>
                        Visible only when <strong>Target Sheet</strong> is selected as output destination.<br>
                        Must be different from Source Sheet.<br>
                        Must also be shared with: <code>salesnexus@salesnexus-user-sheet.iam.gserviceaccount.com</code><br>
                        Columns written: Company ID, Company Name, Domain, Person ID, Name, LinkedIn URL, Experiences, Email, Phone.
                    </div>
                </div>

                <div class="le-guide-section">
                    <p class="le-guide-title">🔑 Profile API Key</p>
                    <div class="le-guide-text">
                        Your ProfileAPI secret key for fetching company and executive data.<br>
                        Used to search companies by domain, find executives by job title,
                        and look up professional email addresses and phone numbers.<br>
                        Get it from your ProfileAPI dashboard. Never share this key.
                    </div>
                </div>

                <div class="le-guide-section">
                    <p class="le-guide-title">📧 Email Column</p>
                    <div class="le-guide-text">
                        The column letter in Source Sheet that contains email addresses.<br>
                        Default: <code>A</code><br>
                        The plugin extracts the domain from each email (e.g. <code>user@acme.com</code> → <code>acme.com</code>)
                        and uses it to search for the company and its executives.
                    </div>
                </div>

                <div class="le-guide-section">
                    <p class="le-guide-title">🎯 Job Titles</p>
                    <div class="le-guide-text">
                        Comma-separated list of job titles used to filter executives.<br>
                        Example: <code>ceo, cto, cfo, vp, director, manager</code><br>
                        Only executives whose current title matches one of these will be extracted.
                        If left empty, all available persons for the company will be returned.
                    </div>
                </div>

                <div class="le-guide-section">
                    <p class="le-guide-title">👥 Person Limit per Domain</p>
                    <div class="le-guide-text">
                        Maximum number of executives to extract per company domain.<br>
                        Range: 1–50. Default: 10.<br>
                        Higher values give more leads but use more API credits and take longer.
                        Each person requires up to 3 ProfileAPI calls (profile, email, phone).
                    </div>
                </div>

                <div class="le-guide-section">
                    <p class="le-guide-title">⚙️ Batch Size</p>
                    <div class="le-guide-text">
                        Number of domains processed in a single batch run.<br>
                        Range: 1–20. Default: 3.<br>
                        Smaller batches reduce the risk of timeouts and server load.
                        Larger batches are faster but may exceed server execution limits.
                    </div>
                </div>

                <div class="le-guide-section">
                    <p class="le-guide-title">🕐 Auto Sync Interval</p>
                    <div class="le-guide-text">
                        How often the plugin automatically checks the Source Sheet for new emails.<br>
                        Options: Every 2 Minutes, Hourly, Daily, Weekly, Monthly.<br>
                        Only new domains (not previously processed) will be added to the queue.
                        Already processed domains are permanently skipped to avoid duplicates.
                    </div>
                </div>
            </div>

            <div class="le-card le-guide">
                <h2 class="le-card-title">�📖 How it works</h2>

                <div class="le-guide-section">
                    <p class="le-guide-title">✅ Person Limit Fix </p>
                    <div class="le-code" style="font-size:11px;line-height:2;">
                        Save settings<br>
                        → le_person_limit saved in DB<br>
                        → get_person_limit() in process_batch()<br>
                        → Correct limit goes to API call<br>
                        → Visible in log
                    </div>
                </div>

                <div class="le-guide-section">
                    <p class="le-guide-title">✅ Auto Sync Fix </p>
                    <div class="le-code" style="font-size:11px;line-height:2;">
                        Cron runs → reads sheet<br>
                        → skips processed domains<br>
                        → adds only new domains<br>
                        → no restart from beginning!<br>
                        → continues from where it was
                    </div>
                </div>

                <div class="le-guide-section">
                    <p class="le-guide-title">✅ Real-time Visibility </p>
                    <div class="le-code" style="font-size:11px;line-height:2;">
                        Which domain is running → visible<br>
                        Activity log → real-time<br>
                        Polling every 3 seconds<br>
                        Shows last 20 activities
                    </div>
                </div>

                <div class="le-guide-section">
                    <p class="le-guide-title">🗑 What happens on reset?</p>
                    <div class="le-guide-warning">
                        ⚠️ Queue + all processed domain history will be deleted.
                        All domains will be processed again in next run.
                    </div>
                </div>
            </div>
        </div>

    </div><!-- /le-grid -->
</div>
<?php
    }

    // =========================================================
    // ✅ ENQUEUE SCRIPTS
    // =========================================================

    public function le_enqueue_scripts($hook) {
        if ($hook !== 'toplevel_page_lead-extractor-ultra') return;
        wp_enqueue_style('le-styles',  plugin_dir_url(__FILE__) . 'assets/css/main.css',  [], time());
        wp_enqueue_script('le-scripts', plugin_dir_url(__FILE__) . 'assets/js/main.js', ['jquery'], time(), true);
        wp_localize_script('le-scripts', 'le_ajax_obj', [
            'ajax_url'     => admin_url('admin-ajax.php'),
            'nonce'        => wp_create_nonce('le_save_settings'),
            'queue_status' => $this->get_queue()['status'],
            // Pass initial next sync timestamp so countdown starts immediately on page load
            'next_sync'    => (int) wp_next_scheduled(self::SYNC_CRON_HOOK),
        ]);
    }
}

new Lead_Executives_Extractor();