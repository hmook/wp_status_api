<?php
/**
 * Plugin Name: Status API
 * Description: Deze plugin maakt een API end-point aan voor het geven van storings informatie.
 * Version: 0.9.2
 * Author: Hanno-Wybren Mook
 * License: Proprietary
 */

// Voorkom direct toegang tot het bestand
if (!defined('ABSPATH')) {
    exit;
}

// Hoofdklasse voor de plugin
class Status_API_Plugin {
    
    // Instance van de plugin (singleton pattern)
    private static $instance = null;
    
    // API Manager, Status Manager en History Manager
    private $api_manager;
    public $status_manager;
    private $history_manager;
    
    /**
     * Constructor - initialiseert de plugin
     */
    private function __construct() {
        // Laad de managers
        $this->api_manager = new Status_API_Manager();
        $this->status_manager = new Status_Message_Manager();
        $this->history_manager = new Status_History_Manager();
        
        // Voeg admin menu's toe
        add_action('admin_menu', array($this, 'add_admin_menus'), 10);
        
        // Register activation and deactivation hooks
        register_activation_hook(__FILE__, array($this, 'activate_plugin'));
        register_deactivation_hook(__FILE__, array($this, 'deactivate_plugin'));
    }
    
    /**
     * Singleton pattern - zorgt voor één instantie van de plugin
     */
    public static function get_instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    /**
     * Voeg alle admin menu's toe in de juiste volgorde
     */
    public function add_admin_menus() {
        // Voeg hoofdmenu toe
        add_menu_page(
            'Status API',
            'Status API',
            'edit_posts',
            'status-api',
            array($this->status_manager, 'display_status_page'),
            'dashicons-database-view',
            30
        );
        
        // Voeg Status Melding submenu toe (kopie van hoofdmenu)
        add_submenu_page(
            'status-api',
            'Status Melding',
            'Status Melding',
            'edit_posts',
            'status-api',
            array($this->status_manager, 'display_status_page')
        );
        
        // Voeg Historie submenu toe
        add_submenu_page(
            'status-api',
            'Status Historie',
            'Status Historie',
            'edit_posts',
            'status-api-history',
            array($this->history_manager, 'display_history_page')
        );
        
        // Voeg API Instellingen submenu toe
        add_submenu_page(
            'status-api',
            'API Instellingen',
            'API Instellingen',
            'manage_options',
            'status-api-settings',
            array($this->api_manager, 'display_settings_page')
        );
    }
    
    /**
     * Plugin activatie hook
     */
    public function activate_plugin() {
        // Voer activatie taken uit voor alle managers
        $this->api_manager->activate();
        $this->status_manager->activate();
        $this->history_manager->activate();
        
        // Spoel de rewrite rules door
        flush_rewrite_rules();
    }
    
    /**
     * Plugin deactivatie hook
     */
    public function deactivate_plugin() {
        // Voer deactivatie taken uit voor alle managers
        $this->api_manager->deactivate();
        $this->status_manager->deactivate();
        $this->history_manager->deactivate();
        
        // Spoel de rewrite rules door
        flush_rewrite_rules();
    }
}

// API Manager Klasse - verantwoordelijk voor API authenticatie en endpoints
class Status_API_Manager {
    
    // API instellingen
    private $api_key_option = 'status_api_key';
    private $api_secret_option = 'status_api_secret';
    
    /**
     * Constructor
     */
    public function __construct() {
        // Registreer API endpoints
        add_action('rest_api_init', array($this, 'register_api_endpoints'));
        
        // Verwerk formulier acties
        add_action('admin_init', array($this, 'process_form_actions'));
    }
    
    /**
     * Plugin activatie
     */
    public function activate() {
        // Genereer API sleutels als ze nog niet bestaan
        if (empty(get_option($this->api_key_option)) || empty(get_option($this->api_secret_option))) {
            $this->generate_api_keys();
        }
    }
    
    /**
     * Plugin deactivatie
     */
    public function deactivate() {
        // Niets nodig voor deactivatie
    }
    
    /**
     * Verwerk formulier acties
     */
    public function process_form_actions() {
        // Controleer of we op de API instellingen pagina zijn
        if (!isset($_GET['page']) || $_GET['page'] !== 'status-api-settings') {
            return;
        }
        
        // Verwerk het opnieuw genereren van API sleutels
        if (isset($_POST['regenerate_keys']) && isset($_POST['regenerate_api_keys_nonce']) && 
            wp_verify_nonce($_POST['regenerate_api_keys_nonce'], 'regenerate_api_keys')) {
            $this->generate_api_keys();
            
            // Redirect naar instellingenpagina
            wp_redirect(add_query_arg(
                array(
                    'page' => 'status-api-settings', 
                    'message' => 'keys_regenerated'
                ),
                admin_url('admin.php')
            ));
            exit;
        }
    }
    
    /**
     * Genereer Bearer token van API key en secret
     */
    private function generate_bearer_token($api_key, $api_secret) {
        // Maak een HMAC-SHA256 hash van de API key met het secret als sleutel
        $signature = hash_hmac('sha256', $api_key, $api_secret);
        
        // Combineer API key en signature met een delimiter
        return base64_encode($api_key . ':' . $signature);
    }
    
