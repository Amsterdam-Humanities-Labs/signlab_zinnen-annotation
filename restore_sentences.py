#!/usr/bin/env python3
from db_credentials import DB_PASSWORD
import mysql.connector
import sys

# Database configuration
db_config = {
    'user': 'user',
    'password': DB_PASSWORD,
    'host': 'localhost',
    'database': 'admin_gebarenoverleg'
}

def restore_sentences_from_backup(backup_table_name):
    """Restore sentences table from backup"""
    conn = None
    cursor = None
    try:
        conn = mysql.connector.connect(**db_config)
        cursor = conn.cursor()
        
        print(f"Restoring sentences table from backup: {backup_table_name}")
        
        # First, check if backup table exists
        cursor.execute(f"SHOW TABLES LIKE '{backup_table_name}'")
        if not cursor.fetchone():
            print(f"❌ Backup table '{backup_table_name}' does not exist!")
            return False
        
        # Get count from backup
        cursor.execute(f"SELECT COUNT(*) FROM {backup_table_name}")
        backup_count = cursor.fetchone()[0]
        print(f"Backup table contains {backup_count} records")
        
        # Drop current sentences table
        cursor.execute("DROP TABLE IF EXISTS sentences")
        print("✓ Dropped current sentences table")
        
        # Recreate sentences table from backup
        cursor.execute(f"CREATE TABLE sentences AS SELECT * FROM {backup_table_name}")
        conn.commit()
        print("✓ Recreated sentences table from backup")
        
        # Verify restoration
        cursor.execute("SELECT COUNT(*) FROM sentences")
        restored_count = cursor.fetchone()[0]
        print(f"✓ Restored {restored_count} records to sentences table")
        
        if restored_count == backup_count:
            print("✓ SUCCESS: All records restored successfully!")
            return True
        else:
            print("⚠ WARNING: Record count mismatch!")
            return False
        
    except mysql.connector.Error as err:
        print(f"Database Error: {err}")
        return False
    except Exception as e:
        print(f"Unexpected error: {e}")
        return False
    finally:
        if cursor:
            cursor.close()
        if conn and conn.is_connected():
            conn.close()

def main():
    backup_table_name = "sentences_backup_20250728_113520"
    
    print("=" * 50)
    print("RESTORING SENTENCES TABLE FROM BACKUP")
    print("=" * 50)
    
    success = restore_sentences_from_backup(backup_table_name)
    
    if success:
        print("✓ Restoration completed successfully!")
        sys.exit(0)
    else:
        print("❌ Restoration failed!")
        sys.exit(1)

if __name__ == "__main__":
    main()