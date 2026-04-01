<?php
/**
 * Plugin Name: Status API
 * Description: Deze plugin maakt een API end-point aan voor het geven van storings informatie.
 * Version: 0.9.9
 * Author: Hanno-Wybren Mook
 * License: Proprietary
 */

// Voorkom direct toegang tot het bestand
if (!defined('ABSPATH')) {
    exit;
}

// Updates via GitHub Releases (Plugin Update Checker)
if (file_exists(__DIR__ . '/vendor/autoload.php')) {
    require_once __DIR__ . '/vendor/autoload.php';

    if (class_exists('\\YahnisElsts\\PluginUpdateChecker\\v5\\PucFactory')) {
        $update_checker = \YahnisElsts\PluginUpdateChecker\v5\PucFactory::buildUpdateChecker(
            'https://github.com/SURFnet/wp_status_api',
            __FILE__,
            'wp_status_api'
        );

        // Gebruik de zip asset van een GitHub Release (consistent met onze build).
        if (method_exists($update_checker, 'getVcsApi') && $update_checker->getVcsApi()) {
            $update_checker->getVcsApi()->enableReleaseAssets();
        }
    }
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
    private $api_clients_option = 'status_api_clients';
    
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
        $this->maybe_migrate_single_key_to_clients();

        // Genereer default client als er nog geen clients bestaan
        $clients = $this->get_api_clients();
        if (empty($clients)) {
            $this->create_api_client('Default');
        }
    }
    
    /**
     * Plugin deactivatie
     */
    public function deactivate() {
        // Niets nodig voor deactivatie
    }

    /**
     * Haal alle API clients op.
     *
     * Structuur:
     * [
     *   'api_key_string' => [
     *     'label' => 'Clientnaam',
     *     'secret' => '...'
     *     'revoked' => false,
     *     'created_at' => 1710000000,
     *     'secret_regenerated_at' => 1710000000|null,
     *     'last_used_at' => 1710000000|null
     *   ],
     * ]
     */
    private function get_api_clients() {
        $clients = get_option($this->api_clients_option, array());
        if (!is_array($clients)) {
            $clients = array();
        }

        foreach ($clients as $key => $client) {
            if (!is_array($client)) {
                unset($clients[$key]);
                continue;
            }

            $clients[$key] = wp_parse_args($client, array(
                'label' => $key,
                'secret' => '',
                'revoked' => false,
                'created_at' => null,
                'secret_regenerated_at' => null,
                'last_used_at' => null,
            ));
        }

        return $clients;
    }

    private function save_api_clients($clients) {
        if (!is_array($clients)) {
            $clients = array();
        }
        update_option($this->api_clients_option, $clients);
    }

    private function maybe_migrate_single_key_to_clients() {
        $clients = $this->get_api_clients();
        if (!empty($clients)) {
            return;
        }

        $legacy_key = get_option($this->api_key_option);
        $legacy_secret = get_option($this->api_secret_option);
        if (!empty($legacy_key) && !empty($legacy_secret)) {
            $now = time();
            $clients = array(
                $legacy_key => array(
                    'label' => 'Legacy',
                    'secret' => $legacy_secret,
                    'revoked' => false,
                    'created_at' => $now,
                    'secret_regenerated_at' => $now,
                    'last_used_at' => null,
                ),
            );
            $this->save_api_clients($clients);
        }
    }

    private function create_api_client($label) {
        $label = is_string($label) ? trim($label) : '';
        if ($label === '') {
            $label = 'Client';
        }

        $clients = $this->get_api_clients();

        do {
            $api_key = wp_generate_password(32, false);
        } while (isset($clients[$api_key]));

        $api_secret = wp_generate_password(64, false);

        $now = time();
        $clients[$api_key] = array(
            'label' => $label,
            'secret' => $api_secret,
            'revoked' => false,
            'created_at' => $now,
            'secret_regenerated_at' => $now,
            'last_used_at' => null,
        );

        $this->save_api_clients($clients);

        // Houd legacy options gevuld met "eerste" client voor backwards-compat
        if (empty(get_option($this->api_key_option)) || empty(get_option($this->api_secret_option))) {
            update_option($this->api_key_option, $api_key);
            update_option($this->api_secret_option, $api_secret);
        }

        return array($api_key, $api_secret);
    }

    private function revoke_api_client($api_key) {
        $clients = $this->get_api_clients();
        if (!isset($clients[$api_key])) {
            return false;
        }
        $clients[$api_key]['revoked'] = true;
        $this->save_api_clients($clients);
        return true;
    }

    private function regenerate_api_client_secret($api_key) {
        $clients = $this->get_api_clients();
        if (!isset($clients[$api_key])) {
            return false;
        }
        $clients[$api_key]['secret'] = wp_generate_password(64, false);
        $clients[$api_key]['revoked'] = false;
        $clients[$api_key]['secret_regenerated_at'] = time();
        $this->save_api_clients($clients);
        return $clients[$api_key]['secret'];
    }

    private function delete_api_client($api_key) {
        $clients = $this->get_api_clients();
        if (!isset($clients[$api_key])) {
            return false;
        }
        if (empty($clients[$api_key]['revoked'])) {
            return false;
        }
        unset($clients[$api_key]);
        $this->save_api_clients($clients);
        return true;
    }
    
    /**
     * Verwerk formulier acties
     */
    public function process_form_actions() {
        // Controleer of we op de API instellingen pagina zijn
        if (!isset($_GET['page']) || $_GET['page'] !== 'status-api-settings') {
            return;
        }

        // Nieuwe client aanmaken
        if (isset($_POST['create_client']) && isset($_POST['create_client_nonce']) &&
            wp_verify_nonce($_POST['create_client_nonce'], 'create_client')) {
            $label = isset($_POST['client_label']) ? sanitize_text_field($_POST['client_label']) : 'Client';
            $this->create_api_client($label);

            wp_redirect(add_query_arg(
                array(
                    'page' => 'status-api-settings',
                    'tab' => 'clients',
                    'message' => 'client_created'
                ),
                admin_url('admin.php')
            ));
            exit;
        }

        // Client intrekken
        if (isset($_POST['revoke_client']) && isset($_POST['revoke_client_nonce']) &&
            wp_verify_nonce($_POST['revoke_client_nonce'], 'revoke_client')) {
            $api_key = isset($_POST['client_key']) ? sanitize_text_field($_POST['client_key']) : '';
            if ($api_key !== '') {
                $this->revoke_api_client($api_key);
            }

            wp_redirect(add_query_arg(
                array(
                    'page' => 'status-api-settings',
                    'tab' => 'clients',
                    'message' => 'client_revoked'
                ),
                admin_url('admin.php')
            ));
            exit;
        }

        // Client secret regenereren
        if (isset($_POST['regenerate_client_secret']) && isset($_POST['regenerate_client_secret_nonce']) &&
            wp_verify_nonce($_POST['regenerate_client_secret_nonce'], 'regenerate_client_secret')) {
            $api_key = isset($_POST['client_key']) ? sanitize_text_field($_POST['client_key']) : '';
            if ($api_key !== '') {
                $this->regenerate_api_client_secret($api_key);
            }

            wp_redirect(add_query_arg(
                array(
                    'page' => 'status-api-settings',
                    'tab' => 'clients',
                    'message' => 'client_secret_regenerated'
                ),
                admin_url('admin.php')
            ));
            exit;
        }

        // Client verwijderen (alleen als hij al ingetrokken is)
        if (isset($_POST['delete_client']) && isset($_POST['delete_client_nonce']) &&
            wp_verify_nonce($_POST['delete_client_nonce'], 'delete_client')) {
            $api_key = isset($_POST['client_key']) ? sanitize_text_field($_POST['client_key']) : '';
            if ($api_key !== '') {
                $this->delete_api_client($api_key);
            }

            wp_redirect(add_query_arg(
                array(
                    'page' => 'status-api-settings',
                    'tab' => 'clients',
                    'message' => 'client_deleted'
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
     * Toon instellingen pagina
     */
    public function display_settings_page() {
        $this->maybe_migrate_single_key_to_clients();
        $clients = $this->get_api_clients();

        $active_tab = isset($_GET['tab']) ? sanitize_key($_GET['tab']) : 'clients';
        if (!in_array($active_tab, array('clients', 'docs'), true)) {
            $active_tab = 'clients';
        }

        $example_key = '';
        $example_secret = '';
        foreach ($clients as $client_key => $client) {
            if (!empty($client['revoked'])) {
                continue;
            }
            $example_key = $client_key;
            $example_secret = $client['secret'];
            break;
        }
        $example_bearer_token = (!empty($example_key) && !empty($example_secret)) ? $this->generate_bearer_token($example_key, $example_secret) : '';
        
        ?>
        <div class="wrap">
            <h1>Status API Instellingen</h1>

            <h2 class="nav-tab-wrapper">
                <a
                    href="<?php echo esc_url(add_query_arg(array('page' => 'status-api-settings', 'tab' => 'clients'), admin_url('admin.php'))); ?>"
                    class="nav-tab <?php echo $active_tab === 'clients' ? 'nav-tab-active' : ''; ?>"
                >
                    API clients
                </a>
                <a
                    href="<?php echo esc_url(add_query_arg(array('page' => 'status-api-settings', 'tab' => 'docs'), admin_url('admin.php'))); ?>"
                    class="nav-tab <?php echo $active_tab === 'docs' ? 'nav-tab-active' : ''; ?>"
                >
                    Documentatie
                </a>
            </h2>
            
            <?php if (isset($_GET['message']) && $_GET['message'] === 'client_created') : ?>
                <div class="notice notice-success is-dismissible">
                    <p>Client aangemaakt!</p>
                </div>
            <?php elseif (isset($_GET['message']) && $_GET['message'] === 'client_revoked') : ?>
                <div class="notice notice-success is-dismissible">
                    <p>Client ingetrokken!</p>
                </div>
            <?php elseif (isset($_GET['message']) && $_GET['message'] === 'client_secret_regenerated') : ?>
                <div class="notice notice-success is-dismissible">
                    <p>Client secret opnieuw gegenereerd!</p>
                </div>
            <?php elseif (isset($_GET['message']) && $_GET['message'] === 'client_deleted') : ?>
                <div class="notice notice-success is-dismissible">
                    <p>Client verwijderd!</p>
                </div>
            <?php endif; ?>

            <style>
                .status-api-field { display: flex; gap: 8px; align-items: flex-start; }
                .status-api-field input.large-text { width: 100%; max-width: 520px; }
                .status-api-field input.regular-text { width: 100%; max-width: 420px; }
                .status-api-field textarea.large-text { width: 100%; max-width: 520px; font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono", "Courier New", monospace; }
                .status-api-actions { display: flex; gap: 6px; flex-wrap: wrap; }
                .status-api-muted { color: #646970; }
                .status-api-badge { display: inline-flex; align-items: center; gap: 6px; padding: 2px 8px; border-radius: 999px; font-weight: 600; font-size: 12px; }
                .status-api-badge--active { background: #e6f6ea; color: #116329; }
                .status-api-badge--revoked { background: #fbeaea; color: #b32d2e; }
                .status-api-row--revoked td { background: #fff7f7; }
                .status-api-label { display: inline-flex; align-items: center; gap: 6px; }
                .status-api-label .dashicons { color: #646970; font-size: 16px; width: 16px; height: 16px; }
                .status-api-actions .button .dashicons { margin-right: 4px; vertical-align: text-top; }
                .status-api-copy.is-copied { border-color: #00a32a; color: #00a32a; }
                .status-api-hidden { display: none; }
            </style>

            <?php if ($active_tab === 'clients') : ?>
                <p class="status-api-muted">Maak per integratie een eigen client aan. Je kunt clients intrekken of (indien ingetrokken) verwijderen.</p>

                <div style="background: #fff; border: 1px solid #dcdcde; border-radius: 8px; padding: 16px; max-width: 1400px; margin: 12px 0 18px;">
                    <h2 style="margin: 0 0 10px;">
                        <span class="dashicons dashicons-plus-alt2" style="vertical-align: middle;"></span>
                        Nieuwe client
                    </h2>
                    <form method="post" action="" style="display:flex; gap: 10px; align-items: flex-end; flex-wrap: wrap;">
                        <?php wp_nonce_field('create_client', 'create_client_nonce'); ?>
                        <div>
                            <label for="client_label"><strong>Naam</strong></label><br />
                            <input type="text" id="client_label" name="client_label" class="regular-text" placeholder="Bijv. Dashboard, Monitor, Klant X" />
                        </div>
                        <div>
                            <button type="submit" name="create_client" class="button button-primary">
                                <span class="dashicons dashicons-plus-alt2"></span>
                                Client toevoegen
                            </button>
                        </div>
                    </form>
                    <p class="status-api-muted" style="margin: 10px 0 0;">
                        Tip: maak per applicatie/klant een aparte client zodat je eenvoudig kunt intrekken of roteren.
                    </p>
                </div>

                <h2>API clients</h2>
                <table class="widefat striped" style="max-width: 1400px;">
                    <thead>
                        <tr>
                            <th style="width: 160px;">Naam</th>
                            <th>Credentials</th>
                            <th style="width: 260px;">Status</th>
                            <th style="width: 330px;">Acties</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($clients)) : ?>
                            <tr><td colspan="4">Nog geen clients.</td></tr>
                        <?php else : ?>
                            <?php $i = 0; foreach ($clients as $client_key => $client) : $i++; ?>
                                <?php
                                    $client_secret = $client['secret'];
                                    $client_token = (!empty($client_key) && !empty($client_secret)) ? $this->generate_bearer_token($client_key, $client_secret) : '';
                                    $client_open_url = add_query_arg(
                                        array(
                                            'api_key' => $client_key,
                                            'api_secret' => $client_secret,
                                        ),
                                        site_url('/wp-json/status-api/v1/status')
                                    );
                                    $is_revoked = !empty($client['revoked']);

                                    $id_key = 'status_api_client_key_' . $i;
                                    $id_secret = 'status_api_client_secret_' . $i;
                                    $id_token = 'status_api_client_token_' . $i;
                                    $id_url = 'status_api_client_url_' . $i;
                                    $id_secret_wrap = 'status_api_client_secret_wrap_' . $i;
                                    $id_token_wrap = 'status_api_client_token_wrap_' . $i;
                                    $id_url_wrap = 'status_api_client_url_wrap_' . $i;
                                ?>
                                <tr class="<?php echo $is_revoked ? 'status-api-row--revoked' : ''; ?>">
                                    <td>
                                        <strong><?php echo esc_html($client['label']); ?></strong><br />
                                        <?php if (!empty($client['created_at'])) : ?>
                                            <span class="status-api-muted">
                                                Aangemaakt: <?php echo esc_html(date_i18n('Y-m-d', (int)$client['created_at'])); ?>
                                            </span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <div style="margin-bottom: 10px;">
                                            <div class="status-api-muted"><span class="status-api-label"><span class="dashicons dashicons-admin-network"></span><strong>API key</strong></span></div>
                                            <div class="status-api-field">
                                                <input id="<?php echo esc_attr($id_key); ?>" type="text" class="regular-text" value="<?php echo esc_attr($client_key); ?>" readonly />
                                                <button type="button" class="button status-api-copy" data-copy-target="<?php echo esc_attr($id_key); ?>">Kopieer</button>
                                            </div>
                                        </div>
                                        <div style="margin-bottom: 10px;">
                                            <div class="status-api-muted"><span class="status-api-label"><span class="dashicons dashicons-lock"></span><strong>API secret</strong></span></div>
                                            <div class="status-api-field">
                                                <button type="button" class="button status-api-toggle" data-toggle-target="<?php echo esc_attr($id_secret_wrap); ?>">
                                                    <span class="dashicons dashicons-visibility"></span>
                                                    Toon
                                                </button>
                                                <button type="button" class="button status-api-copy" data-copy-target="<?php echo esc_attr($id_secret); ?>">Kopieer</button>
                                            </div>
                                            <div id="<?php echo esc_attr($id_secret_wrap); ?>" class="status-api-hidden" style="margin-top: 8px;">
                                                <div class="status-api-field">
                                                    <input id="<?php echo esc_attr($id_secret); ?>" type="text" class="regular-text" value="<?php echo esc_attr($client_secret); ?>" readonly />
                                                </div>
                                            </div>
                                        </div>
                                        <div style="margin-bottom: 10px;">
                                            <div class="status-api-muted"><span class="status-api-label"><span class="dashicons dashicons-shield"></span><strong>Bearer token</strong></span></div>
                                            <div class="status-api-field">
                                                <button type="button" class="button status-api-toggle" data-toggle-target="<?php echo esc_attr($id_token_wrap); ?>">
                                                    <span class="dashicons dashicons-visibility"></span>
                                                    Toon
                                                </button>
                                                <button type="button" class="button status-api-copy" data-copy-target="<?php echo esc_attr($id_token); ?>">Kopieer</button>
                                            </div>
                                            <div id="<?php echo esc_attr($id_token_wrap); ?>" class="status-api-hidden" style="margin-top: 8px;">
                                                <div class="status-api-field">
                                                    <textarea id="<?php echo esc_attr($id_token); ?>" class="large-text" rows="2" readonly><?php echo esc_textarea($client_token); ?></textarea>
                                                </div>
                                            </div>
                                        </div>
                                        <div>
                                            <div class="status-api-muted"><span class="status-api-label"><span class="dashicons dashicons-admin-links"></span><strong>Open endpoint</strong></span></div>
                                            <div class="status-api-field">
                                                <button type="button" class="button status-api-toggle" data-toggle-target="<?php echo esc_attr($id_url_wrap); ?>">
                                                    <span class="dashicons dashicons-visibility"></span>
                                                    Toon
                                                </button>
                                                <button type="button" class="button status-api-copy" data-copy-target="<?php echo esc_attr($id_url); ?>">Kopieer</button>
                                                <a class="button" href="<?php echo esc_url($client_open_url); ?>" target="_blank" rel="noopener noreferrer">
                                                    <span class="dashicons dashicons-external"></span>
                                                    Open
                                                </a>
                                            </div>
                                            <div id="<?php echo esc_attr($id_url_wrap); ?>" class="status-api-hidden" style="margin-top: 8px;">
                                                <div class="status-api-field">
                                                    <input id="<?php echo esc_attr($id_url); ?>" type="text" class="large-text" value="<?php echo esc_attr($client_open_url); ?>" readonly />
                                                </div>
                                            </div>
                                        </div>
                                    </td>
                                    <td>
                                        <?php if ($is_revoked) : ?>
                                            <span class="status-api-badge status-api-badge--revoked">
                                                <span class="dashicons dashicons-dismiss"></span>
                                                Ingetrokken
                                            </span>
                                        <?php else : ?>
                                            <span class="status-api-badge status-api-badge--active">
                                                <span class="dashicons dashicons-yes-alt"></span>
                                                Actief
                                            </span>
                                        <?php endif; ?>
                                        <?php if (!empty($client['secret_regenerated_at'])) : ?>
                                            <div class="status-api-muted" style="margin-top: 6px;">
                                                Secret vernieuwd: <?php echo esc_html(date_i18n('Y-m-d H:i', (int)$client['secret_regenerated_at'])); ?>
                                            </div>
                                        <?php endif; ?>
                                        <?php if (!empty($client['last_used_at'])) : ?>
                                            <div class="status-api-muted" style="margin-top: 6px;">
                                                Laatst gebruikt: <?php echo esc_html(date_i18n('Y-m-d H:i', (int)$client['last_used_at'])); ?>
                                            </div>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <div class="status-api-actions">
                                            <form method="post" action="">
                                                <?php wp_nonce_field('regenerate_client_secret', 'regenerate_client_secret_nonce'); ?>
                                                <input type="hidden" name="client_key" value="<?php echo esc_attr($client_key); ?>" />
                                                <button type="submit" name="regenerate_client_secret" class="button">
                                                    <span class="dashicons dashicons-update"></span>
                                                    Secret regenereren
                                                </button>
                                            </form>
                                            <form method="post" action="">
                                                <?php wp_nonce_field('revoke_client', 'revoke_client_nonce'); ?>
                                                <input type="hidden" name="client_key" value="<?php echo esc_attr($client_key); ?>" />
                                                <button type="submit" name="revoke_client" class="button">
                                                    <span class="dashicons dashicons-no-alt"></span>
                                                    Intrekken
                                                </button>
                                            </form>
                                            <form method="post" action="">
                                                <?php wp_nonce_field('delete_client', 'delete_client_nonce'); ?>
                                                <input type="hidden" name="client_key" value="<?php echo esc_attr($client_key); ?>" />
                                                <button type="submit" name="delete_client" class="button" <?php echo $is_revoked ? '' : 'disabled'; ?>>
                                                    <span class="dashicons dashicons-trash"></span>
                                                    Verwijderen
                                                </button>
                                            </form>
                                        </div>
                                        <?php if (!$is_revoked) : ?>
                                            <div class="status-api-muted" style="margin-top: 6px;">Verwijderen kan pas na intrekken.</div>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>

                <script>
                    (function() {
                        function copyTextFromEl(el) {
                            if (!el) return;
                            var text = (el.value !== undefined) ? el.value : (el.textContent || '');
                            if (navigator.clipboard && navigator.clipboard.writeText) {
                                navigator.clipboard.writeText(text);
                                return;
                            }
                            el.focus();
                            if (el.select) {
                                el.select();
                            }
                            try { document.execCommand('copy'); } catch (e) {}
                            if (window.getSelection) {
                                window.getSelection().removeAllRanges();
                            }
                        }

                        document.addEventListener('click', function(e) {
                            var btn = e.target.closest('.status-api-copy');
                            if (!btn) return;
                            e.preventDefault();
                            var id = btn.getAttribute('data-copy-target');
                            copyTextFromEl(document.getElementById(id));
                            btn.classList.add('is-copied');
                            btn.textContent = 'Gekopieerd';
                            window.setTimeout(function() {
                                btn.classList.remove('is-copied');
                                btn.textContent = 'Kopieer';
                            }, 1200);
                        });

                        document.addEventListener('click', function(e) {
                            var btn = e.target.closest('.status-api-toggle');
                            if (!btn) return;
                            e.preventDefault();
                            var id = btn.getAttribute('data-toggle-target');
                            var el = document.getElementById(id);
                            if (!el) return;
                            var isHidden = el.classList.contains('status-api-hidden');
                            if (isHidden) {
                                el.classList.remove('status-api-hidden');
                                btn.innerHTML = '<span class="dashicons dashicons-hidden"></span> Verberg';
                            } else {
                                el.classList.add('status-api-hidden');
                                btn.innerHTML = '<span class="dashicons dashicons-visibility"></span> Toon';
                            }
                        });
                    })();
                </script>
            <?php else : ?>
                <h2>API Documentatie</h2>
                <p>De Status API biedt toegang tot de huidige status via het volgende endpoint:</p>
                
                <h3>Huidige status ophalen</h3>
                <p>Endpoint: <code><?php echo esc_html(site_url('/wp-json/status-api/v1/status')); ?></code></p>
                <p>Methode: <code>GET</code></p>
                <p>Authenticatie: Bearer token (aanbevolen) of API sleutel/secret.</p>
                <p>Tip: ga naar het tabblad “API clients” en kopieer daar de Bearer token of de “Open endpoint” URL.</p>
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
Authorization: Bearer <?php echo esc_html($example_bearer_token); ?>
                </pre>
                
                <h4>Methode 2: API Sleutel en Secret</h4>
                <p>Voeg de volgende parameters toe aan je aanvraag:</p>
                <pre>
api_key=<?php echo esc_html($example_key); ?>&api_secret=<?php echo esc_html($example_secret); ?>
                </pre>
                
                <h3>Voorbeeld API aanroepen</h3>
                
                <h4>cURL voorbeeld met Bearer token:</h4>
                <pre>
curl -H "Authorization: Bearer <?php echo esc_html($example_bearer_token); ?>" \
     <?php echo esc_html(site_url('/wp-json/status-api/v1/status')); ?>
                </pre>
                
                <h4>JavaScript fetch voorbeeld:</h4>
                <pre>
fetch('<?php echo esc_js(site_url('/wp-json/status-api/v1/status')); ?>', {
    headers: {
        'Authorization': 'Bearer <?php echo esc_js($example_bearer_token); ?>'
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
API Sleutel: <?php echo substr(esc_html($example_key), 0, 16); ?>... (verkort voor veiligheid)
API Secret: <?php echo substr(esc_html($example_secret), 0, 16); ?>... (verkort voor veiligheid)

HMAC-SHA256: hash_hmac('sha256', api_key, api_secret)
Resultaat: [64 karakters hex]

Bearer Token: base64_encode(api_key + ':' + hmac_signature)
                </pre>
            </div>
            <?php endif; ?>
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
        $this->maybe_migrate_single_key_to_clients();
        $clients = $this->get_api_clients();
        
        // METHODE 1: Check voor API key en secret in query parameters
        $api_key = $request->get_param('api_key');
        $api_secret = $request->get_param('api_secret');
        
        if (!empty($api_key) && !empty($api_secret)) {
            if (isset($clients[$api_key]) && empty($clients[$api_key]['revoked']) && hash_equals($clients[$api_key]['secret'], $api_secret)) {
                $clients[$api_key]['last_used_at'] = time();
                $this->save_api_clients($clients);
                return true;
            }
        }
        
        // METHODE 2: Bearer token authenticatie (beveiligd met HMAC)
        $auth_header = $request->get_header('Authorization') ?: $request->get_header('authorization');
        
        if ($auth_header && preg_match('/Bearer\s+(.*)$/i', $auth_header, $matches)) {
            $token = trim($matches[1]);
            
            $client_key = $this->validate_bearer_token_multi($token, $clients);
            if ($client_key !== false) {
                $clients[$client_key]['last_used_at'] = time();
                $this->save_api_clients($clients);
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
     * Valideer Bearer token tegen alle clients.
     * Retourneert api_key bij succes, anders false.
     */
    private function validate_bearer_token_multi($token, $clients) {
        $decoded = base64_decode($token, true);
        if ($decoded === false) {
            return false;
        }

        $parts = explode(':', $decoded, 2);
        if (count($parts) !== 2) {
            return false;
        }

        list($api_key, $signature) = $parts;
        if (empty($api_key) || empty($signature)) {
            return false;
        }

        if (!isset($clients[$api_key]) || !empty($clients[$api_key]['revoked'])) {
            return false;
        }

        $expected_signature = hash_hmac('sha256', $api_key, $clients[$api_key]['secret']);
        return hash_equals($expected_signature, $signature) ? $api_key : false;
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
        // Legacy methode: behoud als wrapper om een default client te maken
        list($api_key, $api_secret) = $this->create_api_client('Default');
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