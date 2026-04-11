<?php
/**
 * Plugin Name: Leads Executives Extractor
 * Description: Extracts domains from Google Sheet Y column emails, searches ProfileAPI for executives, saves to output sheet with duplicate checks.
 * Version: 1.0
 * Author: Custom for Synchronise backup
 */

// Prevent direct access
if (!defined('ABSPATH')) exit;

class LeadsExecutivesExtractor {
    private $input_sheet_id;
    private $output_sheet_id;
    private $profile_api_key;
    private $google_client;
    private $sheets_service;

    public function __construct() {
        add_action('init', [$this, 'init']);
        register_activation_hook(__FILE__, [$this, 'activate']);
        register_deactivation_hook(__FILE__, [$this, 'deactivate']);
    }

    public function init() {
        add_action('admin_menu', [$this, 'admin_menu']);
        add_action('wp_ajax_process_leads', [$this, 'ajax_process_leads']);
        add_action('wp_ajax_save_settings', [$this, 'ajax_save_settings']);
    }

    public function activate() {
        flush_rewrite_rules();
    }

    public function deactivate() {
        wp_clear_scheduled_hook('leads_cron');
        flush_rewrite_rules();
    }

    public function admin_menu() {
        add_menu_page(
            'Leads Executives',
            'Leads Executives',
            'manage_options',
            'leads-executives',
            [$this, 'admin_page']
        );
    }

    public function admin_page() {
        $settings = get_option('leads_extractor_settings', []);
        $message = get_transient('leads_message');
        delete_transient('leads_message');
        ?>
        <div class="wrap">
            <h1>Leads Executives Extractor</h1>
            <?php if ($message): ?>
                <div class="notice notice-<?php echo strpos($message, 'Error') === false ? 'success' : 'error'; ?> is-dismissible">
                    <p><?php echo esc_html($message); ?></p>
                </div>
            <?php endif; ?>
            
            <h2>Settings</h2>
            <form method="post" action="">
                <table class="form-table">
                    <tr>
                        <th>ProfileAPI Key</th>
                        <td><input type="text" name="api_key" value="<?php echo esc_attr($settings['api_key'] ?? ''); ?>" class="regular-text" required></td>
                    </tr>
                    <tr>
                        <th>Input Google Sheet ID</th>
                        <td>
                            <input type="text" name="input_sheet" value="<?php echo esc_attr($settings['input_sheet'] ?? ''); ?>" class="regular-text" placeholder="From URL: docs.google.com/spreadsheets/d/SHEET_ID">
                            <p class="description">Extract ID from your sheet link: https://docs.google.com/spreadsheets/d/<strong>1vEB3DhZuaBj3XjdzLR6lEZfbsC6rvbGx8GN1ud3Wo_U</strong>/edit</p>
                        </td>
                    </tr>
                    <tr>
                        <th>Output Google Sheet ID</th>
                        <td><input type="text" name="output_sheet" value="<?php echo esc_attr($settings['output_sheet'] ?? ''); ?>" class="regular-text" required placeholder="Create new sheet and paste ID"></td>
                    </tr>
                    <tr>
                        <th>Google Service Account JSON</th>
                        <td>
                            <textarea name="service_json" rows="10" class="large-text"><?php echo esc_textarea($settings['service_json'] ?? ''); ?></textarea>
                            <p class="description">1. Go to console.cloud.google.com/apis/credentials 2. Create Service Account 3. Download JSON 4. Enable Sheets API 5. Share both sheets with service account email.</p>
                        </td>
                    </tr>
                </table>
                <?php wp_nonce_field('leads_settings'); ?>
                <p><input type="submit" name="save_settings" class="button-primary" value="Save Settings"></p>
            </form>

            <h2>Process Leads</h2>
            <?php if (!empty($settings['api_key'])): ?>
                <p>
                    <button id="process-leads" class="button-primary">Process Now</button>
                    <span id="progress" style="display:none;">Processing...</span>
                </p>
            <?php else: ?>
                <p class="notice notice-warning">Save settings first!</p>
            <?php endif; ?>
        </div>
        <script>
        jQuery(document).ready(function($) {
            $('#process-leads').click(function() {
                $('#progress').show();
                $.post(ajaxurl, {action: 'process_leads', nonce: '<?php echo wp_create_nonce('leads_process'); ?>'}, function(res) {
                    location.reload();
                });
            });
        });
        </script>
        <?php
    }

    public function ajax_save_settings() {
        check_ajax_referer('leads_settings', 'nonce');
        if (isset($_POST['save_settings'])) {
            $settings = [
                'api_key' => sanitize_text_field($_POST['api_key']),
                'input_sheet' => sanitize_text_field($_POST['input_sheet']),
                'output_sheet' => sanitize_text_field($_POST['output_sheet']),
                'service_json' => sanitize_textarea_field($_POST['service_json'])
            ];
            update_option('leads_extractor_settings', $settings);
            set_transient('leads_message', 'Settings saved successfully!', 30);
        }
        wp_send_json_success();
    }

