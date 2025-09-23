<?php
/**
 * Basecamp Automation Tool
 * Professional automation system for Basecamp project management
 *
 * @package WBComDesigns\BasecampAutomation
 * @version 3.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Main Basecamp Automation Class
 */
class Basecamp_Automation {

    /**
     * API Configuration
     */
    private $config = [
        'account_id' => '',
        'api_base' => 'https://3.basecampapi.com',
        'timeout' => 30,
        'cache_expiry' => 300 // 5 minutes
    ];

    /**
     * Authentication token
     */
    private $token;

    /**
     * Cache for API responses
     */
    private $cache = [];

    /**
     * Logger instance
     */
    private $logger;

    /**
     * Project mappings for quick access (populated dynamically from database)
     */
    private $projects = [];

    /**
     * Constructor
     */
    public function __construct() {
        $this->logger = new Basecamp_Logger();
        $this->initialize_token();
        // Get account ID from settings (no hardcoded default)
        $this->config['account_id'] = get_option('basecamp_account_id', '');

        if (empty($this->config['account_id'])) {
            $this->logger->warning('No Basecamp account ID configured. Run "wp bcr settings --account-id=YOUR_ID" to set it.');
        }

        $this->logger->info('Basecamp Automation initialized');
    }

    /**
     * Initialize authentication token
     */
    private function initialize_token() {
        $token_data = get_option('bcr_token_data', []);
        $this->token = $token_data['access_token'] ?? '';

        if (empty($this->token)) {
            WP_CLI::warning("⚠️  No authentication token found. Run 'wp bc auth setup' first.");
            return false;
        }

        // Check token expiry
        if (!empty($token_data['expires_at']) && time() >= $token_data['expires_at']) {
            WP_CLI::warning("⚠️  Token expired. Attempting refresh...");
            $this->refresh_token();
        }

        return true;
    }

    /**
     * Refresh authentication token
     */
    private function refresh_token() {
        $settings = get_option('bcr_settings', []);
        $token_data = get_option('bcr_token_data', []);

        if (empty($token_data['refresh_token']) || empty($settings['client_id'])) {
            WP_CLI::error("Cannot refresh token. Please re-authenticate.");
            return false;
        }

        $response = wp_remote_post('https://launchpad.37signals.com/authorization/token', [
            'body' => [
                'type' => 'refresh',
                'client_id' => $settings['client_id'],
                'client_secret' => $settings['client_secret'],
                'refresh_token' => $token_data['refresh_token']
            ]
        ]);

        if (!is_wp_error($response)) {
            $body = json_decode(wp_remote_retrieve_body($response), true);
            if (!empty($body['access_token'])) {
                update_option('bcr_token_data', [
                    'access_token' => $body['access_token'],
                    'refresh_token' => $body['refresh_token'] ?? $token_data['refresh_token'],
                    'expires_at' => time() + ($body['expires_in'] ?? 1209600)
                ]);
                $this->token = $body['access_token'];
                WP_CLI::success("✅ Token refreshed successfully");
                return true;
            }
        }

        WP_CLI::error("Failed to refresh token");
        return false;
    }