    /**
     * Valideer Bearer token
     */
    private function validate_bearer_token($token, $stored_key, $stored_secret) {
        // Decodeer de token
        $decoded = base64_decode($token, true);
        
        if ($decoded === false) {
            return false;
        }
        
        // Split de token in API key en signature
        $parts = explode(':', $decoded, 2);
        
        if (count($parts) !== 2) {
            return false;
        }
        
        list($api_key, $signature) = $parts;
        
        // Controleer of de API key overeenkomt
        if ($api_key !== $stored_key) {
            return false;
        }
        
        // Genereer de verwachte signature
        $expected_signature = hash_hmac('sha256', $api_key, $stored_secret);
        
        // Vergelijk de signatures (timing-safe)
        return hash_equals($expected_signature, $signature);
    }
    
    /**
     * Toon instellingen pagina
     */
    public function display_settings_page() {
        $api_key = get_option($this->api_key_option);
        $api_secret = get_option($this->api_secret_option);
        $bearer_token = $this->generate_bearer_token($api_key, $api_secret);
        
        ?>
        <div class="wrap">
            <h1>Status API Instellingen</h1>
            
            <?php if (isset($_GET['message']) && $_GET['message'] === 'keys_regenerated') : ?>
                <div class="notice notice-success is-dismissible">
                    <p>API sleutels opnieuw gegenereerd!</p>
                </div>
            <?php endif; ?>
            
            <p>Gebruik deze API sleutels om toegang te krijgen tot de Status API</p>
            
            <table class="form-table">
                <tr>
                    <th scope="row">API Sleutel</th>
                    <td>
                        <input type="text" class="regular-text" value="<?php echo esc_attr($api_key); ?>" readonly />
                    </td>
                </tr>
                <tr>
                    <th scope="row">API Secret</th>
                    <td>
                        <input type="text" class="regular-text" value="<?php echo esc_attr($api_secret); ?>" readonly />
                    </td>
                </tr>
                <tr>
                    <th scope="row">Bearer Token</th>
                    <td>
                        <textarea class="large-text" rows="2" readonly><?php echo esc_textarea($bearer_token); ?></textarea>
                        <p class="description">Deze token combineert de API sleutel en secret voor veilige authenticatie</p>
                    </td>
                </tr>
            </table>
            
            <form method="post" action="">
                <?php wp_nonce_field('regenerate_api_keys', 'regenerate_api_keys_nonce'); ?>
                <p>
                    <button type="submit" name="regenerate_keys" class="button button-primary">
                        Genereer nieuwe API sleutels
                    </button>
                </p>
            </form>
            
            <h2>API Documentatie</h2>
            <p>De Status API biedt toegang tot de huidige status via het volgende endpoint:</p>
            
            <h3>Huidige status ophalen</h3>
            <p>Endpoint: <code><?php echo esc_html(site_url('/wp-json/status-api/v1/status')); ?></code></p>
            <p>Methode: <code>GET</code></p>
            <p>Authenticatie: Bearer token of API sleutel/secret</p>
            
            <h4>Response formaat:</h4>
            <pre>
{
    "title": "Status titel",
    "text": "Status beschrijving",
    "baseURL": "<?php echo esc_html(site_url()); ?>",
    "status": "geen|green|orange|red",
    "timestamp": 1234567890,
    "statusExpiryDate": "2025-12-31 23:59" (alleen bij groene status met vervaldatum),
    "statusExpiryTimestamp": 1234567890 (alleen bij groene status met vervaldatum)
}
            </pre>
            
            <h3>Authenticatie voorbeelden</h3>
            
            <h4>Methode 1: Bearer Token (Aanbevolen)</h4>
            <p>Gebruik de gecombineerde Bearer token voor maximale veiligheid:</p>
            <pre>
Authorization: Bearer <?php echo esc_html($bearer_token); ?>
            </pre>
            
            <h4>Methode 2: API Sleutel en Secret</h4>
            <p>Voeg de volgende parameters toe aan je aanvraag:</p>
            <pre>
api_key=<?php echo esc_html($api_key); ?>&api_secret=<?php echo esc_html($api_secret); ?>
            </pre>
            
            <h3>Voorbeeld API aanroepen</h3>
            
            <h4>cURL voorbeeld met Bearer token:</h4>
            <pre>
curl -H "Authorization: Bearer <?php echo esc_html($bearer_token); ?>" \
     <?php echo esc_html(site_url('/wp-json/status-api/v1/status')); ?>
            </pre>
            
            <h4>JavaScript fetch voorbeeld:</h4>
            <pre>
fetch('<?php echo esc_js(site_url('/wp-json/status-api/v1/status')); ?>', {
    headers: {
        'Authorization': 'Bearer <?php echo esc_js($bearer_token); ?>'
    }
})
.then(response => response.json())
.then(data => console.log(data));
            </pre>
            
            <h2>Bearer Token Beveiliging</h2>
            <p>De Bearer token wordt gegenereerd met <strong>HMAC-SHA256</strong> voor maximale beveiliging:</p>
            
            <div style="background-color: #f5f5f5; padding: 15px; border-radius: 5px; margin: 20px 0;">
                <h4 style="margin-top: 0;">Hoe de Bearer token wordt gegenereerd:</h4>
                <ol>
                    <li><strong>HMAC-SHA256 Signature</strong>
                        <ul>
                            <li>Algoritme: SHA-256</li>
                            <li>Input data: API Sleutel (32 karakters)</li>
                            <li>Geheime sleutel: API Secret (64 karakters)</li>
                            <li>Output: 64-karakter hexadecimale hash</li>
                        </ul>
                    </li>
                    <li><strong>Token Constructie</strong>
                        <ul>
                            <li>Formaat: <code>API_KEY:HMAC_SIGNATURE</code></li>
                            <li>Encoding: Base64</li>
                        </ul>
                    </li>
                </ol>
                
                <h4>Waarom is dit veilig?</h4>
                <ul>
                    <li><strong>Cryptografisch sterk</strong>: SHA-256 is een bewezen veilig hash-algoritme</li>
                    <li><strong>Geheim-afhankelijk</strong>: Alleen met het juiste API Secret kan de correcte signature worden gegenereerd</li>
                    <li><strong>Tamper-proof</strong>: Elke wijziging in de API key resulteert in een compleet andere signature</li>
                    <li><strong>Niet-omkeerbaar</strong>: Het is onmogelijk om van de token terug te rekenen naar het API Secret</li>
                </ul>
                
                <h4>Voorbeeld berekening:</h4>
                <pre style="background-color: #fff; padding: 10px; border: 1px solid #ddd;">
API Sleutel: <?php echo substr(esc_html($api_key), 0, 16); ?>... (verkort voor veiligheid)
API Secret: <?php echo substr(esc_html($api_secret), 0, 16); ?>... (verkort voor veiligheid)

HMAC-SHA256: hash_hmac('sha256', api_key, api_secret)
Resultaat: [64 karakters hex]

Bearer Token: base64_encode(api_key + ':' + hmac_signature)
                </pre>
            </div>
        </div>
        <?php
    }
    
