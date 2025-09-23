#!/usr/bin/env php
<?php
/**
 * FINAL STANDARDIZATION SCRIPT - Column Standardizer for Basecamp Projects
 *
 * This is the LOCKED FINAL VERSION of the column standardization script.
 *
 * RESPECTS FIXED BASECAMP COLUMNS:
 * - Triage (never modified)
 * - Not now (never modified)
 * - Done (never modified)
 *
 * STANDARDIZED COLUMNS CREATED/RENAMED:
 * - Scope
 * - Suggestions
 * - Bugs
 * - Ready for Development
 * - In Development
 * - Ready for Testing
 * - In Testing
 *
 * FEATURES:
 * - Prevents duplicate column creation
 * - Safe column renaming with conflict detection
 * - Respects existing fixed Basecamp columns
 * - Smart missing column detection
 * - Dry-run mode for testing
 *
 * Usage: php standardize-columns-final.php [--dry-run] [--limit=N] [--start=N] [--project=ID]
 */

// Load WordPress
require_once('/Users/varundubey/Local Sites/reign-learndash/app/public/wp-load.php');
require_once('/Users/varundubey/Local Sites/reign-learndash/app/public/wp-content/plugins/basecamp-cards-reader/includes/class-basecamp-automation.php');
require_once('/Users/varundubey/Local Sites/reign-learndash/app/public/wp-content/plugins/basecamp-cards-reader/includes/class-basecamp-indexer.php');

// Parse command line arguments
$dry_run = in_array('--dry-run', $argv);
$limit = null;
$start = 0;
$specific_project_id = null;

foreach ($argv as $arg) {
    if (strpos($arg, '--limit=') === 0) {
        $limit = (int) substr($arg, 8);
    }
    if (strpos($arg, '--start=') === 0) {
        $start = (int) substr($arg, 8);
    }
    if (strpos($arg, '--project=') === 0) {
        $specific_project_id = substr($arg, 10);
    }
}

echo "🚀 STANDARDIZING ALL PROJECTS (from local index)\n";
echo "========================================\n";
if ($dry_run) {
    echo "⚠️  DRY RUN MODE - No actual changes will be made\n";
}
if ($limit) {
    echo "📊 Limited to {$limit} projects\n";
}
if ($start > 0) {
    echo "📊 Starting from project #{$start}\n";
}
echo "\n";

// Initialize components
$indexer = new Basecamp_Indexer();
$automation = new Basecamp_Automation();

// Handle specific project or use index
if ($specific_project_id) {
    echo "🎯 TARGETING SPECIFIC PROJECT {$specific_project_id}\n";

    // Get project details directly
    $token_data = get_option('bcr_token_data', []);
    $api = new Basecamp_API($token_data['access_token']);
    $account_id = get_option('basecamp_account_id', '');

    $project_response = $api->get("/{$account_id}/projects/{$specific_project_id}.json");
    if ($project_response['code'] !== 200) {
        die("❌ Error: Could not fetch project {$specific_project_id}\n");
    }

    $project_data = $project_response['data'];
    $projects_to_process = [[
        'ID' => $specific_project_id,
        'Name' => $project_data['name']
    ]];
    $total_to_process = 1;
    $total_available = 1;

    echo "📊 Will process specific project: {$project_data['name']}\n\n";
} else {
    // Get all projects from local index (direct file access)
    echo "📋 Loading projects from local index...\n";

    // Access the index file directly
    $upload_dir = wp_upload_dir();
    $projects_index_file = $upload_dir['basedir'] . '/basecamp-index/projects.json';

    if (!file_exists($projects_index_file)) {
        die("❌ Error: Index not found. Run 'wp bcr index build' first.\n");
    }

    $projects_json = file_get_contents($projects_index_file);
    if (empty($projects_json)) {
        die("❌ Error: Could not load projects from index.\n");
    }

    $indexed_projects = json_decode($projects_json, true);
    if (!$indexed_projects) {
        die("❌ Error: Invalid project data from index.\n");
    }

    // Convert indexed format to expected format
    $all_projects = [];
    foreach ($indexed_projects as $project_id => $project_data) {
        $all_projects[] = [
            'ID' => $project_id,
            'Name' => $project_data['name'] ?? 'Unknown Project'
        ];
    }
    $total_available = count($all_projects);

    echo "📊 Found {$total_available} projects in local index\n";

    // Apply start and limit
    $projects_to_process = array_slice($all_projects, $start, $limit);
    $total_to_process = count($projects_to_process);

    echo "📊 Will process {$total_to_process} projects\n\n";
}

