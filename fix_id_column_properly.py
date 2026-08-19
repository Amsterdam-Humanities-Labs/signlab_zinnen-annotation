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
        backup_table_name = f"sentences_backup_before_proper_id_fix_{timestamp}"
        
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

def fix_id_column_properly():
    """Fix ID column to be auto-increment primary key with proper values"""
    conn = None
    cursor = None
    try:
        conn = mysql.connector.connect(**db_config)
        cursor = conn.cursor()
        
        print("\n=== FIXING ID COLUMN ===")
        
        backup_table = "sentences_backup_20250728_113520"
        
        # Step 1: Create temporary table with proper structure
        temp_table = "sentences_temp_proper_fix"
        cursor.execute(f"DROP TABLE IF EXISTS {temp_table}")
        
        # Create temp table with proper ID structure
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
        cursor.execute("SELECT * FROM sentences ORDER BY ID, zinString")
        current_records = cursor.fetchall()
        
        # Get column names (excluding ID since we'll handle that separately)
        cursor.execute("DESCRIBE sentences")
        all_columns = [col[0] for col in cursor.fetchall()]
        data_columns = [col for col in all_columns if col != 'ID']
        
        print(f"Found {len(current_records)} records to process")
        
        # Step 3: Get backup data for ID restoration
        cursor.execute(f"SELECT ID, zinString FROM {backup_table}")
        backup_zinstrings = {row[1]: row[0] for row in cursor.fetchall()}
        print(f"Backup contains {len(backup_zinstrings)} unique zinString mappings")
        
        # Step 4: Process records and insert with correct IDs
        original_restored = 0
        new_records_added = 0
        max_original_id = max(backup_zinstrings.values()) if backup_zinstrings else 0
        
        # First, insert original records with their original IDs
        original_records = []
        new_records = []
        
        for record in current_records:
            record_dict = dict(zip(all_columns, record))
            zin_string = record_dict.get('zinString', '')
            
            if zin_string in backup_zinstrings and record_dict['ID'] != 0:
                # This is an original record with a proper ID
                original_records.append((backup_zinstrings[zin_string], record_dict))
            elif zin_string in backup_zinstrings and record_dict['ID'] == 0:
                # This is an original record but got ID=0, restore original ID
                original_records.append((backup_zinstrings[zin_string], record_dict))
            else:
                # This is a new record (not in backup)
                new_records.append(record_dict)
        
        print(f"Categorized records:")
        print(f"  - Original records to restore: {len(original_records)}")
        print(f"  - New records to add: {len(new_records)}")
        
        # Insert original records with specific IDs
        for original_id, record_dict in original_records:
            values = [record_dict.get(col) for col in data_columns]
            placeholders = ', '.join(['%s'] * len(data_columns))
            columns_str = ', '.join(data_columns)
            
            insert_query = f"""
            INSERT INTO {temp_table} (ID, {columns_str})
            VALUES (%s, {placeholders})
            """
            cursor.execute(insert_query, [original_id] + values)
            original_restored += 1
        
        conn.commit()
        print(f"✓ Restored {original_restored} original records with their IDs")
        
        # Insert new records (auto-increment will assign new IDs)
        for record_dict in new_records:
            values = [record_dict.get(col) for col in data_columns]
            placeholders = ', '.join(['%s'] * len(data_columns))
            columns_str = ', '.join(data_columns)
            
            insert_query = f"""
            INSERT INTO {temp_table} ({columns_str})
            VALUES ({placeholders})
            """
            cursor.execute(insert_query, values)
            new_records_added += 1
        
        conn.commit()
        print(f"✓ Added {new_records_added} new records with auto-increment IDs")
        
        # Step 5: Replace original table with fixed table
        cursor.execute("DROP TABLE sentences")
        cursor.execute(f"RENAME TABLE {temp_table} TO sentences")
        conn.commit()
        print("✓ Replaced original table with fixed table")
        
        # Step 6: Set auto-increment to continue from the right value
        cursor.execute("SELECT MAX(ID) FROM sentences")
        max_id = cursor.fetchone()[0]
        next_auto_increment = max_id + 1
        
        cursor.execute(f"ALTER TABLE sentences AUTO_INCREMENT = {next_auto_increment}")
        conn.commit()
        print(f"✓ Set auto-increment to {next_auto_increment}")
        
        return original_restored, new_records_added, next_auto_increment
        
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
        print(f"  Auto-increment: {has_auto_increment} ✓" if has_auto_increment else f"  Auto-increment: {has_auto_increment} ❌")
        print(f"  Primary key: {has_primary_key} ✓" if has_primary_key else f"  Primary key: {has_primary_key} ❌")
        
        # Check record counts
        cursor.execute("SELECT COUNT(*) FROM sentences")
        total_count = cursor.fetchone()[0]
        
        cursor.execute("SELECT COUNT(*) FROM sentences WHERE userid = '27'")
        userid27_count = cursor.fetchone()[0]
        
        cursor.execute("SELECT COUNT(*) FROM sentences WHERE label = 'ZNN'")
        znn_count = cursor.fetchone()[0]
        
        # Check ID statistics
        cursor.execute("SELECT MIN(ID), MAX(ID) FROM sentences")
        min_id, max_id = cursor.fetchone()
        
        # Check for duplicates
        cursor.execute("SELECT COUNT(*) FROM (SELECT ID, COUNT(*) FROM sentences GROUP BY ID HAVING COUNT(*) > 1) AS dups")
        duplicate_count = cursor.fetchone()[0]
        
        print(f"\nRecord statistics:")
        print(f"  Total records: {total_count}")
        print(f"  Userid=27: {userid27_count}")
        print(f"  Label=ZNN: {znn_count}")
        print(f"  ID range: {min_id} - {max_id}")
        print(f"  Duplicate IDs: {duplicate_count} ✓" if duplicate_count == 0 else f"  Duplicate IDs: {duplicate_count} ❌")
        
        # Sample records
        cursor.execute("SELECT ID, zinString, userid, label FROM sentences ORDER BY ID LIMIT 3")
        first_records = cursor.fetchall()
        
        cursor.execute("SELECT ID, zinString, userid, label FROM sentences ORDER BY ID DESC LIMIT 3")
        last_records = cursor.fetchall()
        
        print(f"\nFirst 3 records:")
        for record in first_records:
            print(f"  ID {record[0]}: {record[1][:50]}... (userid: {record[2]}, label: {record[3]})")
        
        print(f"\nLast 3 records:")
        for record in last_records:
            print(f"  ID {record[0]}: {record[1][:50]}... (userid: {record[2]}, label: {record[3]})")
        
        return total_count, userid27_count, znn_count, min_id, max_id, duplicate_count
        
    except mysql.connector.Error as err:
        print(f"Database Error during verification: {err}")
        return 0, 0, 0, 0, 0, 1
    except Exception as e:
        print(f"Unexpected error during verification: {e}")
        return 0, 0, 0, 0, 0, 1
    finally:
        if cursor:
            cursor.close()
        if conn and conn.is_connected():
            conn.close()