    /**
     * Registreer REST API endpoints
     */
    public function register_api_endpoints() {
        register_rest_route('status-api/v1', '/status', array(
            'methods' => 'GET',
            'callback' => array($this, 'get_status'),
            'permission_callback' => array($this, 'check_api_authentication')
        ));
    }
    
    /**
     * Controleer API authenticatie
     */
    public function check_api_authentication($request) {
        $stored_key = get_option($this->api_key_option);
        $stored_secret = get_option($this->api_secret_option);
        
        // METHODE 1: Check voor API key en secret in query parameters
        $api_key = $request->get_param('api_key');
        $api_secret = $request->get_param('api_secret');
        
        if (!empty($api_key) && !empty($api_secret)) {
            if ($api_key === $stored_key && $api_secret === $stored_secret) {
                return true;
            }
        }
        
        // METHODE 2: Bearer token authenticatie (beveiligd met HMAC)
        $auth_header = $request->get_header('Authorization') ?: $request->get_header('authorization');
        
        if ($auth_header && preg_match('/Bearer\s+(.*)$/i', $auth_header, $matches)) {
            $token = trim($matches[1]);
            
            // Valideer de Bearer token
            if ($this->validate_bearer_token($token, $stored_key, $stored_secret)) {
                return true;
            }
        }
        
        // Authenticatie mislukt
        return new WP_Error(
            'rest_forbidden',
            'Toegang geweigerd. Geldige authenticatie vereist.',
            array('status' => 401)
        );
    }
    
    /**
     * Haal status op en retourneer als API response
     */
    public function get_status() {
        // Gebruik de status manager van de parent plugin instance
        global $status_api_plugin;
        if (isset($status_api_plugin->status_manager)) {
            $status_api_plugin->status_manager->check_and_update_expired_status();
        }
        
        // Verkrijg huidige status data met betere defaults
        $status_data = $this->get_status_data();
        
        // Formatteer response
        $response = array(
            'title' => $status_data['title'],
            'text' => $status_data['content'],
            'baseURL' => site_url(),
            'status' => $status_data['status'],
            'timestamp' => current_time('timestamp'),
            'statusExpiryDate' => $status_data['expiry_date'],
            'statusExpiryTimestamp' => !empty($status_data['expiry_date'])
                ? strtotime($status_data['expiry_date'])
                : null,
        );
    
        return rest_ensure_response($response);
    }
    
    /**
     * Helper functie om status data op te halen
     */
    private function get_status_data() {
        static $cached_status = null;
        
        // Cache de status data voor de duur van de request
        if ($cached_status === null) {
            $defaults = array(
                'title' => '',
                'content' => '',
                'status' => 'geen',
                'expiry_date' => null
            );

            $option = get_option('status_message_data', array());
            $cached_status = wp_parse_args($option, $defaults);

            if (is_string($cached_status['expiry_date']) && trim($cached_status['expiry_date']) === '') {
                $cached_status['expiry_date'] = null;
            }
        }
        
        return $cached_status;
    }
    
