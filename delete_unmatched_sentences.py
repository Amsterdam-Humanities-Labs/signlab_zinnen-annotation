#!/usr/bin/env python3
from db_credentials import DB_PASSWORD
import mysql.connector
import json
import sys
from datetime import datetime

# Database configuration
db_config = {
    'user': 'user',
    'password': DB_PASSWORD,
    'host': 'localhost',
    'database': 'admin_gebarenoverleg'
}

def create_backup_before_deletion():
    """Create a backup before deletion"""
    conn = None
    cursor = None
    try:
        conn = mysql.connector.connect(**db_config)
        cursor = conn.cursor()
        
        # Create backup table name with timestamp
        timestamp = datetime.now().strftime('%Y%m%d_%H%M%S')
        backup_table_name = f"sentences_backup_before_deletion_{timestamp}"
        
        print(f"Creating backup table: {backup_table_name}")
        
        # Create backup table as copy of sentences
        backup_query = f"CREATE TABLE {backup_table_name} AS SELECT * FROM sentences"
        cursor.execute(backup_query)
        conn.commit()
        
        # Get count of backed up records
        cursor.execute(f"SELECT COUNT(*) FROM {backup_table_name}")
        backup_count = cursor.fetchone()[0]
        
        print(f"✓ Backup created: {backup_table_name} ({backup_count} records)")
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

def delete_sentences_by_ids(json_file_path):
    """Delete sentences based on IDs from JSON file"""
    conn = None
    cursor = None
    try:
        # Load unmatched sentences from JSON
        print(f"Loading IDs from: {json_file_path}")
        with open(json_file_path, 'r', encoding='utf-8') as f:
            data = json.load(f)
        
        unmatched_sentences = data['unmatched_sentences']
        sentence_ids_to_delete = [sentence['ID'] for sentence in unmatched_sentences]
        
        if not sentence_ids_to_delete:
            print("No sentences to delete.")
            return 0
        
        print(f"Found {len(sentence_ids_to_delete)} sentence IDs to delete")
        
        conn = mysql.connector.connect(**db_config)
        cursor = conn.cursor()
        
        # Get current count before deletion
        cursor.execute("SELECT COUNT(*) FROM sentences")
        before_count = cursor.fetchone()[0]
        print(f"Current sentences count: {before_count}")
        
        # Create placeholders for IN clause (batch deletion for efficiency)
        batch_size = 1000  # Process in batches to avoid query size limits
        total_deleted = 0
        
        for i in range(0, len(sentence_ids_to_delete), batch_size):
            batch_ids = sentence_ids_to_delete[i:i + batch_size]
            placeholders = ','.join(['%s'] * len(batch_ids))
            delete_query = f"DELETE FROM sentences WHERE ID IN ({placeholders})"
            
            cursor.execute(delete_query, batch_ids)
            batch_deleted = cursor.rowcount
            total_deleted += batch_deleted
            
            print(f"Deleted batch {i//batch_size + 1}: {batch_deleted} records")
        
        conn.commit()
        
        # Get count after deletion
        cursor.execute("SELECT COUNT(*) FROM sentences")
        after_count = cursor.fetchone()[0]
        
        print(f"\n--- Deletion Summary ---")
        print(f"Records before deletion: {before_count}")
        print(f"Records after deletion: {after_count}")
        print(f"Total deleted: {total_deleted}")
        print(f"Expected deletion: {len(sentence_ids_to_delete)}")
        
        if total_deleted == len(sentence_ids_to_delete):
            print("✓ SUCCESS: All target sentences deleted!")
        else:
            print("⚠ WARNING: Deletion count mismatch!")
        
        return total_deleted
        
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

def verify_userid27_results():
    """Verify results specifically for userid=27 sentences"""
    conn = None
    cursor = None
    try:
        conn = mysql.connector.connect(**db_config)
        cursor = conn.cursor()
        
        # Count remaining userid=27 sentences
        cursor.execute("SELECT COUNT(*) FROM sentences WHERE userid = '27'")
        userid27_count = cursor.fetchone()[0]
        
        # Count userid=27 sentences with matching transcriptions
        cursor.execute("""
        SELECT COUNT(DISTINCT s.ID) 
        FROM sentences s
        INNER JOIN matched_transcriptions mt ON s.ID = mt.m_transcription AND mt.zOg LIKE 'zin'
        WHERE s.userid = '27'
        """)
        userid27_matched = cursor.fetchone()[0]
        
        # Total sentences count
        cursor.execute("SELECT COUNT(*) FROM sentences")
        total_count = cursor.fetchone()[0]
        
        print(f"\n--- Verification Results ---")
        print(f"Total sentences remaining: {total_count}")
        print(f"Sentences with userid=27: {userid27_count}")
        print(f"Userid=27 sentences with matches: {userid27_matched}")
        print(f"Userid=27 sentences without matches: {userid27_count - userid27_matched}")
        
        if userid27_count == userid27_matched:
            print("✓ SUCCESS: All remaining userid=27 sentences have matching transcriptions!")
        else:
            print("⚠ WARNING: Some userid=27 sentences still don't have matching transcriptions")
        
        return userid27_count, userid27_matched, total_count
        
    except mysql.connector.Error as err:
        print(f"Database Error during verification: {err}")
        return 0, 0, 0
    except Exception as e:
        print(f"Unexpected error during verification: {e}")
        return 0, 0, 0
    finally:
        if cursor:
            cursor.close()
        if conn and conn.is_connected():
            conn.close()

def main():
    """Main function"""
    print("=" * 60)
    print("DELETE UNMATCHED SENTENCES FROM TABLE")
    print("=" * 60)
    
    json_file_path = '/web/zin/unmatched_sentences.json'
    
    # Step 1: Create backup
    print("\n1. Creating backup before deletion...")
    backup_table_name, backup_count = create_backup_before_deletion()
    
    if not backup_table_name:
        print("❌ Backup failed. Aborting deletion.")
        sys.exit(1)
    
    # Step 2: Delete sentences
    print(f"\n2. Deleting unmatched sentences from: {json_file_path}")
    deleted_count = delete_sentences_by_ids(json_file_path)
    
    if deleted_count == 0:
        print("❌ Deletion failed.")
        sys.exit(1)
    
    # Step 3: Verify results
    print("\n3. Verifying results...")
    userid27_count, userid27_matched, total_count = verify_userid27_results()
    
    # Final summary
    print(f"\n" + "=" * 60)
    print("DELETION SUMMARY")
    print("=" * 60)
    print(f"Backup table: {backup_table_name}")
    print(f"Records deleted: {deleted_count}")
    print(f"Total sentences remaining: {total_count}")
    print(f"Userid=27 sentences remaining: {userid27_count}")
    print(f"Userid=27 sentences with matches: {userid27_matched}")
    print("=" * 60)

if __name__ == "__main__":
    main()