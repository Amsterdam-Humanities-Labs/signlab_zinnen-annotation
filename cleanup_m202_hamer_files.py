#!/usr/bin/env python3
"""
M202* Video Files and .hamer File Cleanup Script

This script queries M202* video files from the database and removes their
associated .hamer files from the raw directory while preserving all other files.
"""
from db_credentials import DB_PASSWORD

import mysql.connector
import json
import os
import re
import argparse
from datetime import datetime
from typing import List, Dict, Tuple

# Database configuration (from /web/mysql_config.php)
DB_CONFIG = {
    'user': 'user',
    'password': DB_PASSWORD,
    'host': 'localhost',
    'database': 'admin_gebarenoverleg'
}

# Directory paths
RAW_DIR = '/web/gebarenoverleg_media/studioFilesMini/raw/'
DEFAULT_BACKUP_DIR = '/web/zin/backups/'


def load_m202_videos_from_db() -> List[Dict]:
    """
    Query all M202* video files from matched_transcriptions table.

    Returns:
        List of dictionaries containing m_file, ID, and m_transcription
    """
    conn = None
    cursor = None
    try:
        conn = mysql.connector.connect(**DB_CONFIG)
        cursor = conn.cursor(dictionary=True)

        query = """
        SELECT DISTINCT m_file, ID, m_transcription
        FROM matched_transcriptions
        WHERE m_file LIKE 'M202%' AND zOg = 'Zin'
        ORDER BY m_file
        """

        cursor.execute(query)
        results = cursor.fetchall()

        return results

    except mysql.connector.Error as err:
        print(f"❌ Database Error: {err}")
        return []
    except Exception as e:
        print(f"❌ Unexpected error: {e}")
        return []
    finally:
        if cursor:
            cursor.close()
        if conn and conn.is_connected():
            conn.close()


def extract_base_filename(m_file: str) -> str:
    """
    Extract base filename by removing .mp4 or .wav extension.

    Args:
        m_file: Filename like "M20240321_0001.mp4"

    Returns:
        Base filename like "M20240321_0001"
    """
    return re.sub(r'\.(mp4|wav)$', '', m_file, flags=re.IGNORECASE)


def create_backup(video_entries: List[Dict], backup_dir: str) -> str:
    """
    Create a JSON backup of M202* database entries.

    Args:
        video_entries: List of video entry dictionaries
        backup_dir: Directory to store backup

    Returns:
        Path to backup file, or empty string on failure
    """
    try:
        timestamp = datetime.now().strftime('%Y%m%d_%H%M%S')
        backup_subdir = os.path.join(backup_dir, f'm202_hamer_cleanup_{timestamp}')

        # Create backup directory
        os.makedirs(backup_subdir, exist_ok=True)

        # Create backup data
        backup_data = {
            'timestamp': timestamp,
            'total_entries': len(video_entries),
            'database': DB_CONFIG['database'],
            'entries': video_entries
        }

        # Write to JSON file
        backup_file = os.path.join(backup_subdir, 'database_entries.json')
        with open(backup_file, 'w', encoding='utf-8') as f:
            json.dump(backup_data, f, indent=2, ensure_ascii=False)

        print(f"✓ Backup created: {backup_file}")
        print(f"✓ Backed up {len(video_entries)} database entries")

        return backup_file

    except Exception as e:
        print(f"❌ Backup failed: {e}")
        return ""


def find_hamer_files(video_entries: List[Dict]) -> Tuple[List[Dict], List[str]]:
    """
    Check which .hamer files exist for the given video entries.

    Args:
        video_entries: List of video entry dictionaries

    Returns:
        Tuple of (list of files to delete with metadata, list of missing files)
    """
    files_to_delete = []
    missing_files = []

    for entry in video_entries:
        base_filename = extract_base_filename(entry['m_file'])
        hamer_path = os.path.join(RAW_DIR, f"{base_filename}.hamer")

        if os.path.exists(hamer_path):
            file_info = {
                'm_file': entry['m_file'],
                'base_filename': base_filename,
                'hamer_path': hamer_path,
                'file_size': os.path.getsize(hamer_path),
                'db_id': entry['ID'],
                'm_transcription': entry['m_transcription']
            }
            files_to_delete.append(file_info)
        else:
            missing_files.append(hamer_path)

    return files_to_delete, missing_files


