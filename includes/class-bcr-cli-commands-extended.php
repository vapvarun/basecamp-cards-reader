<?php
/**
 * Extended WP-CLI Commands for Basecamp Cards Reader
 * Full API coverage for all Basecamp endpoints
 *
 * @package Basecamp_Cards_Reader
 * @since 2.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Full Basecamp API integration via WP-CLI
 */
class BCR_CLI_Commands_Extended {

    /**
     * Basecamp API instance
     */
    private $api;

    /**
     * Quiet mode - suppress all non-essential output
     */
    private $quiet = false;

    /**
     * Constructor
     */
    public function __construct() {
        $this->init_api();
        // Enable quiet mode by default for professional output
        $this->quiet = true;
    }

    /**
     * Initialize API
     */
    private function init_api() {
        $token_data = get_option('bcr_token_data', []);
        if (!empty($token_data['access_token'])) {
            $this->api = new Basecamp_API($token_data['access_token']);
            // Auto-detect account ID
            $this->api->get_account_id();
        }
    }

    /**
     * Helper method for output (respects quiet mode)
     */
    private function output($message, $type = 'line') {
        if ($this->quiet && $type !== 'error') {
            return;
        }

        switch ($type) {
            case 'success':
                WP_CLI::success($message);
                break;
            case 'warning':
                WP_CLI::warning($message);
                break;
            case 'error':
                WP_CLI::error($message);
                break;
            case 'line':
            default:
                WP_CLI::line($message);
                break;
        }
    }

    /**
     * Check if API is configured
     */
    private function check_auth() {
        if (!$this->api || !$this->api->access_token) {
            WP_CLI::error('Basecamp authentication not configured. Run: wp bcr auth setup');
            return false;
        }

        // Check and refresh token if needed
        if (!$this->api->ensure_fresh_token()) {
            WP_CLI::error('Failed to refresh expired token. Please re-authenticate.');
            return false;
        }

        return true;
    }

    /* ===========================
     * PROJECTS COMMANDS
     * =========================== */

    /**
     * Manage projects
     *
     * ## OPTIONS
     *
     * <command>
     * : The subcommand to run (list|get|create|update|trash)
     *
     * [--id=<project_id>]
     * : Project ID (required for get, update, trash)
     *
     * [--name=<name>]
     * : Project name (required for create)
     *
     * [--description=<description>]
     * : Project description
     *
     * [--status=<status>]
     * : Filter by status (archived|trashed)
     *
     * [--page=<page>]
     * : Page number for pagination
     *
     * [--format=<format>]
     * : Output format (table|json|csv|yaml)
     * ---
     * default: table
     * ---
     *
     * ## EXAMPLES
     *
     *     # List all active projects
     *     wp bcr project list
     *
     *     # List archived projects
     *     wp bcr project list --status=archived
     *
     *     # Get specific project details
     *     wp bcr project get --id=12345678
     *
     *     # Create new project
     *     wp bcr project create --name="New Project" --description="Project description"
     *
     *     # Update project
     *     wp bcr project update --id=12345678 --name="Updated Name"
     *
     *     # Trash project
     *     wp bcr project trash --id=12345678
     *
     * @when after_wp_load
     */
    public function project($args, $assoc_args) {
        if (!$this->check_auth()) return;

        $command = $args[0] ?? 'list';
        $format = $assoc_args['format'] ?? 'table';

        switch ($command) {
            case 'list':
                $status = $assoc_args['status'] ?? null;
                $page = $assoc_args['page'] ?? 1;

                $response = $this->api->get_projects($status, $page);

                if (!empty($response['error'])) {
                    WP_CLI::error($response['message']);
                    return;
                }

                $projects = $response['data'] ?? [];

                if (empty($projects)) {
                    WP_CLI::line('No projects found.');
                    return;
                }

                // Format output
                $output_data = [];
                foreach ($projects as $project) {
                    $output_data[] = [
                        'ID' => $project['id'],
                        'Name' => $project['name'],
                        'Description' => substr($project['description'] ?? '', 0, 50),
                        'Status' => $project['status'],
                        'Created' => date('Y-m-d', strtotime($project['created_at'])),
                        'Updated' => date('Y-m-d', strtotime($project['updated_at'])),
                    ];
                }

                WP_CLI\Utils\format_items($format, $output_data, ['ID', 'Name', 'Description', 'Status', 'Created', 'Updated']);
                break;

            case 'get':
                $project_id = $assoc_args['id'] ?? null;
                if (!$project_id) {
                    WP_CLI::error('Project ID is required. Use --id=PROJECT_ID');
                    return;
                }

                $response = $this->api->get_project($project_id);

                if (!empty($response['error'])) {
                    WP_CLI::error($response['message']);
                    return;
                }

                $project = $response['data'];

                if ($format === 'json') {
                    WP_CLI::line(json_encode($project, JSON_PRETTY_PRINT));
                } else {
                    WP_CLI::line("\n=== PROJECT DETAILS ===");
                    WP_CLI::line("ID: " . $project['id']);
                    WP_CLI::line("Name: " . $project['name']);
                    WP_CLI::line("Description: " . ($project['description'] ?? 'N/A'));
                    WP_CLI::line("Status: " . $project['status']);
                    WP_CLI::line("Created: " . $project['created_at']);
                    WP_CLI::line("Updated: " . $project['updated_at']);
                    WP_CLI::line("URL: " . $project['app_url']);
                }
                break;

            case 'create':
                $name = $assoc_args['name'] ?? null;
                if (!$name) {
                    WP_CLI::error('Project name is required. Use --name="Project Name"');
                    return;
                }

                $description = $assoc_args['description'] ?? '';

                $response = $this->api->create_project($name, $description);

                if (!empty($response['error']) || $response['code'] !== 201) {
                    WP_CLI::error('Failed to create project: ' . ($response['message'] ?? 'Unknown error'));
                    return;
                }

                WP_CLI::success("Project created successfully! ID: " . $response['data']['id']);
                break;

            case 'update':
                $project_id = $assoc_args['id'] ?? null;
                if (!$project_id) {
                    WP_CLI::error('Project ID is required. Use --id=PROJECT_ID');
                    return;
                }

                $name = $assoc_args['name'] ?? null;
                $description = isset($assoc_args['description']) ? $assoc_args['description'] : null;

                $response = $this->api->update_project($project_id, $name, $description);

                if (!empty($response['error']) || $response['code'] !== 200) {
                    WP_CLI::error('Failed to update project');
                    return;
                }

                WP_CLI::success("Project updated successfully!");
                break;

            case 'trash':
                $project_id = $assoc_args['id'] ?? null;
                if (!$project_id) {
                    WP_CLI::error('Project ID is required. Use --id=PROJECT_ID');
                    return;
                }

                WP_CLI::confirm("Are you sure you want to trash project $project_id?", $assoc_args);

                $response = $this->api->trash_project($project_id);

                if (!empty($response['error']) || $response['code'] !== 204) {
                    WP_CLI::error('Failed to trash project');
                    return;
                }

                WP_CLI::success("Project trashed successfully!");
                break;

            default:
                WP_CLI::error("Unknown command: $command. Use list|get|create|update|trash");
        }
    }

    /* ===========================
     * CARD TABLE COMMANDS
     * =========================== */

