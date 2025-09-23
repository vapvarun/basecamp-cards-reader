# Quick Start Guide

Get up and running with Basecamp Pro Automation Suite in 5 minutes.

## 🚀 Installation

1. **Install the Plugin**
   ```bash
   cd wp-content/plugins/
   git clone [repository] basecamp-cards-reader
   ```

2. **Activate in WordPress**
   ```bash
   wp plugin activate basecamp-cards-reader
   ```

## 🔐 Initial Setup

### Step 1: Configure OAuth

1. Go to https://launchpad.37signals.com/integrations
2. Create a new app with these settings:
   - **Name**: Your App Name
   - **Redirect URI**: `http://yoursite.com/wp-admin/options-general.php?page=basecamp-reader`
3. Copy the Client ID and Client Secret
4. Navigate to WordPress Admin > Settings > Basecamp Reader
5. Enter credentials and click "Authorize with Basecamp"

### Step 2: Set Account ID

Find your account ID in any Basecamp URL (the number after basecamp.com/)

```bash
wp bcr settings --account-id=YOUR_ACCOUNT_ID
```

### Step 3: Build Initial Index

```bash
wp bcr index build
```

This indexes all your projects for fast searching (takes ~2 minutes for 100+ projects).

## ✨ Basic Usage

### Find a Project
```bash
# Fuzzy search - handles typos and partial matches
wp bcr find "buddy"
wp bcr find "check ins"
```

### List Columns in a Project
```bash
wp bcr list_columns PROJECT_ID
```

### View Cards
```bash
wp bcr project_cards PROJECT_ID
```

### Create a Card
```bash
wp bcr cards create-card --project=PROJECT_ID --column=COLUMN_ID --title="Bug title" --content="Description"
```

### Move a Card
```bash
wp bcr cards move-card --project=PROJECT_ID --card=CARD_ID --to-column=COLUMN_ID
```

## 📝 Common Workflows

### Bug Management
```bash
# Find project
wp bcr find "buddypress business"

# Check bugs column
wp bcr column cards 37594834 "bugs"

# Create bug
wp bcr cards create-card --project=37594834 --column=7415984407 --title="Login bug"

# Move to development
wp bcr cards move-card --project=37594834 --card=CARD_ID --to-column=7415984414
```

### Daily Status Check
```bash
# Update index
wp bcr index refresh

# Check project status
wp bcr project_cards PROJECT_ID

# Monitor system
wp bcr monitor
```

## 🔧 Troubleshooting

### Authentication Issues
```bash
wp bcr auth --check
wp bcr auth --refresh  # If token expired
```

### Index Problems
```bash
wp bcr index build --force  # Rebuild from scratch
```

### Performance Issues
```bash
wp bcr monitor --report  # Check system health
wp bcr logs --cleanup    # Clean old logs
```

## 📚 Next Steps

- Read the full [Command Reference](../cli/COMMAND-REFERENCE.md)
- Explore [Usage Examples](../cli/USAGE-EXAMPLES.md)
- Learn about [Automation](../guides/AUTOMATION-GUIDE.md)
- Check [Production Setup](../PRODUCTION-SUMMARY.md)

## 💡 Pro Tips

1. **Use fuzzy search** - Don't worry about exact project names
2. **Build index regularly** - Run `wp bcr index build` weekly
3. **Monitor performance** - Check `wp bcr monitor` daily
4. **Use automation** - Create scripts for repetitive tasks
5. **Check logs** - Review `wp bcr log view` for issues

## 🆘 Getting Help

```bash
# Check command help
wp bcr --help
wp bcr [command] --help

# View recent logs
wp bcr log view --level=error

# Test API connection
wp bcr debug --test-api
```

---

*Quick Start Guide for Basecamp Pro Automation Suite v5.0*