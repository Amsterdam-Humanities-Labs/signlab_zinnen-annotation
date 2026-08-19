# ZIN Annotation Status Monitor

Python script that monitors annotation completion status for M202* video files and sends periodic Discord webhook notifications.

## Features

- ✅ Tracks M202* video annotation completion (EAF, .pose, .hamer files)
- 📊 Calculates percentages and completion statistics
- 💬 Sends Discord webhook notifications with formatted embeds
- 🔄 Runs continuously with configurable interval (default: 3 hours)
- 📝 Logs all activity to file
- 💾 Saves historical statistics to JSON
- 🛡️ PID file prevents multiple instances
- 🎯 Graceful shutdown on signals

## Current Status (Last Test Run)

**Database:** 5,498 M202* videos found

**File Coverage:**
- EAF files: 3,298 (60.0%)
- .pose files: 47 (0.9%)
- .hamer files: 52 (0.9%)
- Fully complete: 2 (0.0%)

## Setup Instructions

### 1. Install Dependencies

All dependencies are already installed:
- ✅ `python-dotenv` - Environment variable management
- ✅ `mysqlclient` - MySQL database connectivity
- ✅ `requests` - HTTP requests for Discord webhook

### 2. Configure Discord Webhook

You need to create a Discord webhook to receive notifications:

1. Open your Discord server
2. Go to **Server Settings** → **Integrations** → **Webhooks**
3. Click **"New Webhook"**
4. Choose the channel where you want to receive notifications
5. Click **"Copy Webhook URL"**

The webhook URL format:
```
https://discord.com/api/webhooks/{WEBHOOK_ID}/{WEBHOOK_TOKEN}
```

### 3. Configure Environment File

Copy the example file and add your webhook URL:

```bash
cp /web/zin/.env.example /web/zin/.env
nano /web/zin/.env
```

Edit the file and replace the placeholder with your actual webhook URL:

```env
DISCORD_WEBHOOK_URL=https://discord.com/api/webhooks/YOUR_WEBHOOK_ID/YOUR_WEBHOOK_TOKEN
```

**Note:** The Application ID and Public Key you provided are for Discord bots, not webhooks. You need the webhook URL from the steps above.

### 4. Test the Script

Run a single check to verify everything works:

```bash
cd /web/zin
python3 monitor_annotation_status.py --once --verbose
```

You should see:
- Database query results
- File checking progress
- Statistics calculation
- Discord notification sent (✓)
- Statistics saved to JSON

If you see an error about the webhook, make sure you've set the correct URL in `.env`.

## Usage

### Run Once (Testing)

```bash
python3 monitor_annotation_status.py --once
```

### Run Continuously (Production)

#### Option 1: Direct Execution
```bash
python3 monitor_annotation_status.py
```

#### Option 2: Background with nohup
```bash
nohup python3 monitor_annotation_status.py > /dev/null 2>&1 &
```

#### Option 3: Screen Session (Recommended)
```bash
screen -S annotation_monitor
python3 monitor_annotation_status.py
# Press Ctrl+A, then D to detach
# Reattach with: screen -r annotation_monitor
```

#### Option 4: Systemd Service (Advanced)
Create `/etc/systemd/system/zin-monitor.service`:

```ini
[Unit]
Description=ZIN Annotation Status Monitor
After=network.target mysql.service

[Service]
Type=simple
User=gomer
WorkingDirectory=/web/zin
ExecStart=/usr/bin/python3 /web/zin/monitor_annotation_status.py
Restart=on-failure
RestartSec=60

[Install]
WantedBy=multi-user.target
```

Then enable and start:
```bash
sudo systemctl enable zin-monitor
sudo systemctl start zin-monitor
sudo systemctl status zin-monitor
```

## Command Line Options

```bash
python3 monitor_annotation_status.py [OPTIONS]
```

**Options:**
- `--interval HOURS` - Check interval in hours (default: 3)
- `--once` - Run once and exit (for testing)
- `--verbose` - Enable verbose/debug logging
- `--webhook-url URL` - Override webhook URL from .env

**Examples:**

