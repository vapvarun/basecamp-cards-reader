<?php
/**
 * Basecamp Pro - Dynamic Project Management System
 * Automatically discovers and manages all projects
 *
 * @package WBComDesigns\BasecampPro
 * @version 4.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class Basecamp_Pro {

    private $token;
    private $account_id = '5798509';
    private $api_base = 'https://3.basecampapi.com';
    private $projects_cache = null;
    private $cache_file;

    public function __construct() {
        $token_data = get_option('bcr_token_data', []);
        $this->token = $token_data['access_token'] ?? '';
        $this->cache_file = WP_CONTENT_DIR . '/uploads/basecamp_projects_cache.json';
    }

    /**
     * API Request Helper
     */
    private function api($endpoint, $method = 'GET', $data = null) {
        $url = $this->api_base . $endpoint;

        $args = [
            'method' => $method,
            'headers' => [
                'Authorization' => 'Bearer ' . $this->token,
                'User-Agent' => 'WBComDesigns Basecamp Pro'
            ],
            'timeout' => 30
        ];

        if ($data) {
            $args['headers']['Content-Type'] = 'application/json';
            $args['body'] = json_encode($data);
        }

        $response = wp_remote_request($url, $args);

        if (is_wp_error($response)) {
            return false;
        }

        $code = wp_remote_retrieve_response_code($response);
        $body = json_decode(wp_remote_retrieve_body($response), true);

        return ($code === 200 || $code === 201) ? $body : false;
    }

    /**
     * Fetch ALL projects (handles pagination)
     */
    public function fetch_all_projects($force_refresh = false) {
        // Check cache
        if (!$force_refresh && $this->projects_cache) {
            return $this->projects_cache;
        }

        // Check file cache
        if (!$force_refresh && file_exists($this->cache_file)) {
            $cache_age = time() - filemtime($this->cache_file);
            if ($cache_age < 3600) { // 1 hour cache
                $cached = json_decode(file_get_contents($this->cache_file), true);
                if ($cached) {
                    $this->projects_cache = $cached;
                    return $cached;
                }
            }
        }

        WP_CLI::log("Fetching all projects from Basecamp...");

        $all_projects = [];
        $page = 1;

        while (true) {
            $projects = $this->api("/{$this->account_id}/projects.json?page={$page}");

            if (!$projects || empty($projects)) {
                break;
            }

            $all_projects = array_merge($all_projects, $projects);

            if (count($projects) < 15) { // Basecamp returns max 15 per page
                break;
            }

            $page++;
        }

        // Add archived projects
        $archived = $this->api("/{$this->account_id}/projects.json?status=archived");
        if ($archived) {
            foreach ($archived as $project) {
                $project['status'] = 'archived';
                $all_projects[] = $project;
            }
        }

        // Cache the results
        $this->projects_cache = $all_projects;
        file_put_contents($this->cache_file, json_encode($all_projects));

        WP_CLI::success("Found " . count($all_projects) . " projects");

        return $all_projects;
    }

    /**
     * Advanced fuzzy search for projects with pattern matching
     */
    public function find_project($search_term) {
        $projects = $this->fetch_all_projects();
        $search_term = trim($search_term);
        $search_lower = strtolower($search_term);
        $matches = [];

        // Normalize search term (remove common words, normalize spaces)
        $search_normalized = $this->normalize_search_term($search_term);

        foreach ($projects as $project) {
            $name = $project['name'];
            $name_lower = strtolower($name);
            $name_normalized = $this->normalize_project_name($name);
            $desc_lower = strtolower($project['description'] ?? '');

            $score = $this->calculate_project_match_score(
                $search_term,
                $search_lower,
                $search_normalized,
                $name,
                $name_lower,
                $name_normalized,
                $desc_lower
            );

            if ($score > 0) {
                $matches[] = [
                    'project' => $project,
                    'score' => $score,
                    'match_type' => $this->get_match_type($score)
                ];
            }
        }

        // Sort by score (highest first)
        usort($matches, function($a, $b) {
            return $b['score'] - $a['score'];
        });

        return $matches;
    }

    /**
     * Normalize search term for better matching
     */
    private function normalize_search_term($term) {
        // Remove common words that don't add value
        $stop_words = ['the', 'a', 'an', 'and', 'or', 'but', 'for', 'with', 'pro', 'plugin'];
        $words = preg_split('/[\s\-_]+/', strtolower($term));
        $words = array_filter($words, function($word) use ($stop_words) {
            return !in_array($word, $stop_words) && strlen($word) > 1;
        });
        return implode(' ', $words);
    }

    /**
     * Normalize project name for comparison
     */
    private function normalize_project_name($name) {
        // Remove version numbers, brackets, common suffixes
        $normalized = preg_replace('/\s*\([^)]*\)/', '', $name); // Remove (version 2.0)
        $normalized = preg_replace('/\s*v?\d+(\.\d+)*\s*/', '', $normalized); // Remove v1.0, 2.0, etc.
        $normalized = preg_replace('/\s*(pro|plugin|theme|extension)\s*/i', '', $normalized);
        return trim($normalized);
    }

    /**
     * Calculate comprehensive match score
     */
    private function calculate_project_match_score($search_term, $search_lower, $search_normalized, $name, $name_lower, $name_normalized, $desc_lower) {
        $score = 0;

        // 1. Exact matches (highest priority)
        if ($name_lower === $search_lower) {
            return 100;
        }
        if ($name_normalized === $search_normalized) {
            $score += 95;
        }

        // 2. Full substring matches
        if (strpos($name_lower, $search_lower) !== false) {
            // Bonus for beginning of string
            if (strpos($name_lower, $search_lower) === 0) {
                $score += 90;
            } else {
                $score += 80;
            }
        }

        // 3. Acronym matching (e.g., "bp" matches "BuddyPress")
        $acronym_score = $this->check_acronym_match($search_lower, $name_lower);
        $score += $acronym_score;

        // 4. Word-by-word matching
        $word_score = $this->check_word_matches($search_normalized, $name_normalized);
        $score += $word_score;

        // 5. Fuzzy string matching for typos (using Levenshtein distance)
        $fuzzy_score = $this->check_fuzzy_match($search_lower, $name_lower);
        $score += $fuzzy_score;

        // 6. Partial abbreviation matching (e.g., "check" matches "checkins")
        $abbrev_score = $this->check_abbreviation_match($search_lower, $name_lower);
        $score += $abbrev_score;

        // 7. Description matching (lower priority)
        if (strpos($desc_lower, $search_lower) !== false) {
            $score += 10;
        }

        // 8. Pattern-based matching for common project naming conventions
        $pattern_score = $this->check_pattern_match($search_lower, $name_lower);
        $score += $pattern_score;

        return $score;
    }

    /**
     * Check for acronym matches
     */
    private function check_acronym_match($search, $name) {
        if (strlen($search) < 2) return 0;

        // Generate acronym from project name
        $words = preg_split('/[\s\-_]+/', $name);
        $acronym = '';
        foreach ($words as $word) {
            if (strlen($word) > 0) {
                $acronym .= $word[0];
            }
        }

        if (strtolower($acronym) === $search) {
            return 85; // High score for perfect acronym match
        }

        // Partial acronym match
        if (strlen($search) <= strlen($acronym) && strpos($acronym, $search) === 0) {
            return 60;
        }

        return 0;
    }

    /**
     * Check word-by-word matches
     */
    private function check_word_matches($search_normalized, $name_normalized) {
        $search_words = explode(' ', $search_normalized);
        $name_words = explode(' ', strtolower(preg_replace('/[\s\-_]+/', ' ', $name_normalized)));

        $matched_words = 0;
        $total_search_words = count($search_words);

        foreach ($search_words as $search_word) {
            if (strlen($search_word) < 2) continue;

            foreach ($name_words as $name_word) {
                // Exact word match
                if ($search_word === $name_word) {
                    $matched_words++;
                    break;
                }
                // Word starts with search term
                if (strpos($name_word, $search_word) === 0 && strlen($search_word) >= 3) {
                    $matched_words += 0.8;
                    break;
                }
                // Word contains search term
                if (strpos($name_word, $search_word) !== false && strlen($search_word) >= 4) {
                    $matched_words += 0.5;
                    break;
                }
            }
        }

        if ($total_search_words > 0) {
            $match_percentage = $matched_words / $total_search_words;
            return intval($match_percentage * 40); // Max 40 points for word matching
        }

        return 0;
    }

    /**
     * Check fuzzy matches using Levenshtein distance
     */
    private function check_fuzzy_match($search, $name) {
        if (strlen($search) < 3) return 0;

        // Only check if strings are similar in length
        $length_diff = abs(strlen($search) - strlen($name));
        if ($length_diff > strlen($search)) return 0;

        $distance = levenshtein($search, $name);
        $max_length = max(strlen($search), strlen($name));

        // Allow up to 20% character differences
        if ($distance <= $max_length * 0.2) {
            return intval(35 * (1 - ($distance / $max_length)));
        }

        return 0;
    }

    /**
     * Check abbreviation matches
     */
    private function check_abbreviation_match($search, $name) {
        if (strlen($search) < 3) return 0;

        $name_words = preg_split('/[\s\-_]+/', $name);

        foreach ($name_words as $word) {
            if (strlen($word) >= strlen($search)) {
                // Check if search term is start of word
                if (strpos($word, $search) === 0) {
                    $score = intval(25 * (strlen($search) / strlen($word)));
                    return min($score, 25);
                }
            }
        }

        return 0;
    }

    /**
     * Check pattern-based matches for common conventions
     */
    private function check_pattern_match($search, $name) {
        $score = 0;

        // Common project name patterns
        $patterns = [
            // "checkins" should match "buddypress-checkins-pro"
            '/\b' . preg_quote($search, '/') . '\b/' => 30,
            // "check" should match "checkins", "check-ins"
            '/\b' . preg_quote($search, '/') . '/' => 20,
            // Handle hyphenated versions
            '/' . str_replace(' ', '[-\s_]', preg_quote($search, '/')) . '/' => 25,
        ];

        foreach ($patterns as $pattern => $points) {
            if (preg_match($pattern, $name)) {
                $score += $points;
                break; // Take first match only
            }
        }

        return $score;
    }

    /**
     * Get human-readable match type
     */
    private function get_match_type($score) {
        if ($score >= 90) return 'exact';
        if ($score >= 70) return 'strong';
        if ($score >= 40) return 'partial';
        if ($score >= 20) return 'weak';
        return 'minimal';
    }

    /**
     * Get project cards with full details
     */
    public function get_project_cards($project_id) {
        $project = $this->api("/{$this->account_id}/projects/{$project_id}.json");
        if (!$project) {
            return false;
        }

        // Find card table
        $card_table_id = null;
        foreach ($project['dock'] ?? [] as $tool) {
            if (in_array($tool['name'], ['card_table', 'kanban_board'])) {
                $card_table_id = $tool['id'];
                break;
            }
        }

        if (!$card_table_id) {
            return ['project' => $project, 'cards' => [], 'columns' => []];
        }

        // Discover columns
        $columns = [];
        $base = intval($card_table_id);

        for ($id = $base; $id <= $base + 50; $id++) {
            $col = $this->api("/{$this->account_id}/buckets/{$project_id}/card_tables/lists/{$id}.json");
            if ($col && isset($col['title'])) {
                $columns[$id] = $col;
            }
        }

        // Sort columns
        uasort($columns, function($a, $b) {
            return ($a['position'] ?? 0) - ($b['position'] ?? 0);
        });

        // Get cards from each column
        $all_cards = [];
        foreach ($columns as $col_id => $column) {
            $cards = $this->api("/{$this->account_id}/buckets/{$project_id}/card_tables/lists/{$col_id}/cards.json");

            if ($cards) {
                foreach ($cards as $card) {
                    $card['column_name'] = $column['title'];
                    $card['column_id'] = $col_id;
                    $all_cards[] = $card;
                }
            }
        }

        return [
            'project' => $project,
            'columns' => $columns,
            'cards' => $all_cards
        ];
    }

    /**
     * Analyze multiple projects at once
     */
    public function analyze_portfolio($project_ids = null) {
        if (!$project_ids) {
            // Analyze all active projects
            $all_projects = $this->fetch_all_projects();
            $project_ids = array_column(
                array_filter($all_projects, function($p) { return $p['status'] === 'active'; }),
                'id'
            );
        }

        $portfolio = [
            'total_projects' => count($project_ids),
            'total_cards' => 0,
            'total_overdue' => 0,
            'by_status' => [],
            'by_assignee' => [],
            'projects_health' => []
        ];

        foreach ($project_ids as $pid) {
            $data = $this->get_project_cards($pid);
            if (!$data) continue;

            $project_stats = $this->analyze_project_health($data);
            $portfolio['projects_health'][$data['project']['name']] = $project_stats;

            $portfolio['total_cards'] += count($data['cards']);
            $portfolio['total_overdue'] += $project_stats['overdue_count'];

            // Aggregate assignees
            foreach ($project_stats['by_assignee'] ?? [] as $name => $count) {
                $portfolio['by_assignee'][$name] = ($portfolio['by_assignee'][$name] ?? 0) + $count;
            }
        }

        return $portfolio;
    }

    /**
     * Analyze project health
     */
    private function analyze_project_health($data) {
        $stats = [
            'total_cards' => count($data['cards']),
            'completed' => 0,
            'active' => 0,
            'overdue_count' => 0,
            'by_column' => [],
            'by_assignee' => []
        ];

        $today = new DateTime();

        foreach ($data['cards'] as $card) {
            // Status
            if ($card['completed'] ?? false) {
                $stats['completed']++;
            } else {
                $stats['active']++;

                // Check overdue
                if (!empty($card['due_on'])) {
                    $due = new DateTime($card['due_on']);
                    if ($due < $today) {
                        $stats['overdue_count']++;
                    }
                }
            }

            // By column
            $col = $card['column_name'];
            $stats['by_column'][$col] = ($stats['by_column'][$col] ?? 0) + 1;

            // By assignee
            if (!empty($card['assignees'])) {
                foreach ($card['assignees'] as $assignee) {
                    $name = $assignee['name'];
                    $stats['by_assignee'][$name] = ($stats['by_assignee'][$name] ?? 0) + 1;
                }
            }
        }

        $stats['completion_rate'] = $stats['total_cards'] > 0 ?
            round(($stats['completed'] / $stats['total_cards']) * 100, 1) : 0;

        return $stats;
    }

    /**
     * Quick card creation
     */
    public function quick_add($project_name, $column_name, $title, $content = '') {
        // Find project
        $matches = $this->find_project($project_name);
        if (empty($matches)) {
            return ['error' => "Project '$project_name' not found"];
        }

        $project = $matches[0]['project'];
        $data = $this->get_project_cards($project['id']);

        // Find column
        $column_id = null;
        $column_lower = strtolower($column_name);

        foreach ($data['columns'] as $id => $col) {
            if (strpos(strtolower($col['title']), $column_lower) !== false) {
                $column_id = $id;
                break;
            }
        }

        if (!$column_id) {
            return ['error' => "Column '$column_name' not found"];
        }

        // Create card
        $result = $this->api(
            "/{$this->account_id}/buckets/{$project['id']}/card_tables/lists/{$column_id}/cards.json",
            'POST',
            ['title' => $title, 'content' => $content]
        );

        return $result ?: ['error' => 'Failed to create card'];
    }
}

