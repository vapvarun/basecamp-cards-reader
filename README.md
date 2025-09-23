# Basecamp Pro Automation Suite

WordPress plugin for automating Basecamp project management across 100+ projects.

## ✨ Features

- **Full Basecamp API v3** - Complete integration with OAuth 2.0
- **100+ Project Support** - Local indexing for performance
- **Intelligent Search** - Fuzzy matching finds projects instantly
- **Workflow Automation** - Batch operations and task automation
- **CLI Interface** - 50+ WP-CLI commands

## 📦 Installation

```bash
# Clone to plugins directory
cd wp-content/plugins/
git clone [repository] basecamp-cards-reader

# Activate plugin
wp plugin activate basecamp-cards-reader
```

## ⚡ Quick Start

```bash
# 1. Configure account ID (find in any Basecamp URL)
wp bcr settings --account-id=5798509

# 2. Build project index
wp bcr index build

# 3. Find and manage projects
wp bcr find "project name"
wp bcr project_cards PROJECT_ID
```

## 📖 Documentation

- **[Command Reference](docs/COMMANDS.md)** - All commands by function
- **[Quick Start Guide](docs/getting-started/QUICK-START.md)** - Detailed setup
- **[API Documentation](docs/api/API-DOCUMENTATION.md)** - Integration details
- **[Production Guide](docs/PRODUCTION-SUMMARY.md)** - Deployment guide

## 🎯 Common Commands

### Project Management
```bash
wp bcr find "buddy"                      # Find projects (fuzzy)
wp bcr project_cards 37594834            # View all cards
wp bcr column cards 37594834 "bugs"      # View specific column
```

### Card Operations
```bash
# Create card
wp bcr cards create-card --project=ID --column=ID --title="Title"

# Move card
wp bcr cards move-card --project=ID --card=ID --to-column=ID

# List cards
wp bcr cards list-cards --project=ID --column=ID
```

### System
```bash
wp bcr monitor                           # Health check
wp bcr index stats                       # Statistics
wp bcr auth --check                      # Auth status
```

## 🔧 Configuration

### OAuth Setup
1. Create app at https://launchpad.37signals.com/integrations
2. Set redirect URI: `http://yoursite.com/wp-admin/options-general.php?page=basecamp-reader`
3. Configure in WordPress Admin > Settings > Basecamp Reader

### Settings
```bash
wp bcr settings --account-id=YOUR_ID     # Required
wp bcr settings --show                   # View settings
```

## 📊 Requirements

- WordPress 5.0+
- PHP 7.4+
- WP-CLI 2.0+
- Basecamp account with API access

## 🐛 Troubleshooting

```bash
wp bcr auth --check                      # Check authentication
wp bcr debug --test-api                  # Test API connection
wp bcr index build --force               # Rebuild index
wp bcr log view --level=error            # View errors
```

## 📄 License

GPL v2 or later

## 👥 Support

[Wbcom Designs](https://wbcomdesigns.com)

---

*Version 5.0.0 - Enterprise automation for Basecamp*