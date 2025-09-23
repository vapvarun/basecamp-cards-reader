<?php
/**
 * Comprehensive Basecamp API Integration Class
 *
 * Full API coverage including projects, card tables, columns, steps, and all endpoints
 * Based on: https://github.com/basecamp/bc3-api
 *
 * @package Basecamp_Cards_Reader
 * @since 2.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class Basecamp_API {

    /**
     * API base URL
     */
    const API_BASE = 'https://3.basecampapi.com';

    /**
     * OAuth base URL
     */
    const OAUTH_BASE = 'https://launchpad.37signals.com';

    /**
     * Available API scopes
     * https://github.com/basecamp/api/blob/master/sections/authentication.md#oauth-2
     */
    const API_SCOPES = [
        'public',           // Read public data
        'read',            // Read all data
        'write',           // Write data
        'delete',          // Delete data
    ];

    /**
     * Access token
     */
    public $access_token;

    /**
     * Account ID
     */
    public $account_id;

    /**
     * User agent for API requests
     */
    private $user_agent;

    /**
     * Constructor
     */
    public function __construct($access_token = null, $account_id = null) {
        $this->access_token = $access_token ?: get_option('bcr_token_data')['access_token'] ?? '';
        $this->account_id = $account_id ?: get_option('basecamp_account_id', '');
        $this->user_agent = get_bloginfo('name') . ' (' . get_option('admin_email') . ')';
    }

    /**
     * Get OAuth authorization URL with all scopes
     */
    public static function get_oauth_url($client_id, $redirect_uri) {
        return self::OAUTH_BASE . '/authorization/new?' . http_build_query([
            'type' => 'web_server',
            'client_id' => $client_id,
            'redirect_uri' => $redirect_uri,
            'scope' => implode(' ', self::API_SCOPES), // Request all scopes
        ]);
    }

    /**
     * Exchange authorization code for access token
     */
    public static function get_access_token($client_id, $client_secret, $redirect_uri, $code) {
        $response = wp_remote_post(self::OAUTH_BASE . '/authorization/token', [
            'body' => [
                'type' => 'web_server',
                'client_id' => $client_id,
                'client_secret' => $client_secret,
                'redirect_uri' => $redirect_uri,
                'code' => $code,
            ],
        ]);

        if (is_wp_error($response)) {
            return false;
        }

        return json_decode(wp_remote_retrieve_body($response), true);
    }

    /**
     * Refresh access token
     */
    public static function refresh_access_token($client_id, $client_secret, $refresh_token) {
        $response = wp_remote_post(self::OAUTH_BASE . '/authorization/token', [
            'body' => [
                'type' => 'refresh',
                'client_id' => $client_id,
                'client_secret' => $client_secret,
                'refresh_token' => $refresh_token,
            ],
        ]);

        if (is_wp_error($response)) {
            return false;
        }

        return json_decode(wp_remote_retrieve_body($response), true);
    }

    /**
     * Make API request
     */
    private function request($method, $endpoint, $data = null, $query = []) {
        $url = self::API_BASE . $endpoint;

        if (!empty($query)) {
            $url .= '?' . http_build_query($query);
        }

        $args = [
            'method' => $method,
            'headers' => [
                'Authorization' => 'Bearer ' . $this->access_token,
                'User-Agent' => $this->user_agent,
            ],
            'timeout' => 30,
        ];

        if ($data !== null) {
            $args['headers']['Content-Type'] = 'application/json';
            $args['body'] = json_encode($data);
        }

        $response = wp_remote_request($url, $args);

        if (is_wp_error($response)) {
            return [
                'error' => true,
                'message' => $response->get_error_message(),
            ];
        }

        $code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);

        return [
            'code' => $code,
            'data' => json_decode($body, true),
            'headers' => wp_remote_retrieve_headers($response),
        ];
    }

    /**
     * Get request
     */
    public function get($endpoint, $query = []) {
        return $this->request('GET', $endpoint, null, $query);
    }

    /**
     * Post request
     */
    public function post($endpoint, $data = []) {
        return $this->request('POST', $endpoint, $data);
    }

    /**
     * Put request
     */
    public function put($endpoint, $data = []) {
        return $this->request('PUT', $endpoint, $data);
    }

    /**
     * Delete request
     */
    public function delete($endpoint) {
        return $this->request('DELETE', $endpoint);
    }

    /* ===========================
     * AUTHORIZATION & ACCOUNTS
     * =========================== */

    /**
     * Get authorization info
     * https://github.com/basecamp/bc3-api/blob/master/sections/authorization.md
     */
    public function get_authorization() {
        return $this->get('/authorization.json');
    }

    /* ===========================
     * PROJECTS
     * =========================== */

    /**
     * Get all projects
     * https://github.com/basecamp/bc3-api/blob/master/sections/projects.md#get-all-projects
     */
    public function get_projects($status = null, $page = 1) {
        $query = ['page' => $page];
        if ($status) {
            $query['status'] = $status; // archived or trashed
        }
        return $this->get("/{$this->account_id}/projects.json", $query);
    }

    /**
     * Get a project
     * https://github.com/basecamp/bc3-api/blob/master/sections/projects.md#get-a-project
     */
    public function get_project($project_id) {
        return $this->get("/{$this->account_id}/projects/{$project_id}.json");
    }

    /**
     * Create a project
     * https://github.com/basecamp/bc3-api/blob/master/sections/projects.md#create-a-project
     */
    public function create_project($name, $description = '') {
        return $this->post("/{$this->account_id}/projects.json", [
            'name' => $name,
            'description' => $description,
        ]);
    }

    /**
     * Update a project
     * https://github.com/basecamp/bc3-api/blob/master/sections/projects.md#update-a-project
     */
    public function update_project($project_id, $name = null, $description = null) {
        $data = [];
        if ($name !== null) $data['name'] = $name;
        if ($description !== null) $data['description'] = $description;

        return $this->put("/{$this->account_id}/projects/{$project_id}.json", $data);
    }

    /**
     * Trash a project
     * https://github.com/basecamp/bc3-api/blob/master/sections/projects.md#trash-a-project
     */
    public function trash_project($project_id) {
        return $this->delete("/{$this->account_id}/projects/{$project_id}.json");
    }

    /* ===========================
     * CARD TABLES
     * =========================== */

    /**
     * Get card tables
     * https://github.com/basecamp/bc3-api/blob/master/sections/card_tables.md
     */
    public function get_card_tables($project_id) {
        return $this->get("/{$this->account_id}/buckets/{$project_id}/card_tables.json");
    }

    /**
     * Get a card table
     */
    public function get_card_table($project_id, $card_table_id) {
        return $this->get("/{$this->account_id}/buckets/{$project_id}/card_tables/{$card_table_id}.json");
    }

    /* ===========================
     * CARD TABLE COLUMNS
     * =========================== */

    /**
     * Get columns in a card table
     * https://github.com/basecamp/bc3-api/blob/master/sections/card_table_columns.md#get-columns-in-a-card-table
     */
    public function get_columns($project_id, $card_table_id, $page = 1) {
        return $this->get("/{$this->account_id}/buckets/{$project_id}/card_tables/{$card_table_id}/columns.json", [
            'page' => $page
        ]);
    }

    /**
     * Get a column
     * https://github.com/basecamp/bc3-api/blob/master/sections/card_table_columns.md#get-a-column
     */
    public function get_column($project_id, $column_id) {
        return $this->get("/{$this->account_id}/buckets/{$project_id}/card_tables/columns/{$column_id}.json");
    }

    /**
     * Create a column
     * https://github.com/basecamp/bc3-api/blob/master/sections/card_table_columns.md#create-a-column
     */
    public function create_column($project_id, $card_table_id, $title, $color = null) {
        $data = ['title' => $title];
        if ($color) {
            $data['color'] = $color; // e.g., "red", "blue", "green"
        }

        return $this->post("/{$this->account_id}/buckets/{$project_id}/card_tables/{$card_table_id}/columns.json", $data);
    }

    /**
     * Update a column
     * https://github.com/basecamp/bc3-api/blob/master/sections/card_table_columns.md#update-a-column
     */
    public function update_column($project_id, $column_id, $title = null, $color = null) {
        $data = [];
        if ($title !== null) $data['title'] = $title;
        if ($color !== null) $data['color'] = $color;

        return $this->put("/{$this->account_id}/buckets/{$project_id}/card_tables/columns/{$column_id}.json", $data);
    }

    /**
     * Move a column
     * https://github.com/basecamp/bc3-api/blob/master/sections/card_table_columns.md#move-a-column
     */
    public function move_column($project_id, $column_id, $position) {
        return $this->put("/{$this->account_id}/buckets/{$project_id}/card_tables/columns/{$column_id}/position.json", [
            'position' => $position
        ]);
    }

    /* ===========================
     * CARD TABLE CARDS
     * =========================== */

    /**
     * Get cards in a column
     * https://github.com/basecamp/bc3-api/blob/master/sections/card_table_cards.md#get-cards-in-a-column
     */
    public function get_cards($project_id, $column_id, $page = 1) {
        return $this->get("/{$this->account_id}/buckets/{$project_id}/card_tables/columns/{$column_id}/cards.json", [
            'page' => $page
        ]);
    }

    /**
     * Get a card
     * https://github.com/basecamp/bc3-api/blob/master/sections/card_table_cards.md#get-a-card
     */
    public function get_card($project_id, $card_id) {
        return $this->get("/{$this->account_id}/buckets/{$project_id}/card_tables/cards/{$card_id}.json");
    }

    /**
     * Create a card
     * https://github.com/basecamp/bc3-api/blob/master/sections/card_table_cards.md#create-a-card
     */
    public function create_card($project_id, $column_id, $title, $content = '', $due_on = null, $assignee_ids = []) {
        $data = [
            'title' => $title,
            'content' => $content,
        ];

        if ($due_on) {
            $data['due_on'] = $due_on; // Format: YYYY-MM-DD
        }

        if (!empty($assignee_ids)) {
            $data['assignee_ids'] = $assignee_ids;
        }

        return $this->post("/{$this->account_id}/buckets/{$project_id}/card_tables/columns/{$column_id}/cards.json", $data);
    }

    /**
     * Update a card
     * https://github.com/basecamp/bc3-api/blob/master/sections/card_table_cards.md#update-a-card
     */
    public function update_card($project_id, $card_id, $data = []) {
        // Allowed fields: title, content, due_on, assignee_ids, completed
        return $this->put("/{$this->account_id}/buckets/{$project_id}/card_tables/cards/{$card_id}.json", $data);
    }

    /**
     * Move a card to another column
     * https://github.com/basecamp/bc3-api/blob/master/sections/card_table_cards.md#move-a-card-to-another-column
     */
    public function move_card($project_id, $card_id, $column_id, $position = null) {
        $data = ['column_id' => $column_id];
        if ($position !== null) {
            $data['position'] = $position;
        }

        return $this->post("/{$this->account_id}/buckets/{$project_id}/card_tables/cards/{$card_id}/moves.json", $data);
    }

    /**
     * Trash a card
     * https://github.com/basecamp/bc3-api/blob/master/sections/card_table_cards.md#trash-a-card
     */
    public function trash_card($project_id, $card_id) {
        return $this->delete("/{$this->account_id}/buckets/{$project_id}/card_tables/cards/{$card_id}.json");
    }

    /* ===========================
     * CARD TABLE STEPS
     * =========================== */

    /**
     * Get steps on a card
     * https://github.com/basecamp/bc3-api/blob/master/sections/card_table_steps.md#get-steps-on-a-card
     */
    public function get_steps($project_id, $card_id) {
        return $this->get("/{$this->account_id}/buckets/{$project_id}/card_tables/cards/{$card_id}/steps.json");
    }

    /**
     * Get a step
     * https://github.com/basecamp/bc3-api/blob/master/sections/card_table_steps.md#get-a-step
     */
    public function get_step($project_id, $step_id) {
        return $this->get("/{$this->account_id}/buckets/{$project_id}/card_tables/steps/{$step_id}.json");
    }

    /**
     * Create a step
     * https://github.com/basecamp/bc3-api/blob/master/sections/card_table_steps.md#create-a-step
     */
    public function create_step($project_id, $card_id, $title) {
        return $this->post("/{$this->account_id}/buckets/{$project_id}/card_tables/cards/{$card_id}/steps.json", [
            'title' => $title
        ]);
    }

    /**
     * Update a step
     * https://github.com/basecamp/bc3-api/blob/master/sections/card_table_steps.md#update-a-step
     */
    public function update_step($project_id, $step_id, $title = null, $completed = null) {
        $data = [];
        if ($title !== null) $data['title'] = $title;
        if ($completed !== null) $data['completed'] = $completed;

        return $this->put("/{$this->account_id}/buckets/{$project_id}/card_tables/steps/{$step_id}.json", $data);
    }

    /**
     * Reorder steps
     * https://github.com/basecamp/bc3-api/blob/master/sections/card_table_steps.md#reorder-steps
     */
    public function reorder_steps($project_id, $card_id, $step_ids) {
        return $this->put("/{$this->account_id}/buckets/{$project_id}/card_tables/cards/{$card_id}/steps/reorder.json", [
            'ids' => $step_ids
        ]);
    }

    /**
     * Complete a step
     */
    public function complete_step($project_id, $step_id) {
        return $this->update_step($project_id, $step_id, null, true);
    }

    /**
     * Uncomplete a step
     */
    public function uncomplete_step($project_id, $step_id) {
        return $this->update_step($project_id, $step_id, null, false);
    }

    /* ===========================
     * COMMENTS
     * =========================== */

    /**
     * Get comments on a recording
     * https://github.com/basecamp/bc3-api/blob/master/sections/comments.md#get-comments
     */
    public function get_comments($project_id, $recording_id, $page = 1) {
        return $this->get("/{$this->account_id}/buckets/{$project_id}/recordings/{$recording_id}/comments.json", [
            'page' => $page
        ]);
    }

    /**
     * Get a comment
     * https://github.com/basecamp/bc3-api/blob/master/sections/comments.md#get-a-comment
     */
    public function get_comment($project_id, $comment_id) {
        return $this->get("/{$this->account_id}/buckets/{$project_id}/comments/{$comment_id}.json");
    }

    /**
     * Create a comment
     * https://github.com/basecamp/bc3-api/blob/master/sections/comments.md#create-a-comment
     */
    public function create_comment($project_id, $recording_id, $content) {
        return $this->post("/{$this->account_id}/buckets/{$project_id}/recordings/{$recording_id}/comments.json", [
            'content' => $content
        ]);
    }

    /**
     * Update a comment
     * https://github.com/basecamp/bc3-api/blob/master/sections/comments.md#update-a-comment
     */
    public function update_comment($project_id, $comment_id, $content) {
        return $this->put("/{$this->account_id}/buckets/{$project_id}/comments/{$comment_id}.json", [
            'content' => $content
        ]);
    }

    /**
     * Trash a comment
     * https://github.com/basecamp/bc3-api/blob/master/sections/comments.md#trash-a-comment
     */
    public function trash_comment($project_id, $comment_id) {
        return $this->delete("/{$this->account_id}/buckets/{$project_id}/comments/{$comment_id}.json");
    }

    /* ===========================
     * TODOS & TODO LISTS
     * =========================== */

    /**
     * Get todo lists
     * https://github.com/basecamp/bc3-api/blob/master/sections/todolists.md
     */
    public function get_todo_lists($project_id, $status = null, $page = 1) {
        $query = ['page' => $page];
        if ($status) {
            $query['status'] = $status; // archived or trashed
        }
        return $this->get("/{$this->account_id}/buckets/{$project_id}/todosets/todos.json", $query);
    }

    /**
     * Get a todo
     * https://github.com/basecamp/bc3-api/blob/master/sections/todos.md#get-a-to-do
     */
    public function get_todo($project_id, $todo_id) {
        return $this->get("/{$this->account_id}/buckets/{$project_id}/todos/{$todo_id}.json");
    }

    /**
     * Create a todo
     * https://github.com/basecamp/bc3-api/blob/master/sections/todos.md#create-a-to-do
     */
    public function create_todo($project_id, $todolist_id, $content, $due_on = null, $assignee_ids = []) {
        $data = ['content' => $content];

        if ($due_on) {
            $data['due_on'] = $due_on;
        }

        if (!empty($assignee_ids)) {
            $data['assignee_ids'] = $assignee_ids;
        }

        return $this->post("/{$this->account_id}/buckets/{$project_id}/todolists/{$todolist_id}/todos.json", $data);
    }

    /**
     * Update a todo
     * https://github.com/basecamp/bc3-api/blob/master/sections/todos.md#update-a-to-do
     */
    public function update_todo($project_id, $todo_id, $data = []) {
        return $this->put("/{$this->account_id}/buckets/{$project_id}/todos/{$todo_id}.json", $data);
    }

    /**
     * Complete a todo
     * https://github.com/basecamp/bc3-api/blob/master/sections/todos.md#complete-a-to-do
     */
    public function complete_todo($project_id, $todo_id) {
        return $this->post("/{$this->account_id}/buckets/{$project_id}/todos/{$todo_id}/completion.json");
    }

    /**
     * Uncomplete a todo
     * https://github.com/basecamp/bc3-api/blob/master/sections/todos.md#uncomplete-a-to-do
     */
    public function uncomplete_todo($project_id, $todo_id) {
        return $this->delete("/{$this->account_id}/buckets/{$project_id}/todos/{$todo_id}/completion.json");
    }

    /* ===========================
     * PEOPLE
     * =========================== */

    /**
     * Get all people
     * https://github.com/basecamp/bc3-api/blob/master/sections/people.md#get-all-people
     */
    public function get_people($page = 1) {
        return $this->get("/{$this->account_id}/people.json", ['page' => $page]);
    }

    /**
     * Get people on a project
     * https://github.com/basecamp/bc3-api/blob/master/sections/people.md#get-people-on-a-project
     */
    public function get_project_people($project_id, $page = 1) {
        return $this->get("/{$this->account_id}/projects/{$project_id}/people.json", ['page' => $page]);
    }

    /**
     * Get pingable people
     * https://github.com/basecamp/bc3-api/blob/master/sections/people.md#get-pingable-people
     */
    public function get_pingable_people($page = 1) {
        return $this->get("/{$this->account_id}/circles/people.json", ['page' => $page]);
    }

    /**
     * Get person by ID
     * https://github.com/basecamp/bc3-api/blob/master/sections/people.md#get-person
     */
    public function get_person($person_id) {
        return $this->get("/{$this->account_id}/people/{$person_id}.json");
    }

    /**
     * Get my personal info
     * https://github.com/basecamp/bc3-api/blob/master/sections/people.md#get-my-personal-info
     */
    public function get_my_info() {
        return $this->get("/{$this->account_id}/my/profile.json");
    }

    /* ===========================
     * EVENTS (Activity)
     * =========================== */

    /**
     * Get events
     * https://github.com/basecamp/bc3-api/blob/master/sections/events.md
     */
    public function get_events($page = 1, $since = null) {
        $query = ['page' => $page];
        if ($since) {
            $query['since'] = $since; // ISO 8601 datetime
        }
        return $this->get("/{$this->account_id}/events.json", $query);
    }

    /**
     * Get project events
     */
    public function get_project_events($project_id, $page = 1, $since = null) {
        $query = ['page' => $page];
        if ($since) {
            $query['since'] = $since;
        }
        return $this->get("/{$this->account_id}/buckets/{$project_id}/events.json", $query);
    }

    /**
     * Get recording events
     */
    public function get_recording_events($project_id, $recording_id, $page = 1) {
        return $this->get("/{$this->account_id}/buckets/{$project_id}/recordings/{$recording_id}/events.json", [
            'page' => $page
        ]);
    }

    /* ===========================
     * UPLOADS & ATTACHMENTS
     * =========================== */

    /**
     * Create an attachment
     * https://github.com/basecamp/bc3-api/blob/master/sections/attachments.md#create-an-attachment
     */
    public function create_attachment($name, $byte_size, $content_type, $base64_content = null) {
        $data = [
            'name' => $name,
            'byte_size' => $byte_size,
            'content_type' => $content_type,
        ];

        if ($base64_content) {
            $data['content'] = $base64_content;
        }

        return $this->post('/attachments.json', $data);
    }

    /**
     * Get uploads in a project
     * https://github.com/basecamp/bc3-api/blob/master/sections/uploads.md
     */
    public function get_uploads($project_id, $page = 1) {
        return $this->get("/{$this->account_id}/buckets/{$project_id}/vaults.json", ['page' => $page]);
    }

    /**
     * Get an upload
     */
    public function get_upload($project_id, $upload_id) {
        return $this->get("/{$this->account_id}/buckets/{$project_id}/uploads/{$upload_id}.json");
    }

    /* ===========================
     * CAMPFIRES (CHAT)
     * =========================== */

    /**
     * Get campfire lines
     * https://github.com/basecamp/bc3-api/blob/master/sections/campfire_lines.md
     */
    public function get_campfire_lines($project_id, $campfire_id, $page = 1) {
        return $this->get("/{$this->account_id}/buckets/{$project_id}/chats/{$campfire_id}/lines.json", [
            'page' => $page
        ]);
    }

    /**
     * Get a campfire line
     */
    public function get_campfire_line($project_id, $line_id) {
        return $this->get("/{$this->account_id}/buckets/{$project_id}/chats/lines/{$line_id}.json");
    }

    /**
     * Create a campfire line
     */
    public function create_campfire_line($project_id, $campfire_id, $content) {
        return $this->post("/{$this->account_id}/buckets/{$project_id}/chats/{$campfire_id}/lines.json", [
            'content' => $content
        ]);
    }

    /* ===========================
     * HELPER METHODS
     * =========================== */

    /**
     * Set account ID
     */
    public function set_account_id($account_id) {
        $this->account_id = $account_id;
        return $this;
    }

    /**
     * Get account ID from authorization
     */
    public function get_account_id() {
        if (!$this->account_id) {
            $auth = $this->get_authorization();
            if (isset($auth['data']['accounts'][0]['id'])) {
                $this->account_id = $auth['data']['accounts'][0]['id'];
            }
        }
        return $this->account_id;
    }

    /**
     * Parse Basecamp URL
     */
    public static function parse_url($url) {
        $patterns = [
            // Card URL
            'card' => '/basecamp\.com\/(\d+)\/buckets\/(\d+)\/card_tables\/cards\/(\d+)/',
            // Todo URL
            'todo' => '/basecamp\.com\/(\d+)\/buckets\/(\d+)\/todos\/(\d+)/',
            // Project URL
            'project' => '/basecamp\.com\/(\d+)\/projects\/(\d+)/',
            // Column URL
            'column' => '/basecamp\.com\/(\d+)\/buckets\/(\d+)\/card_tables\/columns\/(\d+)/',
        ];

        foreach ($patterns as $type => $pattern) {
            if (preg_match($pattern, $url, $matches)) {
                return [
                    'type' => $type,
                    'account_id' => $matches[1],
                    'project_id' => $matches[2],
                    'recording_id' => $matches[3] ?? null,
                ];
            }
        }

        return false;
    }

    /**
     * Check if token is expired
     */
    public function is_token_expired() {
        $token_data = get_option('bcr_token_data', []);
        return !empty($token_data['expires_at']) && time() >= $token_data['expires_at'];
    }

    /**
     * Auto-refresh token if expired
     */
    public function ensure_fresh_token() {
        if ($this->is_token_expired()) {
            $settings = get_option('bcr_settings', []);
            $token_data = get_option('bcr_token_data', []);

            if (!empty($token_data['refresh_token']) && !empty($settings['client_id'])) {
                $new_token = self::refresh_access_token(
                    $settings['client_id'],
                    $settings['client_secret'],
                    $token_data['refresh_token']
                );

                if ($new_token && isset($new_token['access_token'])) {
                    update_option('bcr_token_data', [
                        'access_token' => $new_token['access_token'],
                        'refresh_token' => $new_token['refresh_token'] ?? $token_data['refresh_token'],
                        'expires_at' => time() + ($new_token['expires_in'] ?? 1209600),
                    ]);

                    $this->access_token = $new_token['access_token'];
                    return true;
                }
            }

            return false;
        }

        return true;
    }
}