    /**
     * Manage card tables and cards
     *
     * ## OPTIONS
     *
     * <command>
     * : The subcommand (list-columns|list-cards|get-card|create-card|update-card|move-card|trash-card)
     *
     * [--project=<project_id>]
     * : Project ID (required)
     *
     * [--table=<table_id>]
     * : Card table ID
     *
     * [--column=<column_id>]
     * : Column ID
     *
     * [--card=<card_id>]
     * : Card ID
     *
     * [--title=<title>]
     * : Card/Column title
     *
     * [--content=<content>]
     * : Card content/description
     *
     * [--due-on=<date>]
     * : Due date (YYYY-MM-DD)
     *
     * [--assignees=<user_ids>]
     * : Comma-separated user IDs
     *
     * [--to-column=<column_id>]
     * : Target column ID for move
     *
     * [--position=<position>]
     * : Position in column
     *
     * [--format=<format>]
     * : Output format
     * ---
     * default: table
     * ---
     *
     * ## EXAMPLES
     *
     *     # List columns in a card table
     *     wp bcr cards list-columns --project=12345678 --table=87654321
     *
     *     # List cards in a column
     *     wp bcr cards list-cards --project=12345678 --column=11111111
     *
     *     # Get specific card
     *     wp bcr cards get-card --project=12345678 --card=22222222
     *
     *     # Create new card
     *     wp bcr cards create-card --project=12345678 --column=11111111 --title="New Card" --content="Card description"
     *
     *     # Move card to different column
     *     wp bcr cards move-card --project=12345678 --card=22222222 --to-column=33333333
     *
     *     # Update card with assignees
     *     wp bcr cards update-card --project=12345678 --card=22222222 --title="Updated" --assignees=123,456
     *
     * @when after_wp_load
     */
    public function cards($args, $assoc_args) {
        if (!$this->check_auth()) return;

        $command = $args[0] ?? 'list-columns';
        $project_id = $assoc_args['project'] ?? null;

        if (!$project_id) {
            WP_CLI::error('Project ID is required. Use --project=PROJECT_ID');
            return;
        }

        $format = $assoc_args['format'] ?? 'table';

        switch ($command) {
            case 'list-columns':
                $table_id = $assoc_args['table'] ?? null;
                if (!$table_id) {
                    WP_CLI::error('Card table ID is required. Use --table=TABLE_ID');
                    return;
                }

                $response = $this->api->get_columns($project_id, $table_id);

                if (!empty($response['error'])) {
                    WP_CLI::error('Failed to fetch columns');
                    return;
                }

                $columns = $response['data'] ?? [];

                if (empty($columns)) {
                    WP_CLI::line('No columns found.');
                    return;
                }

                $output_data = [];
                foreach ($columns as $column) {
                    $output_data[] = [
                        'ID' => $column['id'],
                        'Title' => $column['title'],
                        'Color' => $column['color'] ?? 'none',
                        'Cards' => $column['cards_count'] ?? 0,
                        'Position' => $column['position'],
                    ];
                }

                WP_CLI\Utils\format_items($format, $output_data, ['ID', 'Title', 'Color', 'Cards', 'Position']);
                break;

            case 'list-cards':
                $column_id = $assoc_args['column'] ?? null;
                if (!$column_id) {
                    WP_CLI::error('Column ID is required. Use --column=COLUMN_ID');
                    return;
                }

                $response = $this->api->get_cards($project_id, $column_id);

                if (!empty($response['error'])) {
                    WP_CLI::error('Failed to fetch cards');
                    return;
                }

                $cards = $response['data'] ?? [];

                if (empty($cards)) {
                    WP_CLI::line('No cards found in this column.');
                    return;
                }

                $output_data = [];
                foreach ($cards as $card) {
                    $output_data[] = [
                        'ID' => $card['id'],
                        'Title' => $card['title'],
                        'Due' => $card['due_on'] ?? 'N/A',
                        'Assignees' => count($card['assignees'] ?? []),
                        'Completed' => $card['completed'] ? 'Yes' : 'No',
                        'Comments' => $card['comments_count'] ?? 0,
                    ];
                }

                WP_CLI\Utils\format_items($format, $output_data, ['ID', 'Title', 'Due', 'Assignees', 'Completed', 'Comments']);
                break;

            case 'get-card':
                $card_id = $assoc_args['card'] ?? null;
                if (!$card_id) {
                    WP_CLI::error('Card ID is required. Use --card=CARD_ID');
                    return;
                }

                $response = $this->api->get_card($project_id, $card_id);

                if (!empty($response['error'])) {
                    WP_CLI::error('Failed to fetch card');
                    return;
                }

                $card = $response['data'];

                if ($format === 'json') {
                    WP_CLI::line(json_encode($card, JSON_PRETTY_PRINT));
                } else {
                    WP_CLI::line("\n=== CARD DETAILS ===");
                    WP_CLI::line("ID: " . $card['id']);
                    WP_CLI::line("Title: " . $card['title']);
                    WP_CLI::line("Content: " . strip_tags($card['content'] ?? ''));
                    WP_CLI::line("Due Date: " . ($card['due_on'] ?? 'Not set'));
                    WP_CLI::line("Completed: " . ($card['completed'] ? 'Yes' : 'No'));
                    WP_CLI::line("Comments: " . ($card['comments_count'] ?? 0));

                    if (!empty($card['assignees'])) {
                        WP_CLI::line("\nAssignees:");
                        foreach ($card['assignees'] as $assignee) {
                            WP_CLI::line("  - " . $assignee['name']);
                        }
                    }

                    WP_CLI::line("\nURL: " . $card['app_url']);
                }
                break;

            case 'create-card':
                $column_id = $assoc_args['column'] ?? null;
                $title = $assoc_args['title'] ?? null;

                if (!$column_id || !$title) {
                    WP_CLI::error('Column ID and title are required. Use --column=COLUMN_ID --title="Card Title"');
                    return;
                }

                $content = $assoc_args['content'] ?? '';
                $due_on = $assoc_args['due-on'] ?? null;
                $assignee_ids = !empty($assoc_args['assignees']) ?
                    array_map('intval', explode(',', $assoc_args['assignees'])) : [];

                $response = $this->api->create_card($project_id, $column_id, $title, $content, $due_on, $assignee_ids);

                if (!empty($response['error']) || $response['code'] !== 201) {
                    WP_CLI::error('Failed to create card');
                    return;
                }

                WP_CLI::success("Card created successfully! ID: " . $response['data']['id']);
                break;

            case 'update-card':
                $card_id = $assoc_args['card'] ?? null;
                if (!$card_id) {
                    WP_CLI::error('Card ID is required. Use --card=CARD_ID');
                    return;
                }

                $data = [];
                if (isset($assoc_args['title'])) $data['title'] = $assoc_args['title'];
                if (isset($assoc_args['content'])) $data['content'] = $assoc_args['content'];
                if (isset($assoc_args['due-on'])) $data['due_on'] = $assoc_args['due-on'];
                if (isset($assoc_args['assignees'])) {
                    $data['assignee_ids'] = array_map('intval', explode(',', $assoc_args['assignees']));
                }
                if (isset($assoc_args['completed'])) {
                    $data['completed'] = filter_var($assoc_args['completed'], FILTER_VALIDATE_BOOLEAN);
                }

                $response = $this->api->update_card($project_id, $card_id, $data);

                if (!empty($response['error']) || $response['code'] !== 200) {
                    WP_CLI::error('Failed to update card');
                    return;
                }

                WP_CLI::success("Card updated successfully!");
                break;

            case 'move-card':
                $card_id = $assoc_args['card'] ?? null;
                $to_column = $assoc_args['to-column'] ?? null;

                if (!$card_id || !$to_column) {
                    WP_CLI::error('Card ID and target column are required. Use --card=CARD_ID --to-column=COLUMN_ID');
                    return;
                }

                $position = $assoc_args['position'] ?? null;

                $response = $this->api->move_card($project_id, $card_id, $to_column, $position);

                if (!empty($response['error']) || ($response['code'] !== 200 && $response['code'] !== 204)) {
                    $error_msg = 'Failed to move card';
                    if (!empty($response['error'])) {
                        $error_msg .= ': ' . $response['error'];
                    } else if (isset($response['code'])) {
                        $error_msg .= ' (HTTP ' . $response['code'] . ')';
                    }
                    WP_CLI::error($error_msg);
                    return;
                }

                WP_CLI::success("Card moved successfully!");
                break;

            case 'trash-card':
                $card_id = $assoc_args['card'] ?? null;
                if (!$card_id) {
                    WP_CLI::error('Card ID is required. Use --card=CARD_ID');
                    return;
                }

                WP_CLI::confirm("Are you sure you want to trash card $card_id?", $assoc_args);

                $response = $this->api->trash_card($project_id, $card_id);

                if (!empty($response['error']) || $response['code'] !== 204) {
                    WP_CLI::error('Failed to trash card');
                    return;
                }

                WP_CLI::success("Card trashed successfully!");
                break;

            default:
                WP_CLI::error("Unknown command: $command");
        }
    }

    /* ===========================
     * STEPS COMMANDS
     * =========================== */

    /**
     * Manage card steps
     *
     * ## OPTIONS
     *
     * <command>
     * : The subcommand (list|create|update|complete|uncomplete|reorder)
     *
     * [--project=<project_id>]
     * : Project ID (required)
     *
     * [--card=<card_id>]
     * : Card ID (required for most operations)
     *
     * [--step=<step_id>]
     * : Step ID
     *
     * [--title=<title>]
     * : Step title
     *
     * [--completed=<bool>]
     * : Mark as completed (true/false)
     *
     * [--order=<step_ids>]
     * : Comma-separated step IDs for reordering
     *
     * ## EXAMPLES
     *
     *     # List steps on a card
     *     wp bcr steps list --project=12345678 --card=87654321
     *
     *     # Create new step
     *     wp bcr steps create --project=12345678 --card=87654321 --title="First step"
     *
     *     # Complete a step
     *     wp bcr steps complete --project=12345678 --step=11111111
     *
     *     # Reorder steps
     *     wp bcr steps reorder --project=12345678 --card=87654321 --order=111,222,333
     *
     * @when after_wp_load
     */
    public function steps($args, $assoc_args) {
        if (!$this->check_auth()) return;

        $command = $args[0] ?? 'list';
        $project_id = $assoc_args['project'] ?? null;

        if (!$project_id) {
            WP_CLI::error('Project ID is required. Use --project=PROJECT_ID');
            return;
        }

        switch ($command) {
            case 'list':
                $card_id = $assoc_args['card'] ?? null;
                if (!$card_id) {
                    WP_CLI::error('Card ID is required. Use --card=CARD_ID');
                    return;
                }

                $response = $this->api->get_steps($project_id, $card_id);

                if (!empty($response['error'])) {
                    WP_CLI::error('Failed to fetch steps');
                    return;
                }

                $steps = $response['data'] ?? [];

                if (empty($steps)) {
                    WP_CLI::line('No steps found for this card.');
                    return;
                }

                $output_data = [];
                foreach ($steps as $step) {
                    $output_data[] = [
                        'ID' => $step['id'],
                        'Title' => $step['title'],
                        'Completed' => $step['completed'] ? '✓' : '○',
                        'Position' => $step['position'] ?? 'N/A',
                    ];
                }

                WP_CLI\Utils\format_items('table', $output_data, ['ID', 'Title', 'Completed', 'Position']);
                break;

            case 'create':
                $card_id = $assoc_args['card'] ?? null;
                $title = $assoc_args['title'] ?? null;

                if (!$card_id || !$title) {
                    WP_CLI::error('Card ID and title are required. Use --card=CARD_ID --title="Step title"');
                    return;
                }

                $response = $this->api->create_step($project_id, $card_id, $title);

                if (!empty($response['error']) || $response['code'] !== 201) {
                    WP_CLI::error('Failed to create step');
                    return;
                }

                WP_CLI::success("Step created successfully! ID: " . $response['data']['id']);
                break;

            case 'complete':
                $step_id = $assoc_args['step'] ?? null;
                if (!$step_id) {
                    WP_CLI::error('Step ID is required. Use --step=STEP_ID');
                    return;
                }

                $response = $this->api->complete_step($project_id, $step_id);

                if (!empty($response['error']) || $response['code'] !== 200) {
                    WP_CLI::error('Failed to complete step');
                    return;
                }

                WP_CLI::success("Step marked as completed!");
                break;

            case 'uncomplete':
                $step_id = $assoc_args['step'] ?? null;
                if (!$step_id) {
                    WP_CLI::error('Step ID is required. Use --step=STEP_ID');
                    return;
                }

                $response = $this->api->uncomplete_step($project_id, $step_id);

                if (!empty($response['error']) || $response['code'] !== 200) {
                    WP_CLI::error('Failed to uncomplete step');
                    return;
                }

                WP_CLI::success("Step marked as uncompleted!");
                break;

            case 'reorder':
                $card_id = $assoc_args['card'] ?? null;
                $order = $assoc_args['order'] ?? null;

                if (!$card_id || !$order) {
                    WP_CLI::error('Card ID and order are required. Use --card=CARD_ID --order=111,222,333');
                    return;
                }

                $step_ids = array_map('intval', explode(',', $order));

                $response = $this->api->reorder_steps($project_id, $card_id, $step_ids);

                if (!empty($response['error']) || $response['code'] !== 200) {
                    WP_CLI::error('Failed to reorder steps');
                    return;
                }

                WP_CLI::success("Steps reordered successfully!");
                break;

            default:
                WP_CLI::error("Unknown command: $command");
        }
    }

    /* ===========================
     * PEOPLE COMMANDS
     * =========================== */