// Initialize API for updates
$token_data = get_option('bcr_token_data', []);
$api = new Basecamp_API($token_data['access_token']);
$account_id = get_option('basecamp_account_id', '');

// Column mappings (skip fixed columns: Triage, Not now, Done)
$column_mappings = [
    // Skip 'not now' - it's a fixed Basecamp column
    'backlog' => 'Scope',

    'suggestion' => 'Suggestions',
    'ideas' => 'Suggestions',

    'bugs ( do not fix unless assigned )' => 'Bugs',
    'bugs' => 'Bugs',
    'bug' => 'Bugs',
    'issues' => 'Bugs',

    'ready for development' => 'Ready for Development',
    'to do' => 'Ready for Development',
    'todo' => 'Ready for Development',

    'in progress' => 'In Development',
    'in development' => 'In Development',
    'developing' => 'In Development',

    'ready for testing' => 'Ready for Testing',
    'qa ready' => 'Ready for Testing',

    'testing' => 'In Testing',
    'qa' => 'In Testing',
    'under test' => 'In Testing'

    // Skip 'done' - it's a fixed Basecamp column
];

// Required standardized columns
// Note: Fixed columns (Triage, Not now, Done) are kept as-is
// We only add missing standardized columns if they don't exist
$required_columns = [
    'Scope',
    'Suggestions',
    'Bugs',
    'Ready for Development',
    'In Development',
    'Ready for Testing',
    'In Testing'
];

// Stats tracking
$processed = 0;
$updated_projects = 0;
$total_updated = 0;
$total_created = 0;
$skipped = 0;
$errors = 0;

