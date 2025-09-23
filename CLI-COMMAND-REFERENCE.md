# Basecamp Pro Automation Suite - CLI Command Reference

Complete reference for all WP-CLI commands in the Basecamp Pro Automation Suite.

## Table of Contents

1. [Authentication & Settings](#authentication--settings)
2. [Project Management](#project-management)
3. [Index Management](#index-management)
4. [Column Operations](#column-operations)
5. [Card Management](#card-management)
6. [Comments & Activity](#comments--activity)
7. [Automation & Monitoring](#automation--monitoring)
8. [Legacy Commands](#legacy-commands)
9. [Debugging & Troubleshooting](#debugging--troubleshooting)

---

## Authentication & Settings

### `wp bcr auth`

Manage Basecamp authentication.

**Options:**
- `--check` - Check current authentication status
- `--refresh` - Refresh the access token
- `--reset` - Reset authentication (requires re-authorization)

**Examples:**
```bash
wp bcr auth --check
wp bcr auth --refresh
wp bcr auth --reset
```

### `wp bcr settings`

Manage plugin settings including account configuration.

**Options:**
- `--account-id=<id>` - Set the Basecamp account ID
- `--list` - Show all current settings
- `--clear` - Clear all settings

**Examples:**
```bash
wp bcr settings --account-id=5798509
wp bcr settings --list
wp bcr settings --clear
```

---

## Project Management

### `wp bcr project list`

List all available projects.

**Options:**
- `--search=<term>` - Search projects with fuzzy matching
- `--archived` - Include archived projects
- `--format=<format>` - Output format (table|json|csv)

**Examples:**
```bash
wp bcr project list
wp bcr project list --search="buddy"
wp bcr project list --archived --format=json
```

### `wp bcr project get`

Get details for a specific project.

**Arguments:**
- `<project>` - Project ID or name (fuzzy match supported)

**Options:**
- `--id=<id>` - Explicitly specify project ID

**Examples:**
```bash
wp bcr project get "buddypress"
wp bcr project get 37594834
wp bcr project get --id=37594834
```

### `wp bcr project info`

Get comprehensive information about a project.

**Arguments:**
- `<project>` - Project ID or name

**Options:**
- `--format=<format>` - Output format (table|json|csv)

**Examples:**
```bash
wp bcr project info "buddypress business"
wp bcr project info 37594834 --format=json
```

### `wp bcr project tools`

List enabled tools for a project.

**Arguments:**
- `<project>` - Project ID or name

**Examples:**
```bash
wp bcr project tools "buddypress"
wp bcr project tools 37594834
```

### `wp bcr project people`

List team members in a project.

**Arguments:**
- `<project>` - Project ID or name

**Examples:**
```bash
wp bcr project people "buddypress"
wp bcr project people 37594834
```

### `wp bcr project search`

Search for projects using fuzzy matching.

**Arguments:**
- `<search_term>` - Search term (supports partial matches, acronyms)

**Options:**
- `--show-details` - Show detailed results

**Examples:**
```bash
wp bcr project search "buddy"
wp bcr project search "bpbp"
wp bcr project search "check ins" --show-details
```

---

## Index Management

### `wp bcr index build`

Build or rebuild the project index.

**Options:**
- `--force` - Force rebuild even if index exists
- `--include-archived` - Include archived projects

**Examples:**
```bash
wp bcr index build
wp bcr index build --force
wp bcr index build --include-archived
```

### `wp bcr index refresh`

Refresh the existing index with latest data.

**Examples:**
```bash
wp bcr index refresh
```

### `wp bcr index stats`

Show index statistics and health.

**Examples:**
```bash
wp bcr index stats
```

### `wp bcr index clear`

Clear the entire index.

**Options:**
- `--confirm` - Skip confirmation prompt

**Examples:**
```bash
wp bcr index clear
wp bcr index clear --confirm
```

### `wp bcr index search`

Search within the local index.

**Arguments:**
- `<search_term>` - Search term

**Examples:**
```bash
wp bcr index search "buddy"
wp bcr index search "testing"
```

### `wp bcr index columns`

Discover and index columns for a project.

**Arguments:**
- `<project>` - Project ID or name

**Options:**
- `--force` - Force column rediscovery

**Examples:**
```bash
wp bcr index columns 37594834
wp bcr index columns "buddypress business"
wp bcr index columns 37594834 --force
```

---

## Column Operations

### `wp bcr column list`

List all columns in a project.

**Arguments:**
- `<project>` - Project ID or name

**Options:**
- `--format=<format>` - Output format (table|json|csv)

**Examples:**
```bash
wp bcr column list 37594834
wp bcr column list "buddypress"
wp bcr column list 37594834 --format=json
```

### `wp bcr column get`

Get details for a specific column.

**Arguments:**
- `<project>` - Project ID or name
- `<column>` - Column ID or name

**Examples:**
```bash
wp bcr column get 37594834 7415984407
wp bcr column get "buddypress" "bugs"
```

### `wp bcr column cards`

List all cards in a column.

**Arguments:**
- `<project>` - Project ID or name
- `<column>` - Column ID or name

**Options:**
- `--format=<format>` - Output format (table|json|csv|count)

**Examples:**
```bash
wp bcr column cards 37594834 7415984407
wp bcr column cards "buddypress" "bugs"
wp bcr column cards 37594834 "bugs" --format=count
```

### `wp bcr column discover`

Manually discover columns in a project.

**Arguments:**
- `<project>` - Project ID

**Options:**
- `--range=<start,end>` - ID range to scan

**Examples:**
```bash
wp bcr column discover 37594834
wp bcr column discover 37594834 --range=7415984400,7415984450
```

---

## Card Management

### `wp bcr card list`

List all cards in a project.

**Arguments:**
- `<project>` - Project ID or name

**Options:**
- `--column=<column>` - Filter by column name/ID
- `--assignee=<name>` - Filter by assignee
- `--format=<format>` - Output format (table|json|csv)

**Examples:**
```bash
wp bcr card list 37594834
wp bcr card list "buddypress" --column=bugs
wp bcr card list 37594834 --assignee=john --format=json
```

### `wp bcr card get`

Get details for a specific card.

**Arguments:**
- `<project>` - Project ID or name
- `<card_id>` - Card ID

**Examples:**
```bash
wp bcr card get 37594834 123456
wp bcr card get "buddypress" 123456
```

### `wp bcr card create`

Create a new card.

**Arguments:**
- `<project>` - Project ID or name

**Options:**
- `--title=<title>` - Card title (required)
- `--content=<content>` - Card description
- `--column=<column>` - Column name or ID
- `--assignee=<id>` - Assignee user ID
- `--due=<date>` - Due date (YYYY-MM-DD)

**Examples:**
```bash
wp bcr card create 37594834 --title="Fix login bug" --column=bugs
wp bcr card create "buddypress" --title="New feature" --content="Details here"
wp bcr card create 37594834 --title="Task" --assignee=49507644 --due="2025-01-15"
```

### `wp bcr card update`

Update an existing card.

**Arguments:**
- `<project>` - Project ID or name
- `<card_id>` - Card ID

**Options:**
- `--title=<title>` - New title
- `--content=<content>` - New content
- `--assignee=<id>` - New assignee
- `--due=<date>` - New due date

**Examples:**
```bash
wp bcr card update 37594834 123456 --title="Updated title"
wp bcr card update "buddypress" 123456 --content="New description"
wp bcr card update 37594834 123456 --assignee=49507644
```

### `wp bcr card move`

Move a card to a different column.

**Arguments:**
- `<project>` - Project ID or name
- `<card_id>` - Card ID
- `<column>` - Target column name or ID

**Options:**
- `--position=<num>` - Position in the column (1-based)

**Examples:**
```bash
wp bcr card move 37594834 123456 7415984408
wp bcr card move "buddypress" 123456 "development"
wp bcr card move 37594834 123456 "testing" --position=1
```

### `wp bcr card delete`

Delete a card.

**Arguments:**
- `<project>` - Project ID or name
- `<card_id>` - Card ID

**Options:**
- `--confirm` - Skip confirmation prompt

**Examples:**
```bash
wp bcr card delete 37594834 123456
wp bcr card delete "buddypress" 123456 --confirm
```

### `wp bcr card search`

Search for cards in a project.

**Arguments:**
- `<project>` - Project ID or name
- `<search_term>` - Search term

**Examples:**
```bash
wp bcr card search 37594834 "login"
wp bcr card search "buddypress" "bug"
```

### `wp bcr card filter`

Filter cards by various criteria.

**Arguments:**
- `<project>` - Project ID or name

**Options:**
- `--status=<status>` - Filter by column/status
- `--created=<date>` - Filter by creation date
- `--has-attachments` - Only cards with attachments

**Examples:**
```bash
wp bcr card filter 37594834 --status=bugs
wp bcr card filter "buddypress" --created="2025-01-01"
wp bcr card filter 37594834 --has-attachments
```

---

## Comments & Activity

### `wp bcr read`

Read a card or todo with all details.

**Arguments:**
- `<url>` - Full Basecamp URL

**Options:**
- `--comments` - Include comments
- `--images` - Detect images in comments
- `--format=<format>` - Output format (table|json|csv)

**Examples:**
```bash
wp bcr read "https://3.basecamp.com/5798509/buckets/37594834/card_tables/cards/123456"
wp bcr read "URL" --comments
wp bcr read "URL" --comments --images --format=json
```

### `wp bcr comment`

Post a comment to a card or todo.

**Arguments:**
- `<url>` - Full Basecamp URL
- `<comment>` - Comment text

**Examples:**
```bash
wp bcr comment "https://3.basecamp.com/..." "This is fixed"
wp bcr comment "URL" "Ready for review"
```

### `wp bcr comment add`

Add a comment to a card (alternative syntax).

**Arguments:**
- `<project>` - Project ID or name
- `<card_id>` - Card ID
- `<comment>` - Comment text

**Examples:**
```bash
wp bcr comment add 37594834 123456 "Working on this"
wp bcr comment add "buddypress" 123456 "Ready for QA"
```

### `wp bcr extract_feedback`

Extract feedback from comments.

**Arguments:**
- `<url>` - Full Basecamp URL

**Options:**
- `--plugin=<name>` - Filter by plugin name

**Examples:**
```bash
wp bcr extract_feedback "URL" --plugin=tutorlms
wp bcr extract_feedback "URL" --plugin=buddypress
```

### `wp bcr feedback list`

List feedback for a project.

**Arguments:**
- `<project>` - Project ID or name

**Options:**
- `--plugin=<name>` - Filter by plugin name

**Examples:**
```bash
wp bcr feedback list 37594834 --plugin=buddypress
wp bcr feedback list "buddypress" --plugin=tutorlms
```

---

## Automation & Monitoring

### `wp bcr automate`

Run automation workflows on a project.

**Arguments:**
- `<project>` - Project ID or name

**Options:**
- `--auto-assign` - Auto-assign unassigned cards
- `--move-completed` - Move completed cards to done
- `--escalate-overdue` - Escalate overdue tasks
- `--balance-workload` - Balance team workload
- `--all` - Run all automation workflows

**Examples:**
```bash
wp bcr automate "checkins pro" --all
wp bcr automate 37594834 --auto-assign
wp bcr automate "buddypress" --escalate-overdue --balance-workload
```

### `wp bcr monitor`

Monitor system health and performance.

**Options:**
- `--alerts` - Check for system alerts
- `--report` - Generate comprehensive report
- `--export` - Export metrics data
- `--api` - API usage statistics
- `--performance` - Performance metrics
- `--cache` - Cache statistics

**Examples:**
```bash
wp bcr monitor
wp bcr monitor --alerts
wp bcr monitor --report
wp bcr monitor api
wp bcr monitor performance
```

### `wp bcr stats`

Get statistics for projects and system.

**Sub-commands:**
- `project <id>` - Project statistics
- `columns <id>` - Column distribution
- `activity <id>` - Recent activity

**Options:**
- `--days=<num>` - Number of days for activity

**Examples:**
```bash
wp bcr stats project 37594834
wp bcr stats columns "buddypress"
wp bcr stats activity 37594834 --days=7
```

### `wp bcr logs`

Manage system logs.

**Options:**
- `--cleanup` - Clean old log files
- `--days=<num>` - Days to keep (with cleanup)
- `--export` - Export logs
- `--start=<date>` - Export start date
- `--end=<date>` - Export end date

**Examples:**
```bash
wp bcr logs
wp bcr logs --cleanup --days=14
wp bcr logs --export --start=2025-01-01 --end=2025-01-31
```

### `wp bcr log view`

View system logs.

**Options:**
- `--level=<level>` - Filter by log level (error|warning|info|debug)
- `--tail=<num>` - Number of recent entries

**Examples:**
```bash
wp bcr log view
wp bcr log view --level=error
wp bcr log view --tail=50
```

### `wp bcr log clear`

Clear all system logs.

**Options:**
- `--confirm` - Skip confirmation prompt

**Examples:**
```bash
wp bcr log clear
wp bcr log clear --confirm
```

---

## Legacy Commands

These commands are maintained for backward compatibility.

### `wp bcr find`

Legacy project search command.

**Arguments:**
- `<search_term>` - Search term

**Options:**
- `--show-details` - Show detailed results

**Examples:**
```bash
wp bcr find "checkins"
wp bcr find "bp" --show-details
```

### `wp bcr overview`

Portfolio health overview.

**Examples:**
```bash
wp bcr overview
```

### `wp bcr project_cards`

Detailed project analysis.

**Arguments:**
- `<project_id>` - Project ID

**Examples:**
```bash
wp bcr project_cards 37594834
```

---

## Debugging & Troubleshooting

### `wp bcr debug`

Debug and test system components.

**Options:**
- `--enable` - Enable debug mode
- `--disable` - Disable debug mode
- `--test-api` - Test API connectivity
- `--show-config` - Show current configuration

**Examples:**
```bash
wp bcr debug --enable
wp bcr debug --test-api
wp bcr debug --show-config
```

---

## Output Formats

Most commands support multiple output formats:

- **table** (default) - Human-readable table format
- **json** - JSON format for scripting
- **csv** - CSV format for spreadsheets
- **count** - Simple count (where applicable)

**Example:**
```bash
wp bcr project list --format=json
wp bcr column cards 37594834 "bugs" --format=count
```

---

## Exit Codes

- **0** - Success
- **1** - General error
- **2** - Authentication error
- **3** - Not found error
- **4** - Permission error
- **5** - API error

---

## Environment Variables

- `BASECAMP_ACCOUNT_ID` - Override account ID
- `BASECAMP_DEBUG` - Enable debug mode
- `BASECAMP_CACHE_TTL` - Cache TTL in seconds

---

## Configuration Files

The plugin stores configuration in:
- **Database**: WordPress options table
- **Index**: `wp-content/uploads/basecamp-index/`
- **Logs**: `wp-content/uploads/basecamp-logs/`

---

## Best Practices

1. **Build index regularly** - Run `wp bcr index build` weekly
2. **Use fuzzy search** - Don't worry about exact names
3. **Check auth status** - Run `wp bcr auth --check` if errors occur
4. **Monitor performance** - Use `wp bcr monitor` regularly
5. **Clean logs** - Run `wp bcr logs --cleanup` monthly

---

## Examples of Complex Workflows

### Daily Bug Report
```bash
#!/bin/bash
wp bcr index refresh
for project in $(wp bcr project list --format=json | jq -r '.[] | .id'); do
  bugs=$(wp bcr column cards $project "bugs" --format=count 2>/dev/null)
  if [ "$bugs" -gt "0" ]; then
    echo "Project $project: $bugs bugs"
  fi
done
```

### Automated Card Movement
```bash
# Move all cards from bugs to development
wp bcr card list 37594834 --column=bugs --format=json | \
  jq -r '.[] | .id' | \
  xargs -I {} wp bcr card move 37594834 {} "development"
```

### Project Health Check
```bash
PROJECT="buddypress business"
wp bcr project info "$PROJECT"
for col in bugs development testing done; do
  count=$(wp bcr column cards "$PROJECT" "$col" --format=count)
  echo "$col: $count cards"
done
```

---

*Basecamp Pro Automation Suite v5.0 - Complete CLI Reference*