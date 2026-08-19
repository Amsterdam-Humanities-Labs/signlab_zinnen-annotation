#!/usr/bin/env python3
from db_credentials import DB_PASSWORD
import mysql.connector
import csv
import sys
import json
from datetime import datetime

# Database configuration
db_config = {
    'user': 'user',
    'password': DB_PASSWORD,
    'host': 'localhost',
    'database': 'admin_gebarenoverleg'
}

def create_backup_table():
    """Create a backup of the sentences table before import"""
    conn = None
    cursor = None
    try:
        conn = mysql.connector.connect(**db_config)
        cursor = conn.cursor()
        
        # Create backup table name with timestamp
        timestamp = datetime.now().strftime('%Y%m%d_%H%M%S')
        backup_table_name = f"sentences_backup_before_csv_import_{timestamp}"
        
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

def process_csv_and_import(csv_file_path):
    """Process CSV file and import data to sentences table"""
    conn = None
    cursor = None
    try:
        print(f"Processing CSV file: {csv_file_path}")
        
        # Read and process CSV
        sentences_to_import = []
        
        with open(csv_file_path, 'r', encoding='utf-8') as f:
            # Use semicolon as delimiter based on CSV structure
            csv_reader = csv.DictReader(f, delimiter=';')
            
            for row_num, row in enumerate(csv_reader, start=2):  # Start at 2 because of header
                zin = row['Zin'].strip() if row['Zin'] else ''
                context = row['Context'].strip() if row['Context'] else ''
                
                if not zin:  # Skip empty sentences
                    continue
                
                # Create zinArray as JSON list with the sentence
                zin_array = json.dumps([zin], ensure_ascii=False)
                
                sentence_data = {
                    'zinString': zin,
                    'zinArray': zin_array,
                    'thema': context,
                    'userid': '27',
                    'label': 'ZNN'
                }
                
                sentences_to_import.append(sentence_data)
        
        print(f"Found {len(sentences_to_import)} sentences to import")
        
        if not sentences_to_import:
            print("No sentences to import!")
            return 0
        
        # Connect to database and import
        conn = mysql.connector.connect(**db_config)
        cursor = conn.cursor()
        
        # Get current max ID to avoid conflicts
        cursor.execute("SELECT COALESCE(MAX(ID), 0) FROM sentences")
        max_id = cursor.fetchone()[0]
        print(f"Current max ID in sentences table: {max_id}")
        
        # Prepare insert query
        insert_query = """
        INSERT INTO sentences (zinString, zinArray, thema, userid, label, zinID, glosArray, sortArray)
        VALUES (%s, %s, %s, %s, %s, %s, %s, %s)
        """
        
        # Import sentences in batches
        batch_size = 100
        imported_count = 0
        
        for i in range(0, len(sentences_to_import), batch_size):
            batch = sentences_to_import[i:i + batch_size]
            batch_data = []
            
            for sentence in batch:
                # Create zinID (simple incremental based on max_id)
                zin_id = max_id + imported_count + 1
                
                batch_data.append((
                    sentence['zinString'],
                    sentence['zinArray'],
                    sentence['thema'],
                    sentence['userid'],
                    sentence['label'],
                    zin_id,  # zinID
                    '[]',    # glosArray (empty JSON array)
                    '[]'     # sortArray (empty JSON array)
                ))
                imported_count += 1
            
            # Execute batch insert
            cursor.executemany(insert_query, batch_data)
            conn.commit()
            print(f"Imported batch {i//batch_size + 1}: {len(batch)} sentences")
        
        print(f"✓ Successfully imported {imported_count} sentences")
        return imported_count
        
    except mysql.connector.Error as err:
        print(f"Database Error during import: {err}")
        if conn:
            conn.rollback()
        return 0
    except FileNotFoundError:
        print(f"Error: CSV file not found: {csv_file_path}")
        return 0
    except Exception as e:
        print(f"Unexpected error during import: {e}")
        if conn:
            conn.rollback()
        return 0
    finally:
        if cursor:
            cursor.close()
        if conn and conn.is_connected():
            conn.close()

def verify_import():
    """Verify the import results"""
    conn = None
    cursor = None
    try:
        conn = mysql.connector.connect(**db_config)
        cursor = conn.cursor()
        
        # Get total count
        cursor.execute("SELECT COUNT(*) FROM sentences")
        total_count = cursor.fetchone()[0]
        
        # Count ZNN labeled sentences
        cursor.execute("SELECT COUNT(*) FROM sentences WHERE label = 'ZNN'")
        znn_count = cursor.fetchone()[0]
        
        # Count userid=27 sentences
        cursor.execute("SELECT COUNT(*) FROM sentences WHERE userid = '27'")
        userid27_count = cursor.fetchone()[0]
        
        # Sample a few imported sentences
        cursor.execute("""
        SELECT ID, zinString, thema, userid, label 
        FROM sentences 
        WHERE label = 'ZNN' 
        ORDER BY ID DESC 
        LIMIT 5
        """)
        sample_sentences = cursor.fetchall()
        
        print(f"\n--- Import Verification ---")
        print(f"Total sentences in table: {total_count}")
        print(f"Sentences with label 'ZNN': {znn_count}")
        print(f"Sentences with userid '27': {userid27_count}")
        
        print(f"\nSample imported sentences:")
        for sentence in sample_sentences:
            print(f"  ID {sentence[0]}: {sentence[1][:50]}... (thema: {sentence[2]}, userid: {sentence[3]}, label: {sentence[4]})")
        
        return total_count, znn_count, userid27_count
        
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
    print("CSV IMPORT TO SENTENCES TABLE")
    print("=" * 60)
    
    csv_file_path = '/web/zin/extrazinnen_v1.csv'
    
    # Step 1: Create backup
    print("\n1. Creating backup of sentences table...")
    backup_table_name, backup_count = create_backup_table()
    
    if not backup_table_name:
        print("❌ Backup failed. Aborting import.")
        sys.exit(1)
    
    # Step 2: Import CSV
    print(f"\n2. Importing CSV data from: {csv_file_path}")
    imported_count = process_csv_and_import(csv_file_path)
    
    if imported_count == 0:
        print("❌ Import failed.")
        sys.exit(1)
    
    # Step 3: Verify import
    print("\n3. Verifying import...")
    total_count, znn_count, userid27_count = verify_import()
    
    # Final summary
    print(f"\n" + "=" * 60)
    print("IMPORT SUMMARY")
    print("=" * 60)
    print(f"Backup table: {backup_table_name}")
    print(f"Records imported: {imported_count}")
    print(f"Total sentences after import: {total_count}")
    print(f"Sentences with label 'ZNN': {znn_count}")
    print(f"Sentences with userid '27': {userid27_count}")
    print("=" * 60)

if __name__ == "__main__":
    main()