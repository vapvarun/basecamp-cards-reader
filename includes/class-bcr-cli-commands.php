<?php
/**
 * WP-CLI Commands for Basecamp Cards Reader
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * WP-CLI Commands for Basecamp Cards Reader
 */
class BCR_CLI_Commands {
    
    /**
     * Read a Basecamp card or todo and display its contents
     * 
     * ## OPTIONS
     * 
     * <url>
     * : The Basecamp card or todo URL to read
     * 
     * [--format=<format>]
     * : Output format (table, json, csv, yaml)
     * ---
     * default: table
     * options:
     *   - table
     *   - json
     *   - csv
     *   - yaml
     * ---
     * 
     * [--comments]
     * : Show comments in addition to card details
     * 
     * [--images]
     * : Show image attachment URLs (no download)
     * 
     * ## EXAMPLES
     * 
     *     # Read a card in table format
     *     wp bcr read https://3.basecamp.com/5798509/buckets/37557560/card_tables/cards/9010883489
     * 
     *     # Read a card with comments in JSON format
     *     wp bcr read https://3.basecamp.com/5798509/buckets/37557560/card_tables/cards/9010883489 --comments --format=json
     * 
     *     # Show image URLs from card comments
     *     wp bcr read https://3.basecamp.com/5798509/buckets/37557560/card_tables/cards/9010883489 --comments --images
     * 
     * @when after_wp_load
     */
    public function read($args, $assoc_args) {
        $card_url = $args[0];
        $format = $assoc_args['format'] ?? 'table';
        $show_comments = isset($assoc_args['comments']);
        $show_images = isset($assoc_args['images']);
        
        // Validate URL
        if (!$this->is_valid_basecamp_url($card_url)) {
            WP_CLI::error('Invalid Basecamp card URL provided.');
            return;
        }
        
        // Check if plugin is configured
        $token_data = get_option('bcr_token_data', []);
        if (empty($token_data['access_token'])) {
            WP_CLI::error('Basecamp authentication not configured. Please set up the plugin first.');
            return;
        }
        
        // Parse the URL
        $url_parts = $this->parse_basecamp_url($card_url);
        if (!$url_parts) {
            WP_CLI::error('Could not parse Basecamp URL.');
            return;
        }
        
        WP_CLI::log("Reading Basecamp {$url_parts['type']}...");
        
        // Fetch data
        $card_data = $this->fetch_card_data($url_parts, $token_data);
        if (!$card_data) {
            WP_CLI::error("Failed to fetch {$url_parts['type']} data. Check your authentication.");
            return;
        }
        
        // Prepare output data
        $output_data = [
            'title' => $card_data['card']['title'] ?? 'Unknown',
            'updated_at' => $card_data['card']['updated_at'] ?? '',
            'created_at' => $card_data['card']['created_at'] ?? '',
            'creator' => $card_data['card']['creator']['name'] ?? 'Unknown',
            'assignees' => implode(', ', array_column($card_data['card']['assignees'] ?? [], 'name')),
            'comments_count' => count($card_data['comments'] ?? [])
        ];
        
        if ($show_comments && !empty($card_data['comments'])) {
            $comments = [];
            foreach ($card_data['comments'] as $comment) {
                $comment_data = [
                    'author' => $comment['creator']['name'],
                    'created_at' => $comment['created_at'],
                    'content' => strip_tags($comment['content']),
                    'image_count' => 0,
                ];
                
                if ($show_images) {
                    $images = $this->extract_image_urls($comment['content']);
                    if (!empty($images)) {
                        $comment_data['image_count'] = count($images);
                        $comment_data['images'] = implode(', ', array_column($images, 'url'));
                    }
                } else {
                    // Still show image count even without --images flag
                    $images = $this->extract_image_urls($comment['content']);
                    $comment_data['image_count'] = count($images);
                }
                
                $comments[] = $comment_data;
            }
            $output_data['comments'] = $comments;
        }
        
        // Output based on format
        if ($format === 'json') {
            WP_CLI::log(json_encode($output_data, JSON_PRETTY_PRINT));
        } elseif ($format === 'table') {
            if ($show_comments) {
                $type_label = strtoupper($card_data['type'] ?? 'ITEM');
                WP_CLI::log("\n=== {$type_label} DETAILS ===");
                $details_table = [
                    ['field' => 'Title', 'value' => $output_data['title']],
                    ['field' => 'Creator', 'value' => $output_data['creator']],
                    ['field' => 'Assignees', 'value' => $output_data['assignees']],
                    ['field' => 'Created', 'value' => $output_data['created_at']],
                    ['field' => 'Updated', 'value' => $output_data['updated_at']],
                    ['field' => 'Comments', 'value' => $output_data['comments_count']]
                ];
                WP_CLI\Utils\format_items('table', $details_table, ['field', 'value']);
                
                if (!empty($output_data['comments'])) {
                    WP_CLI::log("\n=== COMMENTS ===");
                    WP_CLI\Utils\format_items('table', $output_data['comments'], ['author', 'created_at', 'content', 'image_count']);
                }
            } else {
                WP_CLI\Utils\format_items('table', [$output_data], array_keys($output_data));
            }
        } else {
            WP_CLI\Utils\format_items($format, [$output_data], array_keys($output_data));
        }
    }
    
