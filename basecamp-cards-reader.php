<?php
/**
 * Plugin Name: Basecamp Cards Reader
 * Description: Lightweight Basecamp card reader for debugging and understanding project issues
 * Version: 1.0.0
 * Author: Wbcom Designs
 */

if (!defined('ABSPATH')) exit;

class Basecamp_Cards_Reader {
    const OPT = 'bcr_settings';
    const TOKEN_OPT = 'bcr_token_data';
    const CACHE_TABLE = 'bcr_image_cache';
    
    public function __construct() {
        add_action('admin_menu', [$this, 'admin_menu']);
        add_action('admin_init', [$this, 'register_settings']);
        add_action('rest_api_init', [$this, 'register_rest']);
        
        // Initialize database table on activation
        register_activation_hook(__FILE__, [$this, 'create_cache_table']);
        
        // Schedule cleanup cron
        add_action('bcr_cleanup_cache', [$this, 'cleanup_old_cache']);
        if (!wp_next_scheduled('bcr_cleanup_cache')) {
            wp_schedule_event(time(), 'hourly', 'bcr_cleanup_cache');
        }
        
        // Clean up on deactivation
        register_deactivation_hook(__FILE__, [$this, 'deactivate']);
        
        // Add WP-CLI commands
        if (defined('WP_CLI') && WP_CLI) {
            WP_CLI::add_command('bcr read', [$this, 'cli_read_card']);
            WP_CLI::add_command('bcr list', [$this, 'cli_list_cards']);
            WP_CLI::add_command('bcr auth', [$this, 'cli_auth_status']);
            WP_CLI::add_command('bcr clear-cache', [$this, 'cli_clear_cache']);
        }
    }
    