    /**
     * Manage people and users
     *
     * ## OPTIONS
     *
     * <command>
     * : The subcommand (list|get|project-people|pingable)
     *
     * [--id=<person_id>]
     * : Person ID
     *
     * [--project=<project_id>]
     * : Project ID
     *
     * [--page=<page>]
     * : Page number
     *
     * ## EXAMPLES
     *
     *     # List all people in account
     *     wp bcr people list
     *
     *     # Get specific person details
     *     wp bcr people get --id=123456
     *
     *     # List people on a project
     *     wp bcr people project-people --project=87654321
     *
     *     # List pingable people
     *     wp bcr people pingable
     *
     * @when after_wp_load
     */
    public function people($args, $assoc_args) {
        if (!$this->check_auth()) return;

        $command = $args[0] ?? 'list';
        $page = $assoc_args['page'] ?? 1;

        switch ($command) {
            case 'list':
                $response = $this->api->get_people($page);

                if (!empty($response['error'])) {
                    WP_CLI::error('Failed to fetch people');
                    return;
                }

                $people = $response['data'] ?? [];

                if (empty($people)) {
                    WP_CLI::line('No people found.');
                    return;
                }

                $output_data = [];
                foreach ($people as $person) {
                    $output_data[] = [
                        'ID' => $person['id'],
                        'Name' => $person['name'],
                        'Email' => $person['email_address'],
                        'Title' => $person['title'] ?? 'N/A',
                        'Admin' => $person['admin'] ? 'Yes' : 'No',
                    ];
                }

                WP_CLI\Utils\format_items('table', $output_data, ['ID', 'Name', 'Email', 'Title', 'Admin']);
                break;

            case 'get':
                $person_id = $assoc_args['id'] ?? null;
                if (!$person_id) {
                    WP_CLI::error('Person ID is required. Use --id=PERSON_ID');
                    return;
                }

                $response = $this->api->get_person($person_id);

                if (!empty($response['error'])) {
                    WP_CLI::error('Failed to fetch person');
                    return;
                }

                $person = $response['data'];

                WP_CLI::line("\n=== PERSON DETAILS ===");
                WP_CLI::line("ID: " . $person['id']);
                WP_CLI::line("Name: " . $person['name']);
                WP_CLI::line("Email: " . $person['email_address']);
                WP_CLI::line("Title: " . ($person['title'] ?? 'N/A'));
                WP_CLI::line("Admin: " . ($person['admin'] ? 'Yes' : 'No'));
                WP_CLI::line("Owner: " . ($person['owner'] ? 'Yes' : 'No'));
                WP_CLI::line("Created: " . $person['created_at']);
                break;

            case 'project-people':
                $project_id = $assoc_args['project'] ?? null;
                if (!$project_id) {
                    WP_CLI::error('Project ID is required. Use --project=PROJECT_ID');
                    return;
                }

                $response = $this->api->get_project_people($project_id, $page);

                if (!empty($response['error'])) {
                    WP_CLI::error('Failed to fetch project people');
                    return;
                }

                $people = $response['data'] ?? [];

                if (empty($people)) {
                    WP_CLI::line('No people found on this project.');
                    return;
                }

                $output_data = [];
                foreach ($people as $person) {
                    $output_data[] = [
                        'ID' => $person['id'],
                        'Name' => $person['name'],
                        'Email' => $person['email_address'],
                        'Admin' => $person['admin'] ? 'Yes' : 'No',
                    ];
                }

                WP_CLI\Utils\format_items('table', $output_data, ['ID', 'Name', 'Email', 'Admin']);
                break;

            case 'pingable':
                $response = $this->api->get_pingable_people($page);

                if (!empty($response['error'])) {
                    WP_CLI::error('Failed to fetch pingable people');
                    return;
                }

                $people = $response['data'] ?? [];

                if (empty($people)) {
                    WP_CLI::line('No pingable people found.');
                    return;
                }

                $output_data = [];
                foreach ($people as $person) {
                    $output_data[] = [
                        'ID' => $person['id'],
                        'Name' => $person['name'],
                        'Email' => $person['email_address'],
                    ];
                }

                WP_CLI\Utils\format_items('table', $output_data, ['ID', 'Name', 'Email']);
                break;

            default:
                WP_CLI::error("Unknown command: $command");
        }
    }

    /* ===========================
     * ACTIVITY/EVENTS COMMANDS
     * =========================== */

    /**
     * View activity and events
     *
     * ## OPTIONS
     *
     * <type>
     * : Event type (all|project|recording)
     *
     * [--project=<project_id>]
     * : Project ID
     *
     * [--recording=<recording_id>]
     * : Recording ID
     *
     * [--since=<datetime>]
     * : Show events since (ISO 8601)
     *
     * [--page=<page>]
     * : Page number
     *
     * ## EXAMPLES
     *
     *     # Show all recent events
     *     wp bcr events all
     *
     *     # Show project events
     *     wp bcr events project --project=12345678
     *
     *     # Show events since yesterday
     *     wp bcr events all --since=2024-01-01T00:00:00Z
     *
     * @when after_wp_load
     */
    public function events($args, $assoc_args) {
        if (!$this->check_auth()) return;

        $type = $args[0] ?? 'all';
        $page = $assoc_args['page'] ?? 1;
        $since = $assoc_args['since'] ?? null;

        switch ($type) {
            case 'all':
                $response = $this->api->get_events($page, $since);
                break;

            case 'project':
                $project_id = $assoc_args['project'] ?? null;
                if (!$project_id) {
                    WP_CLI::error('Project ID is required. Use --project=PROJECT_ID');
                    return;
                }
                $response = $this->api->get_project_events($project_id, $page, $since);
                break;

            case 'recording':
                $project_id = $assoc_args['project'] ?? null;
                $recording_id = $assoc_args['recording'] ?? null;

                if (!$project_id || !$recording_id) {
                    WP_CLI::error('Project ID and recording ID are required');
                    return;
                }

                $response = $this->api->get_recording_events($project_id, $recording_id, $page);
                break;

            default:
                WP_CLI::error("Unknown event type: $type");
                return;
        }

        if (!empty($response['error'])) {
            WP_CLI::error('Failed to fetch events');
            return;
        }

        $events = $response['data'] ?? [];

        if (empty($events)) {
            WP_CLI::line('No events found.');
            return;
        }

        $output_data = [];
        foreach ($events as $event) {
            $output_data[] = [
                'Time' => date('Y-m-d H:i', strtotime($event['created_at'])),
                'Action' => $event['action'],
                'Creator' => $event['creator']['name'] ?? 'System',
                'Summary' => substr($event['excerpt'] ?? '', 0, 50),
            ];
        }

        WP_CLI\Utils\format_items('table', $output_data, ['Time', 'Action', 'Creator', 'Summary']);
    }

    /* ===========================
     * PROJECT CARDS MANAGEMENT
     * =========================== */

    /**
     * Get all cards from a SPECIFIC PROJECT with complete summary
     *
     * This command analyzes cards in a single project only.
     * For portfolio-wide analysis, use 'wp bcr overview'.
     *
     * ## OPTIONS
     *
     * <project_id>
     * : The project ID (required - this is project-specific)
     *
     * [--table=<table_id>]
     * : Card table ID (will auto-detect if not provided)
     *
     * [--format=<format>]
     * : Output format (summary|full|json|csv)
     * ---
     * default: summary
     * ---
     *
     * [--export=<file>]
     * : Export to file
     *
     * ## EXAMPLES
     *
     *     # Get card summary for a specific project
     *     wp bcr project-cards 37594969
     *
     *     # Get full card details for a project
     *     wp bcr project-cards 37594969 --format=full
     *
     *     # Export project cards to JSON
     *     wp bcr project-cards 37594969 --format=json --export=cards.json
     *
     * @subcommand project-cards
     * @when after_wp_load
     */
    public function project_cards($args, $assoc_args) {
        if (!$this->check_auth()) return;

        $project_id = $args[0] ?? null;
        if (!$project_id) {
            WP_CLI::error('Project ID is required');
            return;
        }

        $format = $assoc_args['format'] ?? 'summary';
        $export_file = $assoc_args['export'] ?? null;

        // Auto-detect account ID
        $this->api->get_account_id();

        WP_CLI::log("Fetching project details...");

        // Get project details first
        $project_response = $this->api->get_project($project_id);
        if (!empty($project_response['error'])) {
            WP_CLI::error('Failed to fetch project: ' . $project_response['message']);
            return;
        }

        $project = $project_response['data'];
        WP_CLI::log("\nProject: " . $project['name']);
        WP_CLI::log("Description: " . ($project['description'] ?? 'N/A'));
        WP_CLI::log(str_repeat("=", 60) . "\n");

        // Find card table
        $card_table_id = $assoc_args['table'] ?? null;
        if (!$card_table_id) {
            foreach ($project['dock'] as $tool) {
                if ($tool['name'] === 'card_table' || $tool['name'] === 'kanban_board') {
                    $card_table_id = $tool['id'];
                    break;
                }
            }
        }

        if (!$card_table_id) {
            WP_CLI::error('No card table found in this project');
            return;
        }

        // Discover columns by scanning ID range
        WP_CLI::log("Discovering columns...");
        $columns = [];
        $all_cards = [];

        // Scan for columns (typical range based on Basecamp patterns)
        $base_id = intval($card_table_id);
        for ($id = $base_id + 10; $id <= $base_id + 30; $id++) {
            $response = $this->api->get("/{$this->api->account_id}/buckets/{$project_id}/card_tables/lists/{$id}.json");
            if ($response['code'] === 200) {
                $columns[$id] = $response['data'];
            }
        }

        // Sort columns by position
        uasort($columns, function($a, $b) { return $a['position'] - $b['position']; });

        WP_CLI::log("Found " . count($columns) . " columns\n");

        // Fetch cards from each column
        foreach ($columns as $col_id => $column) {
            $cards_response = $this->api->get("/{$this->api->account_id}/buckets/{$project_id}/card_tables/lists/{$col_id}/cards.json");

            if ($cards_response['code'] === 200 && !empty($cards_response['data'])) {
                foreach ($cards_response['data'] as $card) {
                    $card['column_name'] = $column['title'];
                    $card['column_id'] = $col_id;
                    $all_cards[] = $card;
                }
            }
        }

        // Display based on format
        switch ($format) {
            case 'summary':
                $this->display_cards_summary($columns, $all_cards);
                break;

            case 'full':
                $this->display_cards_full($columns, $all_cards, $project_id);
                break;

            case 'json':
                $output = [
                    'project' => $project,
                    'columns' => array_values($columns),
                    'cards' => $all_cards,
                    'statistics' => $this->calculate_statistics($all_cards)
                ];

                if ($export_file) {
                    file_put_contents($export_file, json_encode($output, JSON_PRETTY_PRINT));
                    WP_CLI::success("Exported to $export_file");
                } else {
                    WP_CLI::log(json_encode($output, JSON_PRETTY_PRINT));
                }
                break;

            case 'csv':
                if (!$export_file) {
                    WP_CLI::error('CSV format requires --export parameter');
                    return;
                }

                $this->export_cards_csv($all_cards, $export_file, $project_id);
                WP_CLI::success("CSV exported to $export_file");
                break;
        }
    }

