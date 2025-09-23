<?php
/**
 * Basecamp Local Indexer
 * Creates and maintains a local searchable index of all Basecamp data
 *
 * @package WBComDesigns\BasecampPro
 * @version 5.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class Basecamp_Indexer {

    private $token;
    private $account_id;
    private $api_base = 'https://3.basecampapi.com';

    // Index storage paths
    private $index_dir;
    private $projects_index;
    private $cards_index;
    private $columns_index;
    private $people_index;
    private $meta_index;

    // Index in memory
    private $index = [
        'projects' => [],
        'cards' => [],
        'columns' => [],
        'people' => [],
        'meta' => []
    ];

    public function __construct() {
        $token_data = get_option('bcr_token_data', []);
        $this->token = $token_data['access_token'] ?? '';
        $this->account_id = get_option('basecamp_account_id', '');

        // Setup index directory
        $upload_dir = wp_upload_dir();
        $this->index_dir = $upload_dir['basedir'] . '/basecamp-index';

        if (!file_exists($this->index_dir)) {
            wp_mkdir_p($this->index_dir);
        }

        // Define index file paths
        $this->projects_index = $this->index_dir . '/projects.json';
        $this->cards_index = $this->index_dir . '/cards.json';
        $this->columns_index = $this->index_dir . '/columns.json';
        $this->people_index = $this->index_dir . '/people.json';
        $this->meta_index = $this->index_dir . '/meta.json';

        // Load existing index
        $this->load_index();
    }

    /**
     * Load index from files
     */
    private function load_index() {
        if (file_exists($this->projects_index)) {
            $this->index['projects'] = json_decode(file_get_contents($this->projects_index), true) ?? [];
        }
        if (file_exists($this->cards_index)) {
            $this->index['cards'] = json_decode(file_get_contents($this->cards_index), true) ?? [];
        }
        if (file_exists($this->columns_index)) {
            $this->index['columns'] = json_decode(file_get_contents($this->columns_index), true) ?? [];
        }
        if (file_exists($this->people_index)) {
            $this->index['people'] = json_decode(file_get_contents($this->people_index), true) ?? [];
        }
        if (file_exists($this->meta_index)) {
            $this->index['meta'] = json_decode(file_get_contents($this->meta_index), true) ?? [];
        }
    }

    /**
     * Save index to files
     */
    private function save_index() {
        file_put_contents($this->projects_index, json_encode($this->index['projects'], JSON_PRETTY_PRINT));
        file_put_contents($this->cards_index, json_encode($this->index['cards'], JSON_PRETTY_PRINT));
        file_put_contents($this->columns_index, json_encode($this->index['columns'], JSON_PRETTY_PRINT));
        file_put_contents($this->people_index, json_encode($this->index['people'], JSON_PRETTY_PRINT));
        file_put_contents($this->meta_index, json_encode($this->index['meta'], JSON_PRETTY_PRINT));
    }

    /**
     * API helper
     */
    private function api($endpoint) {
        $response = wp_remote_get($this->api_base . $endpoint, [
            'headers' => [
                'Authorization' => 'Bearer ' . $this->token,
                'User-Agent' => 'Basecamp Indexer'
            ],
            'timeout' => 30
        ]);

        if (is_wp_error($response)) {
            return false;
        }

        $code = wp_remote_retrieve_response_code($response);
        return $code === 200 ? json_decode(wp_remote_retrieve_body($response), true) : false;
    }

    /**
     * Build complete index
     */
    public function build_full_index($progress_callback = null) {
        $start_time = time();

        // Update meta
        $this->index['meta'] = [
            'last_full_index' => date('Y-m-d H:i:s'),
            'indexing_started' => date('Y-m-d H:i:s', $start_time),
            'account_id' => $this->account_id
        ];

        // 1. Index all projects
        if ($progress_callback) $progress_callback("Indexing projects...");
        $this->index_all_projects();

        // 2. Index all people
        if ($progress_callback) $progress_callback("Indexing people...");
        $this->index_all_people();

        // 3. Index cards for each project
        $total_projects = count($this->index['projects']);
        $current = 0;

        foreach ($this->index['projects'] as $project_id => $project) {
            $current++;
            if ($progress_callback) {
                $progress_callback("Indexing cards for project {$current}/{$total_projects}: {$project['name']}");
            }

            if ($project['status'] === 'active' && !empty($project['has_card_table'])) {
                $this->index_project_cards($project_id);
            }
        }

        // Update meta with completion
        $this->index['meta']['last_full_index_completed'] = date('Y-m-d H:i:s');
        $this->index['meta']['index_time'] = time() - $start_time;
        $this->index['meta']['total_projects'] = count($this->index['projects']);
        $this->index['meta']['total_cards'] = count($this->index['cards']);
        $this->index['meta']['total_people'] = count($this->index['people']);

        // Save to disk
        $this->save_index();

        return $this->index['meta'];
    }

    /**
     * Index all projects
     */
    private function index_all_projects() {
        $page = 1;
        $this->index['projects'] = [];

        while (true) {
            $projects = $this->api("/{$this->account_id}/projects.json?page={$page}");

            if (!$projects || empty($projects)) break;

            foreach ($projects as $project) {
                // Find card table
                $has_card_table = false;
                $card_table_id = null;

                foreach ($project['dock'] ?? [] as $tool) {
                    if (in_array($tool['name'], ['card_table', 'kanban_board'])) {
                        $has_card_table = true;
                        $card_table_id = $tool['id'];
                        break;
                    }
                }

                // Store simplified project data
                $this->index['projects'][$project['id']] = [
                    'id' => $project['id'],
                    'name' => $project['name'],
                    'description' => $project['description'] ?? '',
                    'status' => $project['status'] ?? 'active',
                    'created_at' => $project['created_at'],
                    'updated_at' => $project['updated_at'],
                    'has_card_table' => $has_card_table,
                    'card_table_id' => $card_table_id,
                    'url' => $project['app_url']
                ];
            }

            if (count($projects) < 15) break;
            $page++;
        }

        // Add archived projects
        $archived = $this->api("/{$this->account_id}/projects.json?status=archived");
        if ($archived) {
            foreach ($archived as $project) {
                $this->index['projects'][$project['id']] = [
                    'id' => $project['id'],
                    'name' => $project['name'],
                    'description' => $project['description'] ?? '',
                    'status' => 'archived',
                    'created_at' => $project['created_at'],
                    'updated_at' => $project['updated_at'],
                    'url' => $project['app_url']
                ];
            }
        }
    }

    /**
     * Index all people
     */
    private function index_all_people() {
        $page = 1;
        $this->index['people'] = [];

        while (true) {
            $people = $this->api("/{$this->account_id}/people.json?page={$page}");

            if (!$people || empty($people)) break;

            foreach ($people as $person) {
                $this->index['people'][$person['id']] = [
                    'id' => $person['id'],
                    'name' => $person['name'],
                    'email' => $person['email_address'],
                    'admin' => $person['admin'] ?? false,
                    'owner' => $person['owner'] ?? false,
                    'title' => $person['title'] ?? '',
                    'avatar_url' => $person['avatar_url'] ?? ''
                ];
            }

            if (count($people) < 15) break;
            $page++;
        }
    }

    /**
     * Index cards for a specific project
     */
    private function index_project_cards($project_id) {
        $project = $this->index['projects'][$project_id];

        if (!$project['card_table_id']) return;

        $discovered_columns = [];
        $card_table_id = $project['card_table_id'];

        // HYBRID APPROACH: Scan a reasonable range of column IDs
        // Most columns are within 20-30 IDs of the card table ID
        $base = intval($card_table_id);
        $column_ids_to_check = [];

        // Add IDs in range around the card table ID
        for ($offset = 0; $offset <= 30; $offset++) {
            $column_ids_to_check[] = $base + $offset;
        }

        // Discover columns by checking each potential ID
        foreach ($column_ids_to_check as $column_id) {
            $col = $this->api("/{$this->account_id}/buckets/{$project_id}/card_tables/columns/{$column_id}.json");

            if ($col && isset($col['title'])) {
                $discovered_columns[$column_id] = $col;

                // Classify column type
                $column_type = $this->classify_column_type($col['title']);

                // Store column with classification
                $this->index['columns']["{$project_id}_{$column_id}"] = [
                    'id' => $column_id,
                    'project_id' => $project_id,
                    'project_name' => $project['name'],
                    'title' => $col['title'],
                    'position' => $col['position'] ?? 0,
                    'type' => $column_type,
                    'type_emoji' => $this->get_column_emoji($column_type)
                ];

                // Get cards in this column
                $cards = $this->api("/{$this->account_id}/buckets/{$project_id}/card_tables/lists/{$column_id}/cards.json");

                if ($cards && is_array($cards)) {
                    foreach ($cards as $card) {
                        // Store card with searchable fields and classification
                        $this->index['cards']["{$project_id}_{$card['id']}"] = [
                            'id' => $card['id'],
                            'project_id' => $project_id,
                            'project_name' => $project['name'],
                            'column_id' => $column_id,
                            'column_name' => $col['title'],
                            'column_type' => $column_type,
                            'column_emoji' => $this->get_column_emoji($column_type),
                            'title' => $card['title'],
                            'content' => strip_tags($card['content'] ?? ''),
                            'completed' => $card['completed'] ?? false,
                            'due_on' => $card['due_on'] ?? null,
                            'assignee_ids' => array_column($card['assignees'] ?? [], 'id'),
                            'assignee_names' => array_column($card['assignees'] ?? [], 'name'),
                            'created_at' => $card['created_at'],
                            'updated_at' => $card['updated_at'],
                            'url' => "https://3.basecamp.com/{$this->account_id}/buckets/{$project_id}/card_tables/cards/{$card['id']}",
                            'is_bug' => $column_type === 'bugs',
                            'is_testing' => $column_type === 'testing',
                            'is_development' => $column_type === 'development',
                            'is_done' => $column_type === 'done'
                        ];
                    }
                }
            }
        }
    }

    /**
     * Quick search across all indexed data
     */
    public function search($query, $type = 'all', $filters = []) {
        $query_lower = strtolower($query);
        $results = [
            'projects' => [],
            'cards' => [],
            'people' => []
        ];

        // Search projects
        if ($type === 'all' || $type === 'projects') {
            foreach ($this->index['projects'] as $project) {
                if ($this->matches($query_lower, [
                    $project['name'],
                    $project['description']
                ])) {
                    $results['projects'][] = $project;
                }
            }
        }

        // Search cards
        if ($type === 'all' || $type === 'cards') {
            foreach ($this->index['cards'] as $card) {
                // Apply filters
                if (!empty($filters['project_id']) && $card['project_id'] != $filters['project_id']) {
                    continue;
                }
                if (!empty($filters['assignee']) && !in_array($filters['assignee'], $card['assignee_names'])) {
                    continue;
                }
                if (isset($filters['completed']) && $card['completed'] !== $filters['completed']) {
                    continue;
                }

                // Search
                if ($this->matches($query_lower, [
                    $card['title'],
                    $card['content'],
                    implode(' ', $card['assignee_names'])
                ])) {
                    $results['cards'][] = $card;
                }
            }
        }

        // Search people
        if ($type === 'all' || $type === 'people') {
            foreach ($this->index['people'] as $person) {
                if ($this->matches($query_lower, [
                    $person['name'],
                    $person['email'],
                    $person['title']
                ])) {
                    $results['people'][] = $person;
                }
            }
        }

        return $results;
    }

    /**
     * Helper: Check if query matches any of the fields
     */
    private function matches($query, $fields) {
        foreach ($fields as $field) {
            if (strpos(strtolower($field), $query) !== false) {
                return true;
            }
        }
        return false;
    }

    /**
     * Get statistics from index
     */
    public function get_statistics() {
        $stats = [
            'total_projects' => count($this->index['projects']),
            'active_projects' => 0,
            'total_cards' => count($this->index['cards']),
            'open_cards' => 0,
            'completed_cards' => 0,
            'overdue_cards' => 0,
            'total_people' => count($this->index['people']),
            'cards_by_column' => [],
            'cards_by_project' => [],
            'cards_by_assignee' => [],
            'index_age' => null
        ];

        // Calculate stats
        $today = new DateTime();

        foreach ($this->index['projects'] as $project) {
            if ($project['status'] === 'active') {
                $stats['active_projects']++;
            }
        }

        foreach ($this->index['cards'] as $card) {
            // By status
            if ($card['completed']) {
                $stats['completed_cards']++;
            } else {
                $stats['open_cards']++;

                // Check overdue
                if ($card['due_on']) {
                    $due = new DateTime($card['due_on']);
                    if ($due < $today) {
                        $stats['overdue_cards']++;
                    }
                }
            }

            // By column
            $col = $card['column_name'];
            $stats['cards_by_column'][$col] = ($stats['cards_by_column'][$col] ?? 0) + 1;

            // By project
            $proj = $card['project_name'];
            $stats['cards_by_project'][$proj] = ($stats['cards_by_project'][$proj] ?? 0) + 1;

            // By assignee
            foreach ($card['assignee_names'] as $name) {
                $stats['cards_by_assignee'][$name] = ($stats['cards_by_assignee'][$name] ?? 0) + 1;
            }
        }

        // Index age
        if (!empty($this->index['meta']['last_full_index'])) {
            $last_index = new DateTime($this->index['meta']['last_full_index']);
            $diff = $today->diff($last_index);
            $stats['index_age'] = $diff->format('%a days, %h hours ago');
        }

        return $stats;
    }

    /**
     * Update single card (partial update)
     */
    public function update_card($project_id, $card_id, $data) {
        $key = "{$project_id}_{$card_id}";

        if (isset($this->index['cards'][$key])) {
            $this->index['cards'][$key] = array_merge(
                $this->index['cards'][$key],
                $data
            );
            $this->save_index();
            return true;
        }

        return false;
    }

    /**
     * Get project summary from index (no API call)
     */
    public function get_project_summary($project_id) {
        if (!isset($this->index['projects'][$project_id])) {
            return null;
        }

        $project = $this->index['projects'][$project_id];
        $summary = [
            'project' => $project,
            'columns' => [],
            'cards' => [],
            'stats' => [
                'total' => 0,
                'open' => 0,
                'completed' => 0,
                'overdue' => 0,
                'by_column' => [],
                'by_assignee' => []
            ]
        ];

        // Get columns
        foreach ($this->index['columns'] as $col) {
            if ($col['project_id'] == $project_id) {
                $summary['columns'][] = $col;
                $summary['stats']['by_column'][$col['title']] = 0;
            }
        }

        // Get cards
        $today = new DateTime();
        foreach ($this->index['cards'] as $card) {
            if ($card['project_id'] == $project_id) {
                $summary['cards'][] = $card;
                $summary['stats']['total']++;

                if ($card['completed']) {
                    $summary['stats']['completed']++;
                } else {
                    $summary['stats']['open']++;

                    if ($card['due_on']) {
                        $due = new DateTime($card['due_on']);
                        if ($due < $today) {
                            $summary['stats']['overdue']++;
                        }
                    }
                }

                $summary['stats']['by_column'][$card['column_name']]++;

                foreach ($card['assignee_names'] as $name) {
                    $summary['stats']['by_assignee'][$name] =
                        ($summary['stats']['by_assignee'][$name] ?? 0) + 1;
                }
            }
        }

        return $summary;
    }

    /**
     * Export index to CSV
     */
    public function export_to_csv($type = 'cards') {
        $filename = $this->index_dir . "/export_{$type}_" . date('Y-m-d') . ".csv";
        $fp = fopen($filename, 'w');

        if ($type === 'cards') {
            // Header
            fputcsv($fp, [
                'Card ID', 'Project', 'Column', 'Title', 'Assignees',
                'Status', 'Due Date', 'Created', 'Updated', 'URL'
            ]);

            // Data
            foreach ($this->index['cards'] as $card) {
                fputcsv($fp, [
                    $card['id'],
                    $card['project_name'],
                    $card['column_name'],
                    $card['title'],
                    implode(', ', $card['assignee_names']),
                    $card['completed'] ? 'Completed' : 'Open',
                    $card['due_on'] ?? '',
                    $card['created_at'],
                    $card['updated_at'],
                    $card['url']
                ]);
            }
        }

        fclose($fp);
        return $filename;
    }

    /**
     * Classify column type based on name
     */
    private function classify_column_type($column_name) {
        $name_lower = strtolower($column_name);

        // Bug-related columns
        if (preg_match('/\b(bug|issue|error|fix|problem|defect)\b/', $name_lower)) {
            return 'bugs';
        }

        // Testing-related columns
        if (preg_match('/\b(test|testing|qa|quality|verify|validation)\b/', $name_lower)) {
            return 'testing';
        }

        // Review-related columns
        if (preg_match('/\b(review|code review|pending|approval|waiting)\b/', $name_lower)) {
            return 'review';
        }

        // Development-related columns
        if (preg_match('/\b(dev|development|coding|implement|progress|doing|work)\b/', $name_lower)) {
            return 'development';
        }

        // Done/completed columns
        if (preg_match('/\b(done|complete|finished|closed|resolved|live|deployed)\b/', $name_lower)) {
            return 'done';
        }

        // Todo/backlog columns
        if (preg_match('/\b(todo|to do|backlog|planned|new|open|start)\b/', $name_lower)) {
            return 'todo';
        }

        return 'other';
    }

    /**
     * Get emoji for column type
     */
    private function get_column_emoji($type) {
        return match($type) {
            'bugs' => '🐛',
            'testing' => '🧪',
            'review' => '👀',
            'development' => '💻',
            'done' => '✅',
            'todo' => '📝',
            default => '📊'
        };
    }

    /**
     * Get card statistics by classification
     */
    public function get_card_stats_by_type() {
        if (!$this->load_index()) {
            return false;
        }

        $stats = [
            'bugs' => 0,
            'testing' => 0,
            'development' => 0,
            'review' => 0,
            'done' => 0,
            'todo' => 0,
            'other' => 0,
            'total' => 0
        ];

        foreach ($this->index['cards'] as $card) {
            $type = $card['column_type'] ?? 'other';
            $stats[$type]++;
            $stats['total']++;
        }

        return $stats;
    }

    /**
     * Search cards by type
     */
    public function search_cards_by_type($type) {
        if (!$this->load_index()) {
            return [];
        }

        $results = [];
        foreach ($this->index['cards'] as $card) {
            if (($card['column_type'] ?? 'other') === $type) {
                $results[] = $card;
            }
        }

        return $results;
    }
}

