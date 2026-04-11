<?php

/**

 * Plugin Name: Lead Extractor
 * Description: Extracts executive leads from Google Sheet and writes to another Google Sheet using ProfileAPI. Version 0.1 with smart contact lookup and robust error handling.

 * Author: Ashraf Uddin

 * Author URI: https://webdesgo.com

 * Version: 0.1

 */



require_once __DIR__ . '/vendor/autoload.php';



class Lead_Executives_Extractor {



    // private $sourceSheetId  = '1vEB3DhZuaBj3XjdzLR6lEZfbsC6rvbGx8GN1ud3Wo_U';
    private $sourceSheetId  = '';

    // private $targetSheetId  = '1Y1GoBeEps1OSfOy56QiUEvcY_uADoIXk37ODSBrhF80';
    private $targetSheetId  = '';

    // private $api_key        = 'b0cf2cb9fe3edba7372097a3459e8d2945cb2a63b87b182782e10751931f4b48';
    private $api_key        = '';



    private $sheets_service    = null;

    private $processed_domains = [];

    private $processed_people  = [];



    public function __construct() {

        add_action('admin_menu', [$this, 'menu']);

        add_action('wp_ajax_le_run_process', [$this, 'ajax_process']);

        add_action('wp_ajax_le_save_settings', [$this, 'ajax_save_settings']);

        $this->sourceSheetId = get_option('le_source_sheet_id', '');
        $this->targetSheetId = get_option('le_target_sheet_id', '');
        $this->api_key       = get_option('le_api_key', '');
    }



    // =========================================================

    // ✅ Google Sheets Service (Service Account)

    // =========================================================

    private function get_sheets_service() {

        if ($this->sheets_service) return $this->sheets_service;



        $client = new Google_Client();

        $client->setApplicationName('Lead Executives Extractor');

        $client->setScopes([\Google_Service_Sheets::SPREADSHEETS]);

        $client->setAuthConfig(__DIR__ . '/credential.json');



        $this->sheets_service = new Google_Service_Sheets($client);

        return $this->sheets_service;
    }



    // =========================================================

    // ✅ Source Sheet থেকে Data পড়া (Y Column = index 24)

    // =========================================================

    // private function get_sheet_data() {

    //     try {

    //         $service  = $this->get_sheets_service();

    //         $response = $service->spreadsheets_values->get($this->sourceSheetId, 'Sheet1!'.get_option('le_column_name').':'.get_option('le_column_name'));

    //         $rows     = array_slice($response->getValues(), 1);


    //         echo "<pre>";
    //         print_r( $response );
    //         print_r( get_option('le_column_name') );
    //         echo "</pre>";
    //         exit;

    //         if (empty($rows)) {

    //             error_log('❌ No data found in source sheet');

    //             return [];
    //         }



    //         error_log('📊 Sheet rows loaded: ' . (count($rows) - 1));

    //         return $rows;
    //     } catch (Exception $e) {

    //         echo "<pre>";
    //         print_r( get_option('le_column_name') );
    //         echo "</pre>";
    //         exit;

    //         error_log('❌ Sheet read error: ' . $e->getMessage());

    //         return [];
    //     }
    // }

    private function get_sheet_data() {
        try {
            $service = $this->get_sheets_service();

            // ——— STEP 1: শীট নাম পাওয়া ———
            $spreadsheet = $service->spreadsheets->get($this->sourceSheetId);
            $sheets      = $spreadsheet->getSheets();
            if (empty($sheets)) {
                error_log('❌ No sheets found in spreadsheet');
                return [];
            }

            // প্রথম শীটের নাম
            $sheetName = $sheets[0]->getProperties()->getTitle();

            // ——— STEP 2: কলাম নাম পাওয়া ———
            $col = get_option('le_column_name', 'A');

            // রেঞ্জ বানানো
            $range = $sheetName . '!' . $col . ':' . $col;

            error_log("📊 Reading from: $range (Sheet ID: $this->sourceSheetId)");

            // ——— STEP 3: ডেটা পড়া ———
            $response = $service->spreadsheets_values->get($this->sourceSheetId, $range);
            $values   = $response->getValues();

            // echo "<pre>";
            // print_r($response);
            // print_r(get_option('le_column_name'));
            // echo "</pre>";
            // exit;

            if (empty($values) || count($values) < 2) {
                error_log('❌ No data found in source sheet');
                return [];
            }

            // হেডার বাদ দিয়ে শুধু ডেটা রোস
            $rows = array_slice($values, 1);

            error_log('✅ Sheet rows loaded: ' . count($rows));
            return $rows;
        } catch (Exception $e) {
            error_log('❌ Sheet read error: ' . $e->getMessage());
            error_log('Stack trace: ' . $e->getTraceAsString());
            return [];
        }
    }