```bash
# Check every hour
python3 monitor_annotation_status.py --interval 1

# Test with verbose logging
python3 monitor_annotation_status.py --once --verbose

# Override webhook URL
python3 monitor_annotation_status.py --webhook-url "https://discord.com/api/webhooks/..."

# Check every 6 hours with verbose logging
python3 monitor_annotation_status.py --interval 6 --verbose
```

## Files and Directories

### Created Files

- **`monitor_annotation_status.py`** - Main monitoring script (executable)
- **`.env`** - Discord webhook configuration (add to .gitignore!)
- **`.env.example`** - Template for webhook configuration
- **`logs/annotation_monitor.log`** - Activity log file
- **`annotation_stats.json`** - Historical statistics (JSON array)
- **`annotation_monitor.pid`** - Process ID file (while running)

### Reference Directories

- **`eaf/zin/`** - EAF annotation files
- **`/web/gebarenoverleg_media/studioFilesMini/raw/`** - .pose and .hamer files

## Discord Notification Format

The script sends a formatted embed message:

```
🎬 ZIN Annotation Status Report

📊 Total Videos:     5,498
📝 With EAF:         3,298 (60.0%)
🤸 With .pose:       47 (0.9%)
✋ With .hamer:      52 (0.9%)
✅ Fully Complete:   2 (0.0%)

📅 2026-01-20 09:27:02
```

## Monitoring and Troubleshooting

### Check if Script is Running

```bash
# Check PID file
cat /web/zin/annotation_monitor.pid

# Check process
ps aux | grep monitor_annotation_status

# Check using screen
screen -ls
```

### View Logs

```bash
# Tail logs in real-time
tail -f /web/zin/logs/annotation_monitor.log

# View recent log entries
tail -50 /web/zin/logs/annotation_monitor.log

# Search for errors
grep ERROR /web/zin/logs/annotation_monitor.log
```

### Stop the Script

```bash
# If running in foreground: Ctrl+C

# If running in background: kill using PID
kill $(cat /web/zin/annotation_monitor.pid)

# If running in screen: reattach and Ctrl+C
screen -r annotation_monitor
# Then press Ctrl+C

# Force kill (if needed)
pkill -f monitor_annotation_status.py
```

### View Historical Statistics

```bash
# Pretty print JSON stats
cat /web/zin/annotation_stats.json | python3 -m json.tool

# View last 5 entries
cat /web/zin/annotation_stats.json | python3 -c "import json, sys; data = json.load(sys.stdin); print(json.dumps(data[-5:], indent=2))"
```

## Database Information

- **Database:** `admin_gebarenoverleg`
- **Table:** `matched_transcriptions`
- **Filter:** `m_file LIKE 'M202%' AND zOg = 'Zin'`
- **Credentials:** Stored in script (user/$DB_PASSWORD/localhost)

## Security Notes

1. **Never commit `.env` to git** - Add to `.gitignore`
2. **Webhook URL is sensitive** - Don't share publicly
3. **Database credentials** - Consider moving to `.env` file
4. **File permissions** - Script is executable (755), `.env` should be readable only by owner (600)

## Error Handling

The script includes robust error handling:

- Database connection errors → Logged and retried
- Discord webhook failures → Logged but don't stop monitoring
- File system errors → Logged with details
- Keyboard interrupt (Ctrl+C) → Graceful shutdown
- SIGTERM/SIGINT signals → Clean exit with PID file removal
- Multiple instance prevention → PID file check on startup

## Next Steps

1. ✅ Script created and tested
2. ✅ Dependencies installed
3. ⏳ **YOU NEED TO:** Create Discord webhook and add URL to `.env`
4. ⏳ **YOU NEED TO:** Test with real webhook: `python3 monitor_annotation_status.py --once`
5. ⏳ **YOU NEED TO:** Start continuous monitoring (screen or systemd)

## Support

For issues or questions:
- Check logs: `/web/zin/logs/annotation_monitor.log`
- Review statistics: `/web/zin/annotation_stats.json`
- Test database connection: `mysql -u user -p'$DB_PASSWORD' admin_gebarenoverleg`
- Test Discord webhook: `curl -X POST "YOUR_WEBHOOK_URL" -H "Content-Type: application/json" -d '{"content": "Test"}'`
