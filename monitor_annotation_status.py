#!/usr/bin/env python3
"""
ZIN Annotation Status Monitor

Monitors annotation completion status for M202* video files and sends
periodic Discord webhook notifications.

Usage:
    python3 monitor_annotation_status.py [options]

Options:
    --interval HOURS    Check interval in hours (default: 3)
    --once              Run once and exit (for testing)
    --verbose           Enable verbose logging
    --webhook-url URL   Override Discord webhook URL from .env
"""
from db_credentials import DB_PASSWORD

import os
import sys
import time
import json
import signal
import logging
import argparse
from datetime import datetime
from pathlib import Path
from typing import Dict, Tuple, Optional

try:
    import MySQLdb
    import requests
    from dotenv import load_dotenv
except ImportError as e:
    print(f"Missing required dependency: {e}")
    print("Install with: pip3 install mysqlclient requests python-dotenv")
    sys.exit(1)

# Configuration
BASE_DIR = Path(__file__).parent
EAF_DIR = BASE_DIR / "eaf" / "zin"
HAMER_DIR = Path("/web/gebarenoverleg_media/studioFilesMini/raw")
POSE_DIR = Path("/web/gebarenoverleg_media/studioFilesMini/raw")
SAM3D_DIR = Path("/web/gebarenoverleg_media/studioFilesMini/raw")
LOG_DIR = BASE_DIR / "logs"
PID_FILE = BASE_DIR / "annotation_monitor.pid"
STATS_FILE = BASE_DIR / "annotation_stats.json"
ENV_FILE = BASE_DIR / ".env"

# Database configuration
DB_HOST = "localhost"
DB_USER = "user"
DB_PASS = DB_PASSWORD
DB_NAME = "admin_gebarenoverleg"

# Global flag for graceful shutdown
shutdown_requested = False


def setup_logging(verbose: bool = False) -> logging.Logger:
    """Configure logging to file and console."""
    LOG_DIR.mkdir(exist_ok=True)

    log_level = logging.DEBUG if verbose else logging.INFO

    # Create logger
    logger = logging.getLogger("annotation_monitor")
    logger.setLevel(log_level)

    # File handler
    log_file = LOG_DIR / "annotation_monitor.log"
    file_handler = logging.FileHandler(log_file)
    file_handler.setLevel(log_level)
    file_formatter = logging.Formatter(
        '[%(asctime)s] %(levelname)s: %(message)s',
        datefmt='%Y-%m-%d %H:%M:%S'
    )
    file_handler.setFormatter(file_formatter)

    # Console handler
    console_handler = logging.StreamHandler(sys.stdout)
    console_handler.setLevel(log_level)
    console_formatter = logging.Formatter(
        '[%(asctime)s] %(message)s',
        datefmt='%Y-%m-%d %H:%M:%S'
    )
    console_handler.setFormatter(console_formatter)

    logger.addHandler(file_handler)
    logger.addHandler(console_handler)

    return logger


def signal_handler(signum, frame):
    """Handle shutdown signals gracefully."""
    global shutdown_requested
    logger.info(f"Received signal {signum}, shutting down gracefully...")
    shutdown_requested = True


def write_pid_file():
    """Write PID file to prevent multiple instances."""
    if PID_FILE.exists():
        try:
            with open(PID_FILE, 'r') as f:
                old_pid = int(f.read().strip())

            # Check if process is still running
            try:
                os.kill(old_pid, 0)
                logger.error(f"Another instance is already running (PID: {old_pid})")
                sys.exit(1)
            except OSError:
                # Process doesn't exist, remove stale PID file
                logger.warning(f"Removing stale PID file (PID: {old_pid})")
                PID_FILE.unlink()
        except (ValueError, IOError) as e:
            logger.warning(f"Error reading PID file: {e}")
            PID_FILE.unlink()

    with open(PID_FILE, 'w') as f:
        f.write(str(os.getpid()))
    logger.debug(f"Created PID file: {PID_FILE}")


def remove_pid_file():
    """Remove PID file on exit."""
    if PID_FILE.exists():
        PID_FILE.unlink()
        logger.debug("Removed PID file")


