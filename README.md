# Basecamp Pro Automation Suite

A comprehensive WordPress plugin for automating Basecamp project management across 100+ projects with full API integration and intelligent automation capabilities.

## Description

The Basecamp Pro Automation Suite provides enterprise-level automation for managing multiple Basecamp projects. It features OAuth 2.0 authentication, intelligent fuzzy search, local indexing for performance, and a comprehensive CLI interface designed for both human operators and AI automation tools.

## Core Features

- **Full Basecamp API v3 Coverage** - Complete integration with all Basecamp endpoints
- **OAuth 2.0 Authentication** - Secure API integration with all scopes (read, write, delete)
- **Intelligent Project Search** - Fuzzy matching, acronym support, and pattern recognition
- **Local Indexing System** - JSON-based caching for 100+ projects with minimal API calls
- **Column-First Architecture** - Automatic card classification based on workflow columns
- **Enterprise CLI Interface** - 50+ commands for complete project automation
- **AI-Optimized Design** - Simple commands designed for Claude and other AI tools
- **Database Configuration** - Secure storage of sensitive data, no hardcoding
- **Performance Monitoring** - Built-in API usage tracking and optimization

## Installation

1. Upload plugin files to `/wp-content/plugins/basecamp-cards-reader/`
2. Activate the plugin through the 'Plugins' screen in WordPress
3. Configure authentication and account settings via CLI

## Initial Configuration

### 1. OAuth 2.0 Setup

```bash
# Create Basecamp app at https://launchpad.37signals.com/integrations
# Set redirect URI to: http://yoursite.com/wp-admin/options-general.php?page=basecamp-reader

# Configure via admin interface
# Navigate to Settings > Basecamp Reader
# Enter Client ID and Client Secret
# Click "Authorize with Basecamp"
```

### 2. Account Configuration

```bash
# Set your Basecamp account ID (found in any Basecamp URL)
wp bcr settings --account-id=5798509

# Verify settings
wp bcr settings --list
```

### 3. Build Initial Index

```bash
# Build complete project index (recommended for 100+ projects)
wp bcr index build

# Or build with specific options
wp bcr index build --force --include-archived
```

## Complete CLI Command Reference

### Authentication & Settings

```bash
# Authentication management
wp bcr auth --check                          # Check authentication status
wp bcr auth --reset                          # Reset authentication
wp bcr auth --refresh                        # Refresh access token

# Settings management
wp bcr settings --account-id=5798509         # Set Basecamp account ID
wp bcr settings --list                       # Show all settings
wp bcr settings --clear                      # Clear all settings
```

### Project Management

```bash
# List and search projects
wp bcr project list                          # List all projects
wp bcr project list --search="buddy"         # Search with fuzzy matching
wp bcr project list --archived                # Include archived projects
wp bcr project list --format=json            # JSON output

# Get project details
wp bcr project get "buddypress"              # Get by name (fuzzy match)
wp bcr project get 37594834                  # Get by ID
wp bcr project get --id=37594834             # Explicit ID

# Project information
wp bcr project info "buddypress business"    # Detailed project info
wp bcr project tools "buddypress"            # List enabled tools
wp bcr project people "buddypress"           # List project members
```

### Index Management

```bash
# Build and update index
wp bcr index build                           # Build complete index
wp bcr index build --force                   # Force rebuild
wp bcr index build --include-archived        # Include archived projects

# Index operations
wp bcr index refresh                         # Refresh existing index
wp bcr index stats                           # Show index statistics
wp bcr index clear                           # Clear index
wp bcr index search "buddy"                  # Search in index

# Column discovery
wp bcr index columns 37594834                # Discover all columns in project
wp bcr index columns "buddypress business"   # By project name
```

### Column & List Operations

```bash
# List columns
wp bcr column list 37594834                  # List all columns
wp bcr column list "buddypress"              # By project name
wp bcr column list 37594834 --format=json    # JSON format

# Get column details
wp bcr column get 37594834 7415984407        # Get specific column
wp bcr column cards 37594834 7415984407      # List cards in column
wp bcr column cards "buddypress" "bugs"      # By names
```

### Card Management

