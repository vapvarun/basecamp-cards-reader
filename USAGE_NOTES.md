# Basecamp Cards Reader - Usage Notes

## Commands Available

### Read a Card
```bash
wp bcr read "https://3.basecamp.com/PROJECT_ID/buckets/BUCKET_ID/card_tables/cards/CARD_ID"
```

### Extract Feedback from Card
```bash
wp bcr extract_feedback "https://3.basecamp.com/PROJECT_ID/buckets/BUCKET_ID/card_tables/cards/CARD_ID"
```

### Post Comment on Card
```bash
wp bcr comment "https://3.basecamp.com/PROJECT_ID/buckets/BUCKET_ID/card_tables/cards/CARD_ID" "Your comment text here"
```

### List Recent Cards
```bash
wp bcr list
```

### Authentication Setup
```bash
wp bcr auth
```

## Example Usage

### Reading a Card:
```bash
wp bcr read "https://3.basecamp.com/5798509/buckets/37557585/card_tables/cards/9026157612"
```

### Posting a Comment:
```bash
wp bcr comment "https://3.basecamp.com/5798509/buckets/37557585/card_tables/cards/9026157612" "Issue has been fixed and tested"
```

## Troubleshooting

If you get path errors like "'C:\Program' is not recognized":
1. Ensure you're in the correct WordPress directory
2. Check that wp-cli is properly installed
3. Try running from the WordPress root directory
4. Use proper quoting for URLs and comments

## Notes
- Always wrap URLs in quotes
- Use double quotes for comments with special characters
- The plugin must be active for commands to work
- Ensure Basecamp authentication is configured with `wp bcr auth`