def get_base_filename(filename: str) -> str:
    """Extract base filename without extension."""
    # Remove .wav or .mp4 extension
    if filename.endswith('.wav') or filename.endswith('.mp4'):
        return filename.rsplit('.', 1)[0]
    return filename


def get_videos_missing_hamer() -> list:
    """
    Get list of mp4 files (M202*, L202*, R202*, A202*, B202*) that don't have
    a corresponding .hamer file. Scans filesystem directly.

    Returns:
        List of base filenames without .hamer files
    """
    logger.info("Scanning filesystem for mp4 files missing .hamer files...")

    try:
        missing_hamer = []
        total_mp4 = 0

        # Scan for mp4 files with the target prefixes
        for prefix in ['M202', 'L202', 'R202', 'A202', 'B202']:
            pattern = f"{prefix}*.mp4"
            mp4_files = list(HAMER_DIR.glob(pattern))

            for mp4_path in mp4_files:
                total_mp4 += 1
                base_name = mp4_path.stem  # filename without extension
                hamer_path = HAMER_DIR / f"{base_name}.hamer"
                if not hamer_path.exists():
                    missing_hamer.append(base_name)

        missing_hamer.sort()
        logger.info(f"Found {len(missing_hamer)} videos missing .hamer files (out of {total_mp4} total mp4 files)")
        return missing_hamer

    except Exception as e:
        logger.error(f"Unexpected error in get_videos_missing_hamer: {e}")
        raise


def get_annotation_stats() -> Dict:
    """
    Calculate annotation statistics from filesystem and database.

    Returns:
        Dict with statistics including total count and percentages
    """
    logger.info("Calculating annotation statistics...")

    try:
        # Count mp4 and hamer files from filesystem
        logger.info("Scanning filesystem for mp4 and hamer files...")
        total_mp4 = 0
        hamer_count = 0

        for prefix in ['M202', 'L202', 'R202', 'A202', 'B202']:
            mp4_files = list(HAMER_DIR.glob(f"{prefix}*.mp4"))
            total_mp4 += len(mp4_files)

            hamer_files = list(HAMER_DIR.glob(f"{prefix}*.hamer"))
            hamer_count += len(hamer_files)

        logger.info(f"Found {total_mp4} mp4 files, {hamer_count} hamer files")

        # Count sam3dbody files from post directory
        sam3d_count = len(list(SAM3D_DIR.glob("*.sam3dbody")))
        logger.info(f"Found {sam3d_count} sam3dbody files")

        # Get Zin sentence count from database
        db = MySQLdb.connect(
            host=DB_HOST,
            user=DB_USER,
            passwd=DB_PASS,
            db=DB_NAME,
            charset='utf8mb4'
        )
        cursor = db.cursor()

        cursor.execute("""
            SELECT COUNT(DISTINCT m_file)
            FROM matched_transcriptions
            WHERE m_file LIKE 'M202%' AND zOg = 'Zin'
        """)
        zin_count = cursor.fetchone()[0]

        # Count EAF and pose files for Zin sentences
        cursor.execute("""
            SELECT DISTINCT m_file
            FROM matched_transcriptions
            WHERE m_file LIKE 'M202%' AND zOg = 'Zin'
        """)
        results = cursor.fetchall()

        cursor.close()
        db.close()

        eaf_count = 0
        pose_count = 0
        for (m_file,) in results:
            base_name = get_base_filename(m_file)
            if (EAF_DIR / f"{base_name}.eaf").exists():
                eaf_count += 1
            if (POSE_DIR / f"{base_name}.pose").exists():
                pose_count += 1

        logger.info("Statistics calculation complete")

        # Calculate percentages
        stats = {
            'total_mp4': total_mp4,
            'hamer_count': hamer_count,
            'hamer_pct': round(hamer_count / total_mp4 * 100, 1) if total_mp4 > 0 else 0,
            'sam3d_count': sam3d_count,
            'sam3d_pct': round(sam3d_count / total_mp4 * 100, 1) if total_mp4 > 0 else 0,
            'zin_count': zin_count,
            'eaf_count': eaf_count,
            'eaf_pct': round(eaf_count / zin_count * 100, 1) if zin_count > 0 else 0,
            'pose_count': pose_count,
            'pose_pct': round(pose_count / zin_count * 100, 1) if zin_count > 0 else 0,
            'timestamp': datetime.now().isoformat()
        }

        logger.debug(f"Statistics: {stats}")
        return stats

    except MySQLdb.Error as e:
        logger.error(f"Database error: {e}")
        raise
    except Exception as e:
        logger.error(f"Unexpected error in get_annotation_stats: {e}")
        raise