/**
 * WP-CLI Commands for Basecamp Pro
 */
class Basecamp_Pro_CLI {

    private $bc;

    public function __construct() {
        $this->bc = new Basecamp_Pro();
    }

    /**
     * List all projects
     *
     * ## OPTIONS
     * [--refresh]
     * : Force refresh cache
     *
     * [--status=<status>]
     * : Filter by status (active|archived|all)
     *
     * ## EXAMPLES
     *     wp bc projects
     *     wp bc projects --status=archived
     */
    public function projects($args, $assoc_args) {
        $refresh = isset($assoc_args['refresh']);
        $status_filter = $assoc_args['status'] ?? 'active';

        $projects = $this->bc->fetch_all_projects($refresh);

        if ($status_filter !== 'all') {
            $projects = array_filter($projects, function($p) use ($status_filter) {
                return ($p['status'] ?? 'active') === $status_filter;
            });
        }

        WP_CLI::line("\n📁 BASECAMP PROJECTS (" . count($projects) . " total)\n");
        WP_CLI::line(str_repeat("═", 80));

        $table_data = [];
        foreach ($projects as $p) {
            $table_data[] = [
                'ID' => $p['id'],
                'Name' => substr($p['name'], 0, 40),
                'Description' => substr($p['description'] ?? '', 0, 30),
                'Status' => $p['status'] ?? 'active',
                'Created' => date('Y-m-d', strtotime($p['created_at']))
            ];
        }

        WP_CLI\Utils\format_items('table', $table_data, ['ID', 'Name', 'Description', 'Status', 'Created']);
    }