```bash
# List cards
wp bcr card list 37594834                    # List all cards in project
wp bcr card list "buddypress"                # By project name
wp bcr card list 37594834 --column=bugs      # Filter by column
wp bcr card list 37594834 --assignee=john    # Filter by assignee

# Get card details
wp bcr card get 37594834 123456              # Get specific card
wp bcr card get "buddypress" 123456          # By project name

# Create cards
wp bcr card create 37594834 --title="Fix login bug" --content="Details here" --column=bugs
wp bcr card create "buddypress" --title="New feature" --column=7415984407
wp bcr card create 37594834 --title="Task" --assignee=49507644 --due="2025-01-15"

# Update cards
wp bcr card update 37594834 123456 --title="Updated title"
wp bcr card update 37594834 123456 --content="New description"
wp bcr card update 37594834 123456 --assignee=49507644 --due="2025-01-20"

# Move cards between columns
wp bcr card move 37594834 123456 7415984408  # Move to column by ID
wp bcr card move "buddypress" 123456 "development"  # By names
wp bcr card move 37594834 123456 7415984409 --position=1  # Specific position

# Delete cards
wp bcr card delete 37594834 123456           # Delete card
wp bcr card delete "buddypress" 123456 --confirm  # With confirmation
```

### Comments & Activity

```bash
# Read comments
wp bcr read "https://3.basecamp.com/..." --comments
wp bcr read "https://3.basecamp.com/..." --comments --images

# Post comments
wp bcr comment "https://3.basecamp.com/..." "Comment text"
wp bcr comment add 37594834 123456 "This is fixed"
wp bcr comment add "buddypress" 123456 "Ready for review"

# Extract feedback
wp bcr extract_feedback "URL" --plugin=tutorlms
wp bcr feedback list 37594834 --plugin=buddypress
```

### Workflow Automation

```bash
# Card workflow examples
# Create bug -> Move to development -> Move to testing
wp bcr card create 37594834 --title="Login bug" --column=bugs
wp bcr card move 37594834 [CARD_ID] "development"
wp bcr card move 37594834 [CARD_ID] "testing"

# Batch operations
wp bcr card list 37594834 --column=bugs --format=json | \
  jq -r '.[] | .id' | \
  xargs -I {} wp bcr card move 37594834 {} "development"

# Monitor project status
wp bcr project info "buddypress" --format=json | \
  jq '.columns[] | {name: .title, cards: .cards_count}'
```

### Advanced Search & Filtering

```bash
# Fuzzy project search
wp bcr project search "buddy"                # Matches: BuddyPress, buddy-theme, etc.
wp bcr project search "bpbp"                 # Acronym matching
wp bcr project search "business profile"     # Word-based matching

# Card filtering
wp bcr card search 37594834 "login"          # Search in titles/content
wp bcr card filter 37594834 --status=bugs    # By column/status
wp bcr card filter 37594834 --created="2025-01-01"  # By date
wp bcr card filter 37594834 --has-attachments  # With attachments
```

### Monitoring & Statistics

```bash
# Project statistics
wp bcr stats project 37594834                # Project overview
wp bcr stats project "buddypress"            # By name
wp bcr stats columns 37594834                # Column distribution
wp bcr stats activity 37594834 --days=7      # Recent activity

# System monitoring
wp bcr monitor api                           # API usage stats
wp bcr monitor performance                   # Performance metrics
wp bcr monitor cache                         # Cache statistics
```

### Debugging & Troubleshooting

```bash
# Debug mode
wp bcr debug --enable                        # Enable debug logging
wp bcr debug --test-api                      # Test API connectivity
wp bcr debug --show-config                   # Show configuration

# Log viewing
wp bcr log view                              # View recent logs
wp bcr log view --level=error                # Filter by level
wp bcr log clear                             # Clear logs
```

## Usage Examples

### Example 1: Bug Management Workflow

```bash
# Find bugs in BuddyPress Business Profile
wp bcr project search "buddypress business"
wp bcr column cards 37594834 "bugs"

# Create new bug report
wp bcr card create 37594834 --title="Login fails on mobile" --column=bugs

# Move bug through workflow
CARD_ID=123456
wp bcr card move 37594834 $CARD_ID "development"
wp bcr comment add 37594834 $CARD_ID "Working on fix"
wp bcr card move 37594834 $CARD_ID "testing"
wp bcr comment add 37594834 $CARD_ID "Ready for QA"
```

### Example 2: Project Status Report