def delete_hamer_files(files_to_delete: List[Dict], dry_run: bool = True) -> Tuple[int, int, List[str]]:
    """
    Delete .hamer files with logging.

    Args:
        files_to_delete: List of file dictionaries to delete
        dry_run: If True, only simulate deletion

    Returns:
        Tuple of (successful deletions, failed deletions, error messages)
    """
    successful = 0
    failed = 0
    errors = []

    for file_info in files_to_delete:
        hamer_path = file_info['hamer_path']

        if dry_run:
            print(f"  [DRY RUN] Would delete: {hamer_path}")
            successful += 1
        else:
            try:
                os.remove(hamer_path)
                print(f"  ✓ Deleted: {hamer_path}")
                successful += 1
            except Exception as e:
                error_msg = f"Failed to delete {hamer_path}: {e}"
                print(f"  ❌ {error_msg}")
                errors.append(error_msg)
                failed += 1

    return successful, failed, errors


def save_deletion_log(files_to_delete: List[Dict], missing_files: List[str],
                      successful: int, failed: int, errors: List[str],
                      backup_dir: str, dry_run: bool) -> str:
    """
    Save a detailed log of the deletion operation.

    Args:
        files_to_delete: List of files that were/would be deleted
        missing_files: List of .hamer files that weren't found
        successful: Number of successful deletions
        failed: Number of failed deletions
        errors: List of error messages
        backup_dir: Directory to save log
        dry_run: Whether this was a dry run

    Returns:
        Path to log file
    """
    try:
        # Find the most recent backup directory
        timestamp = datetime.now().strftime('%Y%m%d_%H%M%S')
        backup_subdirs = [d for d in os.listdir(backup_dir)
                         if d.startswith('m202_hamer_cleanup_') and
                         os.path.isdir(os.path.join(backup_dir, d))]

        if backup_subdirs:
            # Use most recent backup directory
            backup_subdir = os.path.join(backup_dir, sorted(backup_subdirs)[-1])
        else:
            # Create new directory if none exists
            backup_subdir = os.path.join(backup_dir, f'm202_hamer_cleanup_{timestamp}')
            os.makedirs(backup_subdir, exist_ok=True)

        log_data = {
            'timestamp': timestamp,
            'dry_run': dry_run,
            'summary': {
                'total_database_entries': len(files_to_delete) + len(missing_files),
                'hamer_files_found': len(files_to_delete),
                'hamer_files_missing': len(missing_files),
                'successful_deletions': successful,
                'failed_deletions': failed
            },
            'files_deleted': [
                {
                    'm_file': f['m_file'],
                    'hamer_path': f['hamer_path'],
                    'file_size': f['file_size']
                }
                for f in files_to_delete
            ],
            'missing_files': missing_files,
            'errors': errors
        }

        log_filename = 'deletion_log_dryrun.json' if dry_run else 'deletion_log.json'
        log_file = os.path.join(backup_subdir, log_filename)

        with open(log_file, 'w', encoding='utf-8') as f:
            json.dump(log_data, f, indent=2, ensure_ascii=False)

        return log_file

    except Exception as e:
        print(f"⚠ Warning: Could not save log file: {e}")
        return ""


def print_summary(video_entries: List[Dict], files_to_delete: List[Dict],
                 missing_files: List[str], successful: int, failed: int,
                 errors: List[str], dry_run: bool):
    """
    Print a comprehensive summary of the operation.

    Args:
        video_entries: All M202* video entries from database
        files_to_delete: .hamer files found
        missing_files: .hamer files not found
        successful: Number of successful deletions
        failed: Number of failed deletions
        errors: List of error messages
        dry_run: Whether this was a dry run
    """
    print("\n" + "=" * 70)
    print("M202* .HAMER FILE CLEANUP SUMMARY")
    print("=" * 70)

    print(f"\nDatabase Query:")
    print(f"  Total M202* entries in database: {len(video_entries)}")

    print(f"\nFile System:")
    print(f"  .hamer files found: {len(files_to_delete)}")
    print(f"  .hamer files not found: {len(missing_files)}")

    if dry_run:
        print(f"\nDry Run Results:")
        print(f"  Files that would be deleted: {successful}")
    else:
        print(f"\nDeletion Results:")
        print(f"  Successfully deleted: {successful}")
        print(f"  Failed to delete: {failed}")

    if missing_files:
        print(f"\nMissing .hamer files (first 10):")
        for path in missing_files[:10]:
            print(f"  - {path}")
        if len(missing_files) > 10:
            print(f"  ... and {len(missing_files) - 10} more")

    if errors:
        print(f"\nErrors:")
        for error in errors:
            print(f"  - {error}")

    if not dry_run and successful > 0:
        print(f"\n✓ Successfully deleted {successful} .hamer files")
        print(f"✓ All other associated files (.mp4, .jpg, .vtt, .pose, .lock) preserved")

    print("=" * 70)