def main():
    """Main function"""
    print("=" * 70)
    print("PROPERLY FIX ID COLUMN STRUCTURE")
    print("=" * 70)
    
    # Step 1: Create backup
    print("1. Creating backup before fixing...")
    backup_name = create_backup_before_fixing()
    if not backup_name:
        print("❌ Backup failed. Aborting fix.")
        sys.exit(1)
    
    # Step 2: Fix ID column
    print("2. Fixing ID column structure...")
    original_restored, new_added, next_auto = fix_id_column_properly()
    
    if original_restored == 0 and new_added == 0:
        print("❌ Fix failed.")
        sys.exit(1)
    
    # Step 3: Verify results
    print("3. Verifying fixed table...")
    total, userid27, znn, min_id, max_id, duplicates = verify_fixed_table()
    
    # Final summary
    success = duplicates == 0
    
    print(f"\n" + "=" * 70)
    print("FIX SUMMARY")
    print("=" * 70)
    print(f"Backup created: {backup_name}")
    print(f"Original records restored: {original_restored}")
    print(f"New records added: {new_added}")
    print(f"Total records: {total}")
    print(f"Auto-increment continues from: {next_auto}")
    print(f"ID range: {min_id} - {max_id}")
    print(f"Status: {'SUCCESS ✓' if success else 'FAILED ❌'}")
    print("=" * 70)

if __name__ == "__main__":
    main()