    /**
     * Make API request with caching and logging
     */
    private function api_request($endpoint, $method = 'GET', $data = null) {
        $start_time = microtime(true);

        // Check cache for GET requests
        if ($method === 'GET' && isset($this->cache[$endpoint])) {
            $cached = $this->cache[$endpoint];
            if ($cached['expires'] > time()) {
                $this->logger->debug('Cache hit for endpoint', ['endpoint' => $endpoint]);
                return $cached['data'];
            }
        }

        $url = $this->config['api_base'] . $endpoint;

        $args = [
            'method' => $method,
            'headers' => [
                'Authorization' => 'Bearer ' . $this->token,
                'User-Agent' => 'WBComDesigns Basecamp Automation/3.0'
            ],
            'timeout' => $this->config['timeout']
        ];

        if ($data !== null) {
            $args['headers']['Content-Type'] = 'application/json';
            $args['body'] = json_encode($data);
        }

        $response = wp_remote_request($url, $args);
        $end_time = microtime(true);
        $response_time = $end_time - $start_time;

        if (is_wp_error($response)) {
            $this->logger->log_api_call($endpoint, $method, $response_time, 0, false);
            $this->logger->error('API request failed', [
                'endpoint' => $endpoint,
                'method' => $method,
                'error' => $response->get_error_message()
            ]);
            return false;
        }

        $code = wp_remote_retrieve_response_code($response);
        $body = json_decode(wp_remote_retrieve_body($response), true);
        $success = $code === 200 || $code === 201;

        // Log API call
        $this->logger->log_api_call($endpoint, $method, $response_time, $code, $success);

        // Cache successful GET requests
        if ($method === 'GET' && $code === 200) {
            $this->cache[$endpoint] = [
                'data' => $body,
                'expires' => time() + $this->config['cache_expiry']
            ];
        }

        if (!$success) {
            $this->logger->warning('API request returned error status', [
                'endpoint' => $endpoint,
                'method' => $method,
                'status_code' => $code,
                'response_time' => $response_time
            ]);
        }

        return $success ? $body : false;
    }

    /**
     * Get project by name or ID
     */
    public function get_project($identifier) {
        // Check if it's a known project name
        $project_id = $this->projects[strtolower($identifier)] ?? $identifier;

        if (!is_numeric($project_id)) {
            WP_CLI::error("Unknown project: $identifier");
            return false;
        }

        return $this->api_request("/{$this->config['account_id']}/projects/{$project_id}.json");
    }

    /**
     * Discover all columns in a project's card table
     */
    public function discover_columns($project_id) {
        $project = $this->get_project($project_id);
        if (!$project) return [];

        // Find card table
        $card_table_id = null;
        foreach ($project['dock'] as $tool) {
            if (in_array($tool['name'], ['card_table', 'kanban_board'])) {
                $card_table_id = $tool['id'];
                break;
            }
        }

        if (!$card_table_id) return [];

        $columns = [];

        // HYBRID APPROACH: Scan reasonable range around card table ID
        // Most columns are within 30 IDs of the card table
        $base = intval($card_table_id);

        for ($offset = 0; $offset <= 30; $offset++) {
            $column_id = $base + $offset;
            $col = $this->api_request("/{$this->config['account_id']}/buckets/{$project_id}/card_tables/columns/{$column_id}.json");

            if ($col && isset($col['title'])) {
                $columns[$column_id] = $col;
            }
        }

        // Sort by position
        uasort($columns, function($a, $b) {
            return ($a['position'] ?? 0) - ($b['position'] ?? 0);
        });

        return $columns;
    }

    /**
     * Get all cards from a project
     */
    public function get_all_cards($project_id) {
        $columns = $this->discover_columns($project_id);
        $all_cards = [];

        foreach ($columns as $col_id => $column) {
            $cards = $this->api_request("/{$this->config['account_id']}/buckets/{$project_id}/card_tables/lists/{$col_id}/cards.json");

            if ($cards) {
                foreach ($cards as $card) {
                    $card['column_name'] = $column['title'];
                    $card['column_id'] = $col_id;
                    $all_cards[] = $card;
                }
            }
        }

        return $all_cards;
    }