    /**
     * Genereer nieuwe API sleutels
     */
    public function generate_api_keys() {
        $api_key = wp_generate_password(32, false);
        $api_secret = wp_generate_password(64, false);
        
        update_option($this->api_key_option, $api_key);
        update_option($this->api_secret_option, $api_secret);
    }
}

// Status Melding Manager Klasse - verantwoordelijk voor het beheren van de status melding
class Status_Message_Manager {
    
    // Cache voor status data
    private $status_cache = null;
    
    // Status constanten
    const STATUS_OPTIONS = array(
        'geen' => array('label' => 'Geen', 'color' => '#999999', 'bg_color' => '#f5f5f5'),
        'green' => array('label' => 'Groen', 'color' => '#008939', 'bg_color' => '#B8E3C9'),
        'orange' => array('label' => 'Oranje', 'color' => '#FFC100', 'bg_color' => '#FEF8D3'),
        'red' => array('label' => 'Rood', 'color' => '#DA362D', 'bg_color' => '#FFCDCA')
    );
    
    /**
     * Constructor
     */
    public function __construct() {
        // Registreer scripts en styles
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_scripts'));
        
        // Verwerk formulier acties
        add_action('admin_init', array($this, 'process_form_actions'));
        
        // Registreer cron event voor controle vervaldatum groene status
        add_action('init', array($this, 'register_cron_events'));
        
        // Voeg actie toe voor status controle
        add_action('check_green_status_expiry', array($this, 'check_and_update_expired_status'));
    }
    
    /**
     * Plugin activatie
     */
    public function activate() {
        // Zorg ervoor dat de default statusdata bestaat
        if (false === get_option('status_message_data')) {
            update_option('status_message_data', $this->get_default_status_data());
        }
        
        // Planner voor cron job
        if (!wp_next_scheduled('check_green_status_expiry')) {
            wp_schedule_event(time(), 'hourly', 'check_green_status_expiry');
        }
    }
    
    /**
     * Plugin deactivatie
     */
    public function deactivate() {
        // Verwijder cron job
        $timestamp = wp_next_scheduled('check_green_status_expiry');
        if ($timestamp) {
            wp_unschedule_event($timestamp, 'check_green_status_expiry');
        }
    }
    
    /**
     * Helper functie voor default status data
     */
    private function get_default_status_data() {
        return array(
            'title' => '',
            'content' => '',
            'status' => 'geen',
            'expiry_date' => null
        );
    }
    
    /**
     * Helper functie om status data op te halen met cache
     */
    private function get_status_data($force_refresh = false) {
        if ($this->status_cache === null || $force_refresh) {
            $this->status_cache = get_option('status_message_data', $this->get_default_status_data());
        }
        return $this->status_cache;
    }
    
    /**
     * Update status data en cache
     */
    private function update_status_data($data) {
        $this->status_cache = $data;
        return update_option('status_message_data', $data);
    }
    
    /**
     * Registreer cron events
     */
    public function register_cron_events() {
        if (!wp_next_scheduled('check_green_status_expiry')) {
            wp_schedule_event(time(), 'hourly', 'check_green_status_expiry');
        }
    }
    
    /**
     * Controleer en update vervallen status
     */
    public function check_and_update_expired_status() {
        $status_data = $this->get_status_data();
        
        // Controleer alleen als de status groen is en een vervaldatum heeft
        if ($status_data['status'] !== 'green' || empty($status_data['expiry_date'])) {
            return;
        }
        
        $current_time = current_time('timestamp');
        $expiry_time = strtotime($status_data['expiry_date']);
        
        // Als de vervaldatum is verstreken, zet status naar 'geen'
        if ($expiry_time <= $current_time) {
            $status_data['status'] = 'geen';
            $this->update_status_data($status_data);
            
            // Log naar historie als automatische statuswijziging
            $history_manager = new Status_History_Manager();
            $history_manager->log_status_change(
                'geen',
                $status_data['title'],
                $status_data['content'],
                null,
                'Automatisch verlopen',
                'system'
            );
            
            // Log de statuswijziging
            error_log(sprintf(
                'Status API: Status is verlopen en teruggezet naar "geen" - Verlopen op: %s - Huidige tijd: %s',
                date('Y-m-d H:i:s', $expiry_time),
                date('Y-m-d H:i:s', $current_time)
            ));
        }
        
        // Update laatst uitgevoerde check timestamp (voor debugging)
        update_option('status_last_expiry_check', current_time('mysql'));
    }
    
    /**
     * Scripts en styles toevoegen
     */
    public function enqueue_admin_scripts($hook) {
        // Alleen laden op onze admin pagina's
        if (strpos($hook, 'status-api') === false) {
            return;
        }
        
        wp_enqueue_script('jquery-ui-datepicker');
        wp_enqueue_style('jquery-ui', 'https://code.jquery.com/ui/1.12.1/themes/base/jquery-ui.css');
        
        // Voeg custom script toe
        $inline_script = "
            jQuery(document).ready(function($) {
                function toggleExpiryField() {
                    const isGreen = $('#status').val() === 'green';
                    $('.expiry-date-field').toggle(isGreen);
                }
                
                $('#expiry_date').datepicker({
                    dateFormat: 'yy-mm-dd',
                    minDate: 0
                });
                
                toggleExpiryField();
                $('#status').on('change', toggleExpiryField);
            });
        ";
        
        wp_add_inline_script('jquery-ui-datepicker', $inline_script);
    }
    