    /**
     * Find and analyze a project
     *
     * ## OPTIONS
     * <search>...
     * : Search terms for project name
     *
     * ## EXAMPLES
     *     wp bc find buddypress checkins
     *     wp bc find "reign theme"
     */
    public function find($args) {
        $search = implode(' ', $args);
        $matches = $this->bc->find_project($search);

        if (empty($matches)) {
            WP_CLI::error("No projects found matching: $search");
            return;
        }

        // Show matches
        if (count($matches) > 1) {
            WP_CLI::line("\n🔍 Found " . count($matches) . " matches:\n");
            foreach (array_slice($matches, 0, 5) as $i => $match) {
                $p = $match['project'];
                WP_CLI::line(($i + 1) . ". " . $p['name'] . " (ID: " . $p['id'] . ")");
                if ($p['description']) {
                    WP_CLI::line("   " . $p['description']);
                }
            }
            WP_CLI::line("\nUsing best match: " . $matches[0]['project']['name']);
        }

        $project_id = $matches[0]['project']['id'];
        $this->analyze_project($project_id);
    }

    /**
     * Analyze a specific project
     */
    private function analyze_project($project_id) {
        $data = $this->bc->get_project_cards($project_id);

        if (!$data) {
            WP_CLI::error("Failed to fetch project data");
            return;
        }

        $project = $data['project'];
        $stats = $this->bc->analyze_project_health($data);

        // Display header
        WP_CLI::line("\n" . str_repeat("═", 80));
        WP_CLI::line("📊 " . strtoupper($project['name']));
        WP_CLI::line(str_repeat("═", 80));

        if ($project['description']) {
            WP_CLI::line($project['description']);
            WP_CLI::line("");
        }

        // Summary stats
        WP_CLI::line("📈 STATISTICS:");
        WP_CLI::line("   Total Cards: " . $stats['total_cards']);
        WP_CLI::line("   Active: " . $stats['active']);
        WP_CLI::line("   Completed: " . $stats['completed'] . " (" . $stats['completion_rate'] . "%)");

        if ($stats['overdue_count'] > 0) {
            WP_CLI::line("   ⚠️  Overdue: " . $stats['overdue_count']);
        }

        // Column distribution
        if (!empty($stats['by_column'])) {
            WP_CLI::line("\n📋 BY COLUMN:");
            foreach ($stats['by_column'] as $col => $count) {
                $bar = str_repeat("█", min(20, intval($count / 2)));
                WP_CLI::line(sprintf("   %-20s %s %d", $col, $bar, $count));
            }
        }

        // Assignee workload
        if (!empty($stats['by_assignee'])) {
            WP_CLI::line("\n👥 BY ASSIGNEE:");
            arsort($stats['by_assignee']);
            foreach ($stats['by_assignee'] as $name => $count) {
                WP_CLI::line(sprintf("   %-20s %d cards", $name, $count));
            }
        }

        // Show recent cards
        if (!empty($data['cards'])) {
            WP_CLI::line("\n📝 RECENT CARDS:");
            $recent = array_slice($data['cards'], 0, 5);
            foreach ($recent as $card) {
                $status = $card['completed'] ? "✓" : "○";
                WP_CLI::line("   $status " . $card['title']);
                if (!empty($card['assignees'])) {
                    WP_CLI::line("     Assigned: " . implode(", ", array_column($card['assignees'], 'name')));
                }
            }
        }

        WP_CLI::line("");
    }

