# Basecamp Cards Reader - CLI Usage Examples

## Overview
The Basecamp Cards Reader plugin now includes comprehensive WP-CLI commands for reading Basecamp cards and todos directly from the command line.

## Prerequisites
- Plugin must be activated: `wp plugin activate basecamp-cards-reader`
- Basecamp authentication must be configured via admin interface
- WP-CLI must be available

## Available Commands

### 1. Check Authentication Status
```bash
wp bcr auth --check
```

### 2. Reset Authentication
```bash
wp bcr auth --reset
```

### 3. Read a Basecamp Card
```bash
# Basic card reading
wp bcr read "https://3.basecamp.com/5798509/buckets/37557560/card_tables/cards/9010883489"

# With comments in table format
wp bcr read "https://3.basecamp.com/5798509/buckets/37557560/card_tables/cards/9010883489" --comments

# With comments and images
wp bcr read "https://3.basecamp.com/5798509/buckets/37557560/card_tables/cards/9010883489" --comments --images

# JSON output format
wp bcr read "https://3.basecamp.com/5798509/buckets/37557560/card_tables/cards/9010883489" --comments --format=json
```

### 4. Read a Basecamp Todo
```bash
# Basic todo reading
wp bcr read "https://3.basecamp.com/5798509/buckets/37557560/todos/1234567890"

# With comments
wp bcr read "https://3.basecamp.com/5798509/buckets/37557560/todos/1234567890" --comments
```

### 5. Extract Specific Feedback
```bash
# Extract feedback for TutorLMS plugin
wp bcr extract_feedback "https://3.basecamp.com/5798509/buckets/37557560/card_tables/cards/9010883489" --plugin=tutorlms

# Extract feedback for any plugin
wp bcr extract_feedback "https://3.basecamp.com/5798509/buckets/37557560/card_tables/cards/9010883489" --plugin=buddypress

# Extract all feedback (no filter)
wp bcr extract_feedback "https://3.basecamp.com/5798509/buckets/37557560/card_tables/cards/9010883489"
```

## Real-World Usage Examples

### Development Workflow
```bash
# 1. Check if authentication is working
wp bcr auth --check

# 2. Read a bug report card with all comments
wp bcr read "https://3.basecamp.com/.../cards/9010883489" --comments --images

# 3. Extract specific plugin feedback for development
wp bcr extract_feedback "https://3.basecamp.com/.../cards/9010883489" --plugin=tutorlms

# 4. Get JSON output for parsing in scripts
wp bcr read "https://3.basecamp.com/.../cards/9010883489" --comments --format=json > feedback.json
```

### Quality Assurance
```bash
# Extract all feedback for testing checklist
wp bcr extract_feedback "https://3.basecamp.com/.../cards/9010883489" > qa-feedback.txt

# Check both cards and todos for complete context
wp bcr read "https://3.basecamp.com/.../cards/123" --comments
wp bcr read "https://3.basecamp.com/.../todos/456" --comments
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
# Process multiple URLs (example script)
#!/bin/bash
CARDS=(
  "https://3.basecamp.com/.../cards/123"
  "https://3.basecamp.com/.../cards/456"
  "https://3.basecamp.com/.../cards/789"
)

for card in "${CARDS[@]}"; do
  echo "Processing: $card"
  wp bcr extract_feedback "$card" --plugin=tutorlms >> all-feedback.txt
done
```

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

### ❌ Problems with Test Files Approach:
- Clutters project root with temporary files
- Hard-coded URLs and parameters
- Not reusable for other projects
- Difficult to maintain and update
- Mixed testing and production code
- No standardized output format

## Integration Examples

### With TutorLMS Development
```bash
# Extract specific TutorLMS issues
wp bcr extract_feedback "https://3.basecamp.com/.../cards/9010883489" --plugin=tutorlms

# Get structured data for automated issue tracking
wp bcr read "https://3.basecamp.com/.../cards/9010883489" --comments --format=json | jq '.comments[] | {author: .author, content: .content, date: .created_at}'
```

This approach is much cleaner, more professional, and eliminates the need for multiple test files in the project root directory.