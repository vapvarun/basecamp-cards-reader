<?php
/**
 * Plugin Name: Basecamp Cards Reader
 * Description: Read and manage Basecamp cards and todos directly from WordPress with OAuth 2.0 authentication
 * Version: 1.1.0
 * Author: Wbcom Designs
 * Author URI: https://wbcomdesigns.com
 * License: GPL v2 or later
 * Text Domain: basecamp-cards-reader
 */

if (!defined('ABSPATH')) {
    exit;
}

class Basecamp_Cards_Reader_Clean {
    const OPT = 'bcr_settings';
    const TOKEN_OPT = 'bcr_token_data';
    
    private static $instance = null;
    
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    private function __construct() {
        add_action('init', [$this, 'init']);
        add_action('admin_menu', [$this, 'admin_menu']);
        add_action('rest_api_init', [$this, 'register_rest_routes']);
        add_action('admin_post_bcr_disconnect', [$this, 'handle_disconnect']);
        add_action('wp_ajax_bcr_read_card', [$this, 'handle_read_card']);
        add_action('wp_ajax_bcr_post_comment', [$this, 'handle_post_comment']);
        
        // Load CLI commands
        $this->load_cli_commands();
    }
    
    private function load_cli_commands() {
        if (defined('WP_CLI') && WP_CLI) {
            require_once plugin_dir_path(__FILE__) . 'includes/class-bcr-cli-commands.php';
        }
    }
    
    public function init() {
        // Plugin initialization
    }
    
    public function activate() {
        // Plugin activation
    }
    
    public function deactivate() {
        // Plugin deactivation
    }
    
    public function admin_menu() {
        add_options_page(
            'Basecamp Cards Reader',
            'Basecamp Reader',
            'manage_options',
            'basecamp-reader',
            [$this, 'admin_page']
        );
    }
    
    public function register_rest_routes() {
        register_rest_route('bcr/v1', '/read-card', [
            'methods' => 'GET',
            'callback' => [$this, 'rest_read_card'],
            'permission_callback' => '__return_true',
        ]);
    }
    
    public function handle_read_card() {
        // Verify nonce
        if (!isset($_POST['_wpnonce']) || !wp_verify_nonce($_POST['_wpnonce'], 'bcr_ajax_nonce')) {
            wp_send_json_error('Security check failed');
        }
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
        }
        
        $url = sanitize_text_field($_POST['url'] ?? '');
        if (empty($url)) {
            wp_send_json_error('URL is required');
        }
        
        // Parse Basecamp URL - handle both todos and cards
        if (!preg_match('/basecamp\.com\/(\d+)\/buckets\/(\d+)\/.*\/(\d+)$/', $url, $matches)) {
            wp_send_json_error('Invalid Basecamp URL format');
        }
        
        $account_id = $matches[1];
        $project_id = $matches[2];
        $recording_id = $matches[3];
        
        // Determine if it's a card or todo
        $is_card = strpos($url, '/card_tables/cards/') !== false;
        $endpoint_type = $is_card ? 'card_tables/cards' : 'todos';
        
        $token_data = get_option(self::TOKEN_OPT, []);
        if (empty($token_data['access_token'])) {
            wp_send_json_error('Not connected to Basecamp. Please connect first.');
        }
        
        // Check token expiration
        if (!empty($token_data['expires_at']) && time() >= $token_data['expires_at']) {
            // Try to refresh
            $this->refresh_token();
            $token_data = get_option(self::TOKEN_OPT, []);
        }
        
        // Fetch data from Basecamp API - use correct endpoint based on type
        $api_url = "https://3.basecampapi.com/$account_id/buckets/$project_id/$endpoint_type/$recording_id.json";
        
        $headers = [
            'Authorization' => 'Bearer ' . $token_data['access_token'],
            'User-Agent' => get_bloginfo('name') . ' (' . get_option('admin_email') . ')',
        ];
        
        $response = wp_remote_get($api_url, ['headers' => $headers]);
        
        if (is_wp_error($response)) {
            wp_send_json_error('Failed to fetch from Basecamp: ' . $response->get_error_message());
        }
        