    /**
     * Configure Basecamp authentication
     * 
     * ## OPTIONS
     * 
     * [--check]
     * : Check current authentication status
     * 
     * [--reset]
     * : Reset authentication (clear stored tokens)
     * 
     * ## EXAMPLES
     * 
     *     # Check authentication status
     *     wp bcr auth --check
     * 
     *     # Reset authentication
     *     wp bcr auth --reset
     */
    public function auth($args, $assoc_args) {
        if (isset($assoc_args['check'])) {
            $token_data = get_option('bcr_token_data', []);
            if (empty($token_data['access_token'])) {
                WP_CLI::error('No authentication configured. Please set up via admin interface.');
            } else {
                WP_CLI::success('Authentication is configured.');
                WP_CLI::log('Expires at: ' . ($token_data['expires_at'] ?? 'Unknown'));
            }
            return;
        }
        
        if (isset($assoc_args['reset'])) {
            delete_option('bcr_token_data');
            delete_option('bcr_settings');
            WP_CLI::success('Authentication reset successfully.');
            return;
        }
        
        WP_CLI::log('Use --check to verify auth status or --reset to clear tokens.');
        WP_CLI::log('Configure authentication via: wp-admin/options-general.php?page=basecamp-reader');
    }
    
    /**
     * Post a comment to a Basecamp card or todo
     * 
     * ## OPTIONS
     * 
     * <url>
     * : The Basecamp card or todo URL to comment on
     * 
     * <comment>
     * : The comment text to post
     * 
     * ## EXAMPLES
     * 
     *     # Post a comment to a card
     *     wp bcr comment https://3.basecamp.com/123/buckets/456/card_tables/cards/789 "This issue has been fixed"
     * 
     * @when after_wp_load
     */
    public function comment($args, $assoc_args) {
        $url = $args[0];
        $comment_text = $args[1];
        
        // Validate URL
        if (!$this->is_valid_basecamp_url($url)) {
            WP_CLI::error('Invalid Basecamp URL provided.');
            return;
        }
        
        // Check authentication
        $token_data = get_option('bcr_token_data', []);
        if (empty($token_data['access_token'])) {
            WP_CLI::error('Basecamp authentication not configured.');
            return;
        }
        
        // Parse URL
        $url_parts = $this->parse_basecamp_url($url);
        if (!$url_parts) {
            WP_CLI::error('Could not parse Basecamp URL.');
            return;
        }
        
        WP_CLI::log('Posting comment to Basecamp...');
        
        // Post comment
        $result = $this->post_comment($url_parts, $comment_text, $token_data);
        
        if ($result) {
            WP_CLI::success('Comment posted successfully!');
        } else {
            WP_CLI::error('Failed to post comment. Check your permissions.');
        }
    }
    
