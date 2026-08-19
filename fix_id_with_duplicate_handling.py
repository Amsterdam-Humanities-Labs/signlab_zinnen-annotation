#!/usr/bin/env python3
from db_credentials import DB_PASSWORD
import mysql.connector
import sys
from datetime import datetime

# Database configuration
db_config = {
    'user': 'user',
    'password': DB_PASSWORD,
    'host': 'localhost',
    'database': 'admin_gebarenoverleg'
}

def create_backup_before_fixing():
    """Create backup before making structural changes"""
    conn = None
    cursor = None
    try:
        conn = mysql.connector.connect(**db_config)
        cursor = conn.cursor()
        
        timestamp = datetime.now().strftime('%Y%m%d_%H%M%S')
        backup_table_name = f"sentences_backup_before_duplicate_fix_{timestamp}"
        
        print(f"Creating backup: {backup_table_name}")
        cursor.execute(f"CREATE TABLE {backup_table_name} AS SELECT * FROM sentences")
        conn.commit()
        
        cursor.execute(f"SELECT COUNT(*) FROM {backup_table_name}")
        backup_count = cursor.fetchone()[0]
        print(f"✓ Backup created with {backup_count} records")
        
        return backup_table_name
        
    except mysql.connector.Error as err:
        print(f"Database Error during backup: {err}")
        return None
    except Exception as e:
        print(f"Unexpected error during backup: {e}")
        return None
    finally:
        if cursor:
            cursor.close()
        if conn and conn.is_connected():
            conn.close()