    /**
     * Analyze project health and statistics
     */
    public function analyze_project($project_id) {
        $cards = $this->get_all_cards($project_id);

        $analysis = [
            'total_cards' => count($cards),
            'by_column' => [],
            'by_assignee' => [],
            'overdue' => [],
            'due_soon' => [],
            'unassigned' => [],
            'completed' => 0,
            'active' => 0,
            'health_score' => 100
        ];

        $today = new DateTime();
        $week_from_now = (clone $today)->modify('+7 days');

        foreach ($cards as $card) {
            // By column
            $col = $card['column_name'];
            $analysis['by_column'][$col] = ($analysis['by_column'][$col] ?? 0) + 1;

            // By status
            if ($card['completed'] ?? false) {
                $analysis['completed']++;
            } else {
                $analysis['active']++;

                // Check due dates
                if (!empty($card['due_on'])) {
                    $due = new DateTime($card['due_on']);
                    if ($due < $today) {
                        $analysis['overdue'][] = $card;
                        $analysis['health_score'] -= 5;
                    } elseif ($due <= $week_from_now) {
                        $analysis['due_soon'][] = $card;
                    }
                }

                // Check assignments
                if (empty($card['assignees'])) {
                    $analysis['unassigned'][] = $card;
                    $analysis['health_score'] -= 2;
                } else {
                    foreach ($card['assignees'] as $assignee) {
                        $name = $assignee['name'];
                        $analysis['by_assignee'][$name] = ($analysis['by_assignee'][$name] ?? 0) + 1;
                    }
                }
            }
        }

        // Calculate health score
        if ($analysis['total_cards'] > 0) {
            $completion_rate = ($analysis['completed'] / $analysis['total_cards']) * 100;
            $analysis['completion_rate'] = round($completion_rate, 1);

            if ($completion_rate < 30) {
                $analysis['health_score'] -= 10;
            }
        }

        $analysis['health_score'] = max(0, min(100, $analysis['health_score']));

        // Log project analysis
        $project_name = $this->get_project($project_id)['name'] ?? 'Unknown';
        $this->logger->log_project_analysis($project_id, $project_name, $analysis);

        return $analysis;
    }

    /**
     * Create a new card
     */
    public function create_card($project_id, $column_id, $title, $content = '', $options = []) {
        $data = [
            'title' => $title,
            'content' => $content
        ];

        if (!empty($options['due_on'])) {
            $data['due_on'] = $options['due_on'];
        }

        if (!empty($options['assignee_ids'])) {
            $data['assignee_ids'] = $options['assignee_ids'];
        }

        return $this->api_request(
            "/{$this->config['account_id']}/buckets/{$project_id}/card_tables/lists/{$column_id}/cards.json",
            'POST',
            $data
        );
    }

    /**
     * Move card to different column
     */
    public function move_card($project_id, $card_id, $to_column_id, $position = null) {
        $data = ['column_id' => $to_column_id];

        if ($position !== null) {
            $data['position'] = $position;
        }

        return $this->api_request(
            "/{$this->config['account_id']}/buckets/{$project_id}/card_tables/cards/{$card_id}/moves.json",
            'PUT',
            $data
        );
    }

    /**
     * Add comment to a card
     */
    public function add_comment($project_id, $card_id, $comment) {
        return $this->api_request(
            "/{$this->config['account_id']}/buckets/{$project_id}/recordings/{$card_id}/comments.json",
            'POST',
            ['content' => $comment]
        );
    }

    /**
     * Generate project report
     */
    public function generate_report($project_id) {
        $project = $this->get_project($project_id);
        $analysis = $this->analyze_project($project_id);

        $report = [
            'project' => [
                'name' => $project['name'],
                'description' => $project['description'] ?? '',
                'id' => $project_id
            ],
            'summary' => [
                'total_cards' => $analysis['total_cards'],
                'active' => $analysis['active'],
                'completed' => $analysis['completed'],
                'completion_rate' => $analysis['completion_rate'] ?? 0,
                'health_score' => $analysis['health_score']
            ],
            'issues' => [
                'overdue_count' => count($analysis['overdue']),
                'overdue_cards' => $analysis['overdue'],
                'unassigned_count' => count($analysis['unassigned']),
                'unassigned_cards' => $analysis['unassigned']
            ],
            'distribution' => [
                'by_column' => $analysis['by_column'],
                'by_assignee' => $analysis['by_assignee']
            ],
            'upcoming' => [
                'due_soon_count' => count($analysis['due_soon']),
                'due_soon_cards' => $analysis['due_soon']
            ],
            'generated_at' => date('Y-m-d H:i:s')
        ];

        return $report;
    }

