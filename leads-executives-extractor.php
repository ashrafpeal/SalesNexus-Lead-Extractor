<?php

/**
 * Plugin Name: SalesNexus Lead Extractor
 * Description: Extracts executive leads from Google Sheet and writes to another Google Sheet using ProfileAPI.
 * Version: 0.1
 * Author: Ashraf Uddin
 * Author URI: https://www.facebook.com/ashraf.peal/
 */

require_once __DIR__ . '/vendor/autoload.php';

class Lead_Executives_Extractor {

    private $sourceSheetId  = '';
    private $targetSheetId  = '';
    private $api_key        = '';

    private $sheets_service    = null;
    private $processed_domains = []; // ✅ duplicate domain skip
    private $processed_people  = []; // ✅ duplicate person skip

    // ✅ Process summary tracking
    private $summary = [
        'total_emails'    => 0,
        'skipped_domains' => 0,
        'failed_domains'  => 0,
        'success_domains' => 0,
        'total_persons'   => 0,
        'errors'          => [],
    ];

    public function __construct() {
        add_action('admin_menu', [$this, 'menu']);
        add_action('wp_ajax_le_run_process', [$this, 'ajax_process']);
        add_action('wp_ajax_le_save_settings', [$this, 'ajax_save_settings']);
        add_action('admin_enqueue_scripts', [$this, 'le_enqueue_scripts']);

        $this->sourceSheetId = get_option('le_source_sheet_id', '');
        $this->targetSheetId = get_option('le_target_sheet_id', '');
        $this->api_key       = get_option('le_api_key', '');
    }

    // =========================================================
    // ✅ Google Sheets Service
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
    // ✅ Source Sheet থেকে সব email পড়া
    // =========================================================
    private function get_sheet_data() {
        try {
            $service = $this->get_sheets_service();
            if (!$service) return [];

            $spreadsheet = $service->spreadsheets->get($this->sourceSheetId);
            $sheets      = $spreadsheet->getSheets();
            if (empty($sheets)) {
                error_log('❌ No sheets found');
                return [];
            }

            $sheetName = $sheets[0]->getProperties()->getTitle();
            $col       = strtoupper(get_option('le_column_name', 'A'));
            $range     = $sheetName . '!' . $col . ':' . $col;

            error_log("📊 Reading: $range");

            $response = $service->spreadsheets_values->get($this->sourceSheetId, $range);
            $values   = $response->getValues();

            if (empty($values) || count($values) < 2) {
                error_log('❌ Sheet empty');
                return [];
            }

            // Header বাদ দিয়ে শুধু data rows
            return array_slice($values, 1);
        } catch (Exception $e) {
            error_log('❌ Sheet read error: ' . $e->getMessage());
            return [];
        }
    }

    // =========================================================
    // ✅ Target Sheet Header নিশ্চিত করা
    // =========================================================
    private function ensure_header() {
        try {
            $service = $this->get_sheets_service();
            if (!$service) return;

            $spreadsheet = $service->spreadsheets->get($this->targetSheetId);
            $sheetName   = $spreadsheet->getSheets()[0]->getProperties()->getTitle();

            $existing = $service->spreadsheets_values->get($this->targetSheetId, $sheetName . '!A1:I1');
            $rows     = $existing->getValues();

            if (empty($rows) || empty($rows[0]) || ($rows[0][0] ?? '') !== 'Company ID') {
                $headers = [[
                    'Company ID',
                    'Company Name',
                    'Company Domain',
                    'Person ID',
                    'Name',
                    'LinkedIn URL',
                    'Experiences',
                    'Email',
                    'Phone'
                ]];
                $body   = new Google_Service_Sheets_ValueRange(['values' => $headers]);
                $params = ['valueInputOption' => 'RAW'];
                $service->spreadsheets_values->update($this->targetSheetId, $sheetName . '!A1', $body, $params);
                error_log('✅ Header written');
            }
        } catch (Exception $e) {
            error_log('❌ Header error: ' . $e->getMessage());
        }
    }