    /**
     * List columns in a project (simple version - just needs project ID)
     *
     * ## OPTIONS
     *
     * <project_id>
     * : The project ID or name
     *
     * [--format=<format>]
     * : Output format (table, json, csv)
     *
     * [--no-cache]
     * : Bypass cache and fetch fresh data from API
     *
     * [--fresh]
     * : Alias for --no-cache
     *
     * ## EXAMPLES
     *
     *     wp bcr columns 37594834
     *     wp bcr columns "BuddyPress Business Profile"
     *     wp bcr columns 37594834 --format=json
     *     wp bcr columns 37594834 --no-cache
     *     wp bcr columns 37594834 --fresh
     *
     * @subcommand columns
     * @when after_wp_load
     */
    public function columns($args, $assoc_args) {
        if (!$this->check_auth()) return;

        $project_identifier = $args[0] ?? null;
        if (!$project_identifier) {
            WP_CLI::error('Project ID or name is required');
            return;
        }

        $format = $assoc_args['format'] ?? 'table';

        // Check for cache bypass in various forms (WP-CLI parameter parsing can be tricky)
        $no_cache = isset($assoc_args['no-cache']) ||
                   isset($assoc_args['fresh']) ||
                   isset($assoc_args['nocache']) ||
                   in_array('--no-cache', $args) ||
                   in_array('--fresh', $args);

        // Resolve project
        $automation = new Basecamp_Automation();

        // Set cache bypass mode if requested
        if ($no_cache) {
            Basecamp_Automation::$bypass_cache = true;
            $automation->clear_cache();
            WP_CLI::line("🔄 Bypassing cache - fetching fresh data...");
        }

        $project_id = $automation->resolve_project($project_identifier);

        if (!$project_id) {
            WP_CLI::error("Project not found: {$project_identifier}");
            return;
        }

        $project = $automation->get_project($project_id);
        WP_CLI::line("📁 Project: {$project['name']}");
        WP_CLI::line("🆔 ID: {$project_id}");
        WP_CLI::line('');

        // Discover columns
        WP_CLI::log("Discovering columns...");
        $columns = $automation->discover_columns($project_id);

        if (empty($columns)) {
            WP_CLI::warning('No columns found in this project');
            return;
        }

        // Sort by position
        uasort($columns, function($a, $b) {
            return ($a['position'] ?? 999) - ($b['position'] ?? 999);
        });

        $output = [];
        foreach ($columns as $col_id => $column) {
            // Get card count
            $account_id = get_option('basecamp_account_id', '');
            if (!$account_id) {
                $account_id = $this->api->get_account_id();
            }

            $cards_response = $this->api->get("/{$account_id}/buckets/{$project_id}/card_tables/lists/{$col_id}/cards.json");
            $card_count = 0;
            if ($cards_response['code'] === 200) {
                $card_count = count($cards_response['data'] ?? []);
            }

            $column_type = $this->get_column_type($column['title']);
            $emoji = $this->get_column_emoji($column_type);

            $output[] = [
                'ID' => $col_id,
                'Name' => $column['title'],
                'Type' => $emoji . ' ' . $column_type,
                'Cards' => $card_count,
                'Position' => $column['position'] ?? 999
            ];
        }

        if ($format === 'json') {
            WP_CLI::line(json_encode($columns, JSON_PRETTY_PRINT));
        } elseif ($format === 'csv') {
            WP_CLI\Utils\format_items('csv', $output, ['ID', 'Name', 'Type', 'Cards', 'Position']);
        } else {
            WP_CLI\Utils\format_items('table', $output, ['ID', 'Name', 'Type', 'Cards', 'Position']);
        }

        WP_CLI::line('');
        WP_CLI::success("Found " . count($columns) . " columns");
    }

    /**
     * Helper: Display cards summary
     */
    private function display_cards_summary($columns, $cards) {
        WP_CLI::log("📋 COLUMNS OVERVIEW:");
        WP_CLI::log(str_repeat("-", 40));

        foreach ($columns as $col_id => $column) {
            $cards_in_col = array_filter($cards, function($c) use ($col_id) {
                return $c['column_id'] == $col_id;
            });

            WP_CLI::log(sprintf("%-20s: %d cards", $column['title'], count($cards_in_col)));
        }

        WP_CLI::log("\n📊 STATISTICS:");
        WP_CLI::log(str_repeat("-", 40));

        $stats = $this->calculate_statistics($cards);

        WP_CLI::log("Total Cards: " . $stats['total']);
        WP_CLI::log("Open Cards: " . $stats['open']);
        WP_CLI::log("Completed: " . $stats['completed']);
        WP_CLI::log("Overdue: " . $stats['overdue']);
        WP_CLI::log("Unassigned: " . $stats['unassigned']);

        if (!empty($stats['by_assignee'])) {
            WP_CLI::log("\n👥 BY ASSIGNEE:");
            foreach ($stats['by_assignee'] as $name => $count) {
                WP_CLI::log("  $name: $count cards");
            }
        }
    }

    /**
     * Helper: Display full card details
     */
    private function display_cards_full($columns, $cards, $project_id) {
        foreach ($columns as $col_id => $column) {
            $cards_in_col = array_filter($cards, function($c) use ($col_id) {
                return $c['column_id'] == $col_id;
            });

            if (empty($cards_in_col)) continue;

            WP_CLI::log("\n═══ " . strtoupper($column['title']) . " ═══\n");

            foreach ($cards_in_col as $card) {
                WP_CLI::log("📌 " . $card['title']);
                WP_CLI::log("   ID: " . $card['id']);

                if (!empty($card['content'])) {
                    $content = strip_tags($card['content']);
                    if (strlen($content) > 100) {
                        $content = substr($content, 0, 100) . "...";
                    }
                    WP_CLI::log("   Description: " . $content);
                }

                if (!empty($card['assignees'])) {
                    $names = array_column($card['assignees'], 'name');
                    WP_CLI::log("   Assignees: " . implode(", ", $names));
                }

                if (!empty($card['due_on'])) {
                    WP_CLI::log("   Due: " . $card['due_on']);
                }

                WP_CLI::log("   Status: " . ($card['completed'] ? "✅ Completed" : "🔄 Open"));
                WP_CLI::log("   URL: https://3.basecamp.com/{$this->api->account_id}/buckets/$project_id/card_tables/cards/{$card['id']}");
                WP_CLI::log("");
            }
        }
    }

    /**
     * Helper: Calculate statistics
     */
    private function calculate_statistics($cards) {
        $stats = [
            'total' => count($cards),
            'open' => 0,
            'completed' => 0,
            'overdue' => 0,
            'unassigned' => 0,
            'by_assignee' => []
        ];

        $today = new DateTime();

        foreach ($cards as $card) {
            if ($card['completed'] ?? false) {
                $stats['completed']++;
            } else {
                $stats['open']++;

                if (!empty($card['due_on'])) {
                    $due = new DateTime($card['due_on']);
                    if ($due < $today) {
                        $stats['overdue']++;
                    }
                }
            }

            if (!empty($card['assignees'])) {
                foreach ($card['assignees'] as $assignee) {
                    $name = $assignee['name'];
                    $stats['by_assignee'][$name] = ($stats['by_assignee'][$name] ?? 0) + 1;
                }
            } else {
                $stats['unassigned']++;
            }
        }

        return $stats;
    }

    /**
     * Helper: Export cards to CSV
     */
    private function export_cards_csv($cards, $file, $project_id) {
        $fp = fopen($file, 'w');

        fputcsv($fp, ['ID', 'Title', 'Column', 'Status', 'Assignees', 'Due Date', 'Created', 'Updated', 'URL']);

        foreach ($cards as $card) {
            fputcsv($fp, [
                $card['id'],
                $card['title'],
                $card['column_name'] ?? '',
                $card['completed'] ? 'Completed' : 'Open',
                !empty($card['assignees']) ? implode(', ', array_column($card['assignees'], 'name')) : '',
                $card['due_on'] ?? '',
                date('Y-m-d', strtotime($card['created_at'])),
                date('Y-m-d', strtotime($card['updated_at'])),
                "https://3.basecamp.com/{$this->api->account_id}/buckets/$project_id/card_tables/cards/{$card['id']}"
            ]);
        }

        fclose($fp);
    }

    /* ===========================
     * QUICK ACCESS COMMANDS
     * =========================== */

    /**
     * Quick read any Basecamp URL
     *
     * ## OPTIONS
     *
     * <url>
     * : The Basecamp URL to read
     *
     * [--comments]
     * : Include comments
     *
     * [--format=<format>]
     * : Output format
     * ---
     * default: text
     * ---
     *
     * ## EXAMPLES
     *
     *     # Read a card
     *     wp bcr read https://3.basecamp.com/12345/buckets/67890/card_tables/cards/11111
     *
     *     # Read with comments
     *     wp bcr read https://3.basecamp.com/12345/buckets/67890/card_tables/cards/11111 --comments
     *
     * @when after_wp_load
     */
    public function read($args, $assoc_args) {
        if (!$this->check_auth()) return;

        $url = $args[0] ?? null;
        if (!$url) {
            WP_CLI::error('URL is required');
            return;
        }

        $parsed = Basecamp_API::parse_url($url);
        if (!$parsed) {
            WP_CLI::error('Invalid Basecamp URL');
            return;
        }

        $this->api->set_account_id($parsed['account_id']);

        switch ($parsed['type']) {
            case 'card':
                $response = $this->api->get_card($parsed['project_id'], $parsed['recording_id']);
                break;

            case 'todo':
                $response = $this->api->get_todo($parsed['project_id'], $parsed['recording_id']);
                break;

            case 'project':
                $response = $this->api->get_project($parsed['project_id']);
                break;

            default:
                WP_CLI::error('Unsupported URL type: ' . $parsed['type']);
                return;
        }

        if (!empty($response['error'])) {
            WP_CLI::error('Failed to fetch data: ' . $response['message']);
            return;
        }

        $data = $response['data'];

        if ($assoc_args['format'] ?? 'text' === 'json') {
            WP_CLI::line(json_encode($data, JSON_PRETTY_PRINT));
        } else {
            WP_CLI::line("\n=== " . strtoupper($parsed['type']) . " DETAILS ===");
            WP_CLI::line("Title: " . ($data['title'] ?? $data['name'] ?? 'N/A'));
            WP_CLI::line("Created: " . $data['created_at']);
            WP_CLI::line("Updated: " . $data['updated_at']);

            if (isset($data['content'])) {
                WP_CLI::line("\nContent:");
                WP_CLI::line(strip_tags($data['content']));
            }

            if (isset($assoc_args['comments'])) {
                $comments_response = $this->api->get_comments($parsed['project_id'], $parsed['recording_id']);

                if (!empty($comments_response['data'])) {
                    WP_CLI::line("\n=== COMMENTS ===");
                    foreach ($comments_response['data'] as $comment) {
                        WP_CLI::line("\n" . $comment['creator']['name'] . " (" . $comment['created_at'] . "):");
                        WP_CLI::line(strip_tags($comment['content']));
                    }
                }
            }
        }
    }

    /**
     * Post a comment to any Basecamp URL
     *
     * ## OPTIONS
     *
     * <url>
     * : The Basecamp URL to comment on
     *
     * <comment>
     * : The comment text
     *
     * ## EXAMPLES
     *
     *     wp bcr comment https://3.basecamp.com/12345/buckets/67890/card_tables/cards/11111 "This is resolved"
     *
     * @when after_wp_load
     */
    public function comment($args, $assoc_args) {
        if (!$this->check_auth()) return;

        $url = $args[0] ?? null;
        $comment = $args[1] ?? null;

        if (!$url || !$comment) {
            WP_CLI::error('URL and comment are required');
            return;
        }

        $parsed = Basecamp_API::parse_url($url);
        if (!$parsed) {
            WP_CLI::error('Invalid Basecamp URL');
            return;
        }

        $this->api->set_account_id($parsed['account_id']);

        $response = $this->api->create_comment($parsed['project_id'], $parsed['recording_id'], $comment);

        if (!empty($response['error']) || $response['code'] !== 201) {
            WP_CLI::error('Failed to post comment');
            return;
        }

        WP_CLI::success('Comment posted successfully!');
    }

