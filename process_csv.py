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
    'user': 'user',
    'password': DB_PASSWORD,
    'host': 'signlab-db',
    'database': 'admin_gebarenoverleg'
}


# Input and output file paths
input_csv_path = 'test_enhanced.csv'  # Replace with your input CSV file path
nothing_rows_csv_path = 'test_nothing_rows.csv'

# Regex pattern for warnings
warning_pattern = re.compile(r"Not enough matched transcriptions for preference", re.IGNORECASE)

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
# Update Function
# ===========================

def update_added_status(connection, m_file=None, m_transcription=None, zOg='Zin'):
    """
    Updates the 'added' status to 0 in matched_transcriptions table based on m_file or m_transcription.
    """
    try:
        cursor = connection.cursor()
        if m_file:
            # query = """
            #     UPDATE matched_transcriptions
            #     SET added = 0
            #     WHERE zOg = %s AND m_file = %s
            # """
            # cursor.execute(query, (zOg, m_file))
            # logging.info(f"Set added=0 for m_file='{m_file}' where zOg='{zOg}'")
            pass
        elif m_transcription:
            query = """
                UPDATE matched_transcriptions
                SET added = 0
                WHERE zOg = %s AND m_transcription = %s
            """
            cursor.execute(query, (zOg, m_transcription))
            logging.info(f"Set added=0 for m_transcription='{m_transcription}' where zOg='{zOg}'")
        connection.commit()
        cursor.close()
    except Error as e:
        logging.error(f"Error updating added status: {e}")

# ===========================
# Processing Function
# ===========================

def process_row(row, connection, nothing_rows_list):
    """
    Processes a single row based on the specified rules.
    """
    zin = row['Zin']
    matched_transcriptions_str = row['matched_transcriptions']
    matched_m_transcriptions_str = row['matched_m_transcriptions']
    selected_m_file_str = row['selected_m_file']
    warning = row['Warning']
    overnieuw = row['Overnieuw']

    # Process 'Overnieuw' == 'Y'
    if pd.notna(overnieuw) and overnieuw.strip().upper() == 'Y':
        if pd.notna(matched_m_transcriptions_str):
            try:
                # Convert string representation of list to actual list
                matched_m_transcriptions = json.loads(matched_m_transcriptions_str.replace("'", '"'))
                for m_transcription in matched_m_transcriptions:
                    update_added_status(connection, m_transcription=m_transcription)
            except json.JSONDecodeError as e:
                logging.error(f"JSON decoding error for matched_m_transcriptions in row '{zin}': {e}")
        else:
            logging.warning(f"No matched_m_transcriptions found for row with Zin: '{zin}'")

    # Check for specific warnings and append to nothing_rows_list
    if pd.notna(warning) and warning_pattern.search(warning):
        nothing_rows_list.append(row.to_dict())
        logging.info(f"Row with Zin '{zin}' added to nothing_rows.csv due to warning.")

    # Process selected_m_file
    if pd.notna(selected_m_file_str) and selected_m_file_str.strip() != '':
        try:
            # Parse matched_transcriptions
            if pd.notna(matched_transcriptions_str) and matched_transcriptions_str.strip() != '':
                matched_transcriptions = json.loads(matched_transcriptions_str.replace("'", '"'))
                matched_m_files = [item['m_file'] for item in matched_transcriptions]
            else:
                matched_transcriptions = []
                matched_m_files = []

            # Parse selected_m_file
            selected_m_files = [file.strip() for file in selected_m_file_str.split(',')]

            # Determine m_files to update (matched_transcriptions not in selected_m_files)
            m_files_to_update = set(matched_m_files) - set(selected_m_files)

            for m_file in m_files_to_update:
                update_added_status(connection, m_file=m_file)
        except json.JSONDecodeError as e:
            logging.error(f"JSON decoding error for matched_transcriptions in row '{zin}': {e}")

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
        process_row(row, connection, nothing_rows_list)

    # After processing all rows, write nothing_rows_list to nothing_rows.csv
    if nothing_rows_list:
        try:
            nothing_rows_df = pd.DataFrame(nothing_rows_list)
            nothing_rows_df.to_csv(nothing_rows_csv_path, index=False, encoding='utf-8-sig')
            logging.info(f"Written {len(nothing_rows_df)} rows to '{nothing_rows_csv_path}'.")
        except Exception as e:
            logging.error(f"Error writing to '{nothing_rows_csv_path}': {e}")
    else:
        logging.info("No rows matched the warning criteria. Nothing_rows.csv not created.")

    # Close the database connection
    connection.close()
    logging.info("Database connection closed.")

if __name__ == "__main__":
    main()
