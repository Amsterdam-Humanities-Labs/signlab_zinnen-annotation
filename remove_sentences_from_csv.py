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
    sentences = []
    try:
        with open(csv_file_path, 'r', encoding='utf-8') as f:
            csv_reader = csv.DictReader(f, delimiter=';')
            for row in csv_reader:
                zin = row['Zin'].strip() if row['Zin'] else ''
                if zin:  # Only add non-empty sentences
                    sentences.append(zin)
        return sentences
    except Exception as e:
        print(f"Error reading CSV: {e}")
        return []

def remove_sentences(sentences_to_remove):
    """Remove sentences from database that match the CSV entries"""
    conn = None
    cursor = None
    removed_count = 0
    not_found_count = 0
    
    try:
        conn = mysql.connector.connect(**db_config)
        cursor = conn.cursor()
        
        # Get initial count
        cursor.execute("SELECT COUNT(*) FROM sentences")
        initial_count = cursor.fetchone()[0]
        print(f"Initial sentence count: {initial_count}")
        
        # Log file for removed sentences
        log_filename = f"removed_sentences_{datetime.now().strftime('%Y%m%d_%H%M%S')}.log"
        
        with open(log_filename, 'w', encoding='utf-8') as log_file:
            log_file.write(f"Removal log - {datetime.now()}\n")
            log_file.write("=" * 60 + "\n\n")
            
            for sentence in sentences_to_remove:
                # Check if sentence exists in database
                check_query = "SELECT ID, zinString, thema FROM sentences WHERE zinString = %s"
                cursor.execute(check_query, (sentence,))
                results = cursor.fetchall()
                
                if results:
                    # Remove all matching sentences
                    delete_query = "DELETE FROM sentences WHERE zinString = %s"
                    cursor.execute(delete_query, (sentence,))
                    removed_count += cursor.rowcount
                    
                    # Log removed sentences
                    for result in results:
                        log_file.write(f"REMOVED - ID: {result[0]}, Sentence: {result[1]}, Theme: {result[2]}\n")
                else:
                    not_found_count += 1
                    log_file.write(f"NOT FOUND - {sentence}\n")
                
                # Commit every 100 deletions
                if removed_count % 100 == 0 and removed_count > 0:
                    conn.commit()
                    print(f"Progress: {removed_count} sentences removed...")
            
            # Final commit
            conn.commit()
            
            # Get final count
            cursor.execute("SELECT COUNT(*) FROM sentences")
            final_count = cursor.fetchone()[0]
            
            # Write summary to log
            log_file.write("\n" + "=" * 60 + "\n")
            log_file.write("SUMMARY\n")
            log_file.write(f"Total sentences in CSV: {len(sentences_to_remove)}\n")
            log_file.write(f"Sentences removed: {removed_count}\n")
            log_file.write(f"Sentences not found: {not_found_count}\n")
            log_file.write(f"Initial DB count: {initial_count}\n")
            log_file.write(f"Final DB count: {final_count}\n")
            log_file.write(f"Net change: {final_count - initial_count}\n")
        
        print(f"\n✓ Removal complete!")
        print(f"  Sentences removed: {removed_count}")
        print(f"  Sentences not found: {not_found_count}")
        print(f"  Log file: {log_filename}")
        
        return removed_count, not_found_count
        
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

def main():
    print("=" * 60)
    print("REMOVE SENTENCES FROM DATABASE BASED ON CSV")
    print("=" * 60)
    
    csv_file_path = '/web/zin/extrazinnen_v1.csv'
    
    print(f"\nReading sentences from: {csv_file_path}")
    sentences_to_remove = read_sentences_from_csv(csv_file_path)
    
    if not sentences_to_remove:
        print("❌ No sentences found in CSV file")
        return
    
    print(f"Found {len(sentences_to_remove)} sentences to remove")
    
    # Confirm before proceeding
    response = input("\nDo you want to proceed with removal? (yes/no): ")
    if response.lower() != 'yes':
        print("Operation cancelled")
        return
    
    print("\nRemoving sentences...")
    removed_count, not_found_count = remove_sentences(sentences_to_remove)
    
    print("\n" + "=" * 60)
    print("REMOVAL COMPLETE")
    print("=" * 60)

if __name__ == "__main__":
    main()