def fix_id_with_duplicate_handling():
    """Fix ID column handling duplicates properly"""
    conn = None
    cursor = None
    try:
        conn = mysql.connector.connect(**db_config)
        cursor = conn.cursor()
        
        print("\n=== FIXING ID COLUMN WITH DUPLICATE HANDLING ===")
        
        backup_table = "sentences_backup_20250728_113520"
        
        # Step 1: Create temporary table with proper structure
        temp_table = "sentences_temp_dup_fix"
        cursor.execute(f"DROP TABLE IF EXISTS {temp_table}")
        
        cursor.execute(f"""
        CREATE TABLE {temp_table} (
            ID INT AUTO_INCREMENT PRIMARY KEY,
            zinID INT DEFAULT NULL,
            glosArray JSON DEFAULT NULL,
            enabled TINYINT(1) DEFAULT NULL,
            zinArray JSON DEFAULT NULL,
            thema VARCHAR(255) DEFAULT NULL,
            sortArray JSON DEFAULT NULL,
            userId VARCHAR(255) DEFAULT NULL,
            comments TEXT DEFAULT NULL,
            status VARCHAR(255) DEFAULT NULL,
            status_annotatie VARCHAR(255) DEFAULT NULL,
            status_glos VARCHAR(255) DEFAULT NULL,
            status_video VARCHAR(255) DEFAULT NULL,
            zinString TEXT DEFAULT NULL,
            glosses TEXT DEFAULT NULL,
            lemmaList TEXT DEFAULT NULL,
            search_lemma VARCHAR(255) DEFAULT NULL,
            gvg VARCHAR(255) DEFAULT NULL,
            label VARCHAR(255) DEFAULT NULL,
            ai_Occurences TEXT DEFAULT NULL,
            lemma_processed TINYINT(1) DEFAULT 0,
            lemma_processed_at TIMESTAMP NULL DEFAULT NULL,
            lemma_error TEXT DEFAULT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
        """)
        conn.commit()
        print("✓ Created temporary table with proper structure")
        
        # Step 2: Get all records from current table
        cursor.execute("SELECT * FROM sentences")
        current_records = cursor.fetchall()
        
        # Get column names
        cursor.execute("DESCRIBE sentences")
        all_columns = [col[0] for col in cursor.fetchall()]
        data_columns = [col for col in all_columns if col != 'ID']
        
        print(f"Found {len(current_records)} records to process")
        
        # Step 3: Get backup data for reference
        cursor.execute(f"SELECT ID, zinString FROM {backup_table}")
        backup_data = cursor.fetchall()
        backup_zinstrings = {}
        
        # Handle multiple entries with same zinString in backup (keep first occurrence)
        for backup_id, zin_string in backup_data:
            if zin_string not in backup_zinstrings:
                backup_zinstrings[zin_string] = backup_id
        
        print(f"Backup contains {len(backup_zinstrings)} unique zinString mappings")
        
        # Step 4: Categorize records
        records_to_insert = []
        used_ids = set()
        
        # First pass: handle records with valid non-zero IDs that match backup
        for record in current_records:
            record_dict = dict(zip(all_columns, record))
            current_id = record_dict['ID']
            zin_string = record_dict.get('zinString', '')
            
            # Skip records with ID=0 for now
            if current_id == 0:
                continue
                
            # Check if this record should have this ID based on backup
            if zin_string in backup_zinstrings:
                expected_id = backup_zinstrings[zin_string]
                
                # Use expected ID if not already used
                if expected_id not in used_ids:
                    records_to_insert.append((expected_id, record_dict))
                    used_ids.add(expected_id)
                else:
                    # Expected ID already used, will assign auto-increment later
                    records_to_insert.append((None, record_dict))
            else:
                # New record not in backup, will get auto-increment
                records_to_insert.append((None, record_dict))
        
        # Second pass: handle records with ID=0 (CSV imports)
        for record in current_records:
            record_dict = dict(zip(all_columns, record))
            current_id = record_dict['ID']
            zin_string = record_dict.get('zinString', '')
            
            if current_id != 0:
                continue
                
            # Check if this is a known sentence from backup
            if zin_string in backup_zinstrings:
                expected_id = backup_zinstrings[zin_string]
                if expected_id not in used_ids:
                    records_to_insert.append((expected_id, record_dict))
                    used_ids.add(expected_id)
                else:
                    # Already used, assign auto-increment
                    records_to_insert.append((None, record_dict))
            else:
                # New record, assign auto-increment
                records_to_insert.append((None, record_dict))
        
        print(f"Prepared {len(records_to_insert)} records for insertion")
        
        # Step 5: Insert records with specific IDs first, then auto-increment
        specific_id_count = 0
        auto_increment_count = 0
        
        # Insert records with specific IDs first
        for target_id, record_dict in records_to_insert:
            if target_id is not None:
                values = [record_dict.get(col) for col in data_columns]
                placeholders = ', '.join(['%s'] * len(data_columns))
                columns_str = ', '.join(data_columns)
                
                insert_query = f"""
                INSERT INTO {temp_table} (ID, {columns_str})
                VALUES (%s, {placeholders})
                """
                try:
                    cursor.execute(insert_query, [target_id] + values)
                    specific_id_count += 1
                except mysql.connector.IntegrityError:
                    # ID already exists, will handle in auto-increment pass
                    records_to_insert.append((None, record_dict))
        
        conn.commit()
        print(f"✓ Inserted {specific_id_count} records with specific IDs")
        
        # Insert remaining records with auto-increment
        for target_id, record_dict in records_to_insert:
            if target_id is None:
                values = [record_dict.get(col) for col in data_columns]
                placeholders = ', '.join(['%s'] * len(data_columns))
                columns_str = ', '.join(data_columns)
                
                insert_query = f"""
                INSERT INTO {temp_table} ({columns_str})
                VALUES ({placeholders})
                """
                cursor.execute(insert_query, values)
                auto_increment_count += 1
        
        conn.commit()
        print(f"✓ Inserted {auto_increment_count} records with auto-increment IDs")
        
        # Step 6: Replace original table
        cursor.execute("DROP TABLE sentences")
        cursor.execute(f"RENAME TABLE {temp_table} TO sentences")
        conn.commit()
        print("✓ Replaced original table with fixed table")
        
        # Step 7: Set auto-increment value
        cursor.execute("SELECT MAX(ID) FROM sentences")
        max_id = cursor.fetchone()[0]
        next_auto_increment = max_id + 1
        
        cursor.execute(f"ALTER TABLE sentences AUTO_INCREMENT = {next_auto_increment}")
        conn.commit()
        print(f"✓ Set auto-increment to {next_auto_increment}")
        
        return specific_id_count, auto_increment_count, next_auto_increment
        
    except mysql.connector.Error as err:
        print(f"Database Error during fix: {err}")
        if conn:
            conn.rollback()
        return 0, 0, 0
    except Exception as e:
        print(f"Unexpected error during fix: {e}")
        if conn:
            conn.rollback()
        return 0, 0, 0
    finally:
        if cursor:
            cursor.close()
        if conn and conn.is_connected():
            conn.close()

