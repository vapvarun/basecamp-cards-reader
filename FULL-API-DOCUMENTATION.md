# Basecamp Cards Reader - Full API Documentation

## Version 2.0.0

Complete Basecamp API integration for WordPress with full support for projects, cards, columns, steps, and more.

## Table of Contents

1. [Installation & Setup](#installation--setup)
2. [OAuth Configuration](#oauth-configuration)
3. [Available Scopes](#available-scopes)
4. [WP-CLI Commands](#wp-cli-commands)
5. [PHP API Usage](#php-api-usage)
6. [API Endpoints Coverage](#api-endpoints-coverage)

## Installation & Setup

1. Install and activate the plugin
2. Navigate to **Settings → Basecamp Reader** in WordPress admin
3. Create a Basecamp app at https://launchpad.37signals.com/integrations
4. Configure OAuth credentials

## OAuth Configuration

The plugin now requests all available Basecamp API scopes:

- `public` - Read public data
- `read` - Read all data
- `write` - Write data
- `delete` - Delete data

## Available Scopes

The plugin provides comprehensive coverage of the Basecamp API v3:

### Projects
- List all projects
- Get project details
- Create new projects
- Update existing projects
- Trash projects

### Card Tables
- List card tables
- Get card table details
- Manage columns
- Manage cards
- Manage steps

### People & Permissions
- List all people
- Get person details
- List project members
- List pingable people

### Activity & Events
- View all events
- View project events
- View recording events

## WP-CLI Commands

### Authentication

```bash
# Check authentication status
wp bcr auth --check

# Reset authentication
wp bcr auth --reset
```

### Projects

```bash
# List all projects
wp bcr project list

# List archived projects
wp bcr project list --status=archived

# Get project details
wp bcr project get --id=12345678

# Create new project
wp bcr project create --name="New Project" --description="Description"

# Update project
wp bcr project update --id=12345678 --name="Updated Name"

# Trash project
wp bcr project trash --id=12345678
```

### Card Tables & Columns

```bash
# List columns in a card table
wp bcr cards list-columns --project=12345678 --table=87654321

# List cards in a column
wp bcr cards list-cards --project=12345678 --column=11111111

# Get specific card
wp bcr cards get-card --project=12345678 --card=22222222

# Create new card
wp bcr cards create-card \
  --project=12345678 \
  --column=11111111 \
  --title="New Card" \
  --content="Card description" \
  --due-on=2024-12-31 \
  --assignees=123,456

# Update card
wp bcr cards update-card \
  --project=12345678 \
  --card=22222222 \
  --title="Updated Title" \
  --completed=true

# Move card to different column
wp bcr cards move-card \
  --project=12345678 \
  --card=22222222 \
  --to-column=33333333 \
  --position=1

# Trash card
wp bcr cards trash-card --project=12345678 --card=22222222
```

### Card Steps

```bash
# List steps on a card
wp bcr steps list --project=12345678 --card=87654321

# Create new step
wp bcr steps create \
  --project=12345678 \
  --card=87654321 \
  --title="First step"

# Complete a step
wp bcr steps complete --project=12345678 --step=11111111

# Uncomplete a step
wp bcr steps uncomplete --project=12345678 --step=11111111

# Reorder steps
wp bcr steps reorder \
  --project=12345678 \
  --card=87654321 \
  --order=111,222,333
```

### People Management

```bash
# List all people
wp bcr people list

# Get person details
wp bcr people get --id=123456

# List project members
wp bcr people project-people --project=87654321

# List pingable people
wp bcr people pingable
```

### Activity & Events

```bash
# Show all recent events
wp bcr events all

# Show project events
wp bcr events project --project=12345678

# Show events since specific date
wp bcr events all --since=2024-01-01T00:00:00Z

# Show recording events
wp bcr events recording --project=12345678 --recording=999999
```

### Quick Access Commands

```bash
# Read any Basecamp URL
wp bcr read https://3.basecamp.com/12345/buckets/67890/card_tables/cards/11111

# Read with comments
wp bcr read https://3.basecamp.com/12345/buckets/67890/card_tables/cards/11111 --comments

# Post comment to any URL
wp bcr comment https://3.basecamp.com/12345/buckets/67890/card_tables/cards/11111 "This is resolved"
```

## PHP API Usage

### Initialize the API

```php
// Get saved token
$token_data = get_option('bcr_token_data');
$api = new Basecamp_API($token_data['access_token']);

// Auto-detect account ID
$api->get_account_id();
```

### Projects

```php
// List projects
$projects = $api->get_projects();

// Get specific project
$project = $api->get_project($project_id);

// Create project
$new_project = $api->create_project('Project Name', 'Description');

// Update project
$api->update_project($project_id, 'New Name', 'New Description');

// Trash project
$api->trash_project($project_id);
```

### Card Operations

```php
// Get columns
$columns = $api->get_columns($project_id, $card_table_id);

// Get cards in column
$cards = $api->get_cards($project_id, $column_id);

// Create card
$new_card = $api->create_card(
    $project_id,
    $column_id,
    'Card Title',
    'Card content',
    '2024-12-31', // due date
    [123, 456]    // assignee IDs
);

// Update card
$api->update_card($project_id, $card_id, [
    'title' => 'Updated Title',
    'content' => 'Updated content',
    'completed' => true
]);

// Move card
$api->move_card($project_id, $card_id, $new_column_id, $position);

// Trash card
$api->trash_card($project_id, $card_id);
```

### Steps

```php
// Get steps
$steps = $api->get_steps($project_id, $card_id);

// Create step
$new_step = $api->create_step($project_id, $card_id, 'Step title');

// Complete step
$api->complete_step($project_id, $step_id);

// Reorder steps
$api->reorder_steps($project_id, $card_id, [111, 222, 333]);
```

### Comments

```php
// Get comments
$comments = $api->get_comments($project_id, $recording_id);

// Post comment
$api->create_comment($project_id, $recording_id, 'Comment text');

// Update comment
$api->update_comment($project_id, $comment_id, 'Updated text');

// Delete comment
$api->trash_comment($project_id, $comment_id);
```

### People

```php
// Get all people
$people = $api->get_people();

// Get project members
$members = $api->get_project_people($project_id);

// Get person details
$person = $api->get_person($person_id);

// Get current user info
$me = $api->get_my_info();
```

### Events & Activity

```php
// Get all events
$events = $api->get_events();

// Get project events
$project_events = $api->get_project_events($project_id);

// Get events since date
$recent_events = $api->get_events(1, '2024-01-01T00:00:00Z');
```

## API Endpoints Coverage

The plugin provides full coverage of Basecamp API v3 endpoints:

### ✅ Implemented Endpoints

- **Projects** - Full CRUD operations
- **Card Tables** - List and manage
- **Card Columns** - Create, update, move, delete
- **Cards** - Full CRUD, move between columns
- **Card Steps** - Create, complete, reorder
- **Comments** - Read, create, update, delete
- **Todos** - Create, update, complete
- **Todo Lists** - List and manage
- **People** - List, search, project members
- **Events** - Activity tracking
- **Uploads** - File attachments
- **Campfires** - Chat messages

### 🔄 Partial Implementation

- **Messages** - Basic support via comments
- **Documents** - Via uploads
- **Schedule Entries** - Basic support

### 📝 Planned Features

- **Webhooks** - Real-time updates
- **Templates** - Project templates
- **Client Visibility** - Client-specific views
- **Question/Answer** - Q&A boards

## Error Handling

The API includes comprehensive error handling:

```php
$response = $api->get_project($project_id);

if (!empty($response['error'])) {
    // Handle error
    echo 'Error: ' . $response['message'];
} else {
    // Process data
    $project = $response['data'];
}
```

## Token Management

The plugin automatically handles token refresh:

```php
// Check if token is expired
if ($api->is_token_expired()) {
    // Auto-refresh
    $api->ensure_fresh_token();
}
```

## Rate Limiting

Basecamp API has rate limits. The plugin respects these limits:

- 50 requests per 10 seconds
- Headers include rate limit information
- Automatic retry on rate limit errors

## Security

- OAuth 2.0 authentication
- Secure token storage in WordPress options
- Nonce verification for AJAX calls
- Capability checks for admin operations

## Support

For issues or feature requests, please contact the plugin developer.

## Changelog

### Version 2.0.0
- Complete API rewrite
- Full Basecamp API v3 coverage
- Extended WP-CLI commands
- Improved error handling
- Automatic token refresh
- Support for all API scopes

### Version 1.2.0
- Initial OAuth implementation
- Basic card and todo reading
- Comment posting

## License

GPL v2 or later