    /**
     * Verwerk formulier acties
     */
    public function process_form_actions() {
        // Controleer of we op de status pagina zijn
        if (!isset($_GET['page']) || $_GET['page'] !== 'status-api') {
            return;
        }
        
        // Verwerk status formulier
        if (isset($_POST['status_action']) && $_POST['status_action'] === 'save') {
            // Controleer nonce
            if (!isset($_POST['status_nonce']) || !wp_verify_nonce($_POST['status_nonce'], 'save_status')) {
                wp_die('Beveiligingscontrole mislukt. Probeer het opnieuw.');
            }
            
            // Verkrijg huidige status voor vergelijking
            $current_status = $this->get_status_data();
            
            // Bereid nieuwe status data voor
            $new_status_data = $this->prepare_status_data_from_form();
            
            // Bepaal type wijziging
            $change_note = $this->determine_change_note($current_status, $new_status_data);
            
            // Sla status data op
            $this->update_status_data($new_status_data);
            
            // Log naar historie
            $history_manager = new Status_History_Manager();
            $history_manager->log_status_change(
                $new_status_data['status'],
                $new_status_data['title'],
                $new_status_data['content'],
                $new_status_data['expiry_date'],
                $change_note
            );
            
            // Redirect met succes bericht
            wp_redirect(add_query_arg(
                array(
                    'page' => 'status-api', 
                    'message' => 'updated'
                ),
                admin_url('admin.php')
            ));
            exit;
        }
    }
    
    /**
     * Bepaal wat er is gewijzigd voor het logboek
     */
    private function determine_change_note($old_status, $new_status) {
        $changes = array();
        
        // Check of dit een nieuwe melding is
        if (empty($old_status['title']) && empty($old_status['content']) && $old_status['status'] === 'geen') {
            return 'Nieuwe melding aangemaakt';
        }
        
        // Check wijzigingen
        if ($old_status['status'] !== $new_status['status']) {
            $old_label = self::STATUS_OPTIONS[$old_status['status']]['label'];
            $new_label = self::STATUS_OPTIONS[$new_status['status']]['label'];
            $changes[] = "Status gewijzigd van {$old_label} naar {$new_label}";
        }
        
        if ($old_status['title'] !== $new_status['title']) {
            $changes[] = "Titel gewijzigd";
        }
        
        if ($old_status['content'] !== $new_status['content']) {
            $changes[] = "Inhoud gewijzigd";
        }
        
        if ($old_status['expiry_date'] !== $new_status['expiry_date']) {
            if (empty($old_status['expiry_date']) && !empty($new_status['expiry_date'])) {
                $changes[] = "Vervaldatum toegevoegd";
            } elseif (!empty($old_status['expiry_date']) && empty($new_status['expiry_date'])) {
                $changes[] = "Vervaldatum verwijderd";
            } else {
                $changes[] = "Vervaldatum gewijzigd";
            }
        }
        
        return !empty($changes) ? implode(', ', $changes) : 'Update zonder wijzigingen';
    }
    
    /**
     * Bereid status data voor uit formulier
     */
    private function prepare_status_data_from_form() {
        $title = sanitize_text_field($_POST['title']);
        $content = wp_kses_post($_POST['content']);
        $status = sanitize_text_field($_POST['status']);
        
        $status_data = array(
            'title' => $title,
            'content' => $content,
            'status' => $status,
            'expiry_date' => null
        );
        
        // Bereid expiry date voor voor groene status
        if ($status === 'green' && isset($_POST['expiry_date']) && !empty($_POST['expiry_date'])) {
            $date = sanitize_text_field($_POST['expiry_date']);
            $time = isset($_POST['expiry_time']) && !empty($_POST['expiry_time']) 
                  ? sanitize_text_field($_POST['expiry_time']) 
                  : '00:00';
            
            $status_data['expiry_date'] = $date . ' ' . $time;
        }
        
        return $status_data;
    }
    
    /**
     * Get status info
     */
    private function get_status_info($status) {
        return isset(self::STATUS_OPTIONS[$status]) ? self::STATUS_OPTIONS[$status] : self::STATUS_OPTIONS['geen'];
    }
    
