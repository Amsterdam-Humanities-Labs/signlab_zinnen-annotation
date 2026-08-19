from db_credentials import DB_PASSWORD
import mysql.connector
import pandas as pd
import json

# Database connection details
db_config = {
    'user': 'user',
    'password': DB_PASSWORD,
    'host': 'signlab-db',
    'database': 'admin_gebarenoverleg'
}

def convert(zinArray):
    try:
        data = json.loads(zinArray)
        if isinstance(data, list):
            return " ".join([str(item) for item in data])
        else:
            return str(data)
    except Exception:
        return str(zinArray)

def detect_duplicates():
    # Function to detect duplicate zinString entries in the database
    conn = mysql.connector.connect(charset='utf8mb4', use_unicode=True, **db_config)
    cursor = conn.cursor(dictionary=True)
    
    query = """
    SELECT zinString, COUNT(*) as count
    FROM sentences
    WHERE zinString IS NOT NULL
    GROUP BY zinString
    HAVING count > 1
    """
    cursor.execute(query)
    duplicates = cursor.fetchall()
    
    cursor.close()
    conn.close()
    return duplicates

def match_duplicate_transcriptions():
    # For each duplicate zinString, get sentence IDs and count matching rows in m_transcriptions table
    conn_admin = mysql.connector.connect(charset='utf8mb4', use_unicode=True, **db_config)
    cursor_admin = conn_admin.cursor(dictionary=True)
    
    query = """
    SELECT zinString, COUNT(*) as count
    FROM sentences
    WHERE zinString IS NOT NULL
    GROUP BY zinString
    HAVING count > 1
    """
    cursor_admin.execute(query)
    duplicates = cursor_admin.fetchall()
    
    for dup in duplicates:
        zin_str = dup['zinString']
        print("Duplicate zinString:", zin_str, "Count:", dup['count'])
        # Get associated sentence IDs for the duplicate zinString
        cursor_admin.execute("SELECT id FROM sentences WHERE zinString = %s", (zin_str,))
        sentence_rows = cursor_admin.fetchall()
        for row in sentence_rows:
            sentence_id = row['id']
            # Query the matched_transcriptions table using the same connection
            cursor_trans = conn_admin.cursor(dictionary=True)
            cursor_trans.execute(
                "SELECT COUNT(*) as match_count FROM matched_transcriptions WHERE m_transcription = %s AND (zOg = 'Zin' OR zOg = 'zin')", 
                (sentence_id,)
            )
            match_result = cursor_trans.fetchone()
            print("Sentence ID:", sentence_id, "matched count:", match_result['match_count'])

            # if count is 0, then remove the row from the sentences table associated with id
            if match_result['match_count'] == 0:
                print("Removing sentence ID:", sentence_id)
                cursor_admin.execute("DELETE FROM sentences WHERE id = %s", (sentence_id,))
                conn_admin.commit()

            cursor_trans.close()
    
    cursor_admin.close()
    conn_admin.close()

if __name__ == '__main__':
    # Create database connection with utf8mb4 charset
    conn = mysql.connector.connect(charset='utf8mb4', use_unicode=True, **db_config)
    
    cursor = conn.cursor(dictionary=True)
    
    # cursor.execute("SELECT id, zinArray FROM sentences WHERE zinArray IS NOT NULL")
    # for row in cursor.fetchall():
        # print(row['id'])
        # print(row['zinArray'])
        # new_value = convert(row['zinArray'])
        # cursor.execute("UPDATE sentences SET zinString=%s WHERE id=%s", (new_value, row['id']))
    
    # Optionally, detect and print duplicate zinString values
    duplicates = detect_duplicates()
    if duplicates:
        print("Duplicate zinString entries found:")
        for dup in duplicates:
            print(dup)
    
    # Match duplicates with corresponding m_transcription rows and output match counts
    print("\nMatching duplicates with m_transcription rows:")
    match_duplicate_transcriptions()
    
    conn.commit()
    cursor.close()
    conn.close()