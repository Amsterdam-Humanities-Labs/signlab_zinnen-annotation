from db_credentials import DB_PASSWORD
\
import mysql.connector
import csv

# Database configuration
db_config = {
    'user': 'user',
    'password': DB_PASSWORD,
    'host': 'localhost',  # Assuming localhost, change if different from setZinToThema.py
    'database': 'admin_gebarenoverleg'
}

# Output CSV file name
csv_file_name = 'lorraine_sentences.csv'

try:
    # Connect to the database
    conn = mysql.connector.connect(**db_config)
    cursor = conn.cursor()

    # SQL query to select sentences with thema 'LORRAINE'
    query_select_sentences = "SELECT zinString FROM sentences WHERE thema = %s"
    thema_value = 'LORRAINE'
    
    cursor.execute(query_select_sentences, (thema_value,))
    
    rows = cursor.fetchall()

    if rows:
        print(f"Found {len(rows)} sentences with thema '{thema_value}'. Exporting to {csv_file_name}...")
        
        # Get column headers
        column_headers = [i[0] for i in cursor.description]
        
        # Write to CSV file
        with open(csv_file_name, 'w', newline='', encoding='utf-8') as csvfile:
            csvwriter = csv.writer(csvfile)
            
            # Write headers
            csvwriter.writerow(column_headers)
            
            # Write data rows
            csvwriter.writerows(rows)
            
        print(f"Successfully exported data to {csv_file_name}")
        
    else:
        print(f"No sentences found with thema '{thema_value}'.")

except mysql.connector.Error as err:
    print(f"Error: {err}")

finally:
    if 'conn' in locals() and conn.is_connected():
        cursor.close()
        conn.close()
        print("MySQL connection is closed.")