```bash
# Get comprehensive project status
PROJECT="buddypress business profile"

# Build fresh index
wp bcr index build

# Get project info
wp bcr project info "$PROJECT"

# Check each column
for column in bugs development testing done; do
  echo "=== $column ==="
  wp bcr column cards "$PROJECT" "$column" --format=count
done
```

### Example 3: Automation Script

```bash
#!/bin/bash
# Daily project status automation

# Update index
wp bcr index refresh

# Get all projects with bugs
wp bcr project list --format=json | \
  jq -r '.[] | .id' | \
  while read project_id; do
    bug_count=$(wp bcr column cards $project_id "bugs" --format=count 2>/dev/null)
    if [ "$bug_count" -gt "0" ]; then
      echo "Project $project_id has $bug_count bugs"
    fi
  done
```

### Example 4: AI Integration

```bash
# Commands optimized for Claude/AI usage
# Simple, predictable syntax

# Find project (fuzzy matching handles typos)
wp bcr project search "check ins"

# Get bug count
wp bcr column cards "check-ins pro" "bugs" --format=count

# Create and manage cards
wp bcr card create "check-ins" --title="Fix API timeout" --column=bugs
wp bcr card move "check-ins" 123456 "development"
```

## Performance Optimization

### Local Indexing
- Indexes 100+ projects locally in JSON format
- Reduces API calls by 90%
- Updates incrementally
- Automatic cache invalidation

### API Rate Limiting
- Intelligent request batching
- Automatic retry with backoff
- Concurrent request management
- Usage monitoring and alerts

### Column Discovery
- Scans appropriate ID ranges
- Caches discovered columns
- Pattern-based classification
- Automatic column type detection

## Security Features

- **No Hardcoded Credentials** - All sensitive data in database
- **Token Encryption** - Secure storage of OAuth tokens
- **Permission Validation** - Checks before operations
- **Audit Logging** - Complete activity tracking
- **Secure API Communication** - HTTPS only

## Troubleshooting

### Authentication Issues

```bash
# Check status
wp bcr auth --check

# If expired
wp bcr auth --refresh

# Complete reset
wp bcr auth --reset
# Then reconfigure via admin interface
```

### Index Problems

```bash
# Rebuild index
wp bcr index build --force

# Check index health
wp bcr index stats

# Clear corrupted index
wp bcr index clear
wp bcr index build
```

### Column Discovery Issues

```bash
# Force column discovery
wp bcr index columns 37594834 --force

# Manual column scan
wp bcr column discover 37594834 --range=7415984400,7415984450
```

### API Errors

```bash
# Test connectivity
wp bcr debug --test-api

# Check rate limits
wp bcr monitor api

# View error logs
wp bcr log view --level=error
```

## Requirements

- WordPress 5.0+
- PHP 7.4+
- WP-CLI 2.0+
- Basecamp account with API access
- OAuth 2.0 app configuration

## Architecture

```
basecamp-cards-reader/
├── includes/
│   ├── class-basecamp-api.php           # Core API client
│   ├── class-basecamp-automation.php    # Automation engine
│   ├── class-basecamp-indexer.php       # Local index manager
│   ├── class-basecamp-logger.php        # Logging system
│   ├── class-basecamp-pro.php           # Pro features
│   └── class-bcr-cli-commands-extended.php  # CLI commands
├── admin/
│   └── class-bcr-admin.php              # Admin interface
└── basecamp-cards-reader.php            # Main plugin file
```

## Changelog

### 5.0.0 (2025-01-23)
- Complete rewrite as Basecamp Pro Automation Suite
- Added full API v3 coverage with all scopes
- Implemented local indexing for 100+ projects
- Added 50+ CLI commands
- Column-first architecture for card classification
- Fuzzy search with multiple algorithms
- Database storage for sensitive configuration
- AI-optimized command structure
- Performance monitoring and optimization
- Enterprise-grade error handling

### 2.0.0
- Added project and column management
- Extended CLI command set
- Improved authentication handling

### 1.1.0
- Added comment posting functionality
- Enhanced admin interface
- HTML formatting support

### 1.0.0
- Initial release
- Basic card and todo reading
- OAuth 2.0 authentication

## Support

For support and feature requests, visit [Wbcom Designs](https://wbcomdesigns.com)

## License

GPL v2 or later

## Author

**Wbcom Designs**
Website: https://wbcomdesigns.com

---

*Basecamp Pro Automation Suite - Enterprise automation for 100+ Basecamp projects*