#!/usr/bin/env python3
from db_credentials import DB_PASSWORD
import mysql.connector
import json
import sys
from datetime import datetime

# Database configuration (same as existing scripts)
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
        backup_query = f"""
        CREATE TABLE {backup_table_name} AS 
        SELECT * FROM sentences
        """
        
        cursor.execute(backup_query)
        conn.commit()
        
        # Get count of backed up records
        cursor.execute(f"SELECT COUNT(*) FROM {backup_table_name}")
        backup_count = cursor.fetchone()[0]
        
        print(f"✓ Backup created successfully: {backup_table_name}")
        print(f"✓ Backed up {backup_count} records")
        
        return backup_table_name, backup_count
        
    except mysql.connector.Error as err:
        print(f"Database Error during backup: {err}")
        return None, 0
    except Exception as e:
        print(f"Unexpected error during backup: {e}")
        return None, 0
    finally:
        if cursor:
            cursor.close()
        if conn and conn.is_connected():
            conn.close()

def delete_unmatched_sentences(json_file_path):
    """Delete sentences based on IDs from the JSON file"""
    conn = None
    cursor = None
    try:
        # Load unmatched sentences from JSON
        with open(json_file_path, 'r', encoding='utf-8') as f:
            data = json.load(f)
        
        unmatched_sentences = data['unmatched_sentences']
        sentence_ids_to_delete = [sentence['ID'] for sentence in unmatched_sentences]
        
        if not sentence_ids_to_delete:
            print("No sentences to delete.")
            return 0
        
        print(f"Preparing to delete {len(sentence_ids_to_delete)} sentences...")
        
        conn = mysql.connector.connect(**db_config)
        cursor = conn.cursor()
        
        # Create placeholders for IN clause
        placeholders = ','.join(['%s'] * len(sentence_ids_to_delete))
        delete_query = f"DELETE FROM sentences WHERE ID IN ({placeholders})"
        
        # Execute deletion
        cursor.execute(delete_query, sentence_ids_to_delete)
        deleted_count = cursor.rowcount
        conn.commit()
        
        print(f"✓ Successfully deleted {deleted_count} sentences from the sentences table")
        
        return deleted_count
        
    except mysql.connector.Error as err:
        print(f"Database Error during deletion: {err}")
        if conn:
            conn.rollback()
        return 0
    except FileNotFoundError:
        print(f"Error: JSON file not found: {json_file_path}")
        return 0
    except json.JSONDecodeError:
        print(f"Error: Invalid JSON in file: {json_file_path}")
        return 0
    except Exception as e:
        print(f"Unexpected error during deletion: {e}")
        if conn:
            conn.rollback()
        return 0
    finally:
        if cursor:
            cursor.close()
        if conn and conn.is_connected():
            conn.close()

def verify_results():
    """Verify the deletion was successful"""
    conn = None
    cursor = None
    try:
        conn = mysql.connector.connect(**db_config)
        cursor = conn.cursor()
        
        # Get current count
        cursor.execute("SELECT COUNT(*) FROM sentences")
        current_count = cursor.fetchone()[0]
        
        # Count sentences that have matching transcriptions
        cursor.execute("""
        SELECT COUNT(DISTINCT s.ID) 
        FROM sentences s
        INNER JOIN matched_transcriptions mt ON s.ID = mt.m_transcription AND mt.zOg LIKE 'zin'
        """)
        matched_count = cursor.fetchone()[0]
        
        print(f"\n--- Verification Results ---")
        print(f"Current sentences count: {current_count}")
        print(f"Sentences with matching transcriptions: {matched_count}")
        print(f"Sentences without matching transcriptions: {current_count - matched_count}")
        
        if current_count == matched_count:
            print("✓ SUCCESS: All remaining sentences have matching transcriptions!")
        else:
            print("⚠ WARNING: Some sentences still don't have matching transcriptions")
        
        return current_count, matched_count
        
    except mysql.connector.Error as err:
        print(f"Database Error during verification: {err}")
        return 0, 0
    except Exception as e:
        print(f"Unexpected error during verification: {e}")
        return 0, 0
    finally:
        if cursor:
            cursor.close()
        if conn and conn.is_connected():
            conn.close()

def main():
    """Main function to backup and cleanup sentences"""
    print("=" * 60)
    print("SENTENCES TABLE BACKUP AND CLEANUP")
    print("=" * 60)
    
    json_file_path = '/web/zin/unmatched_sentences.json'
    
    # Step 1: Create backup
    print("\n1. Creating backup of sentences table...")
    backup_table_name, backup_count = create_backup_table()
    
    if not backup_table_name:
        print("❌ Backup failed. Aborting cleanup.")
        sys.exit(1)
    
    # Step 2: Delete unmatched sentences
    print(f"\n2. Deleting unmatched sentences from JSON file: {json_file_path}")
    deleted_count = delete_unmatched_sentences(json_file_path)
    
    if deleted_count == 0:
        print("❌ Deletion failed or no sentences were deleted.")
        sys.exit(1)
    
    # Step 3: Verify results
    print("\n3. Verifying results...")
    current_count, matched_count = verify_results()
    
    # Summary
    print(f"\n" + "=" * 60)
    print("CLEANUP SUMMARY")
    print("=" * 60)
    print(f"Backup table created: {backup_table_name}")
    print(f"Records backed up: {backup_count}")
    print(f"Records deleted: {deleted_count}")
    print(f"Remaining sentences: {current_count}")
    print(f"Sentences with matches: {matched_count}")
    print("=" * 60)

if __name__ == "__main__":
    main()