    /**
     * Batch operations
     */
    public function batch_move_cards($project_id, $card_ids, $to_column_id) {
        $results = [];

        foreach ($card_ids as $card_id) {
            $result = $this->move_card($project_id, $card_id, $to_column_id);
            $results[$card_id] = $result !== false;
        }

        return $results;
    }

    /**
     * Search cards
     */
    public function search_cards($project_id, $query) {
        $all_cards = $this->get_all_cards($project_id);
        $results = [];
        $query_lower = strtolower($query);

        foreach ($all_cards as $card) {
            if (strpos(strtolower($card['title']), $query_lower) !== false ||
                strpos(strtolower($card['content'] ?? ''), $query_lower) !== false) {
                $results[] = $card;
            }
        }

        return $results;
    }

    /**
     * Get column ID by name
     */
    public function find_column($project_id, $column_name) {
        $columns = $this->discover_columns($project_id);
        $name_lower = strtolower($column_name);

        foreach ($columns as $id => $column) {
            if (strpos(strtolower($column['title']), $name_lower) !== false) {
                return $id;
            }
        }

        return null;
    }

    /**
     * Resolve project using advanced fuzzy matching
     */
    public function resolve_project($name) {
        // If numeric, assume it's already an ID
        if (is_numeric($name)) {
            return $name;
        }

        // Use the professional fuzzy matching from Basecamp_Pro
        $pro = new Basecamp_Pro();
        $matches = $pro->find_project($name);

        if (!empty($matches)) {
            $best_match = $matches[0];

            // Log the matching result
            if (isset($this->logger)) {
                $this->logger->info('Project resolved via fuzzy matching', [
                    'search_term' => $name,
                    'found_project' => $best_match['project']['name'],
                    'match_score' => $best_match['score'],
                    'match_type' => $best_match['match_type']
                ]);
            }

            return $best_match['project']['id'];
        }

        // Fallback to legacy mappings for compatibility
        $name_lower = strtolower(str_replace([' ', '-', '_'], '', $name));
        foreach ($this->projects as $key => $id) {
            if (strpos(str_replace([' ', '-', '_'], '', $key), $name_lower) !== false ||
                strpos($name_lower, str_replace([' ', '-', '_'], '', $key)) !== false) {

                if (isset($this->logger)) {
                    $this->logger->info('Project resolved via legacy mapping', [
                        'search_term' => $name,
                        'mapped_key' => $key,
                        'project_id' => $id
                    ]);
                }
                return $id;
            }
        }

        if (isset($this->logger)) {
            $this->logger->warning('Project not found', ['search_term' => $name]);
        }

        return null;
    }

    /**
     * Automated workflow: Auto-assign cards based on rules
     */
    public function auto_assign_cards($project_id, $rules = []) {
        $cards = $this->get_all_cards($project_id);
        $assignments = [];

        $default_rules = [
            'bug' => ['assignee_pattern' => 'developer', 'priority' => 'high'],
            'feature' => ['assignee_pattern' => 'lead', 'priority' => 'medium'],
            'test' => ['assignee_pattern' => 'qa', 'priority' => 'medium'],
            'urgent' => ['assignee_pattern' => 'lead', 'priority' => 'critical']
        ];

        $rules = array_merge($default_rules, $rules);

        foreach ($cards as $card) {
            if (!empty($card['assignees'])) continue; // Skip already assigned

            $title_lower = strtolower($card['title']);
            $content_lower = strtolower($card['content'] ?? '');

            foreach ($rules as $keyword => $rule) {
                if (strpos($title_lower, $keyword) !== false ||
                    strpos($content_lower, $keyword) !== false) {

                    // Find assignee based on pattern
                    $assignee_id = $this->find_assignee_by_pattern($project_id, $rule['assignee_pattern']);

                    if ($assignee_id) {
                        $update_result = $this->update_card($project_id, $card['id'], [
                            'assignee_ids' => [$assignee_id]
                        ]);

                        $assignments[] = [
                            'card_id' => $card['id'],
                            'card_title' => $card['title'],
                            'assigned_to' => $assignee_id,
                            'rule_matched' => $keyword,
                            'success' => $update_result !== false
                        ];
                    }
                    break; // First match wins
                }
            }
        }

        return $assignments;
    }

