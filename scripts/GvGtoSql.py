import json
import os
import re
import mysql.connector
import srt  # new import for srt parsing
import sys
sys.path.insert(0, '/home/gomer/pythonCron')
from python_client import ClientMonitor

# Initialize Client Monitor
monitor = ClientMonitor(
    api_url="https://signcollect.nl/client_monitor_api/api.php",
    client_id="update-gvg-sentences",
    client_name="Update GvG in Sentences",
    description="Updates gvg field in sentences table from SRT files",
    heartbeat_interval=86400  # 1440 minutes (24 hours)
)

def _read_env(path):
    """KEY=VALUE lines from the estate's env file (the one signcollect-lib reads)."""
    env = {}
    try:
        with open(path) as f:
            for line in f:
                line = line.strip()
                if line and not line.startswith('#') and '=' in line:
                    k, v = line.split('=', 1)
                    env[k.strip()] = v.strip().strip('"').strip("'")
    except OSError:
        pass
    return env

# Credentials come from the env file, never from this file.
_env = _read_env(os.environ.get('SC_ENV_FILE') or
                 os.path.join(os.environ.get('SC_WEB_ROOT', '/web'), '.env'))
db_config = {
    'host': _env.get('DB_HOST', 'localhost'),
    'user': _env.get('DB_USER', ''),
    'password': _env.get('DB_PASS', ''),
    'database': _env.get('DB_NAME', ''),
}

# Establish database connection
def get_db_connection():
    try:
        connection = mysql.connector.connect(**db_config)
        if connection.is_connected():
            print("Successfully connected to the database.")
            return connection
    except mysql.connector.Error as err:
        print(f"Error connecting to MySQL: {err}")
    return None

def process_srt_files():
    import re
    db_connection = get_db_connection()
    if db_connection is None:
        return 0
    cursor = db_connection.cursor()
    directory = '/web/zin/eaf/zin'
    updates_count = 0
    for filename in os.listdir(directory):
        if "Gebaar-voor-gebaar" in filename and "backup" not in filename and filename.endswith(".srt"):
            prefix_match = re.match(r'^(M\d+_\d+)_', filename)
            if not prefix_match:
                continue
            prefix = prefix_match.group(1)
            filepath = os.path.join(directory, filename)
            with open(filepath, encoding='utf-8') as f:
                file_content = f.read()
            subtitles = list(srt.parse(file_content))
            glosses_list = []
            for sub in subtitles:
                text = sub.content.strip()
                if text.startswith('?'):
                    text = text[1:]
                glosses_list.append(text)
            json_glosses = json.dumps(glosses_list)

            # Query matched_transcriptions table for m_transcription based on prefix
            query = "SELECT m_transcription FROM matched_transcriptions WHERE m_file LIKE %s"
            cursor.execute(query, (f"%{prefix}%",))
            result = cursor.fetchone()
            if result:
                m_transcription = result[0]
                # Extract numeric ID from m_transcription string (e.g., '8873_2' -> 8873)
                numeric_id_match = re.match(r'^(\d+)', str(m_transcription))
                if numeric_id_match:
                    numeric_id = int(numeric_id_match.group(1))
                    try:
                        # Update sentences table glosses field with JSON from srt subtitles
                        update_query = "UPDATE sentences SET gvg = %s WHERE id = %s"
                        cursor.execute(update_query, (json_glosses, numeric_id))
                        db_connection.commit()
                        print(f"Updated glosses for transcription id {numeric_id} (from {m_transcription}) from file {filename}")
                        updates_count += 1
                    except mysql.connector.Error as db_err:
                        print(f"Database error updating transcription id {numeric_id}: {db_err}")
                        db_connection.rollback()
                else:
                    print(f"Could not extract numeric ID from m_transcription: {m_transcription}")
                    continue
            else:
                print(f"No matching transcription found for file {filename}")
    cursor.close()
    db_connection.close()
    return updates_count

if __name__ == '__main__':
    try:
        updates_count = process_srt_files()

        # Send success heartbeat with update stats
        monitor.send_heartbeat_with_stats(
            status="success",
            message=f"GvG update completed. Updates: {updates_count}",
            stats={
                "updates_count": updates_count
            }
        )

    except Exception as e:
        # Send error heartbeat
        monitor.send_heartbeat_with_stats(
            status="error",
            message=f"GvG update failed: {str(e)}",
            stats={"error_type": type(e).__name__}
        )
        raise  # Re-raise to maintain existing error behavior