def send_discord_notification(stats: Dict, webhook_url: str = None, bot_token: str = None, channel_id: str = None) -> bool:
    """
    Send annotation statistics to Discord via webhook or bot.

    Args:
        stats: Statistics dictionary from get_annotation_stats()
        webhook_url: Discord webhook URL (optional)
        bot_token: Discord bot token (optional)
        channel_id: Discord channel ID (required if using bot_token)

    Returns:
        True if notification sent successfully, False otherwise
    """
    if not webhook_url and not (bot_token and channel_id):
        logger.error("No Discord webhook URL or bot credentials provided")
        return False

    logger.info("Sending Discord notification...")

    try:
        # Format timestamp nicely
        timestamp = datetime.fromisoformat(stats['timestamp'])
        timestamp_str = timestamp.strftime('%Y-%m-%d %H:%M:%S')

        # Create Discord embed
        embed = {
            "title": "ZIN Annotation Status Report",
            "color": 0x5865F2,  # Discord blurple
            "fields": [
                {
                    "name": "Total MP4 Files",
                    "value": f"**{stats['total_mp4']:,}**",
                    "inline": True
                },
                {
                    "name": "With .hamer",
                    "value": f"{stats['hamer_count']:,} ({stats['hamer_pct']}%)",
                    "inline": True
                },
                {
                    "name": "With .sam3dbody",
                    "value": f"{stats['sam3d_count']:,} ({stats['sam3d_pct']}%)",
                    "inline": True
                },
                {
                    "name": "\u200b",
                    "value": "\u200b",
                    "inline": False
                },
                {
                    "name": "Zin Sentences",
                    "value": f"**{stats['zin_count']:,}**",
                    "inline": True
                },
                {
                    "name": "With EAF",
                    "value": f"{stats['eaf_count']:,} ({stats['eaf_pct']}%)",
                    "inline": True
                },
                {
                    "name": "With .pose",
                    "value": f"{stats['pose_count']:,} ({stats['pose_pct']}%)",
                    "inline": True
                }
            ],
            "footer": {
                "text": f"{timestamp_str}"
            }
        }

        payload = {
            "embeds": [embed]
        }

        # Use bot token if provided, otherwise use webhook
        if bot_token and channel_id:
            logger.debug(f"Using bot token to send to channel {channel_id}")
            url = f"https://discord.com/api/v10/channels/{channel_id}/messages"
            headers = {
                "Authorization": f"Bot {bot_token}",
                "Content-Type": "application/json"
            }

            response = requests.post(
                url,
                json=payload,
                headers=headers,
                timeout=10
            )

            if response.status_code == 200:
                logger.info("✓ Discord notification sent successfully (bot)")
                return True
            else:
                logger.error(f"Discord bot API returned status {response.status_code}: {response.text}")
                return False
        else:
            logger.debug("Using webhook URL")
            response = requests.post(
                webhook_url,
                json=payload,
                timeout=10
            )

            if response.status_code == 204:
                logger.info("✓ Discord notification sent successfully (webhook)")
                return True
            else:
                logger.error(f"Discord webhook returned status {response.status_code}: {response.text}")
                return False

    except requests.exceptions.RequestException as e:
        logger.error(f"Failed to send Discord notification: {e}")
        return False
    except Exception as e:
        logger.error(f"Unexpected error sending notification: {e}")
        return False


def save_stats_to_json(stats: Dict):
    """Save statistics to JSON file for historical tracking."""
    try:
        # Load existing stats if file exists
        history = []
        if STATS_FILE.exists():
            with open(STATS_FILE, 'r') as f:
                history = json.load(f)

        # Append new stats
        history.append(stats)

        # Keep only last 100 entries to prevent file from growing indefinitely
        if len(history) > 100:
            history = history[-100:]

        # Save updated history
        with open(STATS_FILE, 'w') as f:
            json.dump(history, f, indent=2)

        logger.debug(f"Saved statistics to {STATS_FILE}")

    except Exception as e:
        logger.warning(f"Failed to save statistics to JSON: {e}")