    /**
     * Update card properties
     */
    public function update_card($project_id, $card_id, $data) {
        return $this->api_request(
            "/{$this->config['account_id']}/buckets/{$project_id}/card_tables/cards/{$card_id}.json",
            'PUT',
            $data
        );
    }

    /**
     * Find assignee by pattern (developer, qa, lead, etc.)
     */
    private function find_assignee_by_pattern($project_id, $pattern) {
        $people = $this->get_project_people($project_id);
        $pattern_lower = strtolower($pattern);

        foreach ($people as $person) {
            $title_lower = strtolower($person['title'] ?? '');
            $name_lower = strtolower($person['name'] ?? '');

            if (strpos($title_lower, $pattern_lower) !== false ||
                strpos($name_lower, $pattern_lower) !== false) {
                return $person['id'];
            }
        }

        return null;
    }

    /**
     * Get people associated with a project
     */
    public function get_project_people($project_id) {
        return $this->api_request("/{$this->config['account_id']}/projects/{$project_id}/people.json") ?: [];
    }

    /**
     * Automated workflow: Move cards based on completion status
     */
    public function auto_move_completed_cards($project_id) {
        $cards = $this->get_all_cards($project_id);
        $columns = $this->discover_columns($project_id);

        // Find "Done" or "Completed" column
        $done_column_id = null;
        foreach ($columns as $id => $column) {
            if (preg_match('/\b(done|completed|finished|closed)\b/i', $column['title'])) {
                $done_column_id = $id;
                break;
            }
        }

        if (!$done_column_id) {
            return ['error' => 'No completion column found'];
        }

        $moved_cards = [];
        foreach ($cards as $card) {
            if (($card['completed'] ?? false) && $card['column_id'] != $done_column_id) {
                $result = $this->move_card($project_id, $card['id'], $done_column_id);
                $moved_cards[] = [
                    'card_id' => $card['id'],
                    'card_title' => $card['title'],
                    'from_column' => $card['column_name'],
                    'to_column' => $columns[$done_column_id]['title'],
                    'success' => $result !== false
                ];
            }
        }

        return $moved_cards;
    }

    /**
     * Automated workflow: Create recurring tasks
     */
    public function create_recurring_task($project_id, $template, $schedule) {
        $column_id = $this->find_column($project_id, $template['column'] ?? 'todo');

        if (!$column_id) {
            return ['error' => 'Target column not found'];
        }

        // Calculate next due date based on schedule
        $next_due = $this->calculate_next_due_date($schedule);

        $card_data = [
            'title' => $template['title'] . ' - ' . date('M Y'),
            'content' => $template['content'] ?? '',
            'due_on' => $next_due
        ];

        if (!empty($template['assignee_pattern'])) {
            $assignee_id = $this->find_assignee_by_pattern($project_id, $template['assignee_pattern']);
            if ($assignee_id) {
                $card_data['assignee_ids'] = [$assignee_id];
            }
        }

        $result = $this->create_card($project_id, $column_id,
            $card_data['title'],
            $card_data['content'],
            $card_data
        );

        return [
            'success' => $result !== false,
            'card_id' => $result['id'] ?? null,
            'title' => $card_data['title'],
            'due_date' => $next_due,
            'schedule' => $schedule
        ];
    }

    /**
     * Calculate next due date based on schedule
     */
    private function calculate_next_due_date($schedule) {
        $now = new DateTime();

        switch (strtolower($schedule)) {
            case 'daily':
                return $now->modify('+1 day')->format('Y-m-d');
            case 'weekly':
                return $now->modify('+1 week')->format('Y-m-d');
            case 'monthly':
                return $now->modify('+1 month')->format('Y-m-d');
            case 'quarterly':
                return $now->modify('+3 months')->format('Y-m-d');
            default:
                return $now->modify('+1 week')->format('Y-m-d');
        }
    }

