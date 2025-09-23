#!/bin/bash

# Script to standardize all projects one by one
# Usage: ./process-all-projects.sh

cd "/Users/varundubey/Local Sites/reign-learndash/app/public"

echo "🚀 Starting standardization of all projects..."
echo "=============================================="

# Get all project IDs from the project list
PROJECT_IDS=$(wp bcr project list --format=json | jq -r '.[].ID')

# Count total projects
TOTAL=$(echo "$PROJECT_IDS" | wc -l)
CURRENT=0

echo "📊 Found $TOTAL projects to process"
echo ""

# Process each project
for PROJECT_ID in $PROJECT_IDS; do
    CURRENT=$((CURRENT + 1))

    echo "[$CURRENT/$TOTAL] Processing project ID: $PROJECT_ID"

    # Run standardization for this project
    php wp-content/plugins/basecamp-cards-reader/scripts/standardize-columns-final.php --limit=1 --start=$((CURRENT-1))

    echo ""
    echo "Waiting 2 seconds before next project..."
    sleep 2
    echo ""
done

echo "🎉 All projects have been processed!"
echo "=============================================="