    /**
     * Search for specific cards (bugs, features, etc)
     *
     * ## OPTIONS
     * <project>
     * : Project name or ID
     *
     * <query>
     * : Search query
     *
     * ## EXAMPLES
     *     wp bc search "checkins" "bug"
     *     wp bc search 37594969 "login"
     */
    public function search($args) {
        if (count($args) < 2) {
            WP_CLI::error("Usage: wp bc search <project> <query>");
            return;
        }

        $project_search = $args[0];
        $query = $args[1];

        // Find project
        if (is_numeric($project_search)) {
            $project_id = $project_search;
        } else {
            $matches = $this->bc->find_project($project_search);
            if (empty($matches)) {
                WP_CLI::error("Project not found: $project_search");
                return;
            }
            $project_id = $matches[0]['project']['id'];
        }

        // Get cards and search
        $data = $this->bc->get_project_cards($project_id);
        $query_lower = strtolower($query);
        $results = [];

        foreach ($data['cards'] as $card) {
            if (strpos(strtolower($card['title']), $query_lower) !== false ||
                strpos(strtolower($card['content'] ?? ''), $query_lower) !== false) {
                $results[] = $card;
            }
        }

        // Display results
        WP_CLI::line("\n🔍 Found " . count($results) . " cards matching '$query' in " . $data['project']['name']);
        WP_CLI::line(str_repeat("─", 60));

        foreach ($results as $card) {
            WP_CLI::line("\n• " . $card['title']);
            WP_CLI::line("  Column: " . $card['column_name']);
            WP_CLI::line("  ID: " . $card['id']);

            if (!empty($card['assignees'])) {
                WP_CLI::line("  Assigned: " . implode(", ", array_column($card['assignees'], 'name')));
            }

            if ($card['due_on']) {
                WP_CLI::line("  Due: " . $card['due_on']);
            }
        }
    }

