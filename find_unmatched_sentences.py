#!/usr/bin/env python3
from db_credentials import DB_PASSWORD
import mysql.connector
import json
import sys

# Database configuration (same as update_matched_transcriptions.py)
db_config = {
    'user': 'user',
    'password': DB_PASSWORD,
    'host': 'localhost',
    'database': 'admin_gebarenoverleg'
}

def find_unmatched_sentences():
    """
    Find sentences that don't have matching rows in matched_transcriptions table
    Returns results as JSON with count and sentence details
    """
    conn = None
    cursor = None
    try:
        # Connect to database
        conn = mysql.connector.connect(**db_config)
        cursor = conn.cursor(dictionary=True)
        
        # Query to find sentences without matching transcriptions
        # LEFT JOIN to include all sentences, even those without matches
        # WHERE clause filters for NULL values (no matches) and zOg = 'Zin'
        query = """
        SELECT 
            s.ID,
            s.zinString,
            s.thema,
            s.userid
        FROM sentences s
        LEFT JOIN matched_transcriptions mt ON s.ID = mt.m_transcription AND mt.zOg LIKE 'zin'
        WHERE mt.m_transcription IS NULL
        ORDER BY s.ID ASC
        """
        
        cursor.execute(query)
        unmatched_sentences = cursor.fetchall()
        
        # Get total count of sentences for statistics (all users)
        cursor.execute("SELECT COUNT(*) as total FROM sentences")
        total_sentences = cursor.fetchone()['total']
        
        # Get count of matched sentences for statistics (with zOg = 'Zin' filter, all users)
        cursor.execute("""
        SELECT COUNT(DISTINCT s.ID) as matched_count 
        FROM sentences s
        INNER JOIN matched_transcriptions mt ON s.ID = mt.m_transcription AND mt.zOg LIKE 'zin'
        """)
        matched_count = cursor.fetchone()['matched_count']
        
        # Prepare results
        results = {
            "unmatched_sentences": unmatched_sentences,
            "count": len(unmatched_sentences),
            "total_sentences": total_sentences,
            "matched_sentences": matched_count,
            "summary": {
                "unmatched_percentage": round((len(unmatched_sentences) / total_sentences) * 100, 2) if total_sentences > 0 else 0,
                "matched_percentage": round((matched_count / total_sentences) * 100, 2) if total_sentences > 0 else 0
            }
        }
        
        return results
        
    except mysql.connector.Error as err:
        return {
            "error": f"Database Error: {err}",
            "success": False
        }
    except Exception as e:
        return {
            "error": f"An unexpected error occurred: {e}",
            "success": False
        }
    finally:
        if cursor:
            cursor.close()
        if conn and conn.is_connected():
            conn.close()

def main():
    """Main function to execute the query and output results"""
    results = find_unmatched_sentences()
    
    # Output as JSON
    print(json.dumps(results, indent=2, ensure_ascii=False))
    
    # If there's an error, exit with error code
    if 'error' in results:
        sys.exit(1)

if __name__ == "__main__":
    main()