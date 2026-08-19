#!/bin/bash
# Download all sentences and videos from ZIN API using curl and jq

API_URL="https://signcollect.nl/zin/getZinnen.php"
OUTPUT_DIR="downloaded_data"
TIMESTAMP=$(date +%Y%m%d_%H%M%S)

# Create output directory
mkdir -p "$OUTPUT_DIR"

echo "Fetching page 1 to get total pages..."
FIRST_PAGE=$(curl -s "${API_URL}?page=1")

# Check if jq is available
if ! command -v jq &> /dev/null; then
    echo "Error: jq is required but not installed."
    echo "Install it with: sudo apt-get install jq"
    exit 1
fi

TOTAL_PAGES=$(echo "$FIRST_PAGE" | jq -r '.pagination.totalPages')
TOTAL_COUNT=$(echo "$FIRST_PAGE" | jq -r '.pagination.totalCount')

echo "Total pages: $TOTAL_PAGES"
echo "Total sentences: $TOTAL_COUNT"
echo "Downloading all data..."
echo

# Initialize combined JSON array
echo '{"downloaded_at": "'$(date -Iseconds)'", "sentences": [' > "$OUTPUT_DIR/all_sentences_${TIMESTAMP}.json"

# Download all pages
for ((page=1; page<=TOTAL_PAGES; page++)); do
    echo "Fetching page $page/$TOTAL_PAGES..."

    PAGE_DATA=$(curl -s "${API_URL}?page=${page}")

    # Extract rows and append to combined file
    if [ $page -gt 1 ]; then
        echo "," >> "$OUTPUT_DIR/all_sentences_${TIMESTAMP}.json"
    fi

    echo "$PAGE_DATA" | jq -c '.rows[]' >> "$OUTPUT_DIR/all_sentences_${TIMESTAMP}.json"

    # Save individual page as well
    echo "$PAGE_DATA" > "$OUTPUT_DIR/page_${page}.json"

    # Small delay to avoid overwhelming the server
    sleep 0.5
done

# Close JSON array
echo ']}' >> "$OUTPUT_DIR/all_sentences_${TIMESTAMP}.json"

echo
echo "✓ All data saved to: $OUTPUT_DIR/all_sentences_${TIMESTAMP}.json"
echo "✓ Individual pages saved to: $OUTPUT_DIR/page_*.json"
echo
echo "Done!"