/**
 * WP-CLI Commands for Basecamp Indexer
 */
class Basecamp_Index_CLI {

    private $indexer;

    public function __construct() {
        $this->indexer = new Basecamp_Indexer();
    }

    /**
     * Build or rebuild the complete index
     *
     * ## EXAMPLES
     *     wp bc index build
     */
    public function build() {
        WP_CLI::line("🔨 Building Basecamp index...\n");

        $result = $this->indexer->build_full_index(function($message) {
            WP_CLI::line("  " . $message);
        });

        WP_CLI::success("Index built successfully!");
        WP_CLI::line("\n📊 Index Statistics:");
        WP_CLI::line("  Projects: " . $result['total_projects']);
        WP_CLI::line("  Cards: " . $result['total_cards']);
        WP_CLI::line("  People: " . $result['total_people']);
        WP_CLI::line("  Time taken: " . $result['index_time'] . " seconds");
    }

    /**
     * Search the index
     *
     * ## OPTIONS
     * <query>
     * : Search query
     *
     * [--type=<type>]
     * : Type to search (all|projects|cards|people)
     *
     * [--project=<project_id>]
     * : Filter by project ID
     *
     * ## EXAMPLES
     *     wp bc index search "bug"
     *     wp bc index search "login" --type=cards
     *     wp bc index search "feature" --project=37594969
     */
    public function search($args, $assoc_args) {
        $query = $args[0];
        $type = $assoc_args['type'] ?? 'all';
        $filters = [];

        if (isset($assoc_args['project'])) {
            $filters['project_id'] = $assoc_args['project'];
        }

        $results = $this->indexer->search($query, $type, $filters);

        // Display results
        WP_CLI::line("\n🔍 Search Results for: '$query'\n");

        if (!empty($results['projects'])) {
            WP_CLI::line("📁 PROJECTS (" . count($results['projects']) . "):");
            foreach ($results['projects'] as $p) {
                WP_CLI::line("  • " . $p['name'] . " (ID: " . $p['id'] . ")");
            }
            WP_CLI::line("");
        }

        if (!empty($results['cards'])) {
            WP_CLI::line("📋 CARDS (" . count($results['cards']) . "):");
            foreach (array_slice($results['cards'], 0, 10) as $c) {
                WP_CLI::line("  • " . $c['title']);
                WP_CLI::line("    Project: " . $c['project_name'] . " | Column: " . $c['column_name']);
            }
            if (count($results['cards']) > 10) {
                WP_CLI::line("  ... and " . (count($results['cards']) - 10) . " more");
            }
            WP_CLI::line("");
        }

        if (!empty($results['people'])) {
            WP_CLI::line("👥 PEOPLE (" . count($results['people']) . "):");
            foreach ($results['people'] as $p) {
                WP_CLI::line("  • " . $p['name'] . " - " . $p['email']);
            }
        }
    }