    // =========================================================
    // ✅ Target Sheet এ Data লেখা
    // =========================================================
    private function write_to_sheet($row_data) {
        try {
            $service = $this->get_sheets_service();
            if (!$service) return false;

            $spreadsheet = $service->spreadsheets->get($this->targetSheetId);
            $sheetName   = $spreadsheet->getSheets()[0]->getProperties()->getTitle();

            $existing = $service->spreadsheets_values->get($this->targetSheetId, $sheetName . '!A:A');
            $next_row = count($existing->getValues()) + 1;

            $values = [[
                $row_data['company_id']   ?? '',
                $row_data['company_name'] ?? '',
                $row_data['domain']       ?? '',
                $row_data['person_id']    ?? '',
                $row_data['name']         ?? '',
                $row_data['linkedin']     ?? '',
                $row_data['experiences']  ?? '',
                $row_data['email']        ?? '',
                $row_data['phone']        ?? '',
            ]];

            $body   = new Google_Service_Sheets_ValueRange(['values' => $values]);
            $params = ['valueInputOption' => 'RAW'];
            $result = $service->spreadsheets_values->update(
                $this->targetSheetId,
                $sheetName . '!A' . $next_row,
                $body,
                $params
            );

            error_log("✅ Row $next_row written: " . ($row_data['name'] ?? ''));
            return $result->getUpdatedCells() > 0;
        } catch (Exception $e) {
            error_log('❌ Sheet write error: ' . $e->getMessage());
            return false;
        }
    }

    // =========================================================
    // ✅ Domain extract করা
    // =========================================================
    private function extract_domain($email) {
        if (!is_email($email)) return null;
        $parts = explode('@', strtolower(trim($email)));
        return $parts[1] ?? null;
    }

    // =========================================================
    // ✅ Company খোঁজা
    // =========================================================
    private function get_company($domain) {
        try {
            error_log("🔍 Company search: $domain");

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

            if (is_wp_error($response)) {
                throw new Exception('WP Error: ' . $response->get_error_message());
            }

            $body   = json_decode(wp_remote_retrieve_body($response), true);
            $result = $body['data']['results'][0] ?? null;

            if (!$result) {
                error_log("⚠️ Company NOT FOUND: $domain");
                return null;
            }

            return [
                'id'   => $result['id']   ?? '',
                'name' => $result['name'] ?? $domain,
            ];
        } catch (Exception $e) {
            error_log('❌ Company API error: ' . $e->getMessage());
            return null;
        }
    }

