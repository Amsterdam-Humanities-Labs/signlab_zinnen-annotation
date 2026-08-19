from db_credentials import DB_PASSWORD
import os
import pandas as pd
import json
import mysql.connector
from mysql.connector import Error
import re
import logging

# ===========================
# Configuration
# ===========================

# Configure logging
logging.basicConfig(
    filename='process_log.log',
    filemode='a',
    format='%(asctime)s - %(levelname)s - %(message)s',
    level=logging.INFO
)

# Database configuration - Use environment variables for security
db_config = {
    'user': 'user',  # Replace with your actual username or use environment variables
    'password': DB_PASSWORD,  # Replace with your actual password or use environment variables
    'host': 'signlab-db',  # Replace with your actual host
    'database': 'admin_gebarenoverleg'  # Replace with your actual database
}

# Input and output file paths
input_csv_path = '2k.csv'  # Replace with your input CSV file path
nothing_rows_csv_path = 'nothing_rows.csv'  # Output CSV for rows needing further processing

# ===========================
# Database Connection
# ===========================

def create_db_connection(config):
    """
    Creates and returns a database connection.
    """
    try:
        connection = mysql.connector.connect(
            user=config['user'],
            password=config['password'],
            host=config['host'],
            database=config['database']
        )
        if connection.is_connected():
            db_Info = connection.get_server_info()
            logging.info(f"Connected to MySQL Server version {db_Info}")
            return connection
    except Error as e:
        logging.error(f"Error while connecting to MySQL: {e}")
        return None

# ===========================
# Update Functions
# ===========================

def update_status_to_klaar(connection, sentence_id):
    """
    Updates the status of the sentence to 'Klaar' in the sentences table based on ID.
    """
    try:
        cursor = connection.cursor()
        query = """
            UPDATE sentences
            SET status = 'Klaar'
            WHERE ID = %s
        """
        cursor.execute(query, (sentence_id,))
        connection.commit()
        if cursor.rowcount > 0:
            logging.info(f"Updated status to 'Klaar' for sentence ID: '{sentence_id}'")
        else:
            logging.warning(f"No matching sentence found to update to 'Klaar' for ID: '{sentence_id}'")
        cursor.close()
    except Error as e:
        logging.error(f"Error updating status to 'Klaar' for sentence ID '{sentence_id}': {e}")

def update_status_to_niet_voor_app(connection, sentence_id):
    """
    Updates the status of the sentence to 'niet voor app' and moves it to 'Signbank' based on ID.
    """
    try:
        cursor = connection.cursor()
        update_query = """
            UPDATE sentences
            SET status = 'signbank'
            WHERE ID = %s
        """
        cursor.execute(update_query, (sentence_id,))
        connection.commit()
        if cursor.rowcount > 0:
            logging.info(f"Updated status to 'niet voor app' and moved to 'Signbank' for sentence ID: '{sentence_id}'")
        else:
            logging.warning(f"No matching sentence found to update to 'niet voor app' for ID: '{sentence_id}'")
        cursor.close()
    except Error as e:
        logging.error(f"Error updating status to 'niet voor app' for sentence ID '{sentence_id}': {e}")

# ===========================
# Fetch Sentences Function
# ===========================

def fetch_sentences(connection):
    """
    Fetches all sentences from the sentences table and creates a lookup dictionary.
    The dictionary maps lowercased, compact JSON arrays of words (zinArray) to their IDs.
    """
    try:
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
                    logging.warning(f"Invalid JSON format for zinArray: {zin_array}. Skipping.")
                    continue
        logging.info(f"Fetched {len(sentences_dict)} sentences for lookup.")
        return sentences_dict
    except Error as e:
        logging.error(f"Error fetching sentences from database: {e}")
        return {}

# ===========================
# Processing Functions
# ===========================