    /**
     * Show index statistics
     *
     * ## EXAMPLES
     *     wp bc index stats
     */
    public function stats() {
        $stats = $this->indexer->get_statistics();

        WP_CLI::line("\n📊 BASECAMP INDEX STATISTICS");
        WP_CLI::line(str_repeat("═", 50));
        WP_CLI::line("Last indexed: " . ($stats['index_age'] ?? 'Never'));
        WP_CLI::line("");
        WP_CLI::line("Total Projects: " . $stats['total_projects'] . " (" . $stats['active_projects'] . " active)");
        WP_CLI::line("Total Cards: " . $stats['total_cards']);
        WP_CLI::line("  Open: " . $stats['open_cards']);
        WP_CLI::line("  Completed: " . $stats['completed_cards']);
        WP_CLI::line("  Overdue: " . $stats['overdue_cards']);
        WP_CLI::line("Total People: " . $stats['total_people']);

        if (!empty($stats['cards_by_assignee'])) {
            WP_CLI::line("\n👥 Top Assignees:");
            arsort($stats['cards_by_assignee']);
            foreach (array_slice($stats['cards_by_assignee'], 0, 5, true) as $name => $count) {
                WP_CLI::line("  " . sprintf("%-20s %d cards", $name, $count));
            }
        }
    }

    /**
     * Export index to CSV
     *
     * ## OPTIONS
     * [--type=<type>]
     * : What to export (cards|projects)
     *
     * ## EXAMPLES
     *     wp bc index export
     *     wp bc index export --type=projects
     */
    public function export($args, $assoc_args) {
        $type = $assoc_args['type'] ?? 'cards';
        $file = $this->indexer->export_to_csv($type);
        WP_CLI::success("Exported to: " . $file);
    }
}

// Register CLI commands
if (defined('WP_CLI') && WP_CLI) {
    WP_CLI::add_command('bc index', 'Basecamp_Index_CLI');
}