    /**
     * List recent cards or search cards
     * 
     * ## OPTIONS
     * 
     * [--project=<project_id>]
     * : Project ID to search in
     * 
     * [--limit=<count>]
     * : Number of cards to show
     * ---
     * default: 10
     * ---
     * 
     * ## EXAMPLES
     * 
     *     # List recent cards
     *     wp bcr list --limit=5
     */
    public function list($args, $assoc_args) {
        WP_CLI::log('List functionality would require additional Basecamp API integration.');
        WP_CLI::log('Currently supports reading individual cards via URL.');
    }
    
    /**
     * Extract feedback from a specific card for development purposes
     * 
     * ## OPTIONS
     * 
     * <card_url>
     * : The Basecamp card URL containing feedback
     * 
     * [--plugin=<plugin_name>]
     * : Filter feedback for specific plugin
     * 
     * ## EXAMPLES
     * 
     *     # Extract TutorLMS addon feedback
     *     wp bcr extract-feedback https://3.basecamp.com/.../cards/9010883489 --plugin=tutorlms
     */
    public function extract_feedback($args, $assoc_args) {
        $card_url = $args[0];
        $plugin_filter = $assoc_args['plugin'] ?? '';
        
        // Validate URL
        if (!$this->is_valid_basecamp_url($card_url)) {
            WP_CLI::error('Invalid Basecamp card URL provided.');
            return;
        }
        
        // Check authentication
        $token_data = get_option('bcr_token_data', []);
        if (empty($token_data['access_token'])) {
            WP_CLI::error('Basecamp authentication not configured.');
            return;
        }
        
        WP_CLI::log('Extracting feedback from card...');
        
        // Parse and fetch
        $url_parts = $this->parse_basecamp_url($card_url);
        $card_data = $this->fetch_card_data($url_parts, $token_data);
        
        if (!$card_data) {
            WP_CLI::error('Failed to fetch card data.');
            return;
        }
        
        WP_CLI::log("Card: {$card_data['card']['title']}");
        WP_CLI::log("Comments: " . count($card_data['comments']));
        WP_CLI::log(str_repeat('=', 50));
        
        foreach ($card_data['comments'] as $idx => $comment) {
            $comment_num = $idx + 1;
            $author = $comment['creator']['name'];
            $date = $comment['created_at'];
            $content = strip_tags($comment['content']);
            $content = html_entity_decode($content);
            $content = preg_replace('/\s+/', ' ', $content);
            $content = trim($content);
            
            // Filter by plugin if specified
            if ($plugin_filter && stripos($content, $plugin_filter) === false) {
                continue;
            }
            
            if (strlen($content) > 20) {
                WP_CLI::log("\nComment $comment_num by $author ($date):");
                WP_CLI::log(str_repeat('-', 60));
                WP_CLI::log(wordwrap($content, 80));
            }
        }
        
        WP_CLI::success('Feedback extraction complete!');
    }
    
    /**
     * Helper: Validate Basecamp URL (cards or todos)
     */
    private function is_valid_basecamp_url($url) {
        return strpos($url, 'basecamp.com') !== false && 
               (strpos($url, '/cards/') !== false || strpos($url, '/todos/') !== false);
    }
    
    /**
     * Helper: Parse Basecamp URL (cards or todos)
     */
    private function parse_basecamp_url($url) {
        // Card URL pattern
        if (preg_match('/basecamp\.com\/(\d+)\/buckets\/(\d+)\/card_tables\/cards\/(\d+)/', $url, $matches)) {
            return [
                'type' => 'card',
                'account_id' => $matches[1],
                'project_id' => $matches[2], 
                'recording_id' => $matches[3]
            ];
        }
        
        // Todo URL pattern  
        if (preg_match('/basecamp\.com\/(\d+)\/buckets\/(\d+)\/todos\/(\d+)/', $url, $matches)) {
            return [
                'type' => 'todo',
                'account_id' => $matches[1],
                'project_id' => $matches[2],
                'recording_id' => $matches[3]
            ];
        }
        
        return false;
    }
    