    /**
     * Render countdown display
     */
    private function render_countdown($expiry_date) {
        $now = current_time('timestamp');
        $expiry = strtotime($expiry_date);
        $time_remaining = $expiry - $now;
        
        if ($time_remaining <= 0) {
            return 'Verlopen';
        }
        
        $days = floor($time_remaining / (60 * 60 * 24));
        $hours = floor(($time_remaining % (60 * 60 * 24)) / (60 * 60));
        $minutes = floor(($time_remaining % (60 * 60)) / 60);
        
        $countdown = array();
        if ($days > 0) {
            $countdown[] = $days . ' ' . ($days == 1 ? 'dag' : 'dagen');
        }
        if ($hours > 0 || $days > 0) {
            $countdown[] = $hours . ' ' . ($hours == 1 ? 'uur' : 'uren');
        }
        $countdown[] = $minutes . ' ' . ($minutes == 1 ? 'minuut' : 'minuten');
        
        return implode(', ', array_slice($countdown, 0, 2)) . (count($countdown) > 2 ? ' en ' . end($countdown) : '');
    }
    
    /**
     * Toon status pagina
     */
    public function display_status_page() {
        // Voer een real-time check uit voor verlopen statussen
        $this->check_and_update_expired_status();
        
        // Verkrijg huidige status data
        $status_data = $this->get_status_data(true); // Force refresh na possible update
        
        $title = $status_data['title'];
        $content = $status_data['content'];
        $status = $status_data['status'];
        
        $expiry_date_only = '';
        $expiry_time_only = '';
        
        if (!empty($status_data['expiry_date'])) {
            $expiry_date_only = date('Y-m-d', strtotime($status_data['expiry_date']));
            $expiry_time_only = date('H:i', strtotime($status_data['expiry_date']));
        }
        
        $status_info = $this->get_status_info($status);
        
        ?>
        <div class="wrap">
            <h1>Status Melding</h1>
            
            <?php if (isset($_GET['message']) && $_GET['message'] === 'updated') : ?>
                <div class="notice notice-success is-dismissible">
                    <p>Status melding bijgewerkt.</p>
                </div>
            <?php endif; ?>
            
            <div class="card" style="padding: 20px;">
                <h2>Huidige Status</h2>
                
                <div style="margin-bottom: 20px; background-color: <?php echo $status_info['bg_color']; ?>; padding: 15px; border-radius: 5px;">
                    <div style="margin-bottom: 10px;">
                        <strong>Status:</strong> 
                        <span style="display:inline-block; width:12px; height:12px; background-color:<?php echo $status_info['color']; ?>; margin-right:5px; border-radius:50%;"></span>
                        <?php echo esc_html($status_info['label']); ?>
                    </div>
                    
                    <?php if ($status === 'green' && !empty($status_data['expiry_date'])) : ?>
                        <p>
                            <strong>Vervalt op:</strong> <?php echo esc_html(date_i18n('j F Y H:i', strtotime($status_data['expiry_date']))); ?><br>
                            <strong>Huidige tijd:</strong> <?php echo esc_html(date_i18n('j F Y H:i')); ?><br>
                            <strong>Nog geldig voor:</strong> <?php echo esc_html($this->render_countdown($status_data['expiry_date'])); ?>
                        </p>
                    <?php endif; ?>
                    
                    <?php if (!empty($title)) : ?>
                        <h3 style="color: <?php echo $status_info['color']; ?>; margin-top: 15px; margin-bottom: 10px;"><?php echo esc_html($title); ?></h3>
                    <?php endif; ?>
                    
                    <?php if (!empty($content)) : ?>
                        <div class="status-content">
                            <?php echo wpautop($content); ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
            
            <h2>Status Melding Bewerken</h2>
            <p>Er kan altijd maar één status melding actief zijn. De nieuwste status overschrijft altijd de vorige.</p>
            
            <form method="post" action="">
                <input type="hidden" name="status_action" value="save">
                <?php wp_nonce_field('save_status', 'status_nonce'); ?>
                
                <table class="form-table">
                    <tr>
                        <th scope="row"><label for="title">Titel</label></th>
                        <td>
                            <input type="text" name="title" id="title" class="regular-text" value="<?php echo esc_attr($title); ?>">
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="content">Inhoud</label></th>
                        <td>
                            <?php
                            wp_editor($content, 'content', array(
                                'textarea_name' => 'content',
                                'media_buttons' => false,
                                'textarea_rows' => 10
                            ));
                            ?>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="status">Status</label></th>
                        <td>
                            <select name="status" id="status">
                                <?php foreach (self::STATUS_OPTIONS as $key => $info) : ?>
                                    <option value="<?php echo esc_attr($key); ?>" <?php selected($status, $key); ?>>
                                        <?php echo esc_html($info['label']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </td>
                    </tr>
                    <tr class="expiry-date-field" style="<?php echo ($status !== 'green') ? 'display:none;' : ''; ?>">
                        <th scope="row"><label for="expiry_date">Vervaldatum</label></th>
                        <td>
                            <input type="text" name="expiry_date" id="expiry_date" class="regular-text" value="<?php echo esc_attr($expiry_date_only); ?>" placeholder="JJJJ-MM-DD">
                            <p class="description">Datum waarop de status terug naar 'geen' gaat.</p>
                        </td>
                    </tr>
                    <tr class="expiry-date-field" style="<?php echo ($status !== 'green') ? 'display:none;' : ''; ?>">
                        <th scope="row"><label for="expiry_time">Vervaltijd</label></th>
                        <td>
                            <input type="time" name="expiry_time" id="expiry_time" value="<?php echo esc_attr($expiry_time_only); ?>">
                            <p class="description">Tijd waarop de status terug naar 'geen' gaat.</p>
                        </td>
                    </tr>
                </table>
                
                <p class="submit">
                    <input type="submit" name="submit" id="submit" class="button button-primary" value="Status Update Opslaan">
                </p>
            </form>
        </div>
        <?php
    }
}

// Status History Manager Klasse - verantwoordelijk voor het beheren van de status historie
class Status_History_Manager {
    
