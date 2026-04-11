<?php

class Lead_Executives_Extractor {
    private $input_sheet_csv = 'https://docs.google.com/spreadsheets/d/1vEB3DhZuaBj3XjdzLR6lEZfbsC6rvbGx8GN1ud3Wo_U/export?format=csv&gid=0';
    private $webhook_url = 'https://script.google.com/macros/s/AKfycbxaqk7EqYZ2qLf88MiGieR6m_-SVvhq-0JZ6SchgeAmYfV4gpixgfJhsFsRB2ESpRPP/exec';
    private $api_key = 'b0cf2cb9fe3edba7372097a3459e8d2945cb2a63b87b182782e10751931f4b48';

    private $processed_domains = [];
    private $processed_people = [];

    public function __construct() {
        add_action('admin_menu', [$this, 'menu']);
        add_action('wp_ajax_le_run_process', [$this, 'ajax_process']);
    }

    public function menu() {
        add_menu_page('Lead Extractor Ultra', 'Lead Extractor Ultra', 'manage_options', 'lead-extractor-ultra', [$this, 'admin_page']);
    }

    public function admin_page() {
        ?>
        <div class="wrap">
            <h1>🔥 Lead Extractor ULTRA v7.3 - Local CSV Backup</h1>
            <p><strong>Status:</strong> <span id="status" style="color:green;">✅ Ready</span></p>
            <button id="runBtn" class="button button-primary button-large">🚀 Run Test (1 Company)</button>
            <div id="progress" style="margin-top:20px;font-weight:bold;min-height:20px;"></div>
            <div id="debug" style="margin-top:10px;padding:15px;background:#f1f1f1;font-family:monospace;font-size:12px;max-height:400px;overflow:auto;"></div>
            <p><small>📡 Check <code>wp-content/debug.log</code> for logs</small></p>
        </div>
        <script>
        function log(msg) {
            document.getElementById('debug').innerHTML += '<span style="color:#1565c0">' + new Date().toLocaleTimeString() + ': ' + msg + '</span><br>';
            document.getElementById('debug').scrollTop = document.getElementById('debug').scrollHeight;
        }

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
            .then(res => res.json())
            .then(data => {
                log('✅ Server Response: ' + data.message);
                document.getElementById('progress').innerHTML = '<strong style="color:green">' + data.message + '</strong>';
                document.getElementById('status').innerText = '✅ Completed';
                document.getElementById('status').style.color = 'green';
            })
            .catch(err => {
                log('❌ Error: ' + err);
                document.getElementById('progress').innerText = '❌ Error occurred - check debug.log';
                document.getElementById('status').style.color = 'red';
            });
        });
        </script>
        <?php
    }

    public function ajax_process() {
        $result = $this->process();
        wp_send_json(['message' => $result]);
    }

    private function get_sheet_data() {
        $response = wp_remote_get($this->input_sheet_csv, ['timeout' => 15]);
        if (is_wp_error($response)) {
            error_log('❌ Sheet fetch error: ' . $response->get_error_message());
            return [];
        }
        $body = wp_remote_retrieve_body($response);
        $csv_data = array_map('str_getcsv', explode("\n", $body));
        error_log('📊 Sheet rows loaded: ' . (count($csv_data) - 1));
        return $csv_data;
    }

    private function extract_domain($email) {
        if (!is_email($email)) return null;
        $parts = explode('@', strtolower(trim($email)));
        return $parts[1] ?? null;
    }

    private function get_company_id($domain) {
        error_log("🔍 Searching company for: $domain");
        $url = "https://api.profileapi.com/2024-03-01/companies/find";
        $response = wp_remote_post($url, [
            'headers' => [
                'Authorization' => 'ApiKey ' . $this->api_key,
                'Content-Type'  => 'application/json',
                'Accept'        => 'application/json',
            ],
            'body' => wp_json_encode([
                "filters" => ["all" => [["key" => "website", "value" => $domain, "operator" => "="]]],
                "datasets" => ["all"],
                "limit" => 1
            ]),
            'timeout' => 20
        ]);

        if (is_wp_error($response)) {
            error_log('❌ Company API Error: ' . $response->get_error_message());
            return null;
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);
        $company_id = $body['data']['results'][0]['id'] ?? null;
        error_log("✅ Company ID: " . ($company_id ?: 'NOT FOUND'));
        return $company_id;
    }

    private function get_persons($company_id) {
        $titles = ['ceo','cto','cfo','vp','director','manager','head','president','founder'];
        $filters_any = [];
        foreach ($titles as $title) {
            $filters_any[] = ["key"=>"titleAtCurrentCompany","value"=>$title,"operator"=>"~"];
        }

        $body = [
            "filters"=>["all"=>[["key"=>"currentCompanyId","value"=>$company_id,"operator"=>"="]],"any"=>$filters_any],
            "datasets"=>["basic"],
            "limit"=>5
        ];

        $response = wp_remote_post("https://api.profileapi.com/2024-03-01/persons/find", [
            'headers'=>[
                'Authorization'=>'ApiKey '.$this->api_key,
                'Content-Type'=>'application/json',
                'Accept'=>'application/json'
            ],
            'body'=>wp_json_encode($body),
            'timeout'=>30
        ]);

        if (is_wp_error($response)) {
            error_log('❌ Persons API Error: ' . $response->get_error_message());
            return [];
        }
        $data = json_decode(wp_remote_retrieve_body($response), true);

        if(empty($data['data']['results'])) {
            error_log('⚠️ No executives found');
            return [];
        }

        $unique = [];
        foreach($data['data']['results'] as $person) {
            if(!empty($person['id'])) $unique[$person['id']] = $person;
        }
        $persons = array_values($unique);
        error_log("✅ Found " . count($persons) . " executives");
        return $persons;
    }

    private function get_contact_info($person_id) {
        $contact = ['email'=>'','phone'=>''];

        $types = ['professional','personal'];
        foreach ($types as $type) {
            if(empty($contact['email'])) {
                $emailData = $this->api_post("https://api.profileapi.com/2024-03-01/email-contacts/lookup", [
                    "type"=>$type, "id"=>$person_id
                ]);
                if(!empty($emailData['email'])) $contact['email'] = $emailData['email'];
            }

            if(empty($contact['phone'])) {
                $phoneData = $this->api_post("https://api.profileapi.com/2024-03-01/phone-contacts/lookup", [
                    "type"=>$type, "id"=>$person_id
                ]);
                if(!empty($phoneData['phone'])) $contact['phone'] = $phoneData['phone'];
            }
        }

        error_log("📧 Contact {$person_id}: " . json_encode($contact));
        return $contact;
    }

    private function api_post($url,$body) {
        $response = wp_remote_post($url, [
            'headers'=>[
                'Authorization'=>'ApiKey '.$this->api_key,
                'Content-Type'=>'application/json',
                'Accept'=>'application/json'
            ],
            'body'=>wp_json_encode($body),
            'timeout'=>30
        ]);

        if(is_wp_error($response)) return [];
        return json_decode(wp_remote_retrieve_body($response), true) ?? [];
    }

    private function is_duplicate_person($person_id,$email) {
        $key = md5($person_id.$email);
        if(isset($this->processed_people[$key])) return true;
        $this->processed_people[$key] = true;
        return false;
    }

    // ✅ Google Sheet → Webhook + Local CSV both
    private function send_to_sheet($row, $domain) {
        if(empty($row['name']) && empty($row['title']) && empty($row['linkedin'])) {
            error_log("⚠️ Skipping empty row");
            return false;
        }

        // --- 1. Webhook (Google Sheet) ---
        $response = wp_remote_post($this->webhook_url, [
            'body'=>json_encode($row),
            'headers'=>['Content-Type'=>'application/json'],
            'timeout'=>20
        ]);

        $webhook_ok = false;
        if (is_wp_error($response)) {
            error_log('❌ Webhook failed: ' . $response->get_error_message());
        } else {
            $status = wp_remote_retrieve_response_code($response);
            error_log("✅ Webhook Status: $status for $domain");
            $webhook_ok = ($status >= 200 && $status < 300);
        }

        // --- 2. Local CSV Backup (Always save) ---
        $this->emergency_csv($row, $domain);

        return $webhook_ok;
    }

    // ✅ 🚨 LOCAL CSV BACKUP (সবসময় সেভ হবে)
    private function emergency_csv($row, $domain) {
        // ফোল্ডার পাথ করে নিচ্ছি
        $base_dir = WP_CONTENT_DIR . '/Leads-Executives-Extractor/';
        if (!is_dir($base_dir)) {
            wp_mkdir_p($base_dir); // ফোল্ডার না থাকলে বানিয়ে নেয়
        }

        $file = $base_dir . 'leads-export-' . date('Y-m-d') . '.csv';
        $headers = ['Company','Name','Title','Email','Phone','LinkedIn'];

        // ফাইল না থাকলে হেডার লিখে নেয়
        if (!file_exists($file)) {
            file_put_contents($file, implode(',', $headers) . "\n");
            error_log("✅ Created CSV: $file");
        }

        // ডেটা লাইন বানাচ্ছি
        $csv_row = implode(',', [
            $domain,
            $row['name'] ?? '',
            $row['title'] ?? '',
            $row['email'] ?? '',
            $row['phone'] ?? '',
            $row['linkedin'] ?? ''
        ]);

        file_put_contents($file, $csv_row . "\n", FILE_APPEND);
        error_log("✅ CSV row saved: " . $csv_row);
    }

    public function process() {
        error_log("🎬 === LEAD EXTRACTOR v7.3 STARTED ===");

        $rows = $this->get_sheet_data();
        if(empty($rows)) {
            return '❌ No data in Google Sheet';
        }

        // Find first valid domain (Single test)
        $domain = null;
        foreach($rows as $i => $row) {
            if($i === 0) continue;
            $email = trim($row[24] ?? '');
            if($email && is_email($email)) {
                $domain = $this->extract_domain($email);
                if($domain) break;
            }
        }

        if(!$domain) {
            return '❌ No valid email found in column 25';
        }

        error_log("🎯 Target Domain: $domain");
        $this->processed_domains[$domain] = true;

        $company_id = $this->get_company_id($domain);
        if(!$company_id) {
            return "❌ Company not found: $domain";
        }

        $persons = $this->get_persons($company_id);
        if(empty($persons)) {
            return "❌ No executives: $company_id";
        }

        $sent_count = 0;
        foreach($persons as $person) {
            $contact = $this->get_contact_info($person['id']);
            if($this->is_duplicate_person($person['id'], $contact['email'])) continue;

            $primaryExp = null;
            if(!empty($person['experiences'])) {
                foreach($person['experiences'] as $exp) {
                    if(!empty($exp['isCurrent'])) { $primaryExp = $exp; break; }
                }
                if(!$primaryExp) $primaryExp = $person['experiences'][0];
            }

            $row_to_send = [
                'company' => $domain,
                'name' => $person['name'] ?? trim(($person['firstName']??'').' '.($person['lastName']??'')),
                'title' => $primaryExp['title']??'N/A',
                'email' => $contact['email']??'',
                'phone' => $contact['phone']??'',
                'linkedin' => $person['linkedInUrl']??''
            ];

            error_log("📩 Row to send: " . json_encode($row_to_send));

            if($this->send_to_sheet($row_to_send, $domain)) {
                $sent_count++;
            }
        }

        $message = "✅ SUCCESS! Domain: $domain | Webhook: " . $sent_count . " rows | CSV: ALL stored";
        error_log("🏁 === $message ===");
        return $message;
    }
}

new Lead_Executives_Extractor();