        if (wp_remote_retrieve_response_code($response) !== 200) {
            wp_send_json_error('Basecamp API error: ' . wp_remote_retrieve_response_code($response));
        }
        
        $card_data = json_decode(wp_remote_retrieve_body($response), true);
        
        // Fetch comments if record exists
        $comments = [];
        if ($card_data && !empty($card_data['id'])) {
            $comments = $this->fetch_comments($account_id, $project_id, $recording_id, $headers);
        }
        
        wp_send_json_success([
            'title' => $card_data['title'] ?? 'Untitled',
            'description' => $card_data['description'] ?? $card_data['content'] ?? '',
            'type' => $card_data['type'] ?? 'Unknown',
            'url' => $url,
            'created_at' => $card_data['created_at'] ?? '',
            'updated_at' => $card_data['updated_at'] ?? '',
            'creator' => $card_data['creator'] ?? [],
            'assignees' => $card_data['assignees'] ?? [],
            'completed' => $card_data['completed'] ?? false,
            'comments_count' => $card_data['comments_count'] ?? 0,
            'comments' => $comments,
            'raw' => $card_data,
        ]);
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
                // Look for bc-attachment tags - extract both href and filename
                if (preg_match_all('/<bc-attachment([^>]*)>.*?<\/bc-attachment>/i', $comment['content'], $matches)) {
                    foreach ($matches[1] as $attrs) {
                        $attachment = [];
                        
                        // Extract href (download URL)
                        if (preg_match('/href="([^"]+)"/', $attrs, $href_match)) {
                            $attachment['url'] = html_entity_decode($href_match[1]);
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
            }
            
            $formatted_comments[] = $formatted_comment;
        }
        
        return $formatted_comments;
    }
    
    public function refresh_token() {
        $token_data = get_option(self::TOKEN_OPT, []);
        if (empty($token_data['refresh_token'])) {
            return false;
        }
        
        $opt = get_option(self::OPT, []);
        
        $response = wp_remote_post('https://launchpad.37signals.com/authorization/token', [
            'body' => [
                'type' => 'refresh',
                'refresh_token' => $token_data['refresh_token'],
                'client_id' => $opt['client_id'],
                'client_secret' => $opt['client_secret'],
            ],
        ]);
        
        if (!is_wp_error($response)) {
            $body = json_decode(wp_remote_retrieve_body($response), true);
            if (!empty($body['access_token'])) {
                update_option(self::TOKEN_OPT, [
                    'access_token' => $body['access_token'],
                    'refresh_token' => $body['refresh_token'] ?? $token_data['refresh_token'],
                    'expires_at' => time() + ($body['expires_in'] ?? 1209600),
                ]);
                return true;
            }
        }
        
        return false;
    }
    