    /**
     * Automated workflow: Escalate overdue tasks
     */
    public function escalate_overdue_tasks($project_id, $escalation_rules = []) {
        $analysis = $this->analyze_project($project_id);
        $escalations = [];

        $default_rules = [
            'overdue_days' => 3,
            'escalate_to' => 'lead',
            'add_urgent_tag' => true,
            'notify_comment' => true
        ];

        $rules = array_merge($default_rules, $escalation_rules);

        foreach ($analysis['overdue'] as $card) {
            $due_date = new DateTime($card['due_on']);
            $days_overdue = $due_date->diff(new DateTime())->days;

            if ($days_overdue >= $rules['overdue_days']) {
                $escalation_actions = [];

                // Add urgent tag to title if not already present
                if ($rules['add_urgent_tag'] && !preg_match('/\[URGENT\]/i', $card['title'])) {
                    $new_title = '[URGENT] ' . $card['title'];
                    $this->update_card($project_id, $card['id'], ['title' => $new_title]);
                    $escalation_actions[] = 'Added urgent tag';
                }

                // Escalate to lead
                if ($rules['escalate_to']) {
                    $lead_id = $this->find_assignee_by_pattern($project_id, $rules['escalate_to']);
                    if ($lead_id) {
                        $current_assignees = array_column($card['assignees'] ?? [], 'id');
                        if (!in_array($lead_id, $current_assignees)) {
                            $current_assignees[] = $lead_id;
                            $this->update_card($project_id, $card['id'], [
                                'assignee_ids' => $current_assignees
                            ]);
                            $escalation_actions[] = 'Escalated to lead';
                        }
                    }
                }

                // Add notification comment
                if ($rules['notify_comment']) {
                    $comment = "🚨 This task is {$days_overdue} days overdue and has been escalated. Please prioritize.";
                    $this->add_comment($project_id, $card['id'], $comment);
                    $escalation_actions[] = 'Added notification comment';
                }

                $escalations[] = [
                    'card_id' => $card['id'],
                    'card_title' => $card['title'],
                    'days_overdue' => $days_overdue,
                    'actions_taken' => $escalation_actions
                ];
            }
        }

        return $escalations;
    }

    /**
     * Automated workflow: Balance workload across team members
     */
    public function balance_workload($project_id, $max_cards_per_person = 5) {
        $analysis = $this->analyze_project($project_id);
        $rebalances = [];

        // Find overloaded and underloaded team members
        $overloaded = [];
        $underloaded = [];

        foreach ($analysis['by_assignee'] as $assignee => $count) {
            if ($count > $max_cards_per_person) {
                $overloaded[$assignee] = $count;
            } elseif ($count < $max_cards_per_person - 2) {
                $underloaded[$assignee] = $count;
            }
        }

        if (empty($overloaded) || empty($underloaded)) {
            return ['message' => 'Workload is already balanced'];
        }

        // Get all cards to find reassignment candidates
        $cards = $this->get_all_cards($project_id);
        $people = $this->get_project_people($project_id);
        $people_map = [];

        foreach ($people as $person) {
            $people_map[$person['name']] = $person['id'];
        }

        foreach ($overloaded as $overloaded_person => $count) {
            $cards_to_move = $count - $max_cards_per_person;
            $moved_count = 0;

            foreach ($cards as $card) {
                if ($moved_count >= $cards_to_move) break;

                // Check if this card is assigned to the overloaded person
                $assigned_to_overloaded = false;
                foreach ($card['assignees'] ?? [] as $assignee) {
                    if ($assignee['name'] === $overloaded_person) {
                        $assigned_to_overloaded = true;
                        break;
                    }
                }

                if ($assigned_to_overloaded && !($card['completed'] ?? false)) {
                    // Find an underloaded person to reassign to
                    foreach ($underloaded as $underloaded_person => $their_count) {
                        if ($their_count < $max_cards_per_person - 1) {
                            $new_assignee_id = $people_map[$underloaded_person] ?? null;

                            if ($new_assignee_id) {
                                $result = $this->update_card($project_id, $card['id'], [
                                    'assignee_ids' => [$new_assignee_id]
                                ]);

                                if ($result !== false) {
                                    $rebalances[] = [
                                        'card_id' => $card['id'],
                                        'card_title' => $card['title'],
                                        'from' => $overloaded_person,
                                        'to' => $underloaded_person,
                                        'success' => true
                                    ];

                                    $underloaded[$underloaded_person]++;
                                    $moved_count++;
                                    break;
                                }
                            }
                        }
                    }
                }
            }
        }

        return $rebalances;
    }