    /**
     * Monitor system performance and health
     *
     * ## OPTIONS
     *
     * [--alerts]
     * : Check for system alerts
     *
     * [--report]
     * : Generate comprehensive monitoring report
     *
     * [--export]
     * : Export metrics data
     *
     * ## EXAMPLES
     *
     *     wp bcr monitor
     *     wp bcr monitor --alerts
     *     wp bcr monitor --report
     *
     * @when after_wp_load
     */
    public function monitor($args, $assoc_args) {
        $automation = new Basecamp_Automation();
        $logger = $automation->get_logger();

        if (isset($assoc_args['alerts'])) {
            WP_CLI::line('🔍 Checking system alerts...');
            $alerts = $logger->check_alerts();

            if (empty($alerts)) {
                WP_CLI::success('✅ No alerts detected - system healthy');
                return;
            }

            WP_CLI::warning('⚠️ ' . count($alerts) . ' alert(s) detected:');
            foreach ($alerts as $alert) {
                $level_emoji = $alert['level'] === 'critical' ? '🚨' : '⚠️';
                WP_CLI::line("  {$level_emoji} {$alert['message']}");
                if (!empty($alert['data'])) {
                    foreach ($alert['data'] as $key => $value) {
                        WP_CLI::line("    - {$key}: {$value}");
                    }
                }
            }
            return;
        }

        if (isset($assoc_args['report'])) {
            WP_CLI::line('📊 Generating monitoring report...');
            $report = $logger->generate_monitoring_report();

            WP_CLI::line('');
            WP_CLI::line('=== BASECAMP AUTOMATION MONITORING REPORT ===');
            WP_CLI::line('Generated: ' . $report['report_date']);
            WP_CLI::line('');

            WP_CLI::line('🏥 SYSTEM HEALTH');
            WP_CLI::line('  Status: ' . strtoupper($report['summary']['system_health']));
            WP_CLI::line('  Health Score: ' . $report['summary']['health_score'] . '/100');
            WP_CLI::line('');

            WP_CLI::line('📈 PERFORMANCE METRICS');
            WP_CLI::line('  API Calls Today: ' . $report['summary']['api_calls_today']);
            WP_CLI::line('  Average Response Time: ' . $report['summary']['average_response_time']);
            WP_CLI::line('  Error Rate: ' . $report['summary']['error_rate']);
            WP_CLI::line('');

            if (!empty($report['health_check']['issues'])) {
                WP_CLI::line('⚠️ ISSUES DETECTED');
                foreach ($report['health_check']['issues'] as $issue) {
                    WP_CLI::line('  - ' . $issue);
                }
                WP_CLI::line('');
            }

            WP_CLI::line('🔝 TOP API ENDPOINTS');
            $top_endpoints = array_slice($report['api_usage']['top_endpoints'], 0, 5, true);
            foreach ($top_endpoints as $endpoint => $count) {
                WP_CLI::line('  ' . $endpoint . ': ' . $count . ' calls');
            }

            return;
        }

        if (isset($assoc_args['export'])) {
            WP_CLI::line('📤 Exporting monitoring data...');
            $metrics = $logger->get_metrics();

            $export_file = '/tmp/basecamp-monitoring-' . date('Y-m-d-H-i-s') . '.json';
            file_put_contents($export_file, json_encode($metrics, JSON_PRETTY_PRINT));

            WP_CLI::success("✅ Monitoring data exported to: {$export_file}");
            return;
        }

        // Default: Show quick status
        $metrics = $logger->get_metrics();
        $health = $metrics['system_health'];

        $status_emoji = match($health['status']) {
            'excellent' => '💚',
            'good' => '💛',
            'warning' => '🧡',
            'critical' => '🔴',
            default => '❓'
        };

        WP_CLI::line('');
        WP_CLI::line('=== BASECAMP AUTOMATION STATUS ===');
        WP_CLI::line('');
        WP_CLI::line("{$status_emoji} System Health: " . strtoupper($health['status']) . " ({$health['score']}/100)");
        WP_CLI::line('📊 API Calls Today: ' . $metrics['performance']['api_calls_today']);
        WP_CLI::line('⚡ Avg Response Time: ' . round($metrics['api_usage']['average_response_time'], 3) . 's');
        WP_CLI::line('🚨 Error Rate: ' . $metrics['error_rates']['error_rate'] . '%');

        if (!empty($health['issues'])) {
            WP_CLI::line('');
            WP_CLI::warning('Issues detected:');
            foreach ($health['issues'] as $issue) {
                WP_CLI::line('  - ' . $issue);
            }
        }

        WP_CLI::line('');
        WP_CLI::line('Use --alerts, --report, or --export for detailed information');
    }

    /**
     * Manage log files and cleanup
     *
     * ## OPTIONS
     *
     * [--cleanup]
     * : Clean up old log files
     *
     * [--days=<days>]
     * : Days to keep logs (default: 30)
     *
     * [--export]
     * : Export logs for analysis
     *
     * [--start=<date>]
     * : Start date for export (YYYY-MM-DD)
     *
     * [--end=<date>]
     * : End date for export (YYYY-MM-DD)
     *
     * ## EXAMPLES
     *
     *     wp bcr logs --cleanup
     *     wp bcr logs --cleanup --days=14
     *     wp bcr logs --export --start=2024-01-01 --end=2024-01-31
     *
     * @when after_wp_load
     */
    public function logs($args, $assoc_args) {
        $automation = new Basecamp_Automation();
        $logger = $automation->get_logger();

        if (isset($assoc_args['cleanup'])) {
            $days_to_keep = intval($assoc_args['days'] ?? 30);
            WP_CLI::line("🧹 Cleaning up logs older than {$days_to_keep} days...");

            $files_cleaned = $logger->cleanup_logs($days_to_keep);
            WP_CLI::success("✅ Cleaned up {$files_cleaned} old log files");
            return;
        }

        if (isset($assoc_args['export'])) {
            $start_date = $assoc_args['start'] ?? date('Y-m-d', strtotime('-7 days'));
            $end_date = $assoc_args['end'] ?? date('Y-m-d');

            WP_CLI::line("📤 Exporting logs from {$start_date} to {$end_date}...");

            $export_data = $logger->export_logs($start_date, $end_date);
            $export_file = '/tmp/basecamp-logs-export-' . date('Y-m-d-H-i-s') . '.json';

            file_put_contents($export_file, json_encode($export_data, JSON_PRETTY_PRINT));
            WP_CLI::success("✅ Logs exported to: {$export_file}");
            return;
        }

        // Default: Show log status
        $upload_dir = wp_upload_dir();
        $log_dir = $upload_dir['basedir'] . '/basecamp-logs';

        if (!is_dir($log_dir)) {
            WP_CLI::warning('Log directory does not exist');
            return;
        }

        $log_files = glob($log_dir . '/basecamp-automation-*.log');
        $total_size = 0;

        foreach ($log_files as $file) {
            $total_size += filesize($file);
        }

        WP_CLI::line('');
        WP_CLI::line('=== LOG FILE STATUS ===');
        WP_CLI::line('📁 Log Directory: ' . $log_dir);
        WP_CLI::line('📄 Total Files: ' . count($log_files));
        WP_CLI::line('💾 Total Size: ' . $this->format_bytes($total_size));
        WP_CLI::line('📅 Today\'s Log: basecamp-automation-' . date('Y-m-d') . '.log');
        WP_CLI::line('');
        WP_CLI::line('Use --cleanup or --export for log management');
    }

    /**
     * Run automation workflows with monitoring
     *
     * ## OPTIONS
     *
     * <project>
     * : Project name or ID
     *
     * [--auto-assign]
     * : Auto-assign unassigned cards
     *
     * [--move-completed]
     * : Move completed cards to done column
     *
     * [--escalate-overdue]
     * : Escalate overdue tasks
     *
     * [--balance-workload]
     * : Balance workload across team members
     *
     * [--all]
     * : Run all workflows
     *
     * ## EXAMPLES
     *
     *     wp bcr automate "checkins pro" --all
     *     wp bcr automate 37594969 --auto-assign --escalate-overdue
     *
     * @when after_wp_load
     */
    public function automate($args, $assoc_args) {
        $project_identifier = $args[0] ?? null;
        if (!$project_identifier) {
            WP_CLI::error('Project name or ID is required');
            return;
        }

        $automation = new Basecamp_Automation();
        $project_id = $automation->resolve_project($project_identifier);

        if (!$project_id) {
            WP_CLI::error("Project not found: {$project_identifier}");
            return;
        }

        // Determine which workflows to run
        $options = [];

        if (isset($assoc_args['all'])) {
            $options = [
                'auto_assign' => true,
                'auto_move_completed' => true,
                'escalate_overdue' => true,
                'balance_workload' => true
            ];
        } else {
            $options['auto_assign'] = isset($assoc_args['auto-assign']);
            $options['auto_move_completed'] = isset($assoc_args['move-completed']);
            $options['escalate_overdue'] = isset($assoc_args['escalate-overdue']);
            $options['balance_workload'] = isset($assoc_args['balance-workload']);
        }

        // If no specific workflows selected, run default suite
        if (!array_filter($options)) {
            $options = [
                'auto_assign' => true,
                'auto_move_completed' => true,
                'escalate_overdue' => true,
                'balance_workload' => false
            ];
        }

        WP_CLI::line("🤖 Running automation workflows for project: {$project_identifier}");
        WP_CLI::line('');

        $results = $automation->run_automation_suite($project_id, $options);

        // Display results
        foreach ($results['workflows'] as $workflow => $workflow_results) {
            $workflow_name = ucwords(str_replace('_', ' ', $workflow));
            WP_CLI::line("📋 {$workflow_name}:");

            if (is_array($workflow_results)) {
                if (isset($workflow_results['error'])) {
                    WP_CLI::line("  ❌ Error: {$workflow_results['error']}");
                } elseif (empty($workflow_results)) {
                    WP_CLI::line('  ✅ No actions needed');
                } else {
                    $success_count = 0;
                    foreach ($workflow_results as $result) {
                        if (isset($result['success']) && $result['success']) {
                            $success_count++;
                        }
                    }
                    WP_CLI::line("  ✅ Processed " . count($workflow_results) . " items ({$success_count} successful)");
                }
            }
            WP_CLI::line('');
        }

        WP_CLI::success("🎉 Automation suite completed at {$results['timestamp']}");
    }

