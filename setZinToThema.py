from db_credentials import DB_PASSWORD
import mysql.connector

# filepath: /web/zin/setZinToThema.py

db_config = {
    'user': 'user',
    'password': DB_PASSWORD,
    'host': 'localhost',
    'database': 'admin_gebarenoverleg'
}

zin = "zin"  # Replace "your_zin_value" with the actual value for zin

try:
    # Connect to the database
    conn = mysql.connector.connect(**db_config)
    cursor = conn.cursor()

    # Find m_transcription in matched_transcriptions table
    query_matched_transcriptions = "SELECT m_transcription FROM matched_transcriptions WHERE zOg = %s AND added='0'"
    cursor.execute(query_matched_transcriptions, (zin,))
    
    m_transcriptions_initial = [item[0] for item in cursor.fetchall()]

    # Filter m_transcriptions
    m_transcriptions_filtered = []
    if m_transcriptions_initial:
        for mt in m_transcriptions_initial:
            query_check_added = "SELECT COUNT(*) FROM matched_transcriptions WHERE zOg = %s AND m_transcription = %s AND added = '1'"
            cursor.execute(query_check_added, (zin, mt))
            count_added_1 = cursor.fetchone()[0]
            if count_added_1 == 0:
                m_transcriptions_filtered.append(mt)
    
    m_transcriptions = m_transcriptions_filtered

    if m_transcriptions:
        # Update thema in sentences table
        # Using a placeholder for a list of values requires generating a string of %s placeholders
        placeholders = ', '.join(['%s'] * len(m_transcriptions))
        query_sentences = f"UPDATE sentences SET thema = 'LORRAINE', userid='1' WHERE id IN ({placeholders})"
        
        cursor.execute(query_sentences, m_transcriptions)
        conn.commit()
        print(f"{cursor.rowcount} rows updated in sentences table.")
    else:
        print(f"No matching m_transcription found for zin: {zin}")

except mysql.connector.Error as err:
    print(f"Error: {err}")

finally:
    if 'conn' in locals() and conn.is_connected():
        cursor.close()
        conn.close()
        print("MySQL connection is closed.")