    private $table_name;
    
    /**
     * Constructor
     */
    public function __construct() {
        global $wpdb;
        $this->table_name = $wpdb->prefix . 'status_api_history';
        
        // Verwerk formulier acties
        add_action('admin_init', array($this, 'process_form_actions'));
    }
    
    /**
     * Plugin activatie - maak database tabel
     */
    public function activate() {
        global $wpdb;
        
        $charset_collate = $wpdb->get_charset_collate();
        
        $sql = "CREATE TABLE IF NOT EXISTS {$this->table_name} (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            status varchar(20) NOT NULL,
            title text,
            content longtext,
            expiry_date datetime DEFAULT NULL,
            change_date datetime NOT NULL,
            change_note text,
            changed_by varchar(100) DEFAULT NULL,
            PRIMARY KEY (id),
            KEY status (status),
            KEY change_date (change_date)
        ) $charset_collate;";
        
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
    }
    
    /**
     * Plugin deactivatie
     */
    public function deactivate() {
        // Behoud de tabel bij deactivatie (verwijder alleen bij uninstall)
    }
    
    /**
     * Log een statuswijziging
     */
    public function log_status_change($status, $title, $content, $expiry_date, $change_note, $changed_by = null) {
        global $wpdb;
        
        if ($changed_by === null) {
            $current_user = wp_get_current_user();
            $changed_by = $current_user->display_name ?: $current_user->user_login ?: 'Onbekend';
        }
        
        $wpdb->insert(
            $this->table_name,
            array(
                'status' => $status,
                'title' => $title,
                'content' => $content,
                'expiry_date' => $expiry_date,
                'change_date' => current_time('mysql'),
                'change_note' => $change_note,
                'changed_by' => $changed_by
            ),
            array('%s', '%s', '%s', '%s', '%s', '%s', '%s')
        );
    }
    
    /**
     * Verwerk formulier acties
     */
    public function process_form_actions() {
        // Controleer of we op de history pagina zijn
        if (!isset($_GET['page']) || $_GET['page'] !== 'status-api-history') {
            return;
        }
        
        // Verwerk export actie
        if (isset($_GET['action']) && $_GET['action'] === 'export' && 
            isset($_GET['export_nonce']) && wp_verify_nonce($_GET['export_nonce'], 'export_history')) {
            $this->export_history();
        }
        
        // Verwerk clear history actie
        if (isset($_POST['clear_history']) && isset($_POST['clear_history_nonce']) && 
            wp_verify_nonce($_POST['clear_history_nonce'], 'clear_history')) {
            $this->clear_history();
            
            wp_redirect(add_query_arg(
                array(
                    'page' => 'status-api-history',
                    'message' => 'history_cleared'
                ),
                admin_url('admin.php')
            ));
            exit;
        }
    }
    
    /**
     * Haal historie op
     */
    private function get_history($limit = 100, $offset = 0) {
        global $wpdb;
        
        $sql = $wpdb->prepare(
            "SELECT * FROM {$this->table_name} 
             ORDER BY change_date DESC, id DESC 
             LIMIT %d OFFSET %d",
            $limit,
            $offset
        );
        
        return $wpdb->get_results($sql);
    }
    
    /**
     * Tel totaal aantal historie items
     */
    private function count_history() {
        global $wpdb;
        return $wpdb->get_var("SELECT COUNT(*) FROM {$this->table_name}");
    }
    
