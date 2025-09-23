# Basecamp Pro Automation Suite Documentation

Complete documentation for managing 100+ Basecamp projects with automation.

## 📖 Documentation

1. **[Commands Reference](COMMANDS.md)** - All commands grouped by functionality
2. **[Quick Start Guide](getting-started/QUICK-START.md)** - Get running in 5 minutes
3. **[API Documentation](api/API-DOCUMENTATION.md)** - Basecamp API v3 integration
4. **[Production Guide](PRODUCTION-SUMMARY.md)** - Deployment and monitoring

## 🚀 Setup in 3 Steps

```bash
# 1. Configure account
wp bcr settings --account-id=YOUR_ID

# 2. Build index
wp bcr index build

# 3. Start using
wp bcr find "your project"
```

## 💡 Key Features

- **100+ Projects** - Manage at scale with local indexing
- **Fuzzy Search** - Find projects without exact names
- **Column Discovery** - Automatic workflow detection
- **Batch Operations** - Automate repetitive tasks
- **Performance Monitoring** - Real-time health tracking

## 🎯 Common Tasks

### Find & Analyze Projects
```bash
wp bcr find "buddy"                      # Find project
wp bcr project_cards 37594834            # View all cards
```

### Manage Cards
```bash
wp bcr cards create-card --project=ID --column=ID --title="Bug"
wp bcr cards move-card --project=ID --card=ID --to-column=ID
```

### Monitor System
```bash
wp bcr monitor                           # Health check
wp bcr index stats                       # Index statistics
```

## 📚 Full Documentation

- **[All Commands](COMMANDS.md)** - Complete command reference
- **[CLI Examples](cli/USAGE-EXAMPLES.md)** - Practical workflows
- **[Automation Guide](guides/AUTOMATION-GUIDE.md)** - Advanced automation

---

*Version 5.0.0 | [Support](https://wbcomdesigns.com)*