def main():
    """Main monitoring loop."""
    global logger, shutdown_requested

    # Parse command line arguments
    parser = argparse.ArgumentParser(
        description='Monitor ZIN annotation completion status'
    )
    parser.add_argument(
        '--interval',
        type=float,
        default=3.0,
        help='Check interval in hours (default: 3)'
    )
    parser.add_argument(
        '--once',
        action='store_true',
        help='Run once and exit (for testing)'
    )
    parser.add_argument(
        '--verbose',
        action='store_true',
        help='Enable verbose logging'
    )
    parser.add_argument(
        '--webhook-url',
        type=str,
        help='Discord webhook URL (overrides .env)'
    )
    parser.add_argument(
        '--list-missing-hamer',
        action='store_true',
        help='List M202* videos without .hamer files and exit'
    )

    args = parser.parse_args()

    # Setup logging
    logger = setup_logging(args.verbose)

    logger.info("=" * 60)
    logger.info("ZIN Annotation Status Monitor Starting")
    logger.info("=" * 60)

    # Handle --list-missing-hamer mode
    if args.list_missing_hamer:
        missing = get_videos_missing_hamer()
        print(f"\nVideos missing .hamer files ({len(missing)} total):")
        print("-" * 50)
        for filename in missing:
            print(filename)
        print("-" * 50)
        print(f"Total: {len(missing)}")
        sys.exit(0)

    # Load environment variables
    if ENV_FILE.exists():
        load_dotenv(ENV_FILE)
        logger.debug(f"Loaded environment from {ENV_FILE}")
    else:
        logger.warning(f".env file not found at {ENV_FILE}")

    # Get Discord credentials (webhook or bot token)
    webhook_url = args.webhook_url or os.getenv('DISCORD_WEBHOOK_URL')
    bot_token = os.getenv('DISCORD_BOT_TOKEN')
    channel_id = os.getenv('DISCORD_CHANNEL_ID')

    # Validate that we have either webhook or bot credentials
    if not webhook_url and not (bot_token and channel_id):
        logger.error("No Discord credentials provided!")
        logger.error("Set either:")
        logger.error("  - DISCORD_WEBHOOK_URL in .env, or")
        logger.error("  - DISCORD_BOT_TOKEN and DISCORD_CHANNEL_ID in .env")
        sys.exit(1)

    if bot_token and channel_id:
        logger.info(f"Using Discord bot (channel: {channel_id})")
    elif webhook_url:
        logger.info("Using Discord webhook")

    # Setup signal handlers for graceful shutdown
    signal.signal(signal.SIGTERM, signal_handler)
    signal.signal(signal.SIGINT, signal_handler)

    # Write PID file if running continuously
    if not args.once:
        write_pid_file()

    try:
        # Main loop
        interval_seconds = args.interval * 3600

        while not shutdown_requested:
            try:
                # Get statistics
                stats = get_annotation_stats()

                # Send Discord notification
                send_discord_notification(stats, webhook_url=webhook_url, bot_token=bot_token, channel_id=channel_id)

                # Save to JSON for historical tracking
                save_stats_to_json(stats)

                # Exit if running in --once mode
                if args.once:
                    logger.info("Single run complete (--once mode)")
                    break

                # Wait for next interval
                logger.info(f"Next check in {args.interval} hours ({interval_seconds/60:.0f} minutes)")

                # Sleep in small increments to allow for responsive shutdown
                sleep_interval = 60  # Check for shutdown every minute
                elapsed = 0
                while elapsed < interval_seconds and not shutdown_requested:
                    time.sleep(min(sleep_interval, interval_seconds - elapsed))
                    elapsed += sleep_interval

            except KeyboardInterrupt:
                logger.info("Keyboard interrupt received")
                break
            except Exception as e:
                logger.error(f"Error in monitoring loop: {e}", exc_info=True)
                if args.once:
                    raise
                # Wait a bit before retrying
                logger.info("Retrying in 5 minutes...")
                time.sleep(300)

    finally:
        # Cleanup
        if not args.once:
            remove_pid_file()
        logger.info("Monitor stopped")


if __name__ == "__main__":
    main()