def main():
    """Main function to execute the cleanup process."""
    parser = argparse.ArgumentParser(
        description='Cleanup M202* .hamer files from raw directory',
        formatter_class=argparse.RawDescriptionHelpFormatter,
        epilog="""
Examples:
  # Preview what would be deleted (default)
  %(prog)s
  %(prog)s --dry-run

  # Execute deletion (requires confirmation)
  %(prog)s --execute

  # Use custom backup directory
  %(prog)s --execute --backup-dir /custom/path/

  # Verbose output
  %(prog)s --execute --verbose
        """
    )

    parser.add_argument('--dry-run', action='store_true', default=True,
                       help='Preview without deleting (default)')
    parser.add_argument('--execute', action='store_true',
                       help='Actually delete files (requires confirmation)')
    parser.add_argument('--backup-dir', default=DEFAULT_BACKUP_DIR,
                       help=f'Backup directory path (default: {DEFAULT_BACKUP_DIR})')
    parser.add_argument('--verbose', action='store_true',
                       help='Detailed output')

    args = parser.parse_args()

    # Determine dry_run mode
    dry_run = not args.execute

    print("=" * 70)
    print("M202* VIDEO FILES AND .HAMER FILE CLEANUP")
    print("=" * 70)
    print(f"Mode: {'DRY RUN (preview only)' if dry_run else 'EXECUTE (actual deletion)'}")
    print(f"Target directory: {RAW_DIR}")
    print(f"Backup directory: {args.backup_dir}")
    print()

    # Step 1: Query database for M202* videos
    print("Step 1: Querying database for M202* video files...")
    video_entries = load_m202_videos_from_db()

    if not video_entries:
        print("❌ No M202* video files found in database or query failed.")
        return

    print(f"✓ Found {len(video_entries)} M202* video entries in database")

    if args.verbose:
        print("\nSample entries (first 5):")
        for entry in video_entries[:5]:
            print(f"  - {entry['m_file']} (ID: {entry['ID']}, Transcription: {entry['m_transcription']})")

    # Step 2: Create backup
    print(f"\nStep 2: Creating backup...")
    backup_file = create_backup(video_entries, args.backup_dir)

    if not backup_file:
        print("❌ Backup failed. Aborting.")
        return

    # Step 3: Find .hamer files
    print(f"\nStep 3: Checking for .hamer files in {RAW_DIR}...")
    files_to_delete, missing_files = find_hamer_files(video_entries)

    print(f"✓ Found {len(files_to_delete)} .hamer files")
    print(f"✓ {len(missing_files)} .hamer files not found (already deleted or never existed)")

    if not files_to_delete:
        print("\n✓ No .hamer files to delete. All M202* .hamer files already removed.")
        return

    if args.verbose and files_to_delete:
        print("\nFiles to delete (first 10):")
        for file_info in files_to_delete[:10]:
            size_kb = file_info['file_size'] / 1024
            print(f"  - {file_info['hamer_path']} ({size_kb:.1f} KB)")
        if len(files_to_delete) > 10:
            print(f"  ... and {len(files_to_delete) - 10} more")

    # Step 4: Confirm deletion if in execute mode
    if not dry_run:
        print(f"\n⚠ WARNING: About to delete {len(files_to_delete)} .hamer files!")
        print("This operation cannot be undone.")
        response = input("Type 'yes' to proceed: ")

        if response.lower() != 'yes':
            print("❌ Deletion cancelled by user.")
            return

    # Step 5: Delete .hamer files
    print(f"\nStep 4: {'Simulating' if dry_run else 'Executing'} deletion...")
    successful, failed, errors = delete_hamer_files(files_to_delete, dry_run=dry_run)

    # Step 6: Save log
    print(f"\nStep 5: Saving operation log...")
    log_file = save_deletion_log(files_to_delete, missing_files, successful,
                                 failed, errors, args.backup_dir, dry_run)
    if log_file:
        print(f"✓ Log saved: {log_file}")

    # Step 7: Print summary
    print_summary(video_entries, files_to_delete, missing_files,
                 successful, failed, errors, dry_run)

    if dry_run:
        print("\n💡 This was a dry run. To actually delete files, run with --execute")


if __name__ == "__main__":
    main()