    // =========================================================
    // ✅ Persons খোঁজা (2-step)
    // =========================================================
    private function get_persons($company_id) {
        try {
            $titles      = get_option('le_job_titles', []);
            $filters_any = array_map(fn($t) => ['key' => 'titleAtCurrentCompany', 'value' => $t, 'operator' => '~'], $titles);

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
                    'limit'    => 10,
                ]),
                'timeout' => 30,
            ]);

            if (is_wp_error($response)) {
                throw new Exception($response->get_error_message());
            }

            $data = json_decode(wp_remote_retrieve_body($response), true);

            if (empty($data['data']['results'])) {
                error_log("⚠️ No executives for company: $company_id");
                return [];
            }

            // Step 2: প্রতিটা person এর full data নাও
            $full_persons = [];
            foreach ($data['data']['results'] as $person) {
                try {
                    $linkedin_url = $person['linkedInUrl'] ?? '';

                    if (empty($linkedin_url)) {
                        $full_persons[] = $person;
                        continue;
                    }

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

                    if (is_wp_error($full_response)) {
                        throw new Exception($full_response->get_error_message());
                    }

                    $full_data   = json_decode(wp_remote_retrieve_body($full_response), true);
                    $full_person = $full_data['data']['results'][0] ?? null;

                    $full_persons[] = $full_person ?? $person; // fail হলে basic data রাখো

                } catch (Exception $e) {
                    // ✅ একজন person fail করলেও বাকিদের process চলবে
                    error_log('⚠️ Person full-data failed, using basic: ' . $e->getMessage());
                    $full_persons[] = $person;
                }
            }

            return $full_persons;
        } catch (Exception $e) {
            error_log('❌ Persons API error: ' . $e->getMessage());
            return [];
        }
    }

    // =========================================================
    // ✅ Email Lookup
    // =========================================================
    private function lookup_email($person_id) {
        try {
            $response = wp_remote_post('https://api.profileapi.com/2024-03-01/email-contacts/lookup', [
                'headers' => [
                    'Authorization' => 'ApiKey ' . $this->api_key,
                    'Content-Type'  => 'application/json',
                    'Accept'        => 'application/json',
                ],
                'body'    => wp_json_encode(['type' => 'professional', 'id' => $person_id]),
                'timeout' => 30,
            ]);

            if (is_wp_error($response)) {
                throw new Exception($response->get_error_message());
            }

            return json_decode(wp_remote_retrieve_body($response), true) ?? [];
        } catch (Exception $e) {
            error_log('❌ Email lookup failed for ' . $person_id . ': ' . $e->getMessage());
            return []; // ✅ fail হলে empty return — process বন্ধ হবে না
        }
    }

    // =========================================================
    // ✅ Phone Lookup
    // =========================================================
    private function lookup_phone($person_id) {
        try {
            $response = wp_remote_post('https://api.profileapi.com/2024-03-01/phone-contacts/lookup', [
                'headers' => [
                    'Authorization' => 'ApiKey ' . $this->api_key,
                    'Content-Type'  => 'application/json',
                    'Accept'        => 'application/json',
                ],
                'body'    => wp_json_encode(['id' => $person_id]),
                'timeout' => 30,
            ]);

            if (is_wp_error($response)) {
                throw new Exception($response->get_error_message());
            }

            return json_decode(wp_remote_retrieve_body($response), true) ?? [];
        } catch (Exception $e) {
            error_log('❌ Phone lookup failed for ' . $person_id . ': ' . $e->getMessage());
            return []; // ✅ fail হলে empty return — process বন্ধ হবে না
        }
    }

    // =========================================================
    // ✅ Experiences format
    // =========================================================
    private function format_experiences($experiences) {
        if (empty($experiences)) return '';
        $parts = [];
        foreach ($experiences as $exp) {
            $title   = $exp['title'] ?? '';
            $company = $exp['name']  ?? '';
            $current = !empty($exp['isCurrent']) ? ' (Current)' : '';
            if ($title || $company) {
                $parts[] = trim("$title at $company") . $current;
            }
        }
        return implode(' | ', $parts);
    }

    private function is_duplicate_person($person_id) {
        $key = md5($person_id);
        if (isset($this->processed_people[$key])) return true;
        $this->processed_people[$key] = true;
        return false;
    }

    // =========================================================
    // ✅✅✅ MAIN PROCESS — সব email loop করবে, error হলেও চলবে
    // =========================================================
    public function process() {
        error_log('🎬 === LEAD EXTRACTOR STARTED ===');

        // PHP timeout বাড়ানো (shared hosting এ কাজ নাও করতে পারে)
        @set_time_limit(0);

        $this->ensure_header();

        $rows = $this->get_sheet_data();

        if (empty($rows)) {
            return json_encode(['status' => 'error', 'message' => '❌ Source sheet empty']);
        }

        $this->summary['total_emails'] = count($rows);
        error_log("📊 Total emails to process: " . count($rows));

        // ✅ সব row loop করো — একটা fail হলেও পরেরটায় যাবে
        foreach ($rows as $row_index => $row) {

            $email = trim($row[0] ?? '');

            // ——— Email valid না হলে skip করো ———
            if (empty($email) || !is_email($email)) {
                error_log("⏭️ Row $row_index: Invalid email '$email' — skipping");
                $this->summary['errors'][] = "Row $row_index: Invalid email '$email'";
                continue; // ✅ পরের row তে যাও
            }

            $domain = $this->extract_domain($email);

            if (!$domain) {
                error_log("⏭️ Row $row_index: Could not extract domain from '$email' — skipping");
                $this->summary['errors'][] = "Row $row_index: Domain extract failed for '$email'";
                continue;
            }

            // ——— Duplicate domain skip ———
            if (isset($this->processed_domains[$domain])) {
                error_log("⏭️ Domain already processed: $domain — skipping");
                $this->summary['skipped_domains']++;
                continue;
            }

            $this->processed_domains[$domain] = true;
            error_log("🎯 [{$row_index}] Processing domain: $domain");

            // ——— Company খোঁজা — fail হলে next domain ———
            $company = null;
            try {
                $company = $this->get_company($domain);
            } catch (Exception $e) {
                error_log("❌ Company fetch exception for $domain: " . $e->getMessage());
            }

            if (!$company) {
                error_log("⚠️ Company not found for $domain — moving to next");
                $this->summary['failed_domains']++;
                $this->summary['errors'][] = "Company not found: $domain";
                continue; // ✅ পরের domain এ যাও
            }

            // ——— Persons খোঁজা — fail হলে next domain ———
            $persons = [];
            try {
                $persons = $this->get_persons($company['id']);
            } catch (Exception $e) {
                error_log("❌ Persons fetch exception for {$company['id']}: " . $e->getMessage());
            }

            if (empty($persons)) {
                error_log("⚠️ No executives for {$company['name']} — moving to next");
                $this->summary['failed_domains']++;
                $this->summary['errors'][] = "No executives: {$company['name']} ($domain)";
                continue; // ✅ পরের domain এ যাও
            }

            // ——— প্রতিটা person process করো ———
            $domain_written = 0;
            foreach ($persons as $key => $person) {

                // ✅ Person process এ যেকোনো error হলেও পরের person এ যাবে
                try {
                    if (empty($person['id'])) {
                        error_log("⚠️ Person has no ID — skipping");
                        continue;
                    }

                    if ($this->is_duplicate_person($person['id'])) {
                        error_log("⚠️ Duplicate person: " . $person['id']);
                        continue;
                    }

                    // Email ও Phone lookup — এগুলো fail হলেও row লেখা হবে (empty value দিয়ে)
                    $person_email = [];
                    $person_phone = [];

                    try {
                        $person_email = $this->lookup_email($person['id']);
                    } catch (Exception $e) {
                        error_log("⚠️ Email lookup failed: " . $e->getMessage());
                    }

                    try {
                        $person_phone = $this->lookup_phone($person['id']);
                    } catch (Exception $e) {
                        error_log("⚠️ Phone lookup failed: " . $e->getMessage());
                    }

                    $row_to_send = [
                        'company_id'   => $company['id'],
                        'company_name' => $company['name'],
                        'domain'       => $domain,
                        'person_id'    => $person['id'],
                        'name'         => $person['name'] ?? trim(($person['firstName'] ?? '') . ' ' . ($person['lastName'] ?? '')),
                        'linkedin'     => $person['linkedInUrl'] ?? '',
                        'experiences'  => $this->format_experiences($person['experiences'] ?? []),
                        'email'        => $person_email['data']['email'] ?? '',
                        'phone'        => $person_phone['data']['phone'] ?? '',
                    ];

                    if ($this->write_to_sheet($row_to_send)) {
                        $domain_written++;
                        $this->summary['total_persons']++;
                    }
                } catch (Exception $e) {
                    // ✅ একজন person এ error হলেও পরের person চলবে
                    error_log("❌ Person processing error: " . $e->getMessage());
                    $this->summary['errors'][] = "Person error ({$person['id']}): " . $e->getMessage();
                    continue;
                }
            }

            $this->summary['success_domains']++;
            error_log("✅ Domain done: $domain | Written: $domain_written persons");

            if (5 == $key) {
                error_log("⏸️ Process paused after 5 persons for domain: $domain");
                break; // ✅ শুধু প্রথম 5 জন process করো (testing এর জন্য)
            }
        } // ✅ foreach rows শেষ

        // ——— Final Summary ———
        $summary_msg = sprintf(
            "✅ DONE | Total Emails: %d | Success Domains: %d | Failed: %d | Skipped (dup): %d | Persons Written: %d | Errors: %d",
            $this->summary['total_emails'],
            $this->summary['success_domains'],
            $this->summary['failed_domains'],
            $this->summary['skipped_domains'],
            $this->summary['total_persons'],
            count($this->summary['errors'])
        );

        error_log('🏁 === ' . $summary_msg . ' ===');

        if (!empty($this->summary['errors'])) {
            error_log('📋 Error details: ' . implode(' | ', $this->summary['errors']));
        }

        return json_encode([
            'status'  => 'success',
            'message' => $summary_msg,
            'errors'  => $this->summary['errors'],
            'summary' => $this->summary,
        ]);
    }

    // =========================================================
    // Admin UI
    // =========================================================
    public function menu() {
        add_menu_page('Lead Extractor', 'Lead Extractor', 'manage_options', 'lead-extractor-ultra', [$this, 'admin_page'], 'dashicons-clipboard', 6);
    }

    public function admin_page() {
        $source_id = esc_attr(get_option('le_source_sheet_id', ''));
        $target_id = esc_attr(get_option('le_target_sheet_id', ''));
        $api_key   = esc_attr(get_option('le_api_key', ''));
        $col_name  = esc_attr(get_option('le_column_name', ''));
        $job_titles = esc_attr(implode(', ', get_option('le_job_titles', [])));
?>
        <div class="wrap">
            <h1>🔥 SalesNexus Lead Extractor</h1>

            <div class="row">
                <div class="col-md-8">
                    <div class="wrapper">
                        <h2 class="title">⚙️ Settings</h2>
                        <table>
                            <tr>
                                <td><label for="le_source_sheet_id">Source Sheet ID</label></td>
                                <td>
                                    <input type="text" id="le_source_sheet_id" value="<?php echo $source_id; ?>" />
                                    <?php if ($source_id): ?><a href="https://docs.google.com/spreadsheets/d/<?php echo $source_id; ?>/edit" target="_blank">🔗 Open</a><?php endif; ?>
                                </td>
                            </tr>
                            <tr>
                                <td><label for="le_target_sheet_id">Target Sheet ID</label></td>
                                <td>
                                    <input type="text" id="le_target_sheet_id" value="<?php echo $target_id; ?>" />
                                    <?php if ($target_id): ?><a href="https://docs.google.com/spreadsheets/d/<?php echo $target_id; ?>/edit" target="_blank">🔗 Open</a><?php endif; ?>
                                </td>
                            </tr>
                            <tr>
                                <td><label for="le_api_key">Profile API Key</label></td>
                                <td>
                                    <input type="password" id="le_api_key" value="<?php echo $api_key; ?>" />
                                    <a href="#" id="toggleApiKey">👁 Show/Hide</a>
                                </td>
                            </tr>
                            <tr>
                                <td><label for="le_column_name">Email Column</label></td>
                                <td>
                                    <input type="text" id="le_column_name" value="<?php echo $col_name; ?>" maxlength="3" />
                                    <small>Email column letter (A, B, Y...)</small>
                                </td>
                            </tr>
                            <tr>
                                <td><label for="le_job_titles">Job Titles</label></td>
                                <td>
                                    <input
                                        type="text"
                                        id="le_job_titles"
                                        name="le_job_titles"
                                        value="<?php echo $job_titles; ?>"
                                        placeholder="Enter titles separated by comma (e.g. ceo, cto, manager)"
                                        style="width:100%;" />
                                    <small style="color:#666;">
                                        Example: ceo, cfo, vp, director, manager
                                    </small>
                                </td>
                            </tr>
                        </table>
                        <div class="save-section">
                            <button id="saveBtn" class="button button-primary">💾 Save Settings</button>
                            <span id="saveStatus"></span>
                        </div>
                    </div>

                    <div class="wrapper">
                        <h2 class="title">🚀 Run Extractor</h2>
                        <p><strong>Status:</strong> <span id="status" style="color:green;">✅ Ready</span></p>
                        <button id="runBtn" class="button button-primary button-large">🚀 Run All Emails</button>
                        <div id="progress"></div>

                        <!-- Summary Box -->
                        <div id="summaryBox">
                            <h3 style="margin:0 0 10px;">📊 Summary</h3>
                            <div id="summaryContent"></div>
                        </div>

                        <!-- Error Box -->
                        <div id="errorBox">
                            <h3 style="margin:0 0 10px;color:#c0392b;">⚠️ Errors (process চলেছে)</h3>
                            <div id="errorContent"></div>
                        </div>

                        <div id="debug"></div>
                    </div>
                </div> <!-- col-md-7 -->

                <div class="col-md-4">
                    <div class="wrapper">
                        <div class="field-guide-container">
                            <h2 class="field-guide-header">📖 Field Guide</h2>

                            <div class="fg-section">
                                <p class="fg-title">📄 Source Sheet ID</p>
                                <p class="fg-description">The Google Sheet that contains your list of emails to process.</p>
                                <p class="fg-label">How to get the ID:</p>
                                <div class="fg-code-box">
                                    docs.google.com/spreadsheets/d/<span class="fg-id-highlight">1vEB3Dh...Wo_U</span>/edit
                                </div>
                                <p class="fg-helper-text">👆 The highlighted part between <code>/d/</code> and <code>/edit</code></p>
                            </div>

                            <div class="fg-section">
                                <p class="fg-title">📝 Target Sheet ID</p>
                                <p class="fg-description">The Google Sheet where extracted executive leads will be written.</p>
                                <p class="fg-alert-text">⚠️ Both sheets must be shared with your Service Account email: <code>salesnexus@salesnexus-user-sheet.iam.gserviceaccount.com</code>.</p>
                                <div class="fg-status-box">
                                    ✅ Source → <strong>Viewer</strong><br>
                                    ✅ Target → <strong>Editor</strong>
                                </div>
                            </div>

                            <div class="fg-section">
                                <p class="fg-title">🔑 Profile API Key</p>
                                <p class="fg-description">Your API key from <a href="https://profileapi.com" target="_blank">profileapi.com</a> — used to find company and executive data.</p>
                                <div class="fg-warning-box">
                                    🔒 Keep this private. Never share it publicly.
                                </div>
                            </div>

                            <div class="fg-section">
                                <p class="fg-title">📧 Email Column</p>
                                <p class="fg-description">The column letter in your Source Sheet that contains the email addresses.</p>
                                <div class="fg-code-box" style="font-size: 12px;">
                                    A &nbsp;→&nbsp; 1st column<br>
                                    B &nbsp;→&nbsp; 2nd column<br>
                                    Y &nbsp;→&nbsp; 25th column<br>
                                    AA →&nbsp; 27th column
                                </div>
                                <p class="fg-helper-text">Row 1 (header) is always skipped automatically.</p>
                            </div>

                            <div class="fg-section fg-section-last">
                                <p class="fg-title">🎯 Executive Titles</p>
                                <p class="fg-description">Job titles to search for at each company. Only people with these titles will be extracted.</p>
                                <div class="fg-code-box" style="font-size: 12px;">
                                    ceo, cto, cfo, vp, director,<br>manager, founder, president
                                </div>
                                <p class="fg-helper-text">Comma separated. Trailing comma is ignored. Case insensitive.</p>
                            </div>
                        </div>
                    </div><!-- /RIGHT -->
                </div> <!-- col-md-5 -->
            </div> <!-- row -->
        </div>
<?php }

    public function le_enqueue_scripts($handle) {
        if ($handle !== 'toplevel_page_lead-extractor-ultra') {
            return;
        }
        wp_enqueue_style('le-flexgrid-styles', plugin_dir_url(__FILE__) . 'assets/css/flexboxgrid.min.css');
        wp_enqueue_style('le-styles', plugin_dir_url(__FILE__) . 'assets/css/main.css', [], time());
        wp_enqueue_script('le-scripts', plugin_dir_url(__FILE__) . 'assets/js/main.js', ['jquery'], time(), true);

        wp_localize_script('le-scripts', 'le_ajax_obj', [
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce'    => wp_create_nonce('le_save_settings'),
        ]);
    }

    public function ajax_save_settings() {
        if (!check_ajax_referer('le_save_settings', '_wpnonce', false)) {
            wp_send_json_error(['message' => 'Invalid nonce']);
            return;
        }
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Unauthorized']);
            return;
        }
        // Source sheet
        update_option('le_source_sheet_id', sanitize_text_field($_POST['le_source_sheet_id'] ?? ''));
        // Target sheet
        update_option('le_target_sheet_id', sanitize_text_field($_POST['le_target_sheet_id'] ?? ''));
        // API key
        update_option('le_api_key',         sanitize_text_field($_POST['le_api_key']         ?? ''));
        // Column name
        update_option('le_column_name',     strtoupper(sanitize_text_field($_POST['le_column_name'] ?? 'A')));
        // Job titles
        $job_titles = array_map('trim', explode(',', sanitize_text_field($_POST['le_job_titles'] ?? '')));
        update_option('le_job_titles', $job_titles);

        //Return success response
        wp_send_json_success(['message' => 'Settings saved']);
    }

    public function ajax_process() {
        $result = $this->process();
        // JSON string হলে decode করে send করো
        $decoded = json_decode($result, true);
        if ($decoded) {
            wp_send_json($decoded);
        } else {
            wp_send_json(['status' => 'error', 'message' => $result]);
        }
    }
}

new Lead_Executives_Extractor();