    /**
     * Find projects using advanced fuzzy matching
     *
     * ## OPTIONS
     *
     * <search_term>
     * : The project name or partial name to search for
     *
     * [--limit=<number>]
     * : Maximum number of results to show (default: 10)
     *
     * [--min-score=<score>]
     * : Minimum match score to show (default: 20)
     *
     * [--show-details]
     * : Show detailed match information
     *
     * ## EXAMPLES
     *
     *     wp bcr find "checkins"
     *     wp bcr find "bp" --show-details
     *     wp bcr find "reign" --limit=5 --min-score=50
     *
     * @when after_wp_load
     */
    public function find($args, $assoc_args) {
        $search_term = $args[0] ?? null;
        if (!$search_term) {
            WP_CLI::error('Search term is required');
            return;
        }

        $limit = intval($assoc_args['limit'] ?? 10);
        $min_score = intval($assoc_args['min-score'] ?? 20);
        $show_details = isset($assoc_args['show-details']);

        WP_CLI::line("🔍 Searching for projects matching: '{$search_term}'");
        WP_CLI::line('');

        $pro = new Basecamp_Pro();
        $matches = $pro->find_project($search_term);

        if (empty($matches)) {
            WP_CLI::error('No projects found matching the search term');
            return;
        }

        // Filter by minimum score
        $matches = array_filter($matches, function($match) use ($min_score) {
            return $match['score'] >= $min_score;
        });

        // Limit results
        $matches = array_slice($matches, 0, $limit);

        WP_CLI::line("Found " . count($matches) . " matching project(s):");
        WP_CLI::line('');

        foreach ($matches as $i => $match) {
            $project = $match['project'];
            $score = $match['score'];
            $match_type = $match['match_type'];

            // Determine color based on match quality
            $color = match($match_type) {
                'exact' => 'G',     // Green
                'strong' => 'Y',    // Yellow
                'partial' => 'C',   // Cyan
                'weak' => 'R',      // Red
                default => 'N'      // Normal
            };

            $match_indicator = match($match_type) {
                'exact' => '🎯',
                'strong' => '✅',
                'partial' => '🔵',
                'weak' => '🟡',
                default => '⚪'
            };

            WP_CLI::line(WP_CLI::colorize("%{$color}" . ($i + 1) . ". {$project['name']}%n"));
            WP_CLI::line("   {$match_indicator} Match: {$match_type} (score: {$score})");
            WP_CLI::line("   📁 ID: {$project['id']}");

            if (!empty($project['description'])) {
                $desc = trim(strip_tags($project['description']));
                if (strlen($desc) > 80) {
                    $desc = substr($desc, 0, 77) . '...';
                }
                WP_CLI::line("   📝 " . $desc);
            }

            if ($show_details) {
                WP_CLI::line("   🔗 Status: " . ucfirst($project['status'] ?? 'unknown'));
                if (!empty($project['updated_at'])) {
                    $updated = date('Y-m-d H:i', strtotime($project['updated_at']));
                    WP_CLI::line("   📅 Updated: {$updated}");
                }
            }

            WP_CLI::line('');
        }

        // Show usage tip for best match
        if (!empty($matches)) {
            $best_match = $matches[0];
            WP_CLI::success("Best match: {$best_match['project']['name']}");
            WP_CLI::line("Use this project: wp bcr project_cards {$best_match['project']['id']}");
        }
    }

    /**
     * Manage authentication and setup
     *
     * ## OPTIONS
     *
     * [--check]
     * : Check authentication status
     *
     * [--reset]
     * : Reset authentication tokens
     *
     * [--setup]
     * : Show setup instructions
     *
     * ## EXAMPLES
     *
     *     wp bcr auth --check
     *     wp bcr auth --reset
     *     wp bcr auth --setup
     *
     * @when after_wp_load
     */
    public function auth($args, $assoc_args) {
        if (isset($assoc_args['check'])) {
            $token_data = get_option('bcr_token_data', []);
            if (empty($token_data['access_token'])) {
                WP_CLI::error('❌ No authentication configured. Run --setup for instructions.');
            } else {
                $expires_at = $token_data['expires_at'] ?? 0;
                $expires_date = $expires_at ? date('Y-m-d H:i:s', $expires_at) : 'Unknown';
                $is_expired = $expires_at && time() >= $expires_at;

                if ($is_expired) {
                    WP_CLI::warning('⚠️ Authentication token has expired.');
                } else {
                    WP_CLI::success('✅ Authentication is configured and valid.');
                }
                WP_CLI::line('Token expires: ' . $expires_date);
            }
            return;
        }

        if (isset($assoc_args['reset'])) {
            delete_option('bcr_token_data');
            delete_option('bcr_settings');
            WP_CLI::success('✅ Authentication reset successfully.');
            WP_CLI::line('Run --setup to configure authentication again.');
            return;
        }

        if (isset($assoc_args['setup'])) {
            WP_CLI::line('');
            WP_CLI::line('=== BASECAMP AUTHENTICATION SETUP ===');
            WP_CLI::line('');
            WP_CLI::line('1. Go to: https://launchpad.37signals.com/integrations');
            WP_CLI::line('2. Create a new app integration');
            WP_CLI::line('3. Set redirect URL to your WordPress admin');
            WP_CLI::line('4. Copy Client ID and Secret to:');
            WP_CLI::line('   WordPress Admin → Settings → Basecamp Reader');
            WP_CLI::line('5. Complete OAuth flow in admin interface');
            WP_CLI::line('');
            WP_CLI::line('Admin URL: ' . admin_url('options-general.php?page=basecamp-reader'));
            return;
        }

        // Default: Show status
        WP_CLI::line('');
        WP_CLI::line('=== AUTHENTICATION STATUS ===');
        $this->auth($args, ['check' => true]);
        WP_CLI::line('');
        WP_CLI::line('Use --check, --reset, or --setup for specific actions');
    }

    /**
     * Manage local project index for fast searching
     *
     * ## OPTIONS
     *
     * <action>
     * : Action to perform (build, search, stats, export)
     *
     * [<query>]
     * : Search query for search action
     *
     * [--type=<type>]
     * : Filter by type (projects, cards, people, columns)
     *
     * [--project=<project_id>]
     * : Filter by specific project ID
     *
     * [--force]
     * : Force rebuild even if index exists
     *
     * ## EXAMPLES
     *
     *     wp bcr index build
     *     wp bcr index search "bug"
     *     wp bcr index search "login" --type=cards
     *     wp bcr index stats
     *     wp bcr index export
     *
     * @when after_wp_load
     */
    public function index($args, $assoc_args) {
        $action = $args[0] ?? null;
        if (!$action) {
            WP_CLI::error('Action is required. Use: build, search, stats, or export');
            return;
        }

        $indexer = new Basecamp_Indexer();

        switch ($action) {
            case 'build':
                $force = isset($assoc_args['force']);
                WP_CLI::line('🔄 Building Basecamp index...');

                $progress = function($message) {
                    WP_CLI::line("   $message");
                };

                $result = $indexer->build_full_index($progress);

                if ($result) {
                    WP_CLI::success('✅ Index built successfully!');
                    WP_CLI::line('Run: wp bcr index stats to see details');
                } else {
                    WP_CLI::error('Failed to build index');
                }
                break;

            case 'search':
                $query = $args[1] ?? null;
                if (!$query) {
                    WP_CLI::error('Search query is required');
                    return;
                }

                $type = $assoc_args['type'] ?? 'all';
                $project_id = $assoc_args['project'] ?? null;

                $filters = [];
                if ($project_id) {
                    $filters['project_id'] = $project_id;
                }

                WP_CLI::line("🔍 Searching for: '{$query}' (type: {$type})");
                WP_CLI::line('');

                $results = $indexer->search($query, $type, $filters);

                if (empty($results)) {
                    WP_CLI::warning('No results found');
                    return;
                }

                foreach ($results as $result) {
                    $type_emoji = match($result['type']) {
                        'project' => '📁',
                        'card' => '📋',
                        'person' => '👤',
                        'column' => '📊',
                        default => '📄'
                    };

                    WP_CLI::line("{$type_emoji} {$result['title']}");
                    WP_CLI::line("   Type: " . ucfirst($result['type']) . " | Score: {$result['score']}");

                    if (!empty($result['project_name'])) {
                        WP_CLI::line("   Project: {$result['project_name']}");
                    }

                    if (!empty($result['preview'])) {
                        $preview = strlen($result['preview']) > 100
                            ? substr($result['preview'], 0, 97) . '...'
                            : $result['preview'];
                        WP_CLI::line("   Preview: {$preview}");
                    }

                    WP_CLI::line('');
                }

                WP_CLI::success("Found " . count($results) . " results");
                break;

            case 'stats':
                WP_CLI::line('');
                WP_CLI::line('=== BASECAMP INDEX STATISTICS ===');

                $stats = $indexer->get_statistics();

                if (!$stats) {
                    WP_CLI::warning('Index not found. Run: wp bcr index build');
                    return;
                }

                WP_CLI::line('');
                WP_CLI::line('📊 INDEX SIZE');
                WP_CLI::line('  Total Projects: ' . ($stats['total_projects'] ?? 0));
                WP_CLI::line('  Active Projects: ' . ($stats['active_projects'] ?? 0));
                WP_CLI::line('  Total Cards: ' . ($stats['total_cards'] ?? 0));
                WP_CLI::line('  Total People: ' . ($stats['total_people'] ?? 0));
                WP_CLI::line('');

                WP_CLI::line('📈 CARD STATUS');
                WP_CLI::line('  Open Cards: ' . ($stats['open_cards'] ?? 0));
                WP_CLI::line('  Completed Cards: ' . ($stats['completed_cards'] ?? 0));
                WP_CLI::line('  Overdue Cards: ' . ($stats['overdue_cards'] ?? 0));
                WP_CLI::line('');

                if (!empty($stats['cards_by_column'])) {
                    WP_CLI::line('📋 CARDS BY COLUMN');
                    arsort($stats['cards_by_column']);
                    $top_columns = array_slice($stats['cards_by_column'], 0, 5);
                    foreach ($top_columns as $column => $count) {
                        WP_CLI::line("  {$column}: {$count} cards");
                    }
                    WP_CLI::line('');
                }

                if (!empty($stats['cards_by_assignee'])) {
                    WP_CLI::line('👥 TOP ASSIGNEES');
                    arsort($stats['cards_by_assignee']);
                    $top_assignees = array_slice($stats['cards_by_assignee'], 0, 5);
                    foreach ($top_assignees as $assignee => $count) {
                        WP_CLI::line("  {$assignee}: {$count} cards");
                    }
                }
                break;

            case 'export':
                WP_CLI::line('📤 Exporting index data...');

                $export_data = $indexer->export_index();
                if (!$export_data) {
                    WP_CLI::error('Failed to export index. Build index first.');
                    return;
                }

                $export_file = '/tmp/basecamp-index-export-' . date('Y-m-d-H-i-s') . '.json';
                file_put_contents($export_file, json_encode($export_data, JSON_PRETTY_PRINT));

                WP_CLI::success("✅ Index exported to: {$export_file}");
                WP_CLI::line('File size: ' . $this->format_bytes(filesize($export_file)));
                break;

            default:
                WP_CLI::error("Unknown action: {$action}. Use: build, search, stats, or export");
        }
    }

