# Basecamp Pro Automation Suite - Command Reference

All commands grouped by functionality for easy reference.

## 🔐 Authentication & Setup

### Initial Configuration
```bash
# OAuth setup (via WordPress Admin)
Navigate to: Settings > Basecamp Reader

# Set account ID (required)
wp bcr settings --account-id=5798509

# View current settings
wp bcr settings --show
```

### Authentication Management
```bash
wp bcr auth --check      # Check authentication status
wp bcr auth --refresh    # Refresh access token
wp bcr auth --reset      # Reset authentication (requires re-auth)
```

## 🔍 Search & Discovery

### Project Search
```bash
wp bcr find "buddy"                      # Fuzzy search projects
wp bcr find "check ins" --show-details   # Search with details
wp bcr project list                      # List all projects
wp bcr project list --archived           # Include archived projects
```

### Index Management
```bash
wp bcr index build                       # Build initial index (required)
wp bcr index build --force               # Force rebuild
wp bcr index refresh                     # Update existing index
wp bcr index stats                       # View index statistics
wp bcr index clear                       # Clear index
```

## 📁 Project Operations

### Project Information
```bash
wp bcr project get 37594834              # Get project by ID
wp bcr project info "buddypress"         # Get project details
wp bcr project tools "buddypress"        # List enabled tools
wp bcr project people "buddypress"       # List team members
```

### Project Analysis
```bash
wp bcr project_cards 37594834            # Complete project overview
wp bcr overview                          # Portfolio health overview
wp bcr status 37594834                   # Project status by columns
```

## 📋 Column Management

```bash
wp bcr list_columns 37594834             # List all columns
wp bcr column cards 37594834 "bugs"      # List cards in column
wp bcr column cards 37594834 7415984407  # List by column ID
```

## 🎯 Card Operations

### Create Cards
```bash
wp bcr cards create-card \
  --project=37594834 \
  --column=7415984407 \
  --title="Bug title" \
  --content="Description" \
  --due-on="2025-01-30" \
  --assignees=49507644
```

### Read & List Cards
```bash
wp bcr cards list-cards --project=37594834 --column=7415984407
wp bcr cards get-card --project=37594834 --card=123456
wp bcr read "https://3.basecamp.com/..." --comments
```

### Update & Move Cards
```bash
# Update card
wp bcr cards update-card \
  --project=37594834 \
  --card=123456 \
  --title="Updated title"

# Move between columns
wp bcr cards move-card \
  --project=37594834 \
  --card=123456 \
  --to-column=7415984414 \
  --position=1
```

### Delete Cards
```bash
wp bcr cards trash-card --project=37594834 --card=123456
```

## 💬 Comments & Activity

```bash
# Post comments
wp bcr comment "https://3.basecamp.com/..." "Comment text"
wp bcr comment add 37594834 123456 "Ready for review"

# Extract feedback
wp bcr extract_feedback "URL" --plugin=buddypress
wp bcr feedback list 37594834 --plugin=tutorlms
```

## 🤖 Automation

```bash
# Run automation workflows
wp bcr automate "project name" --all
wp bcr automate 37594834 --auto-assign
wp bcr automate 37594834 --escalate-overdue
wp bcr automate 37594834 --balance-workload
```

## 📊 Monitoring & Reporting

### System Monitoring
```bash
wp bcr monitor                           # Quick health check
wp bcr monitor --alerts                  # Check alerts
wp bcr monitor --report                  # Full report
wp bcr monitor api                       # API usage stats
wp bcr monitor performance               # Performance metrics
```

### Bug Tracking
```bash
wp bcr bugs                              # Quick bug count across all projects
```

### Statistics
```bash
wp bcr stats project 37594834            # Project statistics
wp bcr stats columns 37594834            # Column distribution
wp bcr stats activity 37594834 --days=7  # Recent activity
```

## 📝 Logging & Debug

### Log Management
```bash
wp bcr logs                              # View log status
wp bcr logs --cleanup --days=14          # Clean old logs
wp bcr log view --level=error            # View error logs
wp bcr log clear                         # Clear all logs
```

### Debugging
```bash
wp bcr debug --enable                    # Enable debug mode
wp bcr debug --test-api                  # Test API connectivity
wp bcr debug --show-config               # Show configuration
```

## 🔄 Common Workflows

### Daily Bug Check
```bash
# 1. Update index
wp bcr index refresh

# 2. Find project
PROJECT_ID=$(wp bcr find "buddypress business" --format=json | jq -r '.[0].id')

# 3. Check bugs
wp bcr column cards $PROJECT_ID "bugs"
```

### Card Workflow (Bug → Development → Testing)
```bash
# Create bug
CARD_ID=$(wp bcr cards create-card \
  --project=37594834 \
  --column=7415984407 \
  --title="Login bug" \
  --format=json | jq -r '.id')

# Move to development
wp bcr cards move-card \
  --project=37594834 \
  --card=$CARD_ID \
  --to-column=7415984414

# Move to testing
wp bcr cards move-card \
  --project=37594834 \
  --card=$CARD_ID \
  --to-column=7415984417
```

### Batch Operations
```bash
# Move all bugs to development
wp bcr cards list-cards --project=37594834 --column=7415984407 --format=json | \
  jq -r '.[] | .id' | \
  xargs -I {} wp bcr cards move-card --project=37594834 --card={} --to-column=7415984414
```

## 📋 Command Options

### Common Options
- `--format=<format>` - Output format: table (default), json, csv
- `--page=<num>` - Pagination for list commands
- `--quiet` - Suppress informational messages

### Format Examples
```bash
wp bcr project list --format=json | jq    # JSON for scripting
wp bcr project list --format=csv > file.csv  # Export to CSV
```

## 🎯 Quick Reference

### Most Used Commands
```bash
wp bcr find "project"                    # Find project
wp bcr project_cards PROJECT_ID          # View all cards
wp bcr column cards PROJECT_ID "bugs"    # View bugs
wp bcr monitor                           # Check system health
```

### Emergency Commands
```bash
wp bcr auth --check                      # Check if authenticated
wp bcr auth --refresh                    # Fix expired token
wp bcr index build --force               # Rebuild broken index
wp bcr debug --test-api                  # Test connectivity
```

## 🏷️ Column IDs Reference

Common column names and their typical types:
- **bugs** - Bug tracking
- **triage** - Initial assessment
- **ready for development** - Development queue
- **in progress** - Active work
- **ready for testing** - QA queue
- **done** - Completed items
- **suggestion** - Feature requests

## 🔢 Exit Codes

- `0` - Success
- `1` - General error
- `2` - Authentication error
- `3` - Not found
- `4` - Permission denied
- `5` - API error

---

*Command Reference - Basecamp Pro Automation Suite v5.0*