    /**
     * Automated workflow: Archive completed projects
     */
    public function auto_archive_completed_projects($completion_threshold = 95) {
        $archived_projects = [];

        foreach ($this->projects as $name => $id) {
            $analysis = $this->analyze_project($id);

            if (($analysis['completion_rate'] ?? 0) >= $completion_threshold) {
                // Archive the project
                $result = $this->api_request(
                    "/{$this->config['account_id']}/projects/{$id}.json",
                    'PUT',
                    ['status' => 'archived']
                );

                $archived_projects[] = [
                    'project_name' => $name,
                    'project_id' => $id,
                    'completion_rate' => $analysis['completion_rate'],
                    'total_cards' => $analysis['total_cards'],
                    'archived' => $result !== false
                ];
            }
        }

        return $archived_projects;
    }

    /**
     * Run all automated workflows
     */
    public function run_automation_suite($project_id, $options = []) {
        $start_time = microtime(true);

        $this->logger->info('Starting automation suite', [
            'project_id' => $project_id,
            'options' => $options
        ]);

        $results = [
            'project_id' => $project_id,
            'timestamp' => date('Y-m-d H:i:s'),
            'workflows' => []
        ];

        // Auto-assign unassigned cards
        if ($options['auto_assign'] ?? true) {
            $workflow_start = microtime(true);
            $results['workflows']['auto_assign'] = $this->auto_assign_cards($project_id);
            $workflow_time = microtime(true) - $workflow_start;
            $this->logger->log_workflow('auto_assign', $project_id, $results['workflows']['auto_assign'], $workflow_time);
        }

        // Move completed cards
        if ($options['auto_move_completed'] ?? true) {
            $workflow_start = microtime(true);
            $results['workflows']['auto_move_completed'] = $this->auto_move_completed_cards($project_id);
            $workflow_time = microtime(true) - $workflow_start;
            $this->logger->log_workflow('auto_move_completed', $project_id, $results['workflows']['auto_move_completed'], $workflow_time);
        }

        // Escalate overdue tasks
        if ($options['escalate_overdue'] ?? true) {
            $workflow_start = microtime(true);
            $results['workflows']['escalate_overdue'] = $this->escalate_overdue_tasks($project_id);
            $workflow_time = microtime(true) - $workflow_start;
            $this->logger->log_workflow('escalate_overdue', $project_id, $results['workflows']['escalate_overdue'], $workflow_time);
        }

        // Balance workload
        if ($options['balance_workload'] ?? false) {
            $workflow_start = microtime(true);
            $results['workflows']['balance_workload'] = $this->balance_workload($project_id);
            $workflow_time = microtime(true) - $workflow_start;
            $this->logger->log_workflow('balance_workload', $project_id, $results['workflows']['balance_workload'], $workflow_time);
        }

        $total_time = microtime(true) - $start_time;

        $this->logger->info('Automation suite completed', [
            'project_id' => $project_id,
            'total_execution_time' => round($total_time, 3) . 's',
            'workflows_executed' => count($results['workflows'])
        ]);

        return $results;
    }

    /**
     * Get logger instance for external access
     */
    public function get_logger() {
        return $this->logger;
    }
}