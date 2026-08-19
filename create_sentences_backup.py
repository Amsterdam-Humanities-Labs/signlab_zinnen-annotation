#!/usr/bin/env python3
from db_credentials import DB_PASSWORD
import mysql.connector
from datetime import datetime
import sys

# Database configuration
db_config = {
    'user': 'user',
    'password': DB_PASSWORD,
    'host': 'localhost',
    'database': 'admin_gebarenoverleg'
}

def create_backup_table():
    """Create a backup of the sentences table"""
    conn = None
    cursor = None
    try:
        conn = mysql.connector.connect(**db_config)
        cursor = conn.cursor()
        
        # Create backup table name with timestamp
        timestamp = datetime.now().strftime('%Y%m%d_%H%M%S')
        backup_table_name = f"sentences_backup_{timestamp}"
        
        print(f"Creating backup table: {backup_table_name}")
        
        # Create backup table as copy of sentences
        backup_query = f"CREATE TABLE {backup_table_name} AS SELECT * FROM sentences"
        cursor.execute(backup_query)
        conn.commit()
        
        # Get count of backed up records
        cursor.execute(f"SELECT COUNT(*) FROM {backup_table_name}")
        backup_count = cursor.fetchone()[0]
        
        # Get original count
        cursor.execute("SELECT COUNT(*) FROM sentences")
        original_count = cursor.fetchone()[0]
        
        print(f"✓ Backup created: {backup_table_name}")
        print(f"  Original table: {original_count} records")
        print(f"  Backup table: {backup_count} records")
        
        return backup_table_name, backup_count
        
    except mysql.connector.Error as err:
        print(f"Database Error: {err}")
        return None, 0
    except Exception as e:
        print(f"Unexpected error: {e}")
        return None, 0
    finally:
        if cursor:
            cursor.close()
        if conn and conn.is_connected():
            conn.close()

if __name__ == "__main__":
    print("=" * 60)
    print("CREATING BACKUP OF SENTENCES TABLE")
    print("=" * 60)
    
    backup_table_name, backup_count = create_backup_table()
    
    if backup_table_name:
        print(f"\n✓ SUCCESS: Backup table '{backup_table_name}' created with {backup_count} records")
    else:
        print("\n❌ FAILED: Could not create backup")
        sys.exit(1)