# Basecamp Cards Reader (Clean)

A WordPress plugin that reads Basecamp cards and todos on-demand without local storage or image downloading.

## Description

The Basecamp Cards Reader plugin provides a clean, lightweight solution for reading Basecamp cards and todos directly from your WordPress admin. It fetches data on-demand using OAuth 2.0 authentication and includes comprehensive WP-CLI commands for command-line access.

## Features

- **OAuth 2.0 Authentication** - Secure Basecamp API integration
- **On-Demand Reading** - No local storage, fetches fresh data each time
- **Cards & Todos Support** - Automatic type detection from URLs
- **WP-CLI Commands** - Full command-line interface
- **Multiple Output Formats** - Table, JSON, CSV support
- **Image Attachment Detection** - Identifies images without downloading
- **Feedback Extraction** - Filter comments by plugin keywords

## Installation

1. Upload the plugin files to `/wp-content/plugins/basecamp-cards-reader/`
2. Activate the plugin through the 'Plugins' screen in WordPress
3. Configure Basecamp authentication via Settings > Basecamp Reader

## Configuration

### OAuth 2.0 Setup

1. Create a Basecamp app at https://launchpad.37signals.com/integrations
2. Set redirect URI to: `http://yoursite.com/wp-admin/options-general.php?page=basecamp-reader`
3. Enter Client ID and Client Secret in plugin settings
4. Click "Authorize with Basecamp" to complete setup

## WP-CLI Commands

### Authentication Management

```bash
# Check authentication status
wp bcr auth --check

# Reset authentication
wp bcr auth --reset
```

### Reading Cards and Todos

```bash
# Basic card reading
wp bcr read "https://3.basecamp.com/123/buckets/456/card_tables/cards/789"

# With comments in table format
wp bcr read "https://3.basecamp.com/123/buckets/456/card_tables/cards/789" --comments

# With comments and image detection
wp bcr read "https://3.basecamp.com/123/buckets/456/card_tables/cards/789" --comments --images

# JSON output format
wp bcr read "https://3.basecamp.com/123/buckets/456/card_tables/cards/789" --comments --format=json

# Reading todos (same commands work)
wp bcr read "https://3.basecamp.com/123/buckets/456/todos/789" --comments
```

### Extract Feedback

```bash
# Extract feedback for specific plugin
wp bcr extract_feedback "https://3.basecamp.com/123/buckets/456/card_tables/cards/789" --plugin=tutorlms

# Extract all feedback
wp bcr extract_feedback "https://3.basecamp.com/123/buckets/456/card_tables/cards/789"
```

## Output Formats

### Table Format (Default)
Clean, readable output perfect for terminal viewing.

### JSON Format
```bash
wp bcr read "URL" --format=json
```
Structured data perfect for scripting and automation.

### CSV Format
```bash
wp bcr read "URL" --format=csv
```
Suitable for importing into spreadsheets.

## Advanced Usage

### Piping and Filtering
```bash
# Extract feedback and save to file
wp bcr extract_feedback "URL" --plugin=tutorlms > tutorlms-issues.txt

# Get JSON and process with jq
wp bcr read "URL" --comments --format=json | jq '.comments[].content'

# Count comments
wp bcr read "URL" --comments --format=json | jq '.comments | length'
```

### Batch Processing
```bash
#!/bin/bash
CARDS=(
  "https://3.basecamp.com/.../cards/123"
  "https://3.basecamp.com/.../cards/456"
)

for card in "${CARDS[@]}"; do
  echo "Processing: $card"
  wp bcr extract_feedback "$card" --plugin=tutorlms >> all-feedback.txt
done
```

## URL Format Support

The plugin automatically detects and handles:

- **Cards**: `https://3.basecamp.com/ACCOUNT/buckets/PROJECT/card_tables/cards/CARD_ID`
- **Todos**: `https://3.basecamp.com/ACCOUNT/buckets/PROJECT/todos/TODO_ID`

## Troubleshooting

### Authentication Issues
```bash
# Check current status
wp bcr auth --check

# If expired, reset and reconfigure
wp bcr auth --reset
# Then reconfigure via admin: wp-admin/options-general.php?page=basecamp-reader
```

### URL Format Issues
- Ensure URLs contain either `/cards/` or `/todos/`
- URLs must be from `*.basecamp.com`
- Check that the URL is accessible with your authentication

### No Output
- Verify the card/todo exists and is accessible
- Check authentication with `wp bcr auth --check`
- Ensure you have proper permissions in Basecamp

## Benefits Over Manual Testing

### ✅ Advantages of CLI Approach:
- **No Root Folder Clutter**: No test files in project root
- **Reusable Commands**: Same commands work for any Basecamp URL
- **Flexible Output**: Multiple formats (table, JSON, CSV)
- **Scriptable**: Can be used in automated workflows
- **Type Detection**: Automatically handles cards vs todos
- **Focused Extraction**: Filter feedback by plugin name
- **Professional**: Clean, maintainable approach

## Requirements

- WordPress 5.0 or higher
- PHP 7.4 or higher
- WP-CLI (for command-line features)
- Valid Basecamp account with API access

## Support

For support and feature requests, please visit [Wbcom Designs](https://wbcomdesigns.com).

## Changelog

### 1.0.0
- Initial release
- OAuth 2.0 authentication
- WP-CLI commands for cards and todos
- Multiple output formats
- Automatic type detection
- Feedback extraction functionality

## License

This plugin is licensed under the GPL v2 or later.

## Author

**Wbcom Designs**  
Website: https://wbcomdesigns.com