    /**
     * Lightweight portfolio overview (project list only)
     *
     * Shows a quick list of all projects without detailed scanning.
     * For detailed analysis of a specific project, use 'wp bcr status <project_id>'
     *
     * ## OPTIONS
     *
     * [--status=<status>]
     * : Filter by project status (active, archived, all)
     *
     * [--format=<format>]
     * : Output format (table, json, csv)
     *
     * [--analyze]
     * : Perform deep analysis (WARNING: Slow with 100+ projects)
     *
     * ## EXAMPLES
     *
     *     # Quick list of all active projects
     *     wp bcr overview
     *
     *     # List archived projects
     *     wp bcr overview --status=archived
     *
     *     # Export project list to JSON
     *     wp bcr overview --format=json
     *
     *     # Deep analysis (use with caution)
     *     wp bcr overview --analyze
     *
     * @subcommand overview
     * @when after_wp_load
     */
    public function overview($args, $assoc_args) {
        $status_filter = $assoc_args['status'] ?? 'active';
        $format = $assoc_args['format'] ?? 'table';
        $deep_analyze = isset($assoc_args['analyze']);

        WP_CLI::line('');
        WP_CLI::line('=== BASECAMP PORTFOLIO OVERVIEW ===');
        WP_CLI::line('');

        // Use index for quick access
        $indexer = new Basecamp_Indexer();
        $index_stats = $indexer->get_statistics();

        if (empty($index_stats)) {
            WP_CLI::warning('Index not found. Building index...');
            WP_CLI::run_command(['bcr', 'index', 'build']);
            $index_stats = $indexer->get_statistics();
        }

        // Quick stats from index
        WP_CLI::line("📊 Portfolio Statistics:");
        WP_CLI::line("   Total Projects: " . ($index_stats['projects']['count'] ?? 0));
        WP_CLI::line("   Active Projects: " . ($index_stats['projects']['active'] ?? 0));
        WP_CLI::line("   Total People: " . ($index_stats['people']['count'] ?? 0));
        WP_CLI::line("   Index Updated: " . ($index_stats['last_updated'] ?? 'Never'));
        WP_CLI::line('');

        // Get project list
        $pro = new Basecamp_Pro();
        $projects = $pro->fetch_all_projects();
        if ($status_filter !== 'all') {
            $projects = array_filter($projects, function($p) use ($status_filter) {
                return ($p['status'] ?? 'active') === $status_filter;
            });
        }

        // Sort by name
        usort($projects, function($a, $b) {
            return strcasecmp($a['name'], $b['name']);
        });

        // Display project list (lightweight by default)
        if (!$deep_analyze) {
            $output = [];
            foreach ($projects as $project) {
                $status_emoji = match($project['status'] ?? 'active') {
                    'active' => '🟢',
                    'archived' => '📦',
                    'trashed' => '🗑️',
                    default => '⚪'
                };

                $output[] = [
                    'ID' => $project['id'],
                    'Name' => $project['name'],
                    'Status' => $status_emoji . ' ' . ($project['status'] ?? 'active'),
                    'Created' => date('Y-m-d', strtotime($project['created_at'])),
                    'Updated' => date('Y-m-d', strtotime($project['updated_at']))
                ];
            }

            if ($format === 'json') {
                WP_CLI::line(json_encode($projects, JSON_PRETTY_PRINT));
            } elseif ($format === 'csv') {
                WP_CLI\Utils\format_items('csv', $output, ['ID', 'Name', 'Status', 'Created', 'Updated']);
            } else {
                WP_CLI\Utils\format_items('table', $output, ['ID', 'Name', 'Status', 'Created', 'Updated']);
            }

            WP_CLI::line('');
            WP_CLI::success("💡 To analyze a specific project, use: wp bcr status <project_id>");
            WP_CLI::line("💡 For deep portfolio analysis, use: wp bcr overview --analyze (WARNING: Slow)");
            return;
        }

        // Deep analysis mode (with --analyze flag)
        WP_CLI::warning('⚠️ Running deep analysis - this will take a while with 100+ projects...');
        WP_CLI::line('');

        $automation = new Basecamp_Automation();
        $analyzed_count = 0;
        $max_analyze = 10; // Limit to prevent timeout

        foreach ($projects as $project) {
            if ($analyzed_count >= $max_analyze) {
                WP_CLI::line('');
                WP_CLI::warning("Analysis limited to {$max_analyze} projects. Analyze individual projects with: wp bcr status <project_id>");
                break;
            }

            WP_CLI::line("Analyzing: {$project['name']}...");

            // Quick column discovery only
            $columns = $automation->discover_columns($project['id']);
            $bug_count = 0;
            $total_cards = 0;

            foreach ($columns as $col_id => $column) {
                $column_type = $this->get_column_type($column['title']);

                if ($column_type === 'bugs') {
                    // Count bugs in this column
                    $account_id = get_option('basecamp_account_id', '');
                    if (!$account_id) {
                        $account_id = $this->api->get_account_id();
                    }
                    $cards_response = $this->api->get("/{$account_id}/buckets/{$project['id']}/card_tables/lists/{$col_id}/cards.json");
                    $cards = ($cards_response['code'] === 200) ? $cards_response['data'] : [];
                    $bug_count += count($cards);
                    $total_cards += count($cards);
                }
            }

            if ($bug_count > 0) {
                $urgency = $bug_count > 10 ? '🚨' : ($bug_count > 5 ? '⚠️' : '💡');
                WP_CLI::line("   {$urgency} Bugs: {$bug_count}");
            }

            $analyzed_count++;
        }

        WP_CLI::line('');
        WP_CLI::success("Analysis complete. For detailed project analysis use: wp bcr status <project_id>");
    }

    /**
     * Analyze status of a SPECIFIC PROJECT by columns (project-specific)
     *
     * This command analyzes a single project only.
     * For portfolio-wide analysis, use 'wp bcr overview'
     *
     * ## OPTIONS
     *
     * <project>
     * : Project name or ID to analyze (required - this is project-specific)
     *
     * [--detailed]
     * : Show detailed card information for this project
     *
     * [--bugs-only]
     * : Show only bug-related columns for this project
     *
     * [--summary]
     * : Show card distribution summary for this project
     *
     * ## EXAMPLES
     *
     *     # Analyze specific project by name
     *     wp bcr status "checkins pro"
     *
     *     # Analyze specific project with details
     *     wp bcr status 37594969 --detailed
     *
     *     # Show only bugs in a specific project
     *     wp bcr status 37594969 --bugs-only
     *
     * @subcommand status
     * @when after_wp_load
     */
    public function status($args, $assoc_args) {
        $project_identifier = $args[0] ?? null;
        if (!$project_identifier) {
            WP_CLI::error('Project name or ID is required');
            return;
        }

        $detailed = isset($assoc_args['detailed']);
        $bugs_only = isset($assoc_args['bugs-only']);
        $summary_only = isset($assoc_args['summary']);

        // Resolve project
        $automation = new Basecamp_Automation();
        $project_id = $automation->resolve_project($project_identifier);

        if (!$project_id) {
            WP_CLI::error("Project not found: {$project_identifier}");
            return;
        }

        WP_CLI::line("📊 Analyzing project status: {$project_identifier}");
        WP_CLI::line('');

        // Get project info and columns
        $project = $automation->get_project($project_id);
        if (!$project) {
            WP_CLI::error("Failed to fetch project details");
            return;
        }

        WP_CLI::line("📁 Project: {$project['name']}");
        WP_CLI::line("🆔 ID: {$project_id}");
        WP_CLI::line('');

        // Discover all columns first
        WP_CLI::line("🔍 Discovering project columns/lists...");
        $columns = $automation->discover_columns($project_id);

        if (empty($columns)) {
            WP_CLI::warning("No card tables found in this project");
            return;
        }

        WP_CLI::line("✅ Found " . count($columns) . " columns/lists");
        WP_CLI::line('');

        // Classify columns by type
        $column_types = $this->classify_columns($columns);

        if ($summary_only) {
            $this->show_column_summary($column_types, $automation, $project_id);
            return;
        }

        // Show columns with card counts
        $total_cards = 0;
        $status_distribution = [];

        foreach ($columns as $col_id => $column) {
            $column_name = $column['title'];
            $column_type = $this->get_column_type($column_name);

            // Skip non-bug columns if bugs-only mode
            if ($bugs_only && !in_array($column_type, ['bugs', 'testing', 'review'])) {
                continue;
            }

            // Get cards for this column using proper API
            $account_id = get_option('basecamp_account_id', '');
            if (!$account_id) {
                $account_id = $this->api->get_account_id();
            }
            $cards_response = $this->api->get("/{$account_id}/buckets/{$project_id}/card_tables/lists/{$col_id}/cards.json");
            $cards = ($cards_response['code'] === 200) ? $cards_response['data'] : [];
            $card_count = is_array($cards) ? count($cards) : 0;
            $total_cards += $card_count;

            // Track status distribution
            $status_distribution[$column_type] = ($status_distribution[$column_type] ?? 0) + $card_count;

            // Display column info
            $type_emoji = $this->get_column_emoji($column_type);
            $urgency_indicator = $column_type === 'bugs' ? '🚨' : ($column_type === 'testing' ? '⚠️' : '');

            WP_CLI::line("{$type_emoji} {$urgency_indicator} {$column_name}");
            WP_CLI::line("   📊 Type: " . ucfirst($column_type) . " | Cards: {$card_count}");

            if ($detailed && $card_count > 0 && is_array($cards)) {
                WP_CLI::line("   📋 Cards:");
                foreach (array_slice($cards, 0, 5) as $i => $card) {
                    $status_icon = $card['completed'] ?? false ? '✅' : '🔄';
                    $assignee = !empty($card['assignees']) ? $card['assignees'][0]['name'] : 'Unassigned';
                    $title = strlen($card['title']) > 50 ? substr($card['title'], 0, 47) . '...' : $card['title'];
                    WP_CLI::line("     {$status_icon} {$title} ({$assignee})");
                }
                if ($card_count > 5) {
                    WP_CLI::line("     ... and " . ($card_count - 5) . " more cards");
                }
            }

            WP_CLI::line('');
        }

        // Show summary
        WP_CLI::line('=== PROJECT STATUS SUMMARY ===');
        WP_CLI::line("📋 Total Cards: {$total_cards}");
        WP_CLI::line('');

        WP_CLI::line('📊 BY STATUS TYPE:');
        foreach ($status_distribution as $type => $count) {
            $emoji = $this->get_column_emoji($type);
            $percentage = $total_cards > 0 ? round(($count / $total_cards) * 100, 1) : 0;
            WP_CLI::line("  {$emoji} " . ucfirst($type) . ": {$count} ({$percentage}%)");
        }

        // Show critical alerts
        if (!empty($status_distribution['bugs']) && $status_distribution['bugs'] > 0) {
            WP_CLI::line('');
            WP_CLI::warning("🚨 BUGS TO FIX: {$status_distribution['bugs']} bug(s) need attention");
        }

        if (!empty($status_distribution['testing']) && $status_distribution['testing'] > 0) {
            WP_CLI::line("⚠️ READY FOR TESTING: {$status_distribution['testing']} item(s) awaiting testing");
        }
    }

