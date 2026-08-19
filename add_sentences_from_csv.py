#!/usr/bin/env python3
from db_credentials import DB_PASSWORD
import mysql.connector
import csv
import json
from datetime import datetime

# Database configuration
db_config = {
    'user': 'user',
    'password': DB_PASSWORD,
    'host': 'localhost',
    'database': 'admin_gebarenoverleg'
}

def read_sentences_from_csv(csv_file_path):
    """Read sentences from CSV file"""
    sentences_to_add = []
    try:
        with open(csv_file_path, 'r', encoding='utf-8') as f:
            csv_reader = csv.DictReader(f, delimiter=';')
            
            for row_num, row in enumerate(csv_reader, start=2):
                zin = row['Zin'].strip() if row['Zin'] else ''
                context = row['Context'].strip() if row['Context'] else ''
                
                if zin:  # Only add non-empty sentences
                    sentences_to_add.append({
                        'zinString': zin,
                        'thema': context,
                        'row_num': row_num
                    })
        
        return sentences_to_add
    except Exception as e:
        print(f"Error reading CSV: {e}")
        return []

def add_sentences(sentences_to_add):
    """Add sentences to database"""
    conn = None
    cursor = None
    added_count = 0
    duplicate_count = 0
    
    try:
        conn = mysql.connector.connect(**db_config)
        cursor = conn.cursor()
        
        # Get initial count
        cursor.execute("SELECT COUNT(*) FROM sentences")
        initial_count = cursor.fetchone()[0]
        print(f"Initial sentence count: {initial_count}")
        
        # Get current max ID
        cursor.execute("SELECT COALESCE(MAX(ID), 0) FROM sentences")
        max_id = cursor.fetchone()[0]
        
        # Log file for added sentences
        log_filename = f"added_sentences_{datetime.now().strftime('%Y%m%d_%H%M%S')}.log"
        
        with open(log_filename, 'w', encoding='utf-8') as log_file:
            log_file.write(f"Addition log - {datetime.now()}\n")
            log_file.write("=" * 60 + "\n\n")
            
            # Prepare insert query
            insert_query = """
            INSERT INTO sentences (zinString, zinArray, thema, userid, label, zinID, glosArray, sortArray)
            VALUES (%s, %s, %s, %s, %s, %s, %s, %s)
            """
            
            for sentence_data in sentences_to_add:
                # Check if sentence already exists
                check_query = "SELECT ID FROM sentences WHERE zinString = %s"
                cursor.execute(check_query, (sentence_data['zinString'],))
                existing = cursor.fetchone()
                
                if existing:
                    duplicate_count += 1
                    log_file.write(f"DUPLICATE - Row {sentence_data['row_num']}: {sentence_data['zinString']}\n")
                else:
                    # Add the sentence
                    zin_array = json.dumps([sentence_data['zinString']], ensure_ascii=False)
                    zin_id = max_id + added_count + 1
                    
                    values = (
                        sentence_data['zinString'],
                        zin_array,
                        sentence_data['thema'],
                        '27',      # userid
                        'ZNN',     # label
                        zin_id,    # zinID
                        '[]',      # glosArray (empty JSON array)
                        '[]'       # sortArray (empty JSON array)
                    )
                    
                    cursor.execute(insert_query, values)
                    added_count += 1
                    
                    log_file.write(f"ADDED - ID: {cursor.lastrowid}, Row {sentence_data['row_num']}: {sentence_data['zinString']} (Theme: {sentence_data['thema']})\n")
                
                # Commit every 100 additions
                if added_count % 100 == 0 and added_count > 0:
                    conn.commit()
                    print(f"Progress: {added_count} sentences added...")
            
            # Final commit
            conn.commit()
            
            # Get final count
            cursor.execute("SELECT COUNT(*) FROM sentences")
            final_count = cursor.fetchone()[0]
            
            # Write summary to log
            log_file.write("\n" + "=" * 60 + "\n")
            log_file.write("SUMMARY\n")
            log_file.write(f"Total sentences in CSV: {len(sentences_to_add)}\n")
            log_file.write(f"Sentences added: {added_count}\n")
            log_file.write(f"Duplicates skipped: {duplicate_count}\n")
            log_file.write(f"Initial DB count: {initial_count}\n")
            log_file.write(f"Final DB count: {final_count}\n")
            log_file.write(f"Net change: {final_count - initial_count}\n")
        
        print(f"\n✓ Addition complete!")
        print(f"  Sentences added: {added_count}")
        print(f"  Duplicates skipped: {duplicate_count}")
        print(f"  Log file: {log_filename}")
        
        return added_count, duplicate_count
        
    except mysql.connector.Error as err:
        print(f"Database Error: {err}")
        if conn:
            conn.rollback()
        return 0, 0
    except Exception as e:
        print(f"Unexpected error: {e}")
        if conn:
            conn.rollback()
        return 0, 0
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
        
        # Get counts
        cursor.execute("SELECT COUNT(*) FROM sentences")
        total_count = cursor.fetchone()[0]
        
        cursor.execute("SELECT COUNT(*) FROM sentences WHERE label = 'ZNN'")
        znn_count = cursor.fetchone()[0]
        
        cursor.execute("SELECT COUNT(*) FROM sentences WHERE userid = '27'")
        userid27_count = cursor.fetchone()[0]
        
        # Sample recently added sentences
        cursor.execute("""
        SELECT ID, zinString, thema, userid, label 
        FROM sentences 
        WHERE label = 'ZNN' 
        ORDER BY ID DESC 
        LIMIT 5
        """)
        sample_sentences = cursor.fetchall()
        
        print(f"\n--- Import Verification ---")
        print(f"Total sentences: {total_count}")
        print(f"Sentences with label 'ZNN': {znn_count}")
        print(f"Sentences with userid '27': {userid27_count}")
        
        if sample_sentences:
            print(f"\nSample recently added sentences:")
            for sentence in sample_sentences:
                print(f"  ID {sentence[0]}: {sentence[1][:50]}... (theme: {sentence[2]})")
        
    except Exception as e:
        print(f"Error during verification: {e}")
    finally:
        if cursor:
            cursor.close()
        if conn and conn.is_connected():
            conn.close()

def main():
    print("=" * 60)
    print("ADD SENTENCES TO DATABASE FROM CSV")
    print("=" * 60)
    
    csv_file_path = '/web/zin/Extra_2000zinnen.csv'
    
    print(f"\nReading sentences from: {csv_file_path}")
    sentences_to_add = read_sentences_from_csv(csv_file_path)
    
    if not sentences_to_add:
        print("❌ No sentences found in CSV file")
        return
    
    print(f"Found {len(sentences_to_add)} sentences to process")
    
    # Confirm before proceeding
    response = input("\nDo you want to proceed with addition? (yes/no): ")
    if response.lower() != 'yes':
        print("Operation cancelled")
        return
    
    print("\nAdding sentences...")
    added_count, duplicate_count = add_sentences(sentences_to_add)
    
    # Verify the import
    verify_import()
    
    print("\n" + "=" * 60)
    print("ADDITION COMPLETE")
    print("=" * 60)

if __name__ == "__main__":
    main()