    public function create_cache_table() {
        global $wpdb;
        $table_name = $wpdb->prefix . self::CACHE_TABLE;
        $charset_collate = $wpdb->get_charset_collate();
        
        $sql = "CREATE TABLE IF NOT EXISTS $table_name (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            url_hash varchar(32) NOT NULL,
            original_url text NOT NULL,
            local_path text NOT NULL,
            local_url text NOT NULL,
            content_type varchar(100),
            file_size bigint(20),
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            expires_at datetime,
            PRIMARY KEY (id),
            UNIQUE KEY url_hash (url_hash),
            KEY expires_at (expires_at)
        ) $charset_collate;";
        
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
    }
    
    public function deactivate() {
        wp_clear_scheduled_hook('bcr_cleanup_cache');
        $this->cleanup_old_cache(true); // Clean all on deactivation
    }
    
    public function cleanup_old_cache($all = false) {
        global $wpdb;
        $table_name = $wpdb->prefix . self::CACHE_TABLE;
        
        // Get expired entries or all if deactivating
        if ($all) {
            $entries = $wpdb->get_results("SELECT * FROM $table_name");
        } else {
            $entries = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT * FROM $table_name WHERE expires_at < %s",
                    current_time('mysql')
                )
            );
        }
        
        // Delete files and database entries
        foreach ($entries as $entry) {
            if (file_exists($entry->local_path)) {
                @unlink($entry->local_path);
            }
        }
        
        // Delete from database
        if ($all) {
            $wpdb->query("TRUNCATE TABLE $table_name");
        } else {
            $wpdb->query(
                $wpdb->prepare(
                    "DELETE FROM $table_name WHERE expires_at < %s",
                    current_time('mysql')
                )
            );
        }
    }
    
    public function cli_clear_cache() {
        $this->cleanup_old_cache(true);
        WP_CLI::success('Cache cleared successfully');
    }
    
    public function admin_menu() {
        add_menu_page(
            'Basecamp Reader',
            'BC Reader',
            'manage_options',
            'basecamp-reader',
            [$this, 'render_page'],
            'dashicons-visibility',
            30
        );
    }
    
    public function register_settings() {
        register_setting(self::OPT, self::OPT);
    }
    
    public function render_page() {
        // Handle OAuth callback
        if (isset($_GET['code'])) {
            $this->handle_oauth_callback($_GET['code']);
        }
        
        $opt = get_option(self::OPT, []);
        $token_data = get_option(self::TOKEN_OPT, []);
        $is_connected = !empty($token_data['access_token']);
        ?>
        <div class="wrap">
            <h1>Basecamp Cards Reader</h1>
            
            <?php if ($is_connected): ?>
                <div class="notice notice-success">
                    <p>✓ Connected to Basecamp (Expires: <?php echo date('Y-m-d H:i:s', $token_data['expires_at'] ?? 0); ?>)</p>
                </div>
            <?php elseif (isset($_GET['connected'])): ?>
                <div class="notice notice-success is-dismissible">
                    <p>Successfully connected to Basecamp!</p>
                </div>
            <?php else: ?>
                <div class="notice notice-warning">
                    <p>Not connected to Basecamp. Follow the setup guide below to get started.</p>
                </div>
            <?php endif; ?>
            
            <?php if (!$is_connected): ?>
            <div class="card">
                <h2>📘 Setup Guide</h2>
                <style>
                    .setup-steps { counter-reset: step-counter; }
                    .setup-step { 
                        margin: 20px 0; 
                        padding: 15px; 
                        background: #f8f9fa; 
                        border-left: 4px solid #2271b1;
                        position: relative;
                    }
                    .setup-step::before {
                        counter-increment: step-counter;
                        content: counter(step-counter);
                        position: absolute;
                        left: -15px;
                        top: 15px;
                        background: #2271b1;
                        color: white;
                        width: 30px;
                        height: 30px;
                        border-radius: 50%;
                        display: flex;
                        align-items: center;
                        justify-content: center;
                        font-weight: bold;
                    }
                    .setup-step h3 { margin-top: 0; color: #1d2327; }
                    .setup-step code { 
                        background: #fff; 
                        padding: 2px 6px; 
                        border: 1px solid #ddd;
                        display: inline-block;
                        margin: 2px 0;
                    }
                    .setup-step .highlight {
                        background: #fff3cd;
                        padding: 10px;
                        border: 1px solid #ffc107;
                        border-radius: 4px;
                        margin: 10px 0;
                    }
                    .copy-btn {
                        margin-left: 10px;
                        padding: 2px 8px;
                        background: #2271b1;
                        color: white;
                        border: none;
                        border-radius: 3px;
                        cursor: pointer;
                        font-size: 12px;
                    }
                    .copy-btn:hover { background: #135e96; }
                </style>
                
                <div class="setup-steps">
                    <div class="setup-step">
                        <h3>Create a Basecamp Integration App</h3>
                        <ol>
                            <li>Go to <a href="https://launchpad.37signals.com/integrations" target="_blank" style="font-weight: bold;">https://launchpad.37signals.com/integrations</a></li>
                            <li>Click <strong>"Register another application"</strong> button</li>
                            <li>Fill in the application details:
                                <ul style="margin-top: 10px;">
                                    <li><strong>Name:</strong> <code>WordPress Cards Reader</code> (or any name you prefer)</li>
                                    <li><strong>Company:</strong> Your company name</li>
                                    <li><strong>Website:</strong> <code><?php echo esc_html(home_url()); ?></code></li>
                                </ul>
                            </li>
                        </ol>
                    </div>
                    
                    <div class="setup-step">
                        <h3>Configure OAuth Redirect URI</h3>
                        <p>In the <strong>OAuth 2</strong> section of your Basecamp app, add this Redirect URI:</p>
                        <div class="highlight">
                            <code id="redirect-uri" style="font-size: 13px; word-break: break-all;"><?php echo esc_html(admin_url('admin.php?page=basecamp-reader')); ?></code>
                            <button type="button" class="copy-btn" onclick="copyToClipboard('redirect-uri')">Copy</button>
                        </div>
                        <p style="color: #d63638; margin-top: 10px;">⚠️ <strong>Important:</strong> The URI must match EXACTLY, including http/https!</p>
                    </div>
                    
                    <div class="setup-step">
                        <h3>Copy Your App Credentials</h3>
                        <p>After creating the app, Basecamp will show you:</p>
                        <ul>
                            <li><strong>Client ID:</strong> A long string like <code>1ea10a28633b2333a5ba80adc9b58cab2916ced8</code></li>
                            <li><strong>Client Secret:</strong> Another long string (keep this secure!)</li>
                        </ul>
                        <p>Copy these values and paste them in the form below.</p>
                    </div>
                    
                    <div class="setup-step">
                        <h3>Save and Connect</h3>
                        <p>Enter your credentials below, save settings, then click "Connect to Basecamp" to authorize.</p>
                    </div>
                </div>
                
                <script>
                function copyToClipboard(elementId) {
                    const text = document.getElementById(elementId).textContent;
                    navigator.clipboard.writeText(text).then(() => {
                        const btn = event.target;
                        const originalText = btn.textContent;
                        btn.textContent = 'Copied!';
                        setTimeout(() => { btn.textContent = originalText; }, 2000);
                    });
                }
                </script>
            </div>
            <?php endif; ?>
            
            <div class="card" style="margin-top: 20px;">
                <h2>⚙️ OAuth Configuration</h2>
                <form method="post" action="options.php">
                    <?php settings_fields(self::OPT); ?>
                    <table class="form-table">
                        <tr>
                            <th>Client ID</th>
                            <td>
                                <input type="text" name="<?php echo self::OPT; ?>[client_id]" value="<?php echo esc_attr($opt['client_id'] ?? ''); ?>" class="regular-text" placeholder="e.g., 1ea10a28633b2333a5ba80adc9b58cab2916ced8" />
                                <p class="description">From your Basecamp app settings</p>
                            </td>
                        </tr>
                        <tr>
                            <th>Client Secret</th>
                            <td>
                                <input type="password" name="<?php echo self::OPT; ?>[client_secret]" value="<?php echo esc_attr($opt['client_secret'] ?? ''); ?>" class="regular-text" placeholder="Your app's client secret" />
                                <p class="description">Keep this secure - never share it publicly</p>
                            </td>
                        </tr>
                        <tr>
                            <th>Redirect URI</th>
                            <td>
                                <code style="background: #f0f0f0; padding: 5px; display: inline-block;"><?php echo esc_html(admin_url('admin.php?page=basecamp-reader')); ?></code>
                                <p class="description">This must be added to your Basecamp app configuration</p>
                            </td>
                        </tr>
                    </table>
                    <?php submit_button('Save Settings'); ?>
                </form>
                
                <?php if (!empty($opt['client_id']) && !empty($opt['client_secret'])): ?>
                    <hr style="margin: 20px 0;">
                    <p>
                        <a href="<?php echo esc_url($this->get_oauth_url()); ?>" class="button button-primary button-hero">🔗 Connect to Basecamp</a>
                        <span style="margin-left: 15px; color: #666;">This will redirect you to Basecamp to authorize the connection</span>
                    </p>
                <?php else: ?>
                    <p style="color: #d63638;">⚠️ Please enter your Client ID and Client Secret first, then save settings.</p>
                <?php endif; ?>
            </div>
            
            <?php if ($is_connected): ?>
            <div class="card" style="margin-top: 20px;">
                <h2>📖 Read Basecamp Card</h2>
                <p>Enter a Basecamp card URL to read its details:</p>
                <input type="text" id="card-url" placeholder="https://3.basecamp.com/5798509/buckets/37557560/card_tables/cards/9010883489" style="width: 100%; max-width: 600px; padding: 8px;" />
                <button type="button" class="button button-primary" onclick="readCard()">Read Card</button>
                
                <div style="margin-top: 15px; padding: 10px; background: #f0f8ff; border: 1px solid #d1e4f3; border-radius: 4px;">
                    <strong>📌 Supported URL formats:</strong>
                    <ul style="margin: 10px 0 0 20px;">
                        <li>Todo: <code>https://3.basecamp.com/[account]/buckets/[project]/todos/[id]</code></li>
                        <li>Card: <code>https://3.basecamp.com/[account]/buckets/[project]/card_tables/cards/[id]</code></li>
                        <li>Message: <code>https://3.basecamp.com/[account]/buckets/[project]/messages/[id]</code></li>
                    </ul>
                </div>
                
                <div id="card-result" style="margin-top: 20px;"></div>
            </div>
            
            <div class="card" style="margin-top: 20px;">
                <h2>🚀 Quick Tools</h2>
                <h3>WP-CLI Commands</h3>
                <pre style="background: #282c34; color: #abb2bf; padding: 15px; border-radius: 4px; overflow-x: auto;">
<span style="color: #98c379;"># Read a specific card</span>
wp bcr read "https://3.basecamp.com/5798509/buckets/37557560/todos/9010883489"

<span style="color: #98c379;"># List all cards in a project</span>
wp bcr list --account=5798509 --project=37557560

<span style="color: #98c379;"># List cards from a specific todolist</span>
wp bcr list --account=5798509 --project=37557560 --list=123456

<span style="color: #98c379;"># Check authentication status</span>
wp bcr auth
                </pre>
                
                <h3>REST API Endpoints</h3>
                <div style="background: #f8f9fa; padding: 15px; border-radius: 4px;">
                    <p><strong>Read Card:</strong></p>
                    <code>POST <?php echo rest_url('bcr/v1/read-card'); ?></code>
                    <pre style="margin-top: 10px; background: white; padding: 10px; border: 1px solid #ddd;">
{
    "url": "https://3.basecamp.com/5798509/buckets/37557560/todos/9010883489"
}</pre>
                    
                    <p style="margin-top: 15px;"><strong>List Cards:</strong></p>
                    <code>GET <?php echo rest_url('bcr/v1/list-cards'); ?>?account_id=5798509&project_id=37557560</code>
                </div>
            </div>
            
            <script>
            function readCard() {
                const url = document.getElementById('card-url').value;
                const resultDiv = document.getElementById('card-result');
                resultDiv.innerHTML = 'Loading...';
                
                fetch('<?php echo rest_url('bcr/v1/read-card'); ?>', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-WP-Nonce': '<?php echo wp_create_nonce('wp_rest'); ?>'
                    },
                    body: JSON.stringify({ url: url })
                })
                .then(r => r.json())
                .then(data => {
                    if (data.error) {
                        resultDiv.innerHTML = '<div class="notice notice-error"><p>' + data.error + '</p></div>';
                    } else {
                        let html = '<h3>' + (data.title || 'Untitled') + '</h3>';
                        html += '<div style="background: #f0f0f0; padding: 10px; border-radius: 5px;">';
                        html += '<p><strong>Status:</strong> ' + (data.completed ? 'Completed ✓' : 'Open') + '</p>';
                        if (data.due_on) html += '<p><strong>Due:</strong> ' + data.due_on + '</p>';
                        if (data.assignees && data.assignees.length) {
                            html += '<p><strong>Assignees:</strong> ' + data.assignees.map(a => a.name).join(', ') + '</p>';
                        }
                        if (data.description) {
                            html += '<div><strong>Description:</strong><div style="background: white; padding: 10px; margin-top: 5px;">' + data.description + '</div></div>';
                        }
                        if (data.created_at) {
                            html += '<p><strong>Created:</strong> ' + new Date(data.created_at).toLocaleString() + '</p>';
                        }
                        if (data.creator && data.creator.name) {
                            html += '<p><strong>Creator:</strong> ' + data.creator.name + '</p>';
                        }
                        html += '<p><a href="' + data.app_url + '" target="_blank">View in Basecamp →</a></p>';
                        html += '</div>';
                        
                        // Display comments
                        if (data.comments && data.comments.length > 0) {
                            html += '<h4 style="margin-top: 20px;">💬 Comments (' + data.comments.length + ')</h4>';
                            html += '<div style="max-height: 400px; overflow-y: auto; border: 1px solid #ddd; padding: 10px; background: #fafafa;">';
                            
                            data.comments.forEach(function(comment) {
                                html += '<div style="margin-bottom: 15px; padding: 10px; background: white; border-radius: 5px;">';
                                if (comment.creator && comment.creator.name) {
                                    html += '<strong>' + comment.creator.name + '</strong>';
                                    if (comment.creator.avatar_url) {
                                        html += ' <img src="' + comment.creator.avatar_url + '" style="width: 20px; height: 20px; border-radius: 50%; vertical-align: middle; margin-left: 5px;">';
                                    }
                                }
                                if (comment.created_at) {
                                    html += ' <span style="color: #666; font-size: 12px;">• ' + new Date(comment.created_at).toLocaleString() + '</span>';
                                }
                                html += '<div style="margin-top: 8px;">' + comment.content + '</div>';
                                
                                // Display images
                                if (comment.images && comment.images.length > 0) {
                                    html += '<div style="margin-top: 10px;">';
                                    comment.images.forEach(function(imgUrl) {
                                        // Images are already downloaded and stored locally
                                        html += '<a href="' + imgUrl + '" target="_blank">';
                                        html += '<img src="' + imgUrl + '" ';
                                        html += 'style="max-width: 200px; max-height: 200px; margin: 5px; border: 1px solid #ddd; cursor: pointer;" ';
                                        html += 'loading="lazy" ';
                                        html += 'title="Click to view full size" ';
                                        html += 'onerror="this.style.display=\'none\'" /></a>';
                                    });
                                    html += '</div>';
                                }
                                
                                // Display other attachments
                                if (comment.attachments && comment.attachments.length > 0) {
                                    html += '<div style="margin-top: 10px;">';
                                    html += '<strong>📎 Attachments:</strong><br>';
                                    comment.attachments.forEach(function(attachment) {
                                        if (typeof attachment === 'object') {
                                            var name = attachment.name || (attachment.url ? attachment.url.split('/').pop() : 'Attachment');
                                            if (attachment.url) {
                                                var downloadUrl = '<?php echo rest_url('bcr/v1/proxy-image'); ?>?url=' + encodeURIComponent(attachment.url) + '&download=1';
                                                html += '<a href="' + downloadUrl + '" target="_blank">' + name + '</a><br>';
                                            } else if (attachment.sgid) {
                                                html += '<span style="color: #666;">' + name + ' (Basecamp attachment)</span><br>';
                                            }
                                        } else {
                                            html += '<a href="' + attachment + '" target="_blank">' + attachment.split('/').pop() + '</a><br>';
                                        }
                                    });
                                    html += '</div>';
                                }
                                
                                html += '</div>';
                            });
                            
                            html += '</div>';
                        }
                        
                        if (data.raw) {
                            html += '<details style="margin-top: 10px;"><summary>Raw Data</summary><pre style="background: #f0f0f0; padding: 10px; overflow: auto;">' + JSON.stringify(data.raw, null, 2) + '</pre></details>';
                        }
                        
                        resultDiv.innerHTML = html;
                    }
                });
            }
            </script>
            <?php endif; ?>
        </div>
        <?php
    }
    
    private function get_oauth_url() {
        $opt = get_option(self::OPT, []);
        return 'https://launchpad.37signals.com/authorization/new?' . http_build_query([
            'type' => 'web_server',
            'client_id' => $opt['client_id'],
            'redirect_uri' => admin_url('admin.php?page=basecamp-reader'),
        ]);
    }
    
    private function handle_oauth_callback($code) {
        $opt = get_option(self::OPT, []);
        
        $response = wp_remote_post('https://launchpad.37signals.com/authorization/token', [
            'body' => [
                'type' => 'web_server',
                'client_id' => $opt['client_id'],
                'client_secret' => $opt['client_secret'],
                'redirect_uri' => admin_url('admin.php?page=basecamp-reader'),
                'code' => $code,
            ],
        ]);
        
        if (!is_wp_error($response)) {
            $body = json_decode(wp_remote_retrieve_body($response), true);
            if (!empty($body['access_token'])) {
                update_option(self::TOKEN_OPT, [
                    'access_token' => $body['access_token'],
                    'refresh_token' => $body['refresh_token'] ?? '',
                    'expires_at' => time() + ($body['expires_in'] ?? 1209600),
                ]);
                
                wp_redirect(admin_url('admin.php?page=basecamp-reader&connected=1'));
                exit;
            }
        }
    }
    
    public function register_rest() {
        register_rest_route('bcr/v1', '/read-card', [
            'methods' => 'POST',
            'permission_callback' => function() { return current_user_can('manage_options'); },
            'callback' => [$this, 'rest_read_card'],
        ]);
        
        register_rest_route('bcr/v1', '/list-cards', [
            'methods' => 'GET',
            'permission_callback' => function() { return current_user_can('manage_options'); },
            'callback' => [$this, 'rest_list_cards'],
        ]);
        
        register_rest_route('bcr/v1', '/proxy-image', [
            'methods' => 'GET',
            'permission_callback' => '__return_true',
            'callback' => [$this, 'rest_proxy_image'],
        ]);
    }
    
    public function rest_read_card($request) {
        $url = $request->get_param('url');
        $download_images = $request->get_param('download_images');
        
        // Parse Basecamp URL
        // Format: https://3.basecamp.com/{account_id}/buckets/{project_id}/card_tables/cards/{card_id}
        if (!preg_match('#/(\d+)/buckets/(\d+)/card_tables/cards/(\d+)#', $url, $matches)) {
            // Also try todo format
            if (!preg_match('#/(\d+)/buckets/(\d+)/todos/(\d+)#', $url, $matches)) {
                return ['error' => 'Invalid Basecamp URL format'];
            }
        }
        
        $account_id = $matches[1];
        $project_id = $matches[2];
        $card_id = $matches[3];
        
        $data = $this->fetch_card($account_id, $project_id, $card_id);
        
        if (is_wp_error($data)) {
            return ['error' => $data->get_error_message()];
        }
        
        // Pre-download all images if requested
        if ($download_images && !empty($data['comments'])) {
            $this->pre_download_images($data['comments'], $project_id, $card_id);
        }
        
        return $data;
    }
    
    private function pre_download_images($comments, $project_id, $card_id) {
        foreach ($comments as &$comment) {
            if (!empty($comment['images'])) {
                foreach ($comment['images'] as &$img_url) {
                    // Trigger download through proxy
                    $this->cache_image($img_url, $project_id, $card_id);
                }
            }
        }
    }
    
    private function cache_image($url, $project_id = '', $card_id = '') {
        global $wpdb;
        $table_name = $wpdb->prefix . self::CACHE_TABLE;
        $url_hash = md5($url);
        
        // Check if already cached
        $cached = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT local_url FROM $table_name WHERE url_hash = %s AND expires_at > %s",
                $url_hash,
                current_time('mysql')
            )
        );
        
        if ($cached) {
            return $cached->local_url;
        }
        
        // Download and cache
        $token_data = get_option(self::TOKEN_OPT, []);
        if (empty($token_data['access_token'])) {
            return $url; // Return original if not connected
        }
        
        $response = wp_remote_get($url, [
            'headers' => [
                'Authorization' => 'Bearer ' . $token_data['access_token'],
                'User-Agent' => get_bloginfo('name') . ' (' . get_option('admin_email') . ')',
            ],
            'timeout' => 30,
        ]);
        
        if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200) {
            return $url; // Return original on error
        }
        
        // Save using the same logic as proxy endpoint
        $body = wp_remote_retrieve_body($response);
        $content_type = wp_remote_retrieve_header($response, 'content-type');
        
        // Save to organized directory
        $upload_dir = wp_upload_dir();
        $cache_dir = $upload_dir['basedir'] . '/basecamp/' . $project_id . '/' . $card_id;
        if (!file_exists($cache_dir)) {
            wp_mkdir_p($cache_dir);
        }
        
        $path_info = pathinfo(parse_url($url, PHP_URL_PATH));
        $filename = sanitize_file_name($path_info['basename'] ?? $url_hash . '.jpg');
        $local_path = $cache_dir . '/' . $filename;
        $local_url = $upload_dir['baseurl'] . '/basecamp/' . $project_id . '/' . $card_id . '/' . $filename;
        
        file_put_contents($local_path, $body);
        
        // Save to database
        $wpdb->insert(
            $table_name,
            [
                'url_hash' => $url_hash,
                'original_url' => $url,
                'local_path' => $local_path,
                'local_url' => $local_url,
                'content_type' => $content_type,
                'file_size' => strlen($body),
                'created_at' => current_time('mysql'),
                'expires_at' => date('Y-m-d H:i:s', strtotime('+24 hours')),
            ]
        );
        
        return $local_url;
    }
    
    public function rest_list_cards($request) {
        $account_id = $request->get_param('account_id');
        $project_id = $request->get_param('project_id');
        $list_id = $request->get_param('list_id');
        
        if (!$account_id || !$project_id) {
            return ['error' => 'account_id and project_id required'];
        }
        
        $cards = $this->fetch_cards_list($account_id, $project_id, $list_id);
        
        if (is_wp_error($cards)) {
            return ['error' => $cards->get_error_message()];
        }
        
        return ['cards' => $cards];
    }
    
    private function fetch_card($account_id, $project_id, $card_id) {
        $token_data = get_option(self::TOKEN_OPT, []);
        if (empty($token_data['access_token'])) {
            return new WP_Error('not_connected', 'Not connected to Basecamp');
        }
        
        $headers = [
            'Authorization' => 'Bearer ' . $token_data['access_token'],
            'User-Agent' => get_bloginfo('name') . ' (' . get_option('admin_email') . ')',
        ];
        
        // Try as a todo first (most common)
        $url = "https://3.basecampapi.com/$account_id/buckets/$project_id/todos/$card_id.json";
        $card_type = 'todo';
        
        $response = wp_remote_get($url, ['headers' => $headers]);
        
        if (is_wp_error($response)) {
            return $response;
        }
        
        $code = wp_remote_retrieve_response_code($response);
        if ($code === 404) {
            // Try as a card table card
            $url = "https://3.basecampapi.com/$account_id/buckets/$project_id/card_tables/cards/$card_id.json";
            $card_type = 'card';
            $response = wp_remote_get($url, ['headers' => $headers]);
            
            if (is_wp_error($response)) {
                return $response;
            }
            
            $code = wp_remote_retrieve_response_code($response);
        }
        
        if ($code !== 200) {
            return new WP_Error('api_error', 'Basecamp API returned ' . $code);
        }
        
        $body = json_decode(wp_remote_retrieve_body($response), true);
        
        // Fetch comments if available
        $comments = [];
        if (!empty($body['comments_count']) && $body['comments_count'] > 0) {
            $comments = $this->fetch_comments($account_id, $project_id, $card_id, $headers);
            
            // Download all images immediately and replace URLs with local ones
            $comments = $this->download_comment_images($comments, $project_id, $card_id);
        }
        
        // Format the response
        return [
            'id' => $body['id'] ?? $card_id,
            'title' => $body['title'] ?? $body['content'] ?? '',
            'description' => $body['description'] ?? '',
            'completed' => $body['completed'] ?? false,
            'due_on' => $body['due_on'] ?? '',
            'starts_on' => $body['starts_on'] ?? '',
            'assignees' => $body['assignees'] ?? [],
            'comments_count' => $body['comments_count'] ?? 0,
            'comments' => $comments,
            'app_url' => $body['app_url'] ?? $url,
            'type' => $body['type'] ?? 'Unknown',
            'card_type' => $card_type,
            'creator' => $body['creator'] ?? [],
            'created_at' => $body['created_at'] ?? '',
            'updated_at' => $body['updated_at'] ?? '',
            'raw' => $body, // Include raw data for debugging
        ];
    }
    
    private function download_comment_images($comments, $project_id, $card_id) {
        $token_data = get_option(self::TOKEN_OPT, []);
        if (empty($token_data['access_token'])) {
            return $comments; // Return as-is if not connected
        }
        
        $headers = [
            'Authorization' => 'Bearer ' . $token_data['access_token'],
            'User-Agent' => get_bloginfo('name') . ' (' . get_option('admin_email') . ')',
        ];
        
        // Process each comment
        foreach ($comments as &$comment) {
            if (!empty($comment['images'])) {
                $local_images = [];
                foreach ($comment['images'] as $img_url) {
                    $local_url = $this->download_and_save_image($img_url, $project_id, $card_id, $headers);
                    $local_images[] = $local_url;
                }
                $comment['images'] = $local_images;
            }
        }
        
        return $comments;
    }
    
    private function download_and_save_image($url, $project_id, $card_id, $headers) {
        global $wpdb;
        $table_name = $wpdb->prefix . self::CACHE_TABLE;
        $url_hash = md5($url);
        
        // Check if already cached
        $cached = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT local_url, local_path FROM $table_name WHERE url_hash = %s AND expires_at > %s",
                $url_hash,
                current_time('mysql')
            )
        );
        
        if ($cached && file_exists($cached->local_path)) {
            return $cached->local_url;
        }
        
        // Download the image - first check for redirect
        $response = wp_remote_request($url, [
            'method' => 'GET',
            'headers' => $headers,
            'timeout' => 30,
            'redirection' => 0, // Don't follow redirects automatically
        ]);
        
        if (is_wp_error($response)) {
            return $url; // Return original on error
        }
        
        $code = wp_remote_retrieve_response_code($response);
        
        // Handle redirects to AWS S3
        if ($code >= 301 && $code <= 308) {
            $location = wp_remote_retrieve_header($response, 'location');
            if ($location && strpos($location, 'amazonaws.com') !== false) {
                // Follow redirect to AWS without auth headers (AWS uses signed URLs)
                $response = wp_remote_get($location, [
                    'timeout' => 30,
                    'headers' => [
                        'User-Agent' => get_bloginfo('name') . ' (' . get_option('admin_email') . ')',
                    ],
                ]);
                
                if (is_wp_error($response)) {
                    return $url;
                }
                
                $code = wp_remote_retrieve_response_code($response);
            }
        }
        
        if ($code !== 200) {
            // Try with automatic redirect following as fallback
            $response = wp_remote_get($url, [
                'headers' => $headers,
                'timeout' => 30,
                'redirection' => 5,
            ]);
            
            if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200) {
                return $url; // Return original URL on error
            }
        }
        
        $body = wp_remote_retrieve_body($response);
        $content_type = wp_remote_retrieve_header($response, 'content-type');
        
        // Create directory structure
        $upload_dir = wp_upload_dir();
        $cache_dir = $upload_dir['basedir'] . '/basecamp/' . $project_id . '/' . $card_id;
        if (!file_exists($cache_dir)) {
            wp_mkdir_p($cache_dir);
        }
        
        // Generate filename
        $path_info = pathinfo(parse_url($url, PHP_URL_PATH));
        $filename = !empty($path_info['basename']) ? sanitize_file_name($path_info['basename']) : $url_hash . '.jpg';
        
        // Make unique if exists
        $counter = 0;
        $base = pathinfo($filename, PATHINFO_FILENAME);
        $ext = pathinfo($filename, PATHINFO_EXTENSION);
        while (file_exists($cache_dir . '/' . $filename)) {
            $counter++;
            $filename = $base . '-' . $counter . '.' . $ext;
        }
        
        $local_path = $cache_dir . '/' . $filename;
        $local_url = $upload_dir['baseurl'] . '/basecamp/' . $project_id . '/' . $card_id . '/' . $filename;
        
        // Save file
        file_put_contents($local_path, $body);
        
        // Delete old cache entry if exists
        $wpdb->delete($table_name, ['url_hash' => $url_hash]);
        
        // Save to database
        $wpdb->insert(
            $table_name,
            [
                'url_hash' => $url_hash,
                'original_url' => $url,
                'local_path' => $local_path,
                'local_url' => $local_url,
                'content_type' => $content_type,
                'file_size' => strlen($body),
                'created_at' => current_time('mysql'),
                'expires_at' => date('Y-m-d H:i:s', strtotime('+7 days')), // Keep for 7 days
            ]
        );
        
        return $local_url;
    }
    
    private function fetch_comments($account_id, $project_id, $recording_id, $headers) {
        $url = "https://3.basecampapi.com/$account_id/buckets/$project_id/recordings/$recording_id/comments.json";
        
        $response = wp_remote_get($url, ['headers' => $headers]);
        
        if (is_wp_error($response)) {
            return [];
        }
        
        $code = wp_remote_retrieve_response_code($response);
        if ($code !== 200) {
            return [];
        }
        
        $comments = json_decode(wp_remote_retrieve_body($response), true);
        
        // Process comments to extract images and format content
        $formatted_comments = [];
        foreach ($comments as $comment) {
            $formatted_comment = [
                'id' => $comment['id'],
                'content' => $comment['content'] ?? '',
                'creator' => $comment['creator'] ?? [],
                'created_at' => $comment['created_at'] ?? '',
                'updated_at' => $comment['updated_at'] ?? '',
                'attachments' => [],
                'images' => [],
            ];
            
            // Extract attachments and images from content
            if (!empty($comment['content'])) {
                // Look for bc-attachment tags with sgid (Basecamp's signed global ID)
                if (preg_match_all('/<bc-attachment[^>]*sgid="([^"]+)"[^>]*>.*?<\/bc-attachment>/i', $comment['content'], $matches)) {
                    foreach ($matches[1] as $sgid) {
                        $formatted_comment['attachments'][] = ['sgid' => $sgid, 'type' => 'bc-attachment'];
                    }
                }
                
                // Look for bc-attachment tags - extract both href and filename
                if (preg_match_all('/<bc-attachment([^>]*)>.*?<\/bc-attachment>/i', $comment['content'], $matches)) {
                    foreach ($matches[1] as $attrs) {
                        $attachment = [];
                        
                        // Extract href (download URL)
                        if (preg_match('/href="([^"]+)"/', $attrs, $href_match)) {
                            $attachment['url'] = $href_match[1];
                        }
                        
                        // Extract filename
                        if (preg_match('/filename="([^"]+)"/', $attrs, $filename_match)) {
                            $attachment['filename'] = $filename_match[1];
                        }
                        
                        // Extract content-type
                        if (preg_match('/content-type="([^"]+)"/', $attrs, $type_match)) {
                            $attachment['content_type'] = $type_match[1];
                        }
                        
                        // Check if it's an image based on content-type or extension
                        if (!empty($attachment['url'])) {
                            $is_image = false;
                            if (!empty($attachment['content_type']) && strpos($attachment['content_type'], 'image/') === 0) {
                                $is_image = true;
                            } elseif (preg_match('/\.(jpg|jpeg|png|gif|webp)$/i', $attachment['url'])) {
                                $is_image = true;
                            }
                            
                            if ($is_image) {
                                $formatted_comment['images'][] = $attachment['url'];
                            } else {
                                $formatted_comment['attachments'][] = [
                                    'url' => $attachment['url'],
                                    'type' => 'file',
                                    'name' => $attachment['filename'] ?? basename($attachment['url'])
                                ];
                            }
                        }
                    }
                }
                
                // Also look for regular img tags
                if (preg_match_all('/<img[^>]*src="([^"]+)"[^>]*>/i', $comment['content'], $img_matches)) {
                    foreach ($img_matches[1] as $img_url) {
                        if (!in_array($img_url, $formatted_comment['images'])) {
                            $formatted_comment['images'][] = $img_url;
                        }
                    }
                }
                
                // Look for Basecamp figure/attachment divs with data-attachment
                if (preg_match_all('/<div[^>]*class="[^"]*attachment[^"]*"[^>]*data-attachment=\'([^\']+)\'[^>]*>/i', $comment['content'], $data_matches)) {
                    foreach ($data_matches[1] as $data_json) {
                        $attachment_data = json_decode(html_entity_decode($data_json), true);
                        if (!empty($attachment_data['url'])) {
                            if (!empty($attachment_data['content_type']) && strpos($attachment_data['content_type'], 'image') === 0) {
                                $formatted_comment['images'][] = $attachment_data['url'];
                            } else {
                                $formatted_comment['attachments'][] = ['url' => $attachment_data['url'], 'type' => 'data-attachment', 'name' => $attachment_data['filename'] ?? ''];
                            }
                        }
                    }
                }
            }
            
            $formatted_comments[] = $formatted_comment;
        }
        
        return $formatted_comments;
    }
    
    private function fetch_cards_list($account_id, $project_id, $list_id = null) {
        $token_data = get_option(self::TOKEN_OPT, []);
        if (empty($token_data['access_token'])) {
            return new WP_Error('not_connected', 'Not connected to Basecamp');
        }
        
        $headers = [
            'Authorization' => 'Bearer ' . $token_data['access_token'],
            'User-Agent' => get_bloginfo('name') . ' (' . get_option('admin_email') . ')',
        ];
        
        if ($list_id) {
            // Fetch todos from specific list
            $url = "https://3.basecampapi.com/$account_id/buckets/$project_id/todolists/$list_id/todos.json";
        } else {
            // First get the todoset
            $project_url = "https://3.basecampapi.com/$account_id/projects/$project_id.json";
            $project_response = wp_remote_get($project_url, ['headers' => $headers]);
            
            if (is_wp_error($project_response)) {
                return $project_response;
            }
            
            $project = json_decode(wp_remote_retrieve_body($project_response), true);
            $todoset_id = null;
            
            foreach ($project['dock'] ?? [] as $tool) {
                if ($tool['name'] === 'todoset') {
                    $todoset_id = $tool['id'];
                    break;
                }
            }
            
            if (!$todoset_id) {
                return new WP_Error('no_todoset', 'No todoset found in project');
            }
            
            // Get all todolists
            $url = "https://3.basecampapi.com/$account_id/buckets/$project_id/todosets/$todoset_id/todolists.json";
        }
        
        $response = wp_remote_get($url, ['headers' => $headers]);
        
        if (is_wp_error($response)) {
            return $response;
        }
        
        $code = wp_remote_retrieve_response_code($response);
        if ($code !== 200) {
            return new WP_Error('api_error', 'Basecamp API returned ' . $code);
        }
        
        return json_decode(wp_remote_retrieve_body($response), true);
    }
    
    /* ----------------
     * WP-CLI Commands
     * ---------------- */
    public function cli_read_card($args) {
        if (empty($args[0])) {
            WP_CLI::error('Please provide a Basecamp card URL');
        }
        
        $url = $args[0];
        
        // Parse URL
        if (!preg_match('#/(\d+)/buckets/(\d+)/(?:card_tables/cards|todos)/(\d+)#', $url, $matches)) {
            WP_CLI::error('Invalid Basecamp URL format');
        }
        
        $data = $this->fetch_card($matches[1], $matches[2], $matches[3]);
        
        if (is_wp_error($data)) {
            WP_CLI::error($data->get_error_message());
        }
        
        WP_CLI::line("\n=== BASECAMP CARD ===");
        WP_CLI::line("Title: " . ($data['title'] ?: 'Untitled'));
        WP_CLI::line("Status: " . ($data['completed'] ? 'Completed ✓' : 'Open'));
        
        if ($data['due_on']) {
            WP_CLI::line("Due: " . $data['due_on']);
        }
        
        if (!empty($data['assignees'])) {
            WP_CLI::line("Assignees: " . implode(', ', array_column($data['assignees'], 'name')));
        }
        
        if ($data['description']) {
            WP_CLI::line("\nDescription:");
            WP_CLI::line(strip_tags($data['description']));
        }
        
        if (!empty($data['creator']['name'])) {
            WP_CLI::line("Creator: " . $data['creator']['name']);
        }
        
        if ($data['created_at']) {
            WP_CLI::line("Created: " . date('Y-m-d H:i:s', strtotime($data['created_at'])));
        }
        
        WP_CLI::line("\nView in Basecamp: " . $data['app_url']);
        
        // Display comments
        if (!empty($data['comments'])) {
            WP_CLI::line("\n=== COMMENTS (" . count($data['comments']) . ") ===");
            foreach ($data['comments'] as $comment) {
                WP_CLI::line("\n---");
                if (!empty($comment['creator']['name'])) {
                    WP_CLI::line("From: " . $comment['creator']['name']);
                }
                if ($comment['created_at']) {
                    WP_CLI::line("Date: " . date('Y-m-d H:i:s', strtotime($comment['created_at'])));
                }
                WP_CLI::line("\nComment:");
                WP_CLI::line(strip_tags($comment['content']));
                
                if (!empty($comment['images'])) {
                    WP_CLI::line("\nImages:");
                    foreach ($comment['images'] as $img) {
                        WP_CLI::line("  - " . $img);
                    }
                }
                
                if (!empty($comment['attachments'])) {
                    WP_CLI::line("\nAttachments:");
                    foreach ($comment['attachments'] as $attachment) {
                        WP_CLI::line("  - " . basename($attachment));
                    }
                }
            }
        }
        
        if (isset($args[1]) && $args[1] === '--raw') {
            WP_CLI::line("\nRaw Data:");
            WP_CLI::line(json_encode($data['raw'], JSON_PRETTY_PRINT));
        }
    }
    
    public function cli_list_cards($args, $assoc_args) {
        $account_id = $assoc_args['account'] ?? null;
        $project_id = $assoc_args['project'] ?? null;
        $list_id = $assoc_args['list'] ?? null;
        
        if (!$account_id || !$project_id) {
            WP_CLI::error('Please provide --account=ID and --project=ID');
        }
        
        $cards = $this->fetch_cards_list($account_id, $project_id, $list_id);
        
        if (is_wp_error($cards)) {
            WP_CLI::error($cards->get_error_message());
        }
        
        if (empty($cards)) {
            WP_CLI::line('No cards found');
            return;
        }
        
        $table_data = [];
        foreach ($cards as $card) {
            $table_data[] = [
                'ID' => $card['id'] ?? '',
                'Title' => substr($card['title'] ?? $card['content'] ?? '', 0, 50),
                'Status' => isset($card['completed']) ? ($card['completed'] ? 'Done' : 'Open') : 'N/A',
                'Due' => $card['due_on'] ?? '',
            ];
        }
        
        WP_CLI\Utils\format_items('table', $table_data, ['ID', 'Title', 'Status', 'Due']);
    }
    
    public function cli_auth_status() {
        $token_data = get_option(self::TOKEN_OPT, []);
        
        if (empty($token_data['access_token'])) {
            WP_CLI::error('Not connected to Basecamp');
            return;
        }
        
        $expires = date('Y-m-d H:i:s', $token_data['expires_at'] ?? 0);
        $is_expired = time() > ($token_data['expires_at'] ?? 0);
        
        WP_CLI::success('Connected to Basecamp');
        WP_CLI::line('Token expires: ' . $expires . ($is_expired ? ' (EXPIRED)' : ''));
    }
    
    public function rest_proxy_image($request) {
        $url = $request->get_param('url');
        $download = $request->get_param('download');
        
        if (!$url) {
            return new WP_Error('no_url', 'No URL provided', ['status' => 400]);
        }
        
        // Only allow Basecamp URLs for security
        if (!preg_match('/^https:\/\/(3\.basecamp\.com|storage\.3\.basecamp\.com|preview\.3\.basecamp\.com|bc3-production-attachments\.s3\.amazonaws\.com|basecamp-production\.s3\.amazonaws\.com)/i', $url)) {
            return new WP_Error('invalid_url', 'Only Basecamp URLs are allowed', ['status' => 403]);
        }
        
        global $wpdb;
        $table_name = $wpdb->prefix . self::CACHE_TABLE;
        $url_hash = md5($url);
        
        // Check if we have a cached version
        $cached = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM $table_name WHERE url_hash = %s AND expires_at > %s",
                $url_hash,
                current_time('mysql')
            )
        );
        
        if ($cached && file_exists($cached->local_path)) {
            // Serve from cache
            $this->serve_cached_file($cached->local_path, $cached->content_type, $download);
            exit;
        }
        
        // If not cached or expired, fetch from Basecamp
        $token_data = get_option(self::TOKEN_OPT, []);
        if (empty($token_data['access_token'])) {
            return new WP_Error('not_connected', 'Not connected to Basecamp', ['status' => 401]);
        }
        
        // Fetch image with authentication
        $response = wp_remote_get($url, [
            'headers' => [
                'Authorization' => 'Bearer ' . $token_data['access_token'],
                'User-Agent' => get_bloginfo('name') . ' (' . get_option('admin_email') . ')',
            ],
            'timeout' => 30,
        ]);
        
        if (is_wp_error($response)) {
            return new WP_Error('fetch_failed', 'Failed to fetch image', ['status' => 500]);
        }
        
        $code = wp_remote_retrieve_response_code($response);
        if ($code !== 200) {
            return new WP_Error('fetch_failed', 'Failed to fetch image: HTTP ' . $code, ['status' => $code]);
        }
        
        $body = wp_remote_retrieve_body($response);
        $content_type = wp_remote_retrieve_header($response, 'content-type');
        $file_size = strlen($body);
        
        // Extract project and card IDs from the URL if possible
        $project_id = '';
        $card_id = '';
        if (preg_match('/buckets\/(\d+)\/.*?\/(\d+)/', $url, $matches)) {
            $project_id = $matches[1];
            $card_id = $matches[2];
        } elseif (preg_match('/\/(\d+)\/blobs\//', $url, $matches)) {
            // For blob URLs, use account ID as project placeholder
            $project_id = $matches[1];
            // Extract blob ID as card placeholder
            if (preg_match('/blobs\/([a-f0-9\-]+)/', $url, $blob_match)) {
                $card_id = substr($blob_match[1], 0, 8); // Use first 8 chars of blob ID
            }
        }
        
        // Save to organized directory structure: uploads/basecamp/project-id/card-id/
        $upload_dir = wp_upload_dir();
        $base_dir = $upload_dir['basedir'] . '/basecamp';
        
        if ($project_id && $card_id) {
            $cache_dir = $base_dir . '/' . $project_id . '/' . $card_id;
            $relative_path = '/basecamp/' . $project_id . '/' . $card_id;
        } else {
            // Fallback to general cache
            $cache_dir = $base_dir . '/general';
            $relative_path = '/basecamp/general';
        }
        
        if (!file_exists($cache_dir)) {
            wp_mkdir_p($cache_dir);
        }
        
        // Generate filename based on URL and extension
        $path_info = pathinfo(parse_url($url, PHP_URL_PATH));
        $original_filename = isset($path_info['basename']) ? $path_info['basename'] : '';
        $extension = isset($path_info['extension']) ? '.' . $path_info['extension'] : '';
        
        if (!$extension && strpos($content_type, 'image/') === 0) {
            $extension = '.' . str_replace('image/', '', $content_type);
        }
        
        // Use original filename if available, otherwise use hash
        if ($original_filename && strpos($original_filename, '.') !== false) {
            $filename = sanitize_file_name($original_filename);
        } else {
            $filename = $url_hash . $extension;
        }
        
        // Make filename unique if it already exists
        $counter = 0;
        $base_filename = pathinfo($filename, PATHINFO_FILENAME);
        $ext = pathinfo($filename, PATHINFO_EXTENSION);
        while (file_exists($cache_dir . '/' . $filename)) {
            $counter++;
            $filename = $base_filename . '-' . $counter . ($ext ? '.' . $ext : '');
        }
        
        $local_path = $cache_dir . '/' . $filename;
        $local_url = $upload_dir['baseurl'] . $relative_path . '/' . $filename;
        
        // Save file
        file_put_contents($local_path, $body);
        
        // Delete old cache entry if exists
        $wpdb->delete($table_name, ['url_hash' => $url_hash]);
        
        // Save to database index
        $wpdb->insert(
            $table_name,
            [
                'url_hash' => $url_hash,
                'original_url' => $url,
                'local_path' => $local_path,
                'local_url' => $local_url,
                'content_type' => $content_type,
                'file_size' => $file_size,
                'created_at' => current_time('mysql'),
                'expires_at' => date('Y-m-d H:i:s', strtotime('+24 hours')),
            ]
        );
        
        // Serve the file
        $this->serve_cached_file($local_path, $content_type, $download);
        exit;
    }
    
    private function serve_cached_file($file_path, $content_type, $download = false) {
        if (!file_exists($file_path)) {
            http_response_code(404);
            die('File not found');
        }
        
        $file_size = filesize($file_path);
        
        if ($download) {
            header('Content-Disposition: attachment; filename="' . basename($file_path) . '"');
        } else {
            header('Content-Disposition: inline; filename="' . basename($file_path) . '"');
        }
        
        header('Content-Type: ' . ($content_type ?: 'application/octet-stream'));
        header('Content-Length: ' . $file_size);
        header('Cache-Control: public, max-age=86400'); // 24 hours
        header('Expires: ' . gmdate('D, d M Y H:i:s', time() + 86400) . ' GMT');
        
        // Output file
        readfile($file_path);
    }
}

new Basecamp_Cards_Reader();