def convert_zin_to_json_array(zin):
    """
    Converts a sentence (zin) to a lowercased, compact JSON array string.
    """
    if pd.isna(zin):
        return json.dumps([])
    # Convert to lowercase to ensure consistency
    zin_lower = zin.lower()
    # Split by whitespace using regex to keep words with punctuation (e.g., "even?")
    words = re.findall(r'\S+', zin_lower)
    # Convert list to JSON array string without spaces for exact match
    return json.dumps(words, ensure_ascii=False, separators=(',', ':'))

def process_row(row, sentences_dict, connection, nothing_rows_list):
    """
    Processes a single row based on the specified rules.
    Updates the sentences table accordingly.
    """
    zin = row['Zin']
    controle = row['Controle']
    
    # Convert Zin to lowercased JSON array string
    zin_converted = convert_zin_to_json_array(zin)
    
    # Look up the sentence ID in the sentences_dict
    sentence_id = sentences_dict.get(zin_converted)
    
    if sentence_id:
        # Only process rows where Controle is 'goed' or 'fout'
        if pd.isna(controle):
            logging.info(f"Row with Zin '{zin}' skipped because Controle is NaN.")
            return
    
        controle = controle.strip().lower()
    
        if controle == 'goed':
            # Update status to 'Klaar'
            update_status_to_klaar(connection, sentence_id)
        elif controle == 'fout':
            # Update status to 'niet voor app' and move to 'Signbank'
            update_status_to_niet_voor_app(connection, sentence_id)
        else:
            # For any other Controle values, optionally handle or skip
            logging.info(f"Row with Zin '{zin}' skipped because Controle is '{controle}'.")
            # Optionally, add to nothing_rows_list if needed
            nothing_rows_list.append(row.to_dict())
    else:
        # Sentence not found in the database
        logging.warning(f"Sentence not found in database: '{zin}'")
        # Optionally, add to nothing_rows_list for further processing
        nothing_rows_list.append(row.to_dict())

# ===========================
# Main Execution
# ===========================

def main():
    # Create database connection
    connection = create_db_connection(db_config)
    if not connection:
        logging.critical("Database connection failed. Exiting script.")
        return

    try:
        # Fetch sentences and create lookup dictionary
        sentences_dict = fetch_sentences(connection)
        if not sentences_dict:
            logging.critical("No sentences fetched. Exiting script.")
            connection.close()
            return
    except Exception as e:
        logging.error(f"Unexpected error during fetching sentences: {e}")
        connection.close()
        return

    try:
        # Read input CSV
        df = pd.read_csv(input_csv_path, delimiter=',', encoding='utf-8-sig')
        logging.info(f"Input CSV '{input_csv_path}' read successfully with {len(df)} rows.")
    except Exception as e:
        logging.error(f"Error reading input CSV: {e}")
        connection.close()
        return

    # Prepare list to collect rows for nothing_rows.csv
    nothing_rows_list = []

    # Iterate through each row and process
    for index, row in df.iterrows():
        try:
            process_row(row, sentences_dict, connection, nothing_rows_list)
        except Exception as e:
            zin = row['Zin']
            logging.error(f"Error processing row {index} with Zin '{zin}': {e}")
            # Optionally, add to nothing_rows_list
            nothing_rows_list.append(row.to_dict())

    # After processing all rows, write nothing_rows_list to nothing_rows.csv
    if nothing_rows_list:
        try:
            nothing_rows_df = pd.DataFrame(nothing_rows_list)
            nothing_rows_df.to_csv(nothing_rows_csv_path, index=False, encoding='utf-8-sig')
            logging.info(f"Written {len(nothing_rows_df)} rows to '{nothing_rows_csv_path}'.")
        except Exception as e:
            logging.error(f"Error writing to '{nothing_rows_csv_path}': {e}")
    else:
        logging.info("No rows to write to nothing_rows.csv.")

    # Close the database connection
    connection.close()
    logging.info("Database connection closed.")

if __name__ == "__main__":
    main()