// Process each project
foreach ($projects_to_process as $index => $project) {
    $project_num = $start + $index + 1;
    $project_id = $project['ID'];
    $project_name = $project['Name'];

    echo "[{$project_num}/{$total_available}] {$project_name} (ID: {$project_id})\n";

    // Get existing columns safely - try multiple endpoints
    $existing_columns = [];
    $card_table_id = null;

    // Get project details to find card table ID
    $project_details = $automation->get_project($project_id);
    if ($project_details) {
        foreach ($project_details['dock'] as $tool) {
            if (in_array($tool['name'], ['card_table', 'kanban_board'])) {
                $card_table_id = $tool['id'];
                break;
            }
        }
    }

    if (!$card_table_id) {
        echo "   ⏭️  No Kanban board found - skipping\n\n";
        $skipped++;
        continue;
    }

    // Get card table with embedded lists/columns
    echo "   Getting card table details...\n";
    $table_response = $api->get("/{$account_id}/buckets/{$project_id}/card_tables/{$card_table_id}.json");

    if ($table_response['code'] === 200 && isset($table_response['data']['lists'])) {
        $raw_columns = $table_response['data']['lists'];
        echo "   ✅ Found " . count($raw_columns) . " columns\n";

        // Convert to expected format and detect duplicates
        $duplicates_found = [];
        $title_counts = [];

        foreach ($raw_columns as $col) {
            $title = $col['title'];
            if (!isset($title_counts[$title])) {
                $title_counts[$title] = 0;
            }
            $title_counts[$title]++;

            $existing_columns[] = [
                'id' => $col['id'],
                'title' => $title
            ];
        }

        // Report duplicates
        foreach ($title_counts as $title => $count) {
            if ($count > 1) {
                echo "   ⚠️  DUPLICATE: '{$title}' appears {$count} times\n";
                $duplicates_found[] = $title;
            }
        }

        if (!empty($duplicates_found)) {
            echo "   🧹 Will clean up duplicates during processing\n";
        }
    } else {
        echo "   ℹ️  No columns found - empty Kanban board\n";
    }

    $project_updated = 0;
    $project_created = 0;
    $current_column_names = [];

    // Report duplicates but can't delete them via API
    if (!empty($duplicates_found)) {
        echo "   ⚠️  DUPLICATES DETECTED - Manual cleanup needed:\n";

        $columns_by_title = [];
        foreach ($existing_columns as $col) {
            $title = $col['title'];
            if (!isset($columns_by_title[$title])) {
                $columns_by_title[$title] = [];
            }
            $columns_by_title[$title][] = $col;
        }

        // Filter to unique columns by keeping first occurrence
        $clean_columns = [];
        $seen_titles = [];
        $duplicates_to_log = [];

        foreach ($existing_columns as $col) {
            $title = $col['title'];
            if (!in_array($title, $seen_titles)) {
                $clean_columns[] = $col;
                $seen_titles[] = $title;
                echo "     ✅ KEEP: '{$title}' (ID: {$col['id']})\n";
            } else {
                echo "     ⚠️  SKIP: '{$title}' (ID: {$col['id']}) - duplicate\n";
                $duplicates_to_log[] = "- 🚫 DELETE: '{$title}' (ID: {$col['id']}) - duplicate";
            }
        }

        // Log duplicates to file for manual cleanup
        if (!empty($duplicates_to_log)) {
            $log_entry = "\n### DUPLICATES FOUND IN {$project_name} (ID: {$project_id}) - " . date('Y-m-d H:i:s') . "\n";
            $log_entry .= implode("\n", $duplicates_to_log) . "\n";
            file_put_contents('/Users/varundubey/Local Sites/reign-learndash/app/public/wp-content/plugins/basecamp-cards-reader/duplicates-log.txt', $log_entry, FILE_APPEND);
        }

        $existing_columns = $clean_columns;
        echo "   ℹ️  Note: Duplicates must be deleted manually in Basecamp web interface\n\n";
    }

    // Update existing columns if any found
    if (!empty($existing_columns)) {
        foreach ($existing_columns as $column) {
            $col_id = $column['id'];
        $current_name = $column['title'];
        $current_name_lower = strtolower(trim($current_name));
        $new_name = null;

            // Skip fixed Basecamp columns that should never be renamed
            $fixed_columns = ['triage', 'not now', 'done'];
            if (!in_array($current_name_lower, $fixed_columns)) {
                foreach ($column_mappings as $old => $new) {
                    if ($current_name_lower === $old || strpos($current_name_lower, $old) !== false) {
                        $new_name = $new;
                        break;
                    }
                }
            }

            if ($new_name && $current_name !== $new_name) {
                echo "   📝 '{$current_name}' → '{$new_name}'";

                if (!$dry_run) {
                    $update_response = $api->put(
                        "/{$account_id}/buckets/{$project_id}/card_tables/columns/{$col_id}.json",
                        ['title' => $new_name]
                    );

                    if ($update_response['code'] === 200 || $update_response['code'] === 204) {
                        echo " ✅\n";
                        $project_updated++;
                        $total_updated++;
                        $current_column_names[] = $new_name;
                    } else {
                        echo " ❌ (HTTP {$update_response['code']})\n";
                        $current_column_names[] = $current_name;
                    }
                    usleep(300000); // 0.3s delay to avoid rate limiting
                } else {
                    echo " (dry run)\n";
                    $current_column_names[] = $new_name;
                }
            } else {
                $current_column_names[] = $current_name;
            }
        }
    } else {
        echo "   ⚠️  No existing columns found - new Kanban board\n";
    }

    // Smart column creation - only create if truly missing
    echo "   📋 Checking for missing required columns...\n";

    // Get all unique column titles that exist (including from duplicates and renamed columns)
    $all_existing_titles = [];
    foreach ($existing_columns as $col) {
        $all_existing_titles[] = $col['title'];
    }

    // Also add the new names from renamed columns to prevent creating duplicates
    foreach ($existing_columns as $col) {
        $current_name = $col['title'];
        $current_name_lower = strtolower(trim($current_name));
        $fixed_columns = ['triage', 'not now', 'done'];

        if (!in_array($current_name_lower, $fixed_columns)) {
            foreach ($column_mappings as $old => $new) {
                if ($current_name_lower === $old || strpos($current_name_lower, $old) !== false) {
                    $all_existing_titles[] = $new; // Add the target name
                    break;
                }
            }
        }
    }

    $unique_existing_titles = array_unique($all_existing_titles);

    echo "   📊 Current columns: " . implode(', ', $unique_existing_titles) . "\n";

    $missing_columns = array_diff($required_columns, $unique_existing_titles);

    if (!empty($missing_columns)) {
        echo "   📝 Missing columns to create: " . implode(', ', $missing_columns) . "\n";

        foreach ($missing_columns as $missing_col) {
            // Final safety check: ensure column doesn't already exist with this exact name
            $column_exists = false;
            foreach ($existing_columns as $existing_col) {
                if (strtolower(trim($existing_col['title'])) === strtolower(trim($missing_col))) {
                    $column_exists = true;
                    echo "   ⚠️  SKIP: '{$missing_col}' - already exists as '{$existing_col['title']}' (ID: {$existing_col['id']})\n";
                    break;
                }
            }

            if (!$column_exists) {
                echo "   ➕ Creating '{$missing_col}'";

                if (!$dry_run) {
                    if ($card_table_id) {
                        $create_response = $api->post(
                            "/{$account_id}/buckets/{$project_id}/card_tables/{$card_table_id}/columns.json",
                            ['title' => $missing_col]
                        );

                        if ($create_response['code'] === 201) {
                            echo " ✅\n";
                            $project_created++;
                            $total_created++;
                        } else {
                            echo " ❌ (HTTP {$create_response['code']})\n";
                            $errors++;
                        }
                        usleep(300000); // 0.3s delay
                    } else {
                        echo " ❌ (no card table)\n";
                        $errors++;
                    }
                } else {
                    echo " (dry run)\n";
                }
            }
        }
    } else {
        echo "   ✅ All required columns already exist\n";
    }

    if ($project_updated > 0 || $project_created > 0) {
        $updated_projects++;
        echo "   ✅ Updated: {$project_updated}, Created: {$project_created}\n";
    } else {
        echo "   ✓ Already standardized\n";
    }

    echo "\n";
    $processed++;

    // Progress update every 5 projects
    if ($processed % 5 === 0) {
        echo "📊 Progress: {$processed}/{$total_to_process} projects processed\n";
        echo "📊 Stats: {$updated_projects} updated, {$total_updated} columns changed, {$total_created} created\n\n";
    }
}

// Final summary
echo "\n========================================\n";
echo "🎉 STANDARDIZATION COMPLETE\n";
echo "========================================\n";
echo "Projects processed: {$processed}/{$total_to_process}\n";
echo "Projects updated: {$updated_projects}\n";
echo "Columns updated: {$total_updated}\n";
echo "Columns created: {$total_created}\n";
echo "Projects skipped: {$skipped}\n";
echo "Errors: {$errors}\n";

if ($dry_run) {
    echo "\n⚠️  This was a DRY RUN - no actual changes were made.\n";
    echo "Remove --dry-run to apply changes.\n";
} else {
    echo "\n✅ Standardization completed!\n";

    if ($processed < $total_available) {
        $remaining = $total_available - ($start + $processed);
        echo "\nℹ️  To continue with remaining {$remaining} projects:\n";
        echo "php standardize-from-index.php --start=" . ($start + $processed) . "\n";
    }
}

echo "\n💡 Tip: Use --dry-run first to preview changes\n";
echo "💡 Tip: Use --limit=10 to process in smaller batches\n";