    /**
     * Helper: Fetch data from Basecamp (card or todo)
     */
    private function fetch_card_data($url_parts, $token_data) {
        $headers = [
            'Authorization' => 'Bearer ' . $token_data['access_token'],
            'User-Agent' => get_bloginfo('name') . ' (' . get_option('admin_email') . ')',
        ];
        
        // Build the appropriate API URL based on type
        if ($url_parts['type'] === 'card') {
            $api_url = "https://3.basecampapi.com/{$url_parts['account_id']}/buckets/{$url_parts['project_id']}/card_tables/cards/{$url_parts['recording_id']}.json";
        } else {
            $api_url = "https://3.basecampapi.com/{$url_parts['account_id']}/buckets/{$url_parts['project_id']}/todos/{$url_parts['recording_id']}.json";
        }
        
        // Fetch the main record
        $record_response = wp_remote_get($api_url, ['headers' => $headers]);
        
        if (is_wp_error($record_response) || wp_remote_retrieve_response_code($record_response) !== 200) {
            return false;
        }
        
        $record = json_decode(wp_remote_retrieve_body($record_response), true);
        
        // Fetch comments (same endpoint for both cards and todos)
        $comments_url = "https://3.basecampapi.com/{$url_parts['account_id']}/buckets/{$url_parts['project_id']}/recordings/{$url_parts['recording_id']}/comments.json";
        $comments_response = wp_remote_get($comments_url, ['headers' => $headers]);
        
        $comments = [];
        if (!is_wp_error($comments_response) && wp_remote_retrieve_response_code($comments_response) === 200) {
            $comments = json_decode(wp_remote_retrieve_body($comments_response), true);
        }
        
        return [
            'type' => $url_parts['type'],
            'record' => $record,
            'card' => $record, // Keep backward compatibility
            'comments' => $comments
        ];
    }
    
    /**
     * Helper: Post a comment to Basecamp
     */
    private function post_comment($url_parts, $comment_text, $token_data) {
        $headers = [
            'Authorization' => 'Bearer ' . $token_data['access_token'],
            'User-Agent' => get_bloginfo('name') . ' (' . get_option('admin_email') . ')',
            'Content-Type' => 'application/json',
        ];
        
        // Build the comments API URL
        $comments_url = "https://3.basecampapi.com/{$url_parts['account_id']}/buckets/{$url_parts['project_id']}/recordings/{$url_parts['recording_id']}/comments.json";
        
        $data = [
            'content' => $comment_text
        ];
        
        $response = wp_remote_post($comments_url, [
            'headers' => $headers,
            'body' => json_encode($data),
            'method' => 'POST'
        ]);
        
        if (is_wp_error($response)) {
            return false;
        }
        
        $response_code = wp_remote_retrieve_response_code($response);
        return ($response_code === 201);
    }
    
    /**
     * Helper: Extract image URLs from comment content
     */
    private function extract_image_urls($content) {
        $images = [];
        if (preg_match_all('/<bc-attachment([^>]*)>/i', $content, $matches)) {
            foreach ($matches[1] as $attrs) {
                if (preg_match('/href="([^"]+)"/', $attrs, $href_match)) {
                    $url = html_entity_decode($href_match[1]);
                    $content_type = '';
                    if (preg_match('/content-type="([^"]+)"/', $attrs, $type_match)) {
                        $content_type = $type_match[1];
                    }
                    
                    if (strpos($content_type, 'image/') === 0 || preg_match('/\.(jpg|jpeg|png|gif|webp|avif)$/i', $url)) {
                        $images[] = ['url' => $url, 'type' => $content_type];
                    }
                }
            }
        }
        return $images;
    }
}

// Register CLI commands if WP-CLI is available
if (defined('WP_CLI') && WP_CLI) {
    WP_CLI::add_command('bcr', 'BCR_CLI_Commands');
}