    // =========================================================

    // ✅ Target Sheet এ Header লেখা (প্রথমবার)

    // Columns: Company ID | Company Name | Company Domain | Person ID | Name | LinkedIn | Experiences | Email | Phone

    // =========================================================

    private function ensure_header() {

        try {

            $service  = $this->get_sheets_service();

            $existing = $service->spreadsheets_values->get($this->targetSheetId, 'Sheet1!A1:H1');

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

                    'Phone',

                ]];

                $body   = new Google_Service_Sheets_ValueRange(['values' => $headers]);

                $params = ['valueInputOption' => 'RAW'];

                $service->spreadsheets_values->update($this->targetSheetId, 'Sheet1!A1', $body, $params);

                error_log('✅ Header written to target sheet');
            }
        } catch (Exception $e) {

            error_log('❌ Header write error: ' . $e->getMessage());
        }
    }



    // =========================================================

    // ✅ Target Sheet এ Data লেখা

    // =========================================================

    private function write_to_sheet($row_data) {
        try {
            $service = $this->get_sheets_service();

            // ——— 1. Target শীটের প্রথম শীটের নাম পাওয়া ———
            $spreadsheet = $service->spreadsheets->get($this->targetSheetId);
            $sheets      = $spreadsheet->getSheets();
            if (empty($sheets)) {
                error_log('❌ No sheets found in target spreadsheet');
                return false;
            }

            $sheetName = $sheets[0]->getProperties()->getTitle();

            // ——— 2. বিদ্যমান ডেটা পাওয়া এবং next_row বের করা ———
            $range  = $sheetName . '!A:A';
            $existing = $service->spreadsheets_values->get($this->targetSheetId, $range);
            $values   = $existing->getValues();
            $next_row = count($values) + 1;

            // ——— 3. লিখে দেয়া ডেটা ———
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

            $writeRange = $sheetName . '!A' . $next_row;

            $result = $service->spreadsheets_values->update(
                $this->targetSheetId,
                $writeRange,
                $body,
                $params
            );

            error_log("✅ Written to Sheet row $next_row (Range: $writeRange): " . json_encode($row_data));
            return $result->getUpdatedCells() > 0;
        } catch (Exception $e) {
            error_log('❌ Sheet write error: ' . $e->getMessage());
            error_log('Stack trace: ' . $e->getTraceAsString());
            return false;
        }
    }



    // =========================================================

    // Helpers

    // =========================================================

    private function extract_domain($email) {

        if (!is_email($email)) return null;

        $parts = explode('@', strtolower(trim($email)));

        return $parts[1] ?? null;
    }



    // =========================================================

    // ✅ Company খোঁজা — ID + Name দুটোই return করে

    // =========================================================

    private function get_company($domain) {

        error_log("🔍 Searching company for: $domain");



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

            error_log('❌ Company API Error: ' . $response->get_error_message());

            return null;
        }



        $body   = json_decode(wp_remote_retrieve_body($response), true);

        $result = $body['data']['results'][0] ?? null;



        if (!$result) {

            error_log('⚠️ Company NOT FOUND for: ' . $domain);

            return null;
        }



        $company = [

            'id'   => $result['id']   ?? '',

            'name' => $result['name'] ?? $domain,

        ];



        error_log('✅ Company found: ' . json_encode($company));

        return $company;
    }



    // =========================================================

    // ✅ Person খোঁজা — 2-Step Approach

    //

    //  Step 1: basic dataset দিয়ে company+title filter করে persons খোঁজো

    //  Step 2: প্রতিটা person এর linkedInUrl দিয়ে 'all' dataset call করো

    //          → emailContacts + phoneContacts পাওয়া যাবে

    // =========================================================

    private function get_persons($company_id) {

        // --- Step 1: basic dataset দিয়ে persons খোঁজো ---

        $titles      = ['ceo', 'cto', 'cfo', 'vp', 'director', 'manager', 'head', 'president', 'founder'];

        $filters_any = [];

        foreach ($titles as $title) {

            $filters_any[] = ['key' => 'titleAtCurrentCompany', 'value' => $title, 'operator' => '~'];
        }



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

                'datasets' => ['basic'], // ✅ Step 1: basic only — company+title filter কাজ করে

                'limit'    => 5,

            ]),

            'timeout' => 30,

        ]);



        if (is_wp_error($response)) {

            error_log('❌ Persons API Error: ' . $response->get_error_message());

            return [];
        }



        $data = json_decode(wp_remote_retrieve_body($response), true);



        if (empty($data['data']['results'])) {

            error_log('⚠️ No executives found');

            return [];
        }



        $basic_persons = [];

        foreach ($data['data']['results'] as $person) {

            if (!empty($person['id'])) $basic_persons[$person['id']] = $person;
        }



        error_log('✅ Step 1 found: ' . count($basic_persons) . ' executives');



        // --- Step 2: প্রতিটা person এর linkedInUrl দিয়ে full data (all) নাও ---

        $full_persons = [];

        foreach ($basic_persons as $person) {

            $linkedin_url = $person['linkedInUrl'] ?? '';



            if (empty($linkedin_url)) {

                // linkedInUrl না থাকলে basic data ই রাখো

                error_log('⚠️ No LinkedIn URL for: ' . $person['id'] . ' — using basic data');

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

                    'filters'  => [

                        'all' => [['key' => 'linkedInUrl', 'value' => $linkedin_url, 'operator' => '=']],

                    ],

                    'datasets' => ['all'], // ✅ Step 2: linkedInUrl filter + all dataset → emailContacts + phoneContacts আসবে

                    'limit'    => 1,

                ]),

                'timeout' => 30,

            ]);



            if (is_wp_error($full_response)) {

                error_log('❌ Full data error for ' . $person['id'] . ': ' . $full_response->get_error_message());

                $full_persons[] = $person; // fallback to basic

                continue;
            }



            $full_data   = json_decode(wp_remote_retrieve_body($full_response), true);



            // echo "<pre>";

            // print_r( $full_data );

            // echo "</pre>";

            // exit;



            $full_person = $full_data['data']['results'][0] ?? null;



            if ($full_person) {

                error_log('✅ Step 2 full data for: ' . ($full_person['name'] ?? $person['id']));

                $full_persons[] = $full_person;
            } else {

                error_log('⚠️ Step 2 empty for: ' . $person['id'] . ' — using basic data');

                $full_persons[] = $person;
            }
        }



        error_log('✅ Total persons with full data: ' . count($full_persons));

        return $full_persons;
    }



    // =========================================================

    // ✅ Email + Phone বের করা (Smart — আলাদা API call কমানো হয়েছে)

    //

    //  Logic:

    //  1. person find response এ emailContacts থাকলে সেখান থেকে নাও

    //  2. না থাকলে তখনই আলাদা API call করো

    //  (same for phone)

    // =========================================================

    private function get_contact_info($person) {

        $contact = ['email' => '', 'phone' => ''];



        // --- Email: person response থেকে আগে চেষ্টা ---

        if (!empty($person['emailContacts'])) {

            // Professional কে priority দাও

            foreach ($person['emailContacts'] as $ec) {

                if (($ec['type'] ?? '') === 'professional' && !empty($ec['email'])) {

                    $contact['email'] = $ec['email'];

                    break;
                }
            }

            // Professional না পেলে যেকোনোটা নাও

            if (empty($contact['email'])) {

                $contact['email'] = $person['emailContacts'][0]['email'] ?? '';
            }

            error_log('📧 Email from person data: ' . $contact['email']);
        }



        // --- Phone: person response থেকে আগে চেষ্টা ---

        if (!empty($person['phoneContacts'])) {

            foreach ($person['phoneContacts'] as $pc) {

                if (($pc['type'] ?? '') === 'professional' && !empty($pc['phone'])) {

                    $contact['phone'] = $pc['phone'];

                    break;
                }
            }

            if (empty($contact['phone'])) {

                $contact['phone'] = $person['phoneContacts'][0]['phone'] ?? '';
            }

            error_log('📞 Phone from person data: ' . $contact['phone']);
        }



        // --- Email না পেলে তখন আলাদা API call ---

        // --- Email না পেলে তখন আলাদা API call ---

        if (empty($contact['email'])) {

            error_log('📡 Email missing, making separate API call for person: ' . $person['id']);

            foreach (['professional', 'personal'] as $type) {

                $res = $this->lookup_email($type, $person['id']);



                // echo "<pre>";

                // print_r( $res );

                // echo "</pre>";

                // exit;

                if (!empty($res['email'])) {

                    $contact['email'] = $res['email'];

                    break;
                }
            }
        }



        // --- Phone না পেলে তখন আলাদা API call ---

        if (empty($contact['phone'])) {

            error_log('📡 Phone missing, making separate API call for person: ' . $person['id']);

            $res = $this->lookup_phone($type, $person['id']);



            if (!empty($res['phone'])) {

                $contact['phone'] = $res['phone'];
            }
        }



        error_log('✅ Final contact for ' . $person['id'] . ': ' . json_encode($contact));

        return $contact;
    }



    // =========================================================

    // ✅ Email Lookup — curl documentation অনুযায়ী

    //    POST https://api.profileapi.com/2024-03-01/email-contacts/lookup

    //    Body: { "type": "professional"|"personal", "id": "person_id" }

    // =========================================================

    private function lookup_email($person_id) {

        $response = wp_remote_post('https://api.profileapi.com/2024-03-01/email-contacts/lookup', [

            'headers' => [

                'Authorization' => 'ApiKey ' . $this->api_key,

                'Content-Type'  => 'application/json',

                'Accept'        => 'application/json',

            ],

            'body'    => wp_json_encode([

                'type' => 'professional',

                'id'   => $person_id,

            ]),

            'timeout' => 30,

        ]);



        if (is_wp_error($response)) {

            error_log('❌ Email lookup error: ' . $response->get_error_message());

            return [];
        }



        $data = json_decode(wp_remote_retrieve_body($response), true) ?? [];

        error_log('📧 Email lookup for ' . $person_id . ': ' . json_encode($data));

        return $data;
    }





    // =========================================================

    // ✅ Phone Lookup — curl documentation অনুযায়ী

    //    POST https://api.profileapi.com/2024-03-01/phone-contacts/lookup

    //    Body: { "type": "professional"|"personal", "id": "person_id" }

    // =========================================================

    private function lookup_phone($person_id) {

        $response = wp_remote_post('https://api.profileapi.com/2024-03-01/phone-contacts/lookup', [

            'headers' => [

                'Authorization' => 'ApiKey ' . $this->api_key,

                'Content-Type'  => 'application/json',

                'Accept'        => 'application/json',

            ],

            'body'    => wp_json_encode([

                'id'   => $person_id,

            ]),

            'timeout' => 30,

        ]);



        if (is_wp_error($response)) {

            error_log('❌ Phone lookup error: ' . $response->get_error_message());

            return [];
        }



        $data = json_decode(wp_remote_retrieve_body($response), true) ?? [];

        error_log('📞 Phone lookup for ' . $person_id . ': ' . json_encode($data));

        return $data;
    }



    // =========================================================

    // ✅ Experiences থেকে readable string বানাও

    //    e.g. "Senior Engineer at Acme (Current) | Manager at XYZ"

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

    // ✅ Local CSV Backup (proper escaping)

    // =========================================================

    private function emergency_csv($row) {

        $base_dir = WP_CONTENT_DIR . '/Leads-Executives-Extractor/';

        if (!is_dir($base_dir)) wp_mkdir_p($base_dir);



        $file = $base_dir . 'leads-export-' . date('Y-m-d') . '.csv';



        if (!file_exists($file)) {

            $headers = ['Company ID', 'Company Name', 'Company Domain', 'Name', 'LinkedIn URL', 'Experiences', 'Email', 'Phone'];

            file_put_contents($file, implode(',', $headers) . "\n");

            error_log('✅ Created CSV: ' . $file);
        }



        $cols = [

            $row['company_id']   ?? '',

            $row['company_name'] ?? '',

            $row['domain']       ?? '',

            $row['person_id']    ?? '',

            $row['name']         ?? '',

            $row['linkedin']     ?? '',

            $row['experiences']  ?? '',

            $row['email']        ?? '',

            $row['phone']        ?? '',

        ];



        // Comma বা newline থাকলে quotes দাও

        $escaped = array_map(function ($val) {

            return (strpos($val, ',') !== false || strpos($val, "\n") !== false)

                ? '"' . str_replace('"', '""', $val) . '"'

                : $val;
        }, $cols);



        file_put_contents($file, implode(',', $escaped) . "\n", FILE_APPEND);

        error_log('✅ CSV row saved for: ' . ($row['name'] ?? 'unknown'));
    }



    // =========================================================

    // ✅ Main Process

    // =========================================================

    public function process() {

        error_log('🎬 === LEAD EXTRACTOR STARTED ===');



        $this->ensure_header();



        $rows = $this->get_sheet_data();

        // print_r($rows);
        // echo "<pre></pre>";
        // exit;

        if (empty($rows)) {
            return '❌ No data in source Google Sheet';
        }



        // Y Column (index 24) থেকে প্রথম valid email খোঁজো

        $domain = null;

        foreach ($rows as $i => $row) {

            // print_r($row[0]);
            // echo "<pre></pre>";
            // exit;

            $email = trim($row[0] ?? '');
            // print_r($email);
            // echo "<pre></pre>";
            // exit;

            if ($email && is_email($email)) {

                $domain = $this->extract_domain($email);

                if ($domain) break;
            }
        }



        if (!$domain) {

            return '❌ No valid email found in Y column (column 25)';
        }



        error_log('🎯 Target Domain: ' . $domain);

        $this->processed_domains[$domain] = true;

        // print_r($domain);
        // exit;



        // ✅ Company ID + Name দুটোই পাও

        $company = $this->get_company($domain);

        if (!$company) {

            return '❌ Company not found: ' . $domain;
        }



        $persons = $this->get_persons($company['id']);

        if (empty($persons)) {

            return '❌ No executives found for: ' . $company['id'];
        }



        $sent_count = 0;

        foreach ($persons as $key => $person) {



            if ($this->is_duplicate_person($person['id'])) {

                error_log('⚠️ Duplicate skipped: ' . $person['id']);

                continue;
            }



            // ✅ Smart contact — person data থেকে আগে, না পেলে API call

            // $contact = $this->get_contact_info($person);



            $person_email = $this->lookup_email($person['id']);

            $person_phone = $this->lookup_phone($person['id']);



            // echo "<pre>";

            // print_r($person_email);

            // print_r( $person_phone );

            // echo "</pre>";



            $row_to_send = [

                'company_id'   => $company['id'],

                'company_name' => $company['name'],

                'domain'       => $domain,

                'person_id' => $person['id'],

                'name'         => $person['name'] ?? trim(($person['firstName'] ?? '') . ' ' . ($person['lastName'] ?? '')),

                'linkedin'     => $person['linkedInUrl'] ?? '',

                'experiences'  => $this->format_experiences($person['experiences'] ?? []),

                'email'        => $person_email['data']['email'] ?? '',

                'phone'        => $person_phone['data']['phone'] ?? '',

            ];



            error_log('📩 Row to send: ' . json_encode($row_to_send));



            if ($this->write_to_sheet($row_to_send)) {

                $sent_count++;
            }



            // $this->emergency_csv($row_to_send);

            // break;

            if (5 == $key) break; // ⬅️ Single person test — সব person এর জন্য এই line সরাও

        }



        $message = "✅ SUCCESS! Domain: $domain | Company: {$company['name']} | Sheet: $sent_count rows | CSV: stored";

        error_log('🏁 === ' . $message . ' ===');

        return $message;
    }



    // =========================================================

    // Admin UI

    // =========================================================

    public function menu() {

        add_menu_page(

            'Lead Extractor',

            'Lead Extractor',

            'manage_options',

            'lead-extractor-ultra',

            [$this, 'admin_page']

        );
    }


    public function admin_page() {
        $source_id  = esc_attr(get_option('le_source_sheet_id', ''));
        $target_id  = esc_attr(get_option('le_target_sheet_id', ''));
        $api_key    = esc_attr(get_option('le_api_key', ''));
        $col_name   = esc_attr(get_option('le_column_name', 'Y'));
?>
        <div class="wrap">
            <h1>🔥 Lead Extractor Ultra</h1>

            <!-- ========== Settings Box ========== -->
            <div style="background:#fff;border:1px solid #ccd0d4;border-radius:4px;padding:24px;margin-bottom:20px;max-width:680px;">
                <h2 style="margin-top:0;padding-bottom:12px;border-bottom:1px solid #eee;">⚙️ Settings</h2>

                <table style="width:100%;border-collapse:collapse;">
                    <tr>
                        <td style="padding:10px 0;width:180px;font-weight:600;vertical-align:top;">
                            <label for="le_source_sheet_id">Source Sheet ID</label>
                        </td>
                        <td style="padding:10px 0;">
                            <input type="text"
                                id="le_source_sheet_id"
                                value="<?php echo $source_id; ?>"
                                placeholder="e.g. 1vEB3DhZuaBj3X..."
                                style="width:100%;box-sizing:border-box;" />
                            <?php if ($source_id) : ?>
                                <a href="https://docs.google.com/spreadsheets/d/<?php echo $source_id; ?>/edit"
                                    target="_blank" style="font-size:12px;">🔗 Open Sheet</a>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:10px 0;font-weight:600;vertical-align:top;">
                            <label for="le_target_sheet_id">Target Sheet ID</label>
                        </td>
                        <td style="padding:10px 0;">
                            <input type="text"
                                id="le_target_sheet_id"
                                value="<?php echo $target_id; ?>"
                                placeholder="e.g. 1Y1GoBeEps1OS..."
                                style="width:100%;box-sizing:border-box;" />
                            <?php if ($target_id) : ?>
                                <a href="https://docs.google.com/spreadsheets/d/<?php echo $target_id; ?>/edit"
                                    target="_blank" style="font-size:12px;">🔗 Open Sheet</a>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:10px 0;font-weight:600;vertical-align:top;">
                            <label for="le_api_key">Profile API Key</label>
                        </td>
                        <td style="padding:10px 0;">
                            <input type="password"
                                id="le_api_key"
                                value="<?php echo $api_key; ?>"
                                placeholder="Your profileapi.com API key"
                                style="width:100%;box-sizing:border-box;" />
                            <small style="color:#666;">
                                <a href="#" id="toggleApiKey" style="font-size:12px;">👁 Show/Hide</a>
                            </small>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:10px 0;font-weight:600;vertical-align:top;">
                            <label for="le_column_name">Email Column</label>
                        </td>
                        <td style="padding:10px 0;">
                            <input type="text"
                                id="le_column_name"
                                value="<?php echo $col_name; ?>"
                                placeholder="e.g. Y"
                                maxlength="3"
                                style="width:80px;text-transform:uppercase;" />
                            <small style="color:#666;margin-left:8px;">Column letter that contains emails (A, B, Y, AA...)</small>
                        </td>
                    </tr>
                </table>

                <div style="margin-top:16px;padding-top:16px;border-top:1px solid #eee;">
                    <button id="saveBtn" class="button button-primary">💾 Save Settings</button>
                    <span id="saveStatus" style="margin-left:12px;display:none;font-weight:600;"></span>
                </div>
            </div>

            <!-- ========== Run Box ========== -->
            <div style="background:#fff;border:1px solid #ccd0d4;border-radius:4px;padding:24px;max-width:680px;">
                <h2 style="margin-top:0;padding-bottom:12px;border-bottom:1px solid #eee;">🚀 Run Extractor</h2>
                <p><strong>Status:</strong> <span id="status" style="color:green;">✅ Ready</span></p>
                <button id="runBtn" class="button button-primary button-large">🚀 Run (1 Company Test)</button>
                <div id="progress" style="margin-top:15px;font-weight:bold;min-height:20px;"></div>
                <div id="debug" style="margin-top:10px;padding:15px;background:#f8f8f8;font-family:monospace;font-size:12px;max-height:400px;overflow:auto;border:1px solid #ddd;border-radius:4px;"></div>
            </div>
        </div>

        <script>
            // Show/Hide API Key
            document.getElementById('toggleApiKey').addEventListener('click', function(e) {
                e.preventDefault();
                const input = document.getElementById('le_api_key');
                input.type = input.type === 'password' ? 'text' : 'password';
            });

            // Auto uppercase column input
            document.getElementById('le_column_name').addEventListener('input', function() {
                this.value = this.value.toUpperCase();
            });

            // ✅ Save Settings
            document.getElementById('saveBtn').addEventListener('click', function() {
                const btn = this;
                const status = document.getElementById('saveStatus');
                btn.disabled = true;
                btn.innerText = 'Saving...';
                status.style.display = 'none';

                fetch(ajaxurl, {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/x-www-form-urlencoded'
                        },
                        body: new URLSearchParams({
                            action: 'le_save_settings',
                            le_source_sheet_id: document.getElementById('le_source_sheet_id').value.trim(),
                            le_target_sheet_id: document.getElementById('le_target_sheet_id').value.trim(),
                            le_api_key: document.getElementById('le_api_key').value.trim(),
                            le_column_name: document.getElementById('le_column_name').value.trim().toUpperCase(),
                            _wpnonce: '<?php echo wp_create_nonce("le_save_settings"); ?>'
                        })
                    })
                    .then(res => res.json())
                    .then(data => {
                        status.style.display = 'inline';
                        status.innerText = data.success ? '✅ Settings saved!' : '❌ Save failed';
                        status.style.color = data.success ? 'green' : 'red';
                        btn.disabled = false;
                        btn.innerText = '💾 Save Settings';
                    })
                    .catch(err => {
                        status.style.display = 'inline';
                        status.innerText = '❌ Error: ' + err;
                        status.style.color = 'red';
                        btn.disabled = false;
                        btn.innerText = '💾 Save Settings';
                    });
            });

            // ✅ Log helper
            function log(msg) {
                document.getElementById('debug').innerHTML += '<span style="color:#1565c0">' + new Date().toLocaleTimeString() + ': ' + msg + '</span><br>';
                document.getElementById('debug').scrollTop = document.getElementById('debug').scrollHeight;
            }

            // ✅ Run Extractor
            document.getElementById('runBtn').addEventListener('click', function() {
                document.getElementById('status').innerText = 'Processing...';
                document.getElementById('status').style.color = 'blue';
                document.getElementById('progress').innerText = 'Starting extraction...';
                document.getElementById('debug').innerHTML = '';
                log('🔄 Starting Lead Extraction...');

                fetch(ajaxurl, {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/x-www-form-urlencoded'
                        },
                        body: 'action=le_run_process'
                    })
                    .then(res => res.text())
                    .then(data => {
                        console.log('✅ Response: ' + data);
                        document.getElementById('progress').innerHTML = '<strong style="color:green">' + data + '</strong>';
                        document.getElementById('status').innerText = '✅ Completed';
                        document.getElementById('status').style.color = 'green';
                    })
                    .catch(err => {
                        log('❌ Error: ' + err);
                        document.getElementById('progress').innerText = '❌ Error — check debug.log';
                        document.getElementById('status').style.color = 'red';
                    });
            });
        </script>
<?php }

    // ✅ Save Settings AJAX
    public function ajax_save_settings() {
        if (!check_ajax_referer('le_save_settings', '_wpnonce', false)) {
            wp_send_json_error(['message' => 'Invalid nonce']);
            return;
        }
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Unauthorized']);
            return;
        }

        update_option('le_source_sheet_id', sanitize_text_field($_POST['le_source_sheet_id'] ?? ''));
        update_option('le_target_sheet_id', sanitize_text_field($_POST['le_target_sheet_id'] ?? ''));
        update_option('le_api_key',         sanitize_text_field($_POST['le_api_key']         ?? ''));
        update_option('le_column_name',     strtoupper(sanitize_text_field($_POST['le_column_name'])));

        wp_send_json_success(['message' => 'Settings saved']);
    }



    public function ajax_process() {

        $result = $this->process();

        wp_send_json(['message' => $result]);
    }
}



new Lead_Executives_Extractor();