    /**
     * Create a card quickly
     *
     * ## OPTIONS
     * <project>
     * : Project name
     *
     * <column>
     * : Column name (todo|progress|testing|done)
     *
     * <title>
     * : Card title
     *
     * [--content=<content>]
     * : Card description
     *
     * ## EXAMPLES
     *     wp bc add "checkins" "todo" "Fix map display bug"
     *     wp bc add "reign theme" "testing" "Test mobile view" --content="Check responsive design"
     */
    public function add($args, $assoc_args) {
        if (count($args) < 3) {
            WP_CLI::error("Usage: wp bc add <project> <column> <title>");
            return;
        }

        $result = $this->bc->quick_add(
            $args[0],
            $args[1],
            $args[2],
            $assoc_args['content'] ?? ''
        );

        if (isset($result['error'])) {
            WP_CLI::error($result['error']);
        } else {
            WP_CLI::success("Card created! ID: " . $result['id']);
            WP_CLI::line("URL: " . $result['app_url']);
        }
    }

    /**
     * Show portfolio overview
     *
     * ## EXAMPLES
     *     wp bc overview
     */
    public function overview() {
        WP_CLI::line("\n📊 Analyzing portfolio... this may take a moment...\n");

        $portfolio = $this->bc->analyze_portfolio();

        WP_CLI::line("═══ PORTFOLIO OVERVIEW ═══");
        WP_CLI::line("");
        WP_CLI::line("Total Projects: " . $portfolio['total_projects']);
        WP_CLI::line("Total Cards: " . $portfolio['total_cards']);
        WP_CLI::line("Overdue Cards: " . $portfolio['total_overdue']);

        if (!empty($portfolio['by_assignee'])) {
            WP_CLI::line("\n👥 TOP CONTRIBUTORS:");
            arsort($portfolio['by_assignee']);
            $top = array_slice($portfolio['by_assignee'], 0, 10, true);

            foreach ($top as $name => $count) {
                WP_CLI::line(sprintf("   %-20s %d cards", $name, $count));
            }
        }

        if (!empty($portfolio['projects_health'])) {
            WP_CLI::line("\n🏥 PROJECT HEALTH:");

            // Sort by completion rate
            uasort($portfolio['projects_health'], function($a, $b) {
                return ($b['completion_rate'] ?? 0) - ($a['completion_rate'] ?? 0);
            });

            foreach ($portfolio['projects_health'] as $name => $stats) {
                $symbol = $stats['overdue_count'] > 0 ? "⚠️ " : "✅ ";
                WP_CLI::line($symbol . sprintf("%-30s %d cards (%d%% complete)",
                    substr($name, 0, 30),
                    $stats['total_cards'],
                    $stats['completion_rate']
                ));
            }
        }
    }
}

// Register commands
if (defined('WP_CLI') && WP_CLI) {
    WP_CLI::add_command('bc', 'Basecamp_Pro_CLI');
}