    public function admin_page() {
        $opt = get_option(self::OPT, []);
        $token_data = get_option(self::TOKEN_OPT, []);
        
        // Handle OAuth callback
        if (!empty($_GET['code']) && !empty($_GET['state'])) {
            $this->handle_oauth_callback($_GET['code']);
            wp_redirect(admin_url('options-general.php?page=basecamp-reader'));
            exit;
        }
        
        // Handle form submissions
        if (isset($_POST['save_settings'])) {
            check_admin_referer('bcr_save_settings');
            
            $new_opt = [
                'client_id' => sanitize_text_field($_POST['client_id']),
                'client_secret' => sanitize_text_field($_POST['client_secret']),
            ];
            
            update_option(self::OPT, $new_opt);
            $opt = $new_opt;
            
            echo '<div class="notice notice-success"><p>Settings saved!</p></div>';
        }
        
        $is_connected = !empty($token_data['access_token']);
        $is_expired = !empty($token_data['expires_at']) && time() >= $token_data['expires_at'];
        
        ?>
        <div class="wrap">
            <h1>Basecamp Cards Reader</h1>
            
            <div class="card">
                <h2>OAuth 2.0 Setup</h2>
                <p>To use this plugin, you need to create a Basecamp app:</p>
                <ol>
                    <li>Go to <a href="https://launchpad.37signals.com/integrations" target="_blank">Basecamp Integrations</a></li>
                    <li>Register a new app with these settings:</li>
                    <li><strong>Redirect URI:</strong> <code><?php echo admin_url('options-general.php?page=basecamp-reader'); ?></code></li>
                </ol>
            </div>
            
            <form method="post">
                <?php wp_nonce_field('bcr_save_settings'); ?>
                <table class="form-table">
                    <tr>
                        <th><label for="client_id">Client ID</label></th>
                        <td><input type="text" id="client_id" name="client_id" value="<?php echo esc_attr($opt['client_id'] ?? ''); ?>" class="regular-text" required></td>
                    </tr>
                    <tr>
                        <th><label for="client_secret">Client Secret</label></th>
                        <td><input type="password" id="client_secret" name="client_secret" value="<?php echo esc_attr($opt['client_secret'] ?? ''); ?>" class="regular-text" required></td>
                    </tr>
                </table>
                
                <?php submit_button('Save Settings', 'primary', 'save_settings'); ?>
            </form>
            
            <div class="card">
                <h2>Connection Status</h2>
                <?php if ($is_connected && !$is_expired): ?>
                    <p style="color: green;">✅ Connected to Basecamp</p>
                    <p>Token expires: <?php echo date('Y-m-d H:i:s', $token_data['expires_at']); ?></p>
                    <form method="post" action="<?php echo admin_url('admin-post.php'); ?>" style="margin-top: 10px;">
                        <input type="hidden" name="action" value="bcr_disconnect">
                        <?php wp_nonce_field('bcr_disconnect'); ?>
                        <?php submit_button('Disconnect', 'secondary', 'disconnect', false); ?>
                    </form>
                <?php elseif ($is_connected && $is_expired): ?>
                    <p style="color: orange;">⚠️ Token expired</p>
                    <a href="<?php echo $this->get_oauth_url(); ?>" class="button button-primary">Reconnect to Basecamp</a>
                <?php else: ?>
                    <p>❌ Not connected</p>
                    <?php if (!empty($opt['client_id']) && !empty($opt['client_secret'])): ?>
                        <a href="<?php echo $this->get_oauth_url(); ?>" class="button button-primary">Connect to Basecamp</a>
                    <?php else: ?>
                        <p>Please enter your Client ID and Client Secret above first.</p>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
            
            <?php if ($is_connected && !$is_expired): ?>
            <div class="card">
                <h2>Read Card/Todo</h2>
                <form id="test-form">
                    <p>
                        <label for="card-url">Basecamp Card/Todo URL:</label><br>
                        <input type="url" id="card-url" placeholder="https://3.basecamp.com/..." style="width: 400px;" value="https://3.basecamp.com/5798509/buckets/37557560/card_tables/cards/9010883489">
                        <button type="submit" class="button button-primary">Read Card</button>
                    </p>
                </form>
                <div id="result" style="margin-top: 20px;"></div>
            </div>
            
            <div class="card">
                <h2>Post Comment</h2>
                <form id="comment-form">
                    <p>
                        <label for="comment-url">Basecamp Card/Todo URL:</label><br>
                        <input type="url" id="comment-url" placeholder="https://3.basecamp.com/..." style="width: 400px;" value="https://3.basecamp.com/5798509/buckets/37557560/card_tables/cards/9010883489">
                    </p>
                    <p>
                        <label for="comment-text">Comment:</label><br>
                        <textarea id="comment-text" rows="5" style="width: 400px;" placeholder="Enter your comment here..."></textarea>
                    </p>
                    <p>
                        <label>
                            <input type="checkbox" id="use-html"> Use HTML formatting
                        </label>
                    </p>
                    <p>
                        <button type="submit" class="button button-primary">Post Comment</button>
                    </p>
                </form>
                <div id="comment-result" style="margin-top: 20px;"></div>
            </div>
            
            <script>
            const bcr_nonce = '<?php echo wp_create_nonce('bcr_ajax_nonce'); ?>';
            
            document.getElementById('test-form').addEventListener('submit', function(e) {
                e.preventDefault();
                
                const url = document.getElementById('card-url').value;
                const resultDiv = document.getElementById('result');
                
                if (!url) {
                    resultDiv.innerHTML = '<p style="color: red;">Please enter a Basecamp card URL</p>';
                    return;
                }
                
                resultDiv.innerHTML = '<p>Loading...</p>';
                
                fetch(ajaxurl, {
                    method: 'POST',
                    headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                    body: 'action=bcr_read_card&url=' + encodeURIComponent(url) + '&_wpnonce=' + bcr_nonce
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        let html = '<div style="border: 1px solid #ddd; padding: 15px; background: white;">';
                        html += '<h3>' + data.data.title + '</h3>';
                        html += '<p><strong>Type:</strong> ' + data.data.type + '</p>';
                        html += '<p><strong>Created:</strong> ' + new Date(data.data.created_at).toLocaleString() + '</p>';
                        
                        if (data.data.description) {
                            html += '<p><strong>Description:</strong></p><div>' + data.data.description + '</div>';
                        }
                        
                        if (data.data.comments && data.data.comments.length > 0) {
                            html += '<h4>Comments (' + data.data.comments.length + ')</h4>';
                            html += '<div>';
                            
                            data.data.comments.forEach(function(comment) {
                                html += '<div style="margin-bottom: 15px; padding: 10px; background: #f9f9f9; border-left: 3px solid #0073aa;">';
                                if (comment.creator && comment.creator.name) {
                                    html += '<strong>' + comment.creator.name + '</strong>';
                                }
                                if (comment.created_at) {
                                    html += ' <span style="color: #666; font-size: 12px;">• ' + new Date(comment.created_at).toLocaleString() + '</span>';
                                }
                                html += '<div style="margin-top: 8px;">' + comment.content + '</div>';
                                
                                // Display image URLs (not downloaded)
                                if (comment.images && comment.images.length > 0) {
                                    html += '<div style="margin-top: 10px;">';
                                    html += '<strong>📷 Images (' + comment.images.length + '):</strong><br>';
                                    comment.images.forEach(function(imgUrl, idx) {
                                        html += '<a href="' + imgUrl + '" target="_blank">Image ' + (idx + 1) + '</a><br>';
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
                                                html += '<a href="' + attachment.url + '" target="_blank">' + name + '</a><br>';
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
                        
                        html += '</div>';
                        resultDiv.innerHTML = html;
                    } else {
                        resultDiv.innerHTML = '<p style="color: red;">Error: ' + data.data + '</p>';
                    }
                });
            });
            
            // Comment posting functionality
            document.getElementById('comment-form').addEventListener('submit', function(e) {
                e.preventDefault();
                
                const url = document.getElementById('comment-url').value;
                const comment = document.getElementById('comment-text').value;
                const useHtml = document.getElementById('use-html').checked;
                const resultDiv = document.getElementById('comment-result');
                
                if (!url || !comment) {
                    resultDiv.innerHTML = '<p style="color: red;">Please enter both URL and comment</p>';
                    return;
                }
                
                resultDiv.innerHTML = '<p>Posting comment...</p>';
                
                fetch(ajaxurl, {
                    method: 'POST',
                    headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                    body: 'action=bcr_post_comment&url=' + encodeURIComponent(url) + 
                          '&comment=' + encodeURIComponent(comment) +
                          '&use_html=' + (useHtml ? '1' : '0') +
                          '&_wpnonce=' + bcr_nonce
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        resultDiv.innerHTML = '<div class="notice notice-success" style="padding: 10px;"><p>✅ Comment posted successfully!</p></div>';
                        document.getElementById('comment-text').value = '';
                    } else {
                        resultDiv.innerHTML = '<div class="notice notice-error" style="padding: 10px;"><p>❌ Error: ' + (data.data || 'Failed to post comment') + '</p></div>';
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    resultDiv.innerHTML = '<div class="notice notice-error" style="padding: 10px;"><p>❌ Error posting comment</p></div>';
                });
            });
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
            'redirect_uri' => admin_url('options-general.php?page=basecamp-reader'),
        ]);
    }
    
    private function handle_oauth_callback($code) {
        $opt = get_option(self::OPT, []);
        
        $response = wp_remote_post('https://launchpad.37signals.com/authorization/token', [
            'body' => [
                'type' => 'web_server',
                'client_id' => $opt['client_id'],
                'client_secret' => $opt['client_secret'],
                'redirect_uri' => admin_url('options-general.php?page=basecamp-reader'),
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
                return true;
            }
        }
        
        return false;
    }
    
    public function handle_disconnect() {
        check_admin_referer('bcr_disconnect');
        delete_option(self::TOKEN_OPT);
        wp_redirect(admin_url('admin.php?page=basecamp-reader'));
        exit;
    }
    
    public function rest_read_card($request) {
        $url = $request->get_param('url');
        
        if (!$url) {
            return new WP_Error('no_url', 'URL parameter is required', ['status' => 400]);
        }
        
        // Simulate the AJAX call
        $_POST['url'] = $url;
        
        ob_start();
        $this->handle_read_card();
        $output = ob_get_clean();
        
        return json_decode($output, true);
    }
    
    public function handle_post_comment() {
        // Verify nonce
        if (!isset($_POST['_wpnonce']) || !wp_verify_nonce($_POST['_wpnonce'], 'bcr_ajax_nonce')) {
            wp_send_json_error('Security check failed');
        }
        
        // Check user permissions
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Insufficient permissions');
        }
        
        $url = sanitize_text_field($_POST['url'] ?? '');
        $comment_text = wp_kses_post($_POST['comment'] ?? '');
        $use_html = sanitize_text_field($_POST['use_html'] ?? '0');
        
        if (!$url || !$comment_text) {
            wp_send_json_error('URL and comment are required');
        }
        
        // Parse URL to get account, project, and card/todo ID
        $pattern_card = '/basecamp\.com\/(\d+)\/buckets\/(\d+)\/card_tables\/cards\/(\d+)/';
        $pattern_todo = '/basecamp\.com\/(\d+)\/buckets\/(\d+)\/todos\/(\d+)/';
        
        $account_id = '';
        $project_id = '';
        $recording_id = '';
        
        if (preg_match($pattern_card, $url, $matches)) {
            $account_id = $matches[1];
            $project_id = $matches[2];
            $recording_id = $matches[3];
        } elseif (preg_match($pattern_todo, $url, $matches)) {
            $account_id = $matches[1];
            $project_id = $matches[2];
            $recording_id = $matches[3];
        } else {
            wp_send_json_error('Invalid Basecamp URL');
        }
        
        // Get token
        $token_data = get_option(self::TOKEN_OPT, []);
        if (empty($token_data['access_token'])) {
            wp_send_json_error('Not authenticated');
        }
        
        // Build comment API URL
        $comments_url = "https://3.basecampapi.com/{$account_id}/buckets/{$project_id}/recordings/{$recording_id}/comments.json";
        
        // Format comment based on HTML option
        if ($use_html === '1') {
            // Allow HTML formatting
            $comment_content = $comment_text;
        } else {
            // Convert line breaks to <br> for plain text
            $comment_content = nl2br(esc_html($comment_text));
        }
        
        // Post comment
        $response = wp_remote_post($comments_url, [
            'headers' => [
                'Authorization' => 'Bearer ' . $token_data['access_token'],
                'User-Agent' => get_bloginfo('name') . ' (' . get_option('admin_email') . ')',
                'Content-Type' => 'application/json',
            ],
            'body' => json_encode(['content' => $comment_content]),
            'timeout' => 30
        ]);
        
        if (is_wp_error($response)) {
            wp_send_json_error($response->get_error_message());
        }
        
        $code = wp_remote_retrieve_response_code($response);
        if ($code === 201) {
            wp_send_json_success('Comment posted successfully');
        } else {
            $body = json_decode(wp_remote_retrieve_body($response), true);
            $error_msg = $body['error'] ?? 'Failed to post comment';
            wp_send_json_error($error_msg);
        }
    }
}

// Initialize the plugin
$bcr_instance = Basecamp_Cards_Reader_Clean::get_instance();

// Activation/Deactivation hooks
register_activation_hook(__FILE__, [$bcr_instance, 'activate']);
register_deactivation_hook(__FILE__, [$bcr_instance, 'deactivate']);