def verify_fixed_table():
    """Verify the fixed table"""
    conn = None
    cursor = None
    try:
        conn = mysql.connector.connect(**db_config)
        cursor = conn.cursor()
        
        print("\n=== VERIFICATION ===")
        
        # Check table structure
        cursor.execute("SHOW CREATE TABLE sentences")
        create_statement = cursor.fetchone()[1]
        
        has_auto_increment = 'AUTO_INCREMENT' in create_statement
        has_primary_key = 'PRIMARY KEY' in create_statement and '`ID`' in create_statement
        
        print(f"Table structure:")
        print(f"  Auto-increment: {'✓' if has_auto_increment else '❌'}")
        print(f"  Primary key: {'✓' if has_primary_key else '❌'}")
        
        # Check record counts and duplicates
        cursor.execute("SELECT COUNT(*) FROM sentences")
        total_count = cursor.fetchone()[0]
        
        cursor.execute("SELECT COUNT(DISTINCT ID) FROM sentences")
        unique_ids = cursor.fetchone()[0]
        
        cursor.execute("SELECT MIN(ID), MAX(ID) FROM sentences")
        min_id, max_id = cursor.fetchone()
        
        cursor.execute("SELECT COUNT(*) FROM sentences WHERE userid = '27'")
        userid27_count = cursor.fetchone()[0]
        
        cursor.execute("SELECT COUNT(*) FROM sentences WHERE label = 'ZNN'")
        znn_count = cursor.fetchone()[0]
        
        duplicates = total_count - unique_ids
        
        print(f"\nRecord statistics:")
        print(f"  Total records: {total_count}")
        print(f"  Unique IDs: {unique_ids}")
        print(f"  Duplicate IDs: {duplicates} {'✓' if duplicates == 0 else '❌'}")
        print(f"  ID range: {min_id} - {max_id}")
        print(f"  Userid=27: {userid27_count}")
        print(f"  Label=ZNN: {znn_count}")
        
        return total_count, unique_ids, duplicates, min_id, max_id
        
    except mysql.connector.Error as err:
        print(f"Database Error during verification: {err}")
        return 0, 0, 1, 0, 0
    except Exception as e:
        print(f"Unexpected error during verification: {e}")
        return 0, 0, 1, 0, 0
    finally:
        if cursor:
            cursor.close()
        if conn and conn.is_connected():
            conn.close()

def main():
    """Main function"""
    print("=" * 70)
    print("FIX ID COLUMN WITH DUPLICATE HANDLING")
    print("=" * 70)
    
    # Step 1: Create backup
    print("1. Creating backup before fixing...")
    backup_name = create_backup_before_fixing()
    if not backup_name:
        print("❌ Backup failed. Aborting fix.")
        sys.exit(1)
    
    # Step 2: Fix ID column
    print("2. Fixing ID column with duplicate handling...")
    specific_count, auto_count, next_auto = fix_id_with_duplicate_handling()
    
    if specific_count == 0 and auto_count == 0:
        print("❌ Fix failed.")
        sys.exit(1)
    
    # Step 3: Verify results
    print("3. Verifying fixed table...")
    total, unique, duplicates, min_id, max_id = verify_fixed_table()
    
    # Final summary
    success = duplicates == 0
    
    print(f"\n" + "=" * 70)
    print("FIX SUMMARY")
    print("=" * 70)
    print(f"Backup created: {backup_name}")
    print(f"Records with specific IDs: {specific_count}")
    print(f"Records with auto-increment IDs: {auto_count}")
    print(f"Total records: {total}")
    print(f"Unique IDs: {unique}")
    print(f"Auto-increment continues from: {next_auto}")
    print(f"Status: {'SUCCESS ✓' if success else 'FAILED ❌'}")
    print("=" * 70)

if __name__ == "__main__":
    main()