    /**
     * Exporteer historie naar CSV
     */
    private function export_history() {
        global $wpdb;
        
        // Haal alle historie op
        $history = $wpdb->get_results("SELECT * FROM {$this->table_name} ORDER BY change_date DESC");
        
        // Set headers voor download
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="status-historie-' . date('Y-m-d-His') . '.csv"');
        
        // Open output stream
        $output = fopen('php://output', 'w');
        
        // UTF-8 BOM voor Excel
        fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));
        
        // Headers
        fputcsv($output, array(
            'ID',
            'Status',
            'Titel',
            'Inhoud',
            'Vervaldatum',
            'Wijzigingsdatum',
            'Wijziging',
            'Gewijzigd door'
        ), ';');
        
        // Data
        foreach ($history as $entry) {
            $status_info = Status_Message_Manager::STATUS_OPTIONS[$entry->status] ?? Status_Message_Manager::STATUS_OPTIONS['geen'];
            
            fputcsv($output, array(
                $entry->id,
                $status_info['label'],
                $entry->title,
                strip_tags($entry->content),
                $entry->expiry_date ? date_i18n('j F Y H:i', strtotime($entry->expiry_date)) : '',
                date_i18n('j F Y H:i', strtotime($entry->change_date)),
                $entry->change_note,
                $entry->changed_by
            ), ';');
        }
        
        fclose($output);
        exit;
    }
    
    /**
     * Wis historie
     */
    private function clear_history() {
        global $wpdb;
        $wpdb->query("TRUNCATE TABLE {$this->table_name}");
    }
    
    /**
     * Toon historie pagina
     */
    public function display_history_page() {
        // Paginering
        $per_page = 50;
        $current_page = isset($_GET['paged']) ? max(1, intval($_GET['paged'])) : 1;
        $offset = ($current_page - 1) * $per_page;
        
        // Haal historie op
        $history = $this->get_history($per_page, $offset);
        $total_items = $this->count_history();
        $total_pages = ceil($total_items / $per_page);
        
        // Export URL
        $export_url = wp_nonce_url(
            add_query_arg(
                array(
                    'page' => 'status-api-history',
                    'action' => 'export'
                ),
                admin_url('admin.php')
            ),
            'export_history',
            'export_nonce'
        );
        
        ?>
        <div class="wrap">
            <h1>Status Historie</h1>
            
            <?php if (isset($_GET['message']) && $_GET['message'] === 'history_cleared') : ?>
                <div class="notice notice-success is-dismissible">
                    <p>Historie is gewist.</p>
                </div>
            <?php endif; ?>
            
            <p>Overzicht van alle statuswijzigingen en updates.</p>
            
            <div style="margin-bottom: 20px;">
                <a href="<?php echo esc_url($export_url); ?>" class="button">
                    <span class="dashicons dashicons-download" style="margin-top: 4px;"></span> 
                    Exporteer naar CSV
                </a>
                
                <?php if ($total_items > 0) : ?>
                <form method="post" style="display: inline-block; margin-left: 10px;" onsubmit="return confirm('Weet je zeker dat je de complete historie wilt wissen?');">
                    <?php wp_nonce_field('clear_history', 'clear_history_nonce'); ?>
                    <button type="submit" name="clear_history" class="button">
                        <span class="dashicons dashicons-trash" style="margin-top: 4px;"></span> 
                        Wis Historie
                    </button>
                </form>
                <?php endif; ?>
            </div>
            
            <?php if (empty($history)) : ?>
                <p>Er zijn nog geen statuswijzigingen gelogd.</p>
            <?php else : ?>
                <table class="wp-list-table widefat fixed striped">
                    <thead>
                        <tr>
                            <th style="width: 150px;">Datum/Tijd</th>
                            <th style="width: 80px;">Status</th>
                            <th>Titel</th>
                            <th style="width: 200px;">Wijziging</th>
                            <th style="width: 150px;">Gewijzigd door</th>
                            <th style="width: 150px;">Vervaldatum</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($history as $entry) : 
                            $status_info = Status_Message_Manager::STATUS_OPTIONS[$entry->status] ?? Status_Message_Manager::STATUS_OPTIONS['geen'];
                        ?>
                        <tr>
                            <td><?php echo esc_html(date_i18n('j M Y H:i', strtotime($entry->change_date))); ?></td>
                            <td>
                                <span style="display:inline-block; width:12px; height:12px; background-color:<?php echo $status_info['color']; ?>; margin-right:5px; border-radius:50%;"></span>
                                <?php echo esc_html($status_info['label']); ?>
                            </td>
                            <td>
                                <strong><?php echo esc_html($entry->title ?: '(Geen titel)'); ?></strong>
                                <?php if (!empty($entry->content)) : ?>
                                    <details style="margin-top: 5px;">
                                        <summary style="cursor: pointer; color: #2271b1;">Bekijk inhoud</summary>
                                        <div style="margin-top: 10px; padding: 10px; background-color: #f5f5f5; border-radius: 3px;">
                                            <?php echo wpautop(esc_html($entry->content)); ?>
                                        </div>
                                    </details>
                                <?php endif; ?>
                            </td>
                            <td><?php echo esc_html($entry->change_note); ?></td>
                            <td><?php echo esc_html($entry->changed_by); ?></td>
                            <td>
                                <?php if ($entry->expiry_date) : ?>
                                    <?php echo esc_html(date_i18n('j M Y H:i', strtotime($entry->expiry_date))); ?>
                                <?php else : ?>
                                    -
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                
                <?php if ($total_pages > 1) : ?>
                <div class="tablenav">
                    <div class="tablenav-pages">
                        <span class="displaying-num"><?php echo sprintf('%d items', $total_items); ?></span>
                        <?php
                        echo paginate_links(array(
                            'base' => add_query_arg('paged', '%#%'),
                            'format' => '',
                            'prev_text' => '&laquo;',
                            'next_text' => '&raquo;',
                            'total' => $total_pages,
                            'current' => $current_page
                        ));
                        ?>
                    </div>
                </div>
                <?php endif; ?>
            <?php endif; ?>
        </div>
        <?php
    }
}

// Start de plugin
$status_api_plugin = Status_API_Plugin::get_instance();