    public function ajax_process_leads() {
        check_ajax_referer('leads_process', 'nonce');
        $settings = get_option('leads_extractor_settings');
        if (!$this->init_google_client($settings['service_json'])) {
            wp_send_json_error('Google Client init failed');
        }

        $this->input_sheet_id = $settings['input_sheet'];
        $this->output_sheet_id = $settings['output_sheet'];
        $this->profile_api_key = $settings['api_key'];

        $processed = 0;
        $errors = [];

        try {
            $emails = $this->read_input_emails();
            $output_data = $this->read_output_data(); // For duplicates

            $domains_processed = [];
            foreach ($emails as $email) {
                if (!$email) continue;

                $domain = $this->extract_domain($email);
                if (!$domain || isset($domains_processed[$domain])) continue; // Skip duplicate domains
                $domains_processed[$domain] = true;

                $executives = $this->search_profileapi_executives($domain);
                foreach ($executives as $exec) {
                    if ($this->is_duplicate($output_data, $exec['email'] ?? '', $domain)) continue;
                    $this->append_to_output($exec);
                    $processed++;
                }
            }

            set_transient('leads_message', "Processed: $processed executives added.", 30);
            wp_send_json_success($processed);

        } catch (Exception $e) {
            $errors[] = $e->getMessage();
            set_transient('leads_message', 'Error: ' . implode('; ', $errors), 30);
            wp_send_json_error($errors);
        }
    }

    private function init_google_client($json) {
        if (!$json || !file_put_contents(plugin_dir_path(__FILE__) . 'service-account.json', $json)) {
            return false;
        }

        if (!class_exists('Google_Client')) {
            require_once plugin_dir_path(__FILE__) . 'vendor/autoload.php';
        }

        $this->google_client = new Google_Client();
        $this->google_client->setAuthConfig(plugin_dir_path(__FILE__) . 'service-account.json');
        $this->google_client->addScope(Google_Service_Sheets::SPREADSHEETS);
        $this->sheets_service = new Google_Service_Sheets($this->google_client);
        return true;
    }

    private function read_input_emails() {
        $range = 'Leads!Y:Y'; // Assume tab name 'Leads', adjust if needed
        $response = $this->sheets_service->spreadsheets_values->get($this->input_sheet_id, $range);
        return $response->getValues() ?: [];
    }

    private function read_output_data() {
        $range = 'Executives!A:Z'; // Read all for duplicate check
        try {
            $response = $this->sheets_service->spreadsheets_values->get($this->output_sheet_id, $range);
            $data = [];
            foreach ($response->getValues() ?? [] as $row) {
                if (count($row) >= 2) { // Assume col A: email, B: domain
                    $data[strtolower($row[0]) . '|' . strtolower($row[1] ?? '')] = true;
                }
            }
            return $data;
        } catch (Exception $e) {
            return []; // New sheet, no data
        }
    }

    private function extract_domain($email) {
        $parts = explode('@', trim($email));
        return isset($parts[1]) ? strtolower($parts[1]) : false;
    }

    private function search_profileapi_executives($domain) {
        $exec_titles = 'CEO|CFO|CTO|CMO|CIO|VP|Vice President|Director|Manager|Head of';
        $url = 'https://api.profileapi.com/2024-03-01/persons/search';

        $body = json_encode([
            'filters' => [
                'all' => [
                    ['key' => 'currentCompanyWebsite', 'value' => $domain, 'operator' => '='],
                    ['key' => 'titleAtCurrentCompany', 'value' => $exec_titles, 'operator' => '~']
                ]
            ],
            'limit' => 100
        ]);

        $args = [
            'method' => 'POST',
            'headers' => [
                'Authorization' => 'ApiKey ' . $this->profile_api_key,
                'Content-Type' => 'application/json'
            ],
            'body' => $body,
            'timeout' => 30
        ];

        $response = wp_remote_post($url, $args);
        if (is_wp_error($response)) {
            throw new Exception('API Request failed: ' . $response->get_error_message());
        }

        $body_resp = wp_remote_retrieve_body($response);
        $data = json_decode($body_resp, true);

        if (isset($data['data'])) {
            return array_map(function($person) use ($domain) {
                return [
                    'name' => $person['name'] ?? '',
                    'email' => $person['email'] ?? '',
                    'title' => $person['titleAtCurrentCompany'] ?? '',
                    'company' => $person['currentCompany']['name'] ?? '',
                    'domain' => $domain,
                    'linkedin' => $person['linkedin'] ?? '',
                    'phone' => $person['phone'] ?? ''
                ];
            }, $data['data']);
        }

        return [];
    }

    private function is_duplicate($output_data, $email, $domain) {
        $key = strtolower($email) . '|' . $domain;
        return isset($output_data[$key]);
    }

    private function append_to_output($exec) {
        $range = 'Executives!A:H'; // Columns: Name, Email, Title, Company, Domain, LinkedIn, Phone, Original Email
        $values = [
            [
                $exec['name'],
                $exec['email'],
                $exec['title'],
                $exec['company'],
                $exec['domain'],
                $exec['linkedin'],
                $exec['phone'],
                '' // Add original if needed
            ]
        ];

        $body = new Google_Service_Sheets_ValueRange(['values' => $values]);
        $params = ['valueInputOption' => 'RAW'];

        $this->sheets_service->spreadsheets_values->append(
            $this->output_sheet_id,
            $range,
            $body,
            $params
        );
    }
}

new LeadsExecutivesExtractor();
?>