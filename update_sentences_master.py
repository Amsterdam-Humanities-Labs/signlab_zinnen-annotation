#!/usr/bin/env python3
from db_credentials import DB_PASSWORD
import subprocess
import sys
import mysql.connector
from datetime import datetime

# Database configuration
db_config = {
    'user': 'user',
    'password': DB_PASSWORD,
    'host': 'localhost',
    'database': 'admin_gebarenoverleg'
}

def get_sentence_stats():
    """Get current statistics from sentences table"""
    conn = None
    cursor = None
    try:
        conn = mysql.connector.connect(**db_config)
        cursor = conn.cursor()
        
        cursor.execute("SELECT COUNT(*) FROM sentences")
        total = cursor.fetchone()[0]
        
        cursor.execute("SELECT COUNT(*) FROM sentences WHERE label = 'ZNN'")
        znn = cursor.fetchone()[0]
        
        cursor.execute("SELECT COUNT(*) FROM sentences WHERE userid = '27'")
        userid27 = cursor.fetchone()[0]
        
        return total, znn, userid27
    except Exception as e:
        print(f"Error getting stats: {e}")
        return 0, 0, 0
    finally:
        if cursor:
            cursor.close()
        if conn and conn.is_connected():
            conn.close()

def run_script(script_path, description):
    """Run a Python script and capture output"""
    print(f"\n{'=' * 60}")
    print(f"Running: {description}")
    print('=' * 60)
    
    try:
        result = subprocess.run(['python3', script_path], 
                               capture_output=True, 
                               text=True,
                               input='yes\n')  # Auto-confirm for remove/add scripts
        
        print(result.stdout)
        if result.stderr:
            print(f"Errors: {result.stderr}")
        
        return result.returncode == 0
    except Exception as e:
        print(f"Error running script: {e}")
        return False

def main():
    print("=" * 80)
    print("MASTER SCRIPT: UPDATE SENTENCES TABLE")
    print("=" * 80)
    print(f"Started at: {datetime.now()}")
    
    # Get initial statistics
    print("\nGetting initial database statistics...")
    initial_total, initial_znn, initial_userid27 = get_sentence_stats()
    print(f"Initial state: {initial_total} total sentences ({initial_znn} ZNN, {initial_userid27} userid=27)")
    
    # Step 1: Create backup
    if not run_script('/web/zin/create_sentences_backup.py', 'Creating database backup'):
        print("\n❌ Backup failed! Aborting operation.")
        sys.exit(1)
    
    # Get user confirmation before proceeding
    print("\n" + "=" * 80)
    print("⚠️  WARNING: The next steps will modify the database!")
    print("=" * 80)
    response = input("\nDo you want to proceed with removal and addition? (yes/no): ")
    if response.lower() != 'yes':
        print("Operation cancelled by user")
        sys.exit(0)
    
    # Step 2: Remove sentences from extrazinnen_v1.csv
    if not run_script('/web/zin/remove_sentences_from_csv.py', 'Removing sentences from extrazinnen_v1.csv'):
        print("\n⚠️  Warning: Removal script encountered issues")
    
    # Get statistics after removal
    after_removal_total, after_removal_znn, after_removal_userid27 = get_sentence_stats()
    print(f"\nAfter removal: {after_removal_total} total sentences ({after_removal_znn} ZNN, {after_removal_userid27} userid=27)")
    print(f"Net change from removal: {after_removal_total - initial_total} sentences")
    
    # Step 3: Add sentences from Extra_2000zinnen.csv
    if not run_script('/web/zin/add_sentences_from_csv.py', 'Adding sentences from Extra_2000zinnen.csv'):
        print("\n⚠️  Warning: Addition script encountered issues")
    
    # Get final statistics
    final_total, final_znn, final_userid27 = get_sentence_stats()
    
    # Print summary
    print("\n" + "=" * 80)
    print("OPERATION SUMMARY")
    print("=" * 80)
    print(f"Completed at: {datetime.now()}")
    print("\nDatabase Statistics:")
    print(f"  Initial: {initial_total} sentences ({initial_znn} ZNN, {initial_userid27} userid=27)")
    print(f"  After removal: {after_removal_total} sentences")
    print(f"  Final: {final_total} sentences ({final_znn} ZNN, {final_userid27} userid=27)")
    print(f"\nNet Changes:")
    print(f"  Removal phase: {after_removal_total - initial_total} sentences")
    print(f"  Addition phase: {final_total - after_removal_total} sentences")
    print(f"  Total change: {final_total - initial_total} sentences")
    print("=" * 80)
    
    # Check for log files
    print("\nLog files created during this operation:")
    subprocess.run(['ls', '-la', '*.log'], shell=True)

if __name__ == "__main__":
    main()