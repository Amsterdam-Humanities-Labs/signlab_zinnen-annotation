from db_credentials import DB_PASSWORD
import pandas as pd
import re
import json
import mysql.connector
from mysql.connector import Error

# Database configuration
db_config = {
    'user': 'user',
    'password': DB_PASSWORD,
    'host': 'signlab-db',
    'database': 'admin_gebarenoverleg'
}

# CSV file paths
# csv_file_path = '2k.csv'
# output_csv_path = '2k_enhanced.csv'

csv_file_path = 'test.csv'
output_csv_path = 'test_enhanced.csv'

# Function to clean column names by stripping leading/trailing spaces and removing BOM if present
def clean_column_names(df):
    # Remove BOM from the first column name if present
    df.columns = [col.encode('utf-8').decode('utf-8-sig') if idx == 0 else col for idx, col in enumerate(df.columns)]
    # Strip leading/trailing whitespace from all column names
    df.columns = df.columns.str.strip()
    return df

# Function to split 'Zin' into words and convert to JSON array
def convert_zin_to_json_array(zin):
    if pd.isna(zin):
        return json.dumps([])
    # Convert to lowercase to ensure consistency
    zin_lower = zin.lower()
    # Split by whitespace using regex to keep words with punctuation (e.g., "even?")
    words = re.findall(r'\S+', zin_lower)
    # Convert list to JSON array string without spaces for exact match
    return json.dumps(words, ensure_ascii=False, separators=(',', ':'))

# Function to connect to the MySQL database
def create_db_connection(config):
    try:
        connection = mysql.connector.connect(
            user=config['user'],
            password=config['password'],
            host=config['host'],
            database=config['database']
        )
        if connection.is_connected():
            print("Successfully connected to the database.")
            return connection
    except Error as e:
        print(f"Error while connecting to MySQL: {e}")
        return None

# Function to fetch all sentences and create a lookup dictionary
def fetch_sentences(connection):
    query = "SELECT ID, zinArray FROM sentences ORDER BY ID DESC"
    cursor = connection.cursor()
    cursor.execute(query)
    results = cursor.fetchall()
    cursor.close()
    
    # Create a dictionary with compact lowercase zinArray as keys and ID as values
    sentences_dict = {}
    for row in results:
        sentence_id, zin_array = row
        if isinstance(zin_array, str):
            try:
                # Load the JSON array
                zin_list = json.loads(zin_array)
                # Ensure all words are lowercase
                zin_list_lower = [word.lower() for word in zin_list]
                # Dump back to compact JSON string
                zin_array_compact = json.dumps(zin_list_lower, ensure_ascii=False, separators=(',', ':'))
                sentences_dict[zin_array_compact] = sentence_id
            except json.JSONDecodeError:
                print(f"Warning: Invalid JSON format for zinArray: {zin_array}. Skipping.")
                continue
    return sentences_dict

# Function to get matched transcriptions based on ID and Zin
def get_matched_transcriptions(connection, zin, sentence_id):
    # IMPORTANT: Verify the correct column name for sentence ID in the 'matched_transcriptions' table
    # The error indicates 'sentence_id' does not exist. It might be 'sentenceID', 'SentenceID', etc.
    # Replace 'sentence_id' below with the correct column name as per your database schema.
    correct_sentence_id_column = 'm_transcription'  # Example: change as needed
    
    query = f"""
        SELECT m_file, m_transcription 
        FROM matched_transcriptions 
        WHERE zOg = %s AND {correct_sentence_id_column} = %s
        ORDER BY id ASC
    """
    cursor = connection.cursor()
    cursor.execute(query, ("Zin", sentence_id))
    results = cursor.fetchall()
    cursor.close()
    # Extract the 'm_file' and 'm_transcription' from each row
    transcriptions = [{'m_file': row[0], 'm_transcription': row[1]} for row in results]
    return transcriptions

# Main processing function
def process_csv_with_db(csv_path, output_path, db_config):
    # Step 1: Read the CSV file with 'utf-8-sig' encoding to handle BOM
    try:
        df = pd.read_csv(csv_path, delimiter=',', encoding='utf-8-sig')
    except UnicodeDecodeError:
        print("UnicodeDecodeError encountered. Trying with 'utf-8' encoding without BOM.")
        try:
            df = pd.read_csv(csv_path, delimiter=',', encoding='utf-8')
        except Exception as e:
            print(f"Failed to read CSV file with 'utf-8' encoding: {e}")
            return
    except Exception as e:
        print(f"Failed to read CSV file: {e}")
        return

    # Step 2: Clean column names
    df = clean_column_names(df)
    print("Columns after cleaning:", df.columns.tolist())

    # Verify that 'Zin' column exists
    if 'Zin' not in df.columns:
        print("Error: 'Zin' column not found in the CSV file.")
        return
    
    # Step 2.1: Remove rows where both 'Overnieuw' and "Welke video goeie als er meerdere video's zijn?" are empty
    df = df.dropna(subset=['Overnieuw', "Welke video goeie als er meerdere video's zijn?"], how='all')

    # Step 3: Convert 'Zin' to JSON array
    df['zinArray'] = df['Zin'].apply(convert_zin_to_json_array)

    # Step 4: Connect to the database
    connection = create_db_connection(db_config)
    if not connection:
        print("Database connection failed. Exiting.")
        return

    # Step 5: Fetch all sentences and create a lookup dictionary
    sentences_dict = fetch_sentences(connection)
    if not sentences_dict:
        print("No sentences fetched from the database. Exiting.")
        connection.close()
        return
    try:
        first_item = next(iter(sentences_dict.items()))
        print(f"First item in sentences_dict: {first_item}")
    except StopIteration:
        print("Sentences dictionary is empty.")
    print(f"Fetched {len(sentences_dict)} sentences from the database.")

    # Step 6: Initialize lists to store matched transcriptions and warnings
    matched_transcriptions_list = []
    matched_m_transcriptions_list = []
    warnings_list = []

    # Step 7: Iterate through each row in the DataFrame
    for index, row in df.iterrows():
        zin_original = row['Zin']
        zin_lower = zin_original.lower()
        zin_array = row['zinArray']
        print(f"\nProcessing row {index + 1}: '{zin_original}'")

        print(f"zinArray: {zin_array}")
        # Lookup sentence ID from the pre-fetched dictionary
        sentence_id = sentences_dict.get(zin_array)
        if sentence_id is None:
            warning_msg = f"No sentence ID found for Zin Array: '{zin_array}'."
            print(f"Warning: {warning_msg} Skipping.")
            matched_transcriptions_list.append([])
            matched_m_transcriptions_list.append([])
            warnings_list.append(warning_msg)
            continue  # Allow processing of all rows

        print(f"Found sentence ID: {sentence_id}")

        # Get matched transcriptions
        transcriptions = get_matched_transcriptions(connection, zin_lower, sentence_id)
        if transcriptions:
            print(f"Found {len(transcriptions)} matched transcription(s).")
            matched_transcriptions_list.append(transcriptions)
            # Extract m_transcription separately
            m_transcriptions = [t['m_transcription'] for t in transcriptions]
            matched_m_transcriptions_list.append(m_transcriptions)
            warnings_list.append(None)  # No warning
        else:
            warning_msg = "No matched transcriptions found."
            print(f"Warning: {warning_msg}")
            matched_transcriptions_list.append([])
            matched_m_transcriptions_list.append([])
            warnings_list.append(warning_msg)

    # Step 8: Add the matched transcriptions and warnings to the DataFrame
    df['matched_transcriptions'] = matched_transcriptions_list
    df['matched_m_transcriptions'] = matched_m_transcriptions_list
    df['Warning'] = warnings_list

    # Step 9: Filter rows based on 'Overnieuw' and video preference
    # Define the list of acceptable video preferences
    video_preferences = ['V1', 'V2', 'V3', 'V4', 'V5', 'V6', 'V7', 'V8', 'V9', 'V10']

    # Filter rows where 'Overnieuw' == 'Y' OR video preference contains at least one of V1-V5
    filtered_df = df[
        (df['Overnieuw'] == 'Y') |
        (df["Welke video goeie als er meerdere video's zijn?"].str.contains('|'.join(video_preferences), na=False))
    ].copy()

    print(f"\nFiltered down to {len(filtered_df)} rows based on 'Overnieuw' == 'Y' or video preferences.")

    # Step 10: Define a mapping from video preference to index
    preference_to_index = {
        'V1': 0,
        'V2': 1,
        'V3': 2,
        'V4': 3,
        'V5': 4, 
        'V6': 5,
        'V7': 6,
        
    }

    # Function to select m_file and m_transcription based on video preference
    def select_m_file(row):
        preference = row["Welke video goeie als er meerdere video's zijn?"]
        # Extract all preferences if multiple are present (e.g., "V1 en V2")
        preferences = re.findall(r'V[1-5]', str(preference))
        selected_files = []
        selected_transcriptions = []
        warnings = []
        for pref in preferences:
            index = preference_to_index.get(pref, None)
            if index is not None:
                try:
                    selected_file = row['matched_transcriptions'][index]['m_file']
                    selected_transcription = row['matched_transcriptions'][index]['m_transcription']
                    selected_files.append(selected_file)
                    selected_transcriptions.append(selected_transcription)
                except IndexError:
                    warning_msg = f"Not enough matched transcriptions"
                    print(f"Warning: {warning_msg}")
                    warnings.append(warning_msg)
                    selected_files.append(None)
                    selected_transcriptions.append(None)
        # Join multiple selected files and transcriptions with a comma, or return the single file/transcription
        if selected_files:
            selected_file_str = ', '.join([f for f in selected_files if f is not None]) or None
            selected_transcription_str = ', '.join([t for t in selected_transcriptions if t is not None]) or None
        else:
            selected_file_str = None
            selected_transcription_str = None
        # Join all warnings with a semicolon
        if warnings:
            combined_warnings = '; '.join(warnings)
        else:
            combined_warnings = None
        return pd.Series([selected_file_str, selected_transcription_str, combined_warnings])

    # Apply the function to get 'selected_m_file', 'selected_m_transcription', and 'Warning'
    selected_columns = filtered_df.apply(select_m_file, axis=1)
    selected_columns.columns = ['selected_m_file', 'selected_m_transcription', 'Selected_Warning']
    filtered_df = pd.concat([filtered_df, selected_columns], axis=1)

    # Step 11: Merge 'selected_m_file', 'selected_m_transcription', and 'Selected_Warning' back to the main DataFrame
    # This will align the 'selected_m_file', 'selected_m_transcription', and 'Warning' based on the index
    df = df.merge(
        filtered_df[['selected_m_file', 'selected_m_transcription', 'Selected_Warning']],
        left_index=True,
        right_index=True,
        how='left'
    )

    # Combine existing 'Warning' with 'Selected_Warning'
    df['Warning'] = df.apply(
        lambda row: '; '.join(filter(None, [row['Warning'], row['Selected_Warning']])) if pd.notna(row['Selected_Warning']) else row['Warning'],
        axis=1
    )

    # Drop the temporary 'Selected_Warning' column
    df.drop(columns=['Selected_Warning'], inplace=True)

    # Step 12: Close the database connection
    if connection.is_connected():
        connection.close()
        print("\nDatabase connection closed.")

    # Step 13: Select only the desired columns for the output CSV
    # Include 'm_transcription' values and 'Warning' column
    df = df[['Zin', 'matched_transcriptions', 'matched_m_transcriptions', 'selected_m_file', 'selected_m_transcription', "Welke video goeie als er meerdere video's zijn?", 'Overnieuw', 'Warning']]

    # Step 14: Save the enhanced DataFrame to a new CSV
    try:
        df.to_csv(output_csv_path, index=False, encoding='utf-8-sig')
        print(f"\nEnhanced CSV saved to '{output_csv_path}'.")
    except Exception as e:
        print(f"Failed to save enhanced CSV: {e}")

# Run the processing function
if __name__ == "__main__":
    process_csv_with_db(csv_file_path, output_csv_path, db_config)
