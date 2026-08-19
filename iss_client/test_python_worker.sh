#!/bin/bash
# Test Python worker standalone

REQUEST_ID="test-$(date +%s)"
HAMER_URL="https://signcollect.nl/gebarenoverleg_media/studioFilesMini/raw/M20260115_0367.hamer"
FPS=60

echo "Testing Python worker..."
echo "Request ID: $REQUEST_ID"
echo ""

# Create progress directory in home (not /tmp)
PROGRESS_DIR="$HOME/iss_progress"
mkdir -p "$PROGRESS_DIR"
chmod 777 "$PROGRESS_DIR"

# Create initial progress file
PROGRESS_FILE="$PROGRESS_DIR/${REQUEST_ID}.json"
echo '{"status":"starting","percentage":0,"message":"Test starting..."}' > "$PROGRESS_FILE"

echo "Progress file: $PROGRESS_FILE"
echo ""

# Run worker in background with modified progress directory
python3 /web/zin/iss_client/iss_worker.py "$REQUEST_ID" "$HAMER_URL" "$FPS" &
WORKER_PID=$!

echo "Worker PID: $WORKER_PID"
echo ""
echo "Monitoring progress (Ctrl+C to stop):"
echo "========================================="

# Monitor progress
for i in {1..60}; do
    sleep 2

    if [ ! -f "$PROGRESS_FILE" ]; then
        echo "[$i] Progress file not found"
        continue
    fi

    STATUS=$(jq -r '.status' "$PROGRESS_FILE" 2>/dev/null)
    PCT=$(jq -r '.percentage' "$PROGRESS_FILE" 2>/dev/null)
    MSG=$(jq -r '.message' "$PROGRESS_FILE" 2>/dev/null)
    STAGE=$(jq -r '.stage // "N/A"' "$PROGRESS_FILE" 2>/dev/null)

    echo "[$i] Status: $STATUS ($PCT%) - $STAGE - $MSG"

    if [ "$STATUS" = "completed" ]; then
        echo ""
        echo "========================================="
        echo "SUCCESS! Segmentation complete"
        echo "========================================="
        echo ""
        echo "Result preview:"
        jq -r '.result.srt_content' "$PROGRESS_FILE" 2>/dev/null | head -20
        break
    fi

    if [ "$STATUS" = "error" ]; then
        echo ""
        echo "========================================="
        echo "ERROR occurred"
        echo "========================================="
        jq '.' "$PROGRESS_FILE"
        break
    fi
done

# Check if worker is still running
if ps -p $WORKER_PID > /dev/null 2>&1; then
    echo ""
    echo "Worker still running (PID: $WORKER_PID)"
else
    echo ""
    echo "Worker has stopped"
fi