    /**
     * Classify columns by their likely purpose
     */
    private function classify_columns($columns) {
        $classified = [
            'bugs' => [],
            'todo' => [],
            'development' => [],
            'review' => [],
            'testing' => [],
            'done' => [],
            'other' => []
        ];

        foreach ($columns as $id => $column) {
            $type = $this->get_column_type($column['title']);
            $classified[$type][] = [
                'id' => $id,
                'name' => $column['title'],
                'position' => $column['position'] ?? 0
            ];
        }

        return $classified;
    }

    /**
     * Determine column type based on name
     */
    private function get_column_type($column_name) {
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
     * Show column summary
     */
    private function show_column_summary($column_types, $automation, $project_id) {
        WP_CLI::line('=== COLUMN/LIST STRUCTURE ===');
        WP_CLI::line('');

        foreach ($column_types as $type => $columns) {
            if (empty($columns)) continue;

            $emoji = $this->get_column_emoji($type);
            WP_CLI::line("{$emoji} " . strtoupper($type) . " (" . count($columns) . " columns):");

            foreach ($columns as $column) {
                WP_CLI::line("  • {$column['name']}");
            }
            WP_CLI::line('');
        }
    }

    /**
     * Bug count for a specific project (project-specific by default)
     *
     * ## OPTIONS
     *
     * <project>
     * : Project ID or name (required unless using --all)
     *
     * [--all]
     * : Scan ALL projects (portfolio-wide) - WARNING: This can be slow with 100+ projects
     *
     * [--threshold=<number>]
     * : With --all, only show projects with bugs >= threshold (default: 0)
     *
     * [--detailed]
     * : Show detailed bug information
     *
     * ## EXAMPLES
     *
     *     # Show bugs in a specific project
     *     wp bcr bugs "BuddyPress Business Profile"
     *     wp bcr bugs 37594834
     *
     *     # Show bugs in specific project with details
     *     wp bcr bugs 37594834 --detailed
     *
     *     # Scan ALL projects (use with caution)
     *     wp bcr bugs --all
     *     wp bcr bugs --all --threshold=5
     *
     * @subcommand bugs
     * @when after_wp_load
     */
    public function bugs($args, $assoc_args) {
        $all_projects = isset($assoc_args['all']);
        $threshold = intval($assoc_args['threshold'] ?? 0);
        $detailed = isset($assoc_args['detailed']);

        $automation = new Basecamp_Automation();

        // Single project mode (default)
        if (!$all_projects) {
            $project_identifier = $args[0] ?? null;
            if (!$project_identifier) {
                WP_CLI::error('Project ID or name is required (or use --all for portfolio scan)');
                return;
            }

            // Resolve project
            $project_id = $automation->resolve_project($project_identifier);
            if (!$project_id) {
                WP_CLI::error("Project not found: {$project_identifier}");
                return;
            }

            $project = $automation->get_project($project_id);
            WP_CLI::line("🐛 Checking bugs in: {$project['name']}");
            WP_CLI::line('');

            // Discover columns
            $columns = $automation->discover_columns($project_id);
            $bug_count = 0;
            $bug_cards = [];

            foreach ($columns as $col_id => $column) {
                $column_type = $this->get_column_type($column['title']);

                if ($column_type === 'bugs') {
                    $account_id = get_option('basecamp_account_id', '');
                    if (!$account_id) {
                        $account_id = $this->api->get_account_id();
                    }

                    $cards_response = $this->api->get("/{$account_id}/buckets/{$project_id}/card_tables/lists/{$col_id}/cards.json");
                    $cards = ($cards_response['code'] === 200) ? $cards_response['data'] : [];

                    if (!empty($cards)) {
                        $bug_count += count($cards);
                        $bug_cards = array_merge($bug_cards, $cards);

                        WP_CLI::line("📌 Column: {$column['title']}");
                        WP_CLI::line("   Found: " . count($cards) . " bugs");

                        if ($detailed) {
                            foreach ($cards as $card) {
                                WP_CLI::line("   - [{$card['id']}] {$card['title']}");
                                if (!empty($card['due_on'])) {
                                    $due = new DateTime($card['due_on']);
                                    $now = new DateTime();
                                    if ($due < $now) {
                                        WP_CLI::line("     ⚠️ OVERDUE: " . $card['due_on']);
                                    }
                                }
                            }
                        }
                    }
                }
            }

            WP_CLI::line('');
            if ($bug_count === 0) {
                WP_CLI::success("✅ No bugs found in this project!");
            } else {
                $urgency = $bug_count > 10 ? '🚨🚨' : ($bug_count > 5 ? '🚨' : '⚠️');
                WP_CLI::line("{$urgency} Total bugs: {$bug_count}");

                if (!$detailed) {
                    WP_CLI::line("💡 Use --detailed to see individual bugs");
                }
            }

            return;
        }

        // All projects mode (with --all flag)
        WP_CLI::warning('⚠️ Scanning ALL projects - this may take a while...');
        WP_CLI::line('');

        $pro = new Basecamp_Pro();
        $projects = $pro->fetch_all_projects();

        $bug_summary = [];
        $total_bugs = 0;

        foreach ($projects as $project) {
            if (($project['status'] ?? 'active') !== 'active') continue;

            // Discover columns
            $columns = $automation->discover_columns($project['id']);
            $project_bugs = 0;

            foreach ($columns as $col_id => $column) {
                $column_type = $this->get_column_type($column['title']);

                if ($column_type === 'bugs') {
                    $account_id = get_option('basecamp_account_id', '');
                    if (!$account_id) {
                        $account_id = $this->api->get_account_id();
                    }
                    $cards_response = $this->api->get("/{$account_id}/buckets/{$project['id']}/card_tables/lists/{$col_id}/cards.json");
                    $cards = ($cards_response['code'] === 200) ? $cards_response['data'] : [];
                    $bug_count = is_array($cards) ? count($cards) : 0;
                    $project_bugs += $bug_count;
                }
            }

            if ($project_bugs > $threshold) {
                $bug_summary[] = [
                    'project' => $project,
                    'bugs' => $project_bugs
                ];
                $total_bugs += $project_bugs;
            }
        }

        // Sort by bug count (highest first)
        usort($bug_summary, function($a, $b) {
            return $b['bugs'] - $a['bugs'];
        });

        WP_CLI::line("🚨 FOUND {$total_bugs} TOTAL BUGS across " . count($bug_summary) . " projects");
        WP_CLI::line('');

        if (empty($bug_summary)) {
            WP_CLI::success("✅ No projects have bugs above threshold ({$threshold})");
            return;
        }

        foreach ($bug_summary as $item) {
            $project = $item['project'];
            $bug_count = $item['bugs'];

            $urgency = $bug_count > 10 ? '🚨🚨' : ($bug_count > 5 ? '🚨' : '⚠️');
            WP_CLI::line("{$urgency} {$project['name']}: {$bug_count} bugs");
            WP_CLI::line("   📁 ID: {$project['id']}");

            if ($detailed) {
                WP_CLI::line("   🔧 Action: wp bcr status {$project['id']} --bugs-only");
                WP_CLI::line("   🤖 Automate: wp bcr automate {$project['id']} --escalate-overdue");
            }

            WP_CLI::line('');
        }

        // Recommendations
        WP_CLI::line('💡 RECOMMENDATIONS:');
        if ($total_bugs > 20) {
            WP_CLI::line('  • High bug count detected - consider bug triage meeting');
        }
        WP_CLI::line('  • Run automation to escalate overdue bugs');
        WP_CLI::line('  • Use: wp bcr status [project] --bugs-only for details');
    }

    /**
     * Format bytes to human readable format
     */
    private function format_bytes($bytes, $precision = 2) {
        $units = array('B', 'KB', 'MB', 'GB', 'TB');

        for ($i = 0; $bytes > 1024 && $i < count($units) - 1; $i++) {
            $bytes /= 1024;
        }

        return round($bytes, $precision) . ' ' . $units[$i];
    }

    /**
     * Manage Basecamp settings
     *
     * ## OPTIONS
     *
     * [--show]
     * : Show current settings
     *
     * [--account-id=<id>]
     * : Set the Basecamp account/organization ID
     *
     * [--reset]
     * : Reset all settings to defaults
     *
     * ## EXAMPLES
     *
     *     wp bcr settings --show
     *     wp bcr settings --account-id=5798509
     *     wp bcr settings --reset
     *
     * @when after_wp_load
     */
    public function settings($args, $assoc_args) {
        if (isset($assoc_args['show']) || (!isset($assoc_args['account-id']) && !isset($assoc_args['reset']))) {
            $account_id = get_option('basecamp_account_id', '');
            $token_data = get_option('bcr_token_data', []);

            WP_CLI::line("=== BASECAMP SETTINGS ===");
            WP_CLI::line("");

            if (empty($account_id)) {
                WP_CLI::line("🏢 Account ID: ❌ Not configured");
                WP_CLI::warning("Run 'wp bcr settings --account-id=YOUR_ID' to configure");
            } else {
                WP_CLI::line("🏢 Account ID: " . $account_id);
            }

            WP_CLI::line("🔑 Token Status: " . (!empty($token_data['access_token']) ? '✅ Configured' : '❌ Not configured'));

            if (!empty($token_data['expires_at'])) {
                $expires_in = $token_data['expires_at'] - time();
                if ($expires_in > 0) {
                    WP_CLI::line("⏱️  Token Expires: " . human_time_diff(time(), $token_data['expires_at']) . ' remaining');
                } else {
                    WP_CLI::line("⏱️  Token Status: ⚠️ Expired");
                }
            }

            WP_CLI::line("");
            WP_CLI::line("📡 API Base: https://3.basecampapi.com");

            if (!empty($account_id)) {
                WP_CLI::line("🔗 Account URL: https://3.basecamp.com/$account_id");
            }

            return;
        }

        if (isset($assoc_args['account-id'])) {
            $account_id = $assoc_args['account-id'];
            update_option('basecamp_account_id', $account_id);
            WP_CLI::success("Account ID updated to: $account_id");
            WP_CLI::line("All API calls will now use this account ID.");
            return;
        }

        if (isset($assoc_args['reset'])) {
            delete_option('basecamp_account_id');
            WP_CLI::success("Settings reset - account ID removed");
            WP_CLI::line("You'll need to configure the account ID with: wp bcr settings --account-id=YOUR_ID");
            return;
        }
    }


}

// Register CLI commands if WP-CLI is available
if (defined('WP_CLI') && WP_CLI) {
    WP_CLI::add_command('bcr', 'BCR_CLI_Commands_Extended');
}