from db_credentials import DB_PASSWORD
import mysql.connector
import os

# Database configuration
db_config = {
    'user': 'user',
    'password': DB_PASSWORD,
    'host': 'localhost',
    'database': 'admin_gebarenoverleg'
}

# Path to the text file
txt_file_path = '/web/zin/zinnenOpnieuw.txt'

def update_added_status():
    conn = None
    cursor = None
    try:
        conn = mysql.connector.connect(**db_config)
        cursor = conn.cursor()

        if not os.path.exists(txt_file_path):
            print(f"Error: File not found at {txt_file_path}")
            return

        with open(txt_file_path, 'r', encoding='utf-8') as f:
            sentences_to_process = [line.strip() for line in f if line.strip()]

        updated_count_total = 0
        processed_sentences = 0
        not_found_sentences = []

        for zin_string in sentences_to_process:
            processed_sentences += 1
            # Find ID in sentences table
            query_find_sentence_id = "SELECT ID FROM sentences WHERE zinString = %s"
            cursor.execute(query_find_sentence_id, (zin_string,))
            result_rows = cursor.fetchall() # Changed from fetchone() to fetchall() to consume all results

            if result_rows: # Check if the list of rows is not empty
                sentence_id = result_rows[0][0] # Get ID from the first row
                
                # Update matched_transcriptions table
                # We assume m_transcription in matched_transcriptions corresponds to sentences.ID
                query_update_matched = "UPDATE matched_transcriptions SET added = '0' WHERE m_transcription = %s AND zOg='zin'"
                cursor.execute(query_update_matched, (sentence_id,))
                matched_updated_count = cursor.rowcount # Store rowcount for this specific update
                conn.commit()

                #also update sentences userId to '1'
                query_update_sentences = "UPDATE sentences SET userid = '1' AND thema='LORRAINE' WHERE ID = %s"
                cursor.execute(query_update_sentences, (sentence_id,))
                conn.commit()
                
                if matched_updated_count > 0: # Use the stored rowcount for matched_transcriptions
                    print(f"Updated {matched_updated_count} rows in matched_transcriptions for sentence ID {sentence_id} ('{zin_string}')")
                    updated_count_total += matched_updated_count
                else:
                    print(f"No rows in matched_transcriptions needed update for sentence ID {sentence_id} ('{zin_string}') or value was already '0'.")
            else:
                print(f"Sentence not found in 'sentences' table: '{zin_string}'")
                not_found_sentences.append(zin_string)

        print(f"\n--- Summary ---")
        print(f"Total sentences processed from file: {processed_sentences}")
        print(f"Total rows updated in 'matched_transcriptions': {updated_count_total}")
        if not_found_sentences:
            print(f"Sentences from file not found in 'sentences' table ({len(not_found_sentences)}):")
            for s in not_found_sentences:
                print(f"  - {s}")
        else:
            print("All sentences from the file were found and processed.")


    except mysql.connector.Error as err:
        print(f"Database Error: {err}")
    except FileNotFoundError:
        print(f"Error: The file {txt_file_path} was not found.")
    except Exception as e:
        print(f"An unexpected error occurred: {e}")
    finally:
        if cursor:
            cursor.close()
        if conn and conn.is_connected():
            conn.close()
            print("MySQL connection is closed.")

if __name__ == "__main__":
    update_added_status()
