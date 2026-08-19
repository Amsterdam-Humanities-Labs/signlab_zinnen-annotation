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

def analyze_table_structure():
    """Analyze current table structure and backup table"""
    conn = None
    cursor = None
    try:
        conn = mysql.connector.connect(**db_config)
        cursor = conn.cursor()
        
        print("=== ANALYZING TABLE STRUCTURES ===")
        
        # Check current sentences table structure
        cursor.execute("DESCRIBE sentences")
        current_structure = cursor.fetchall()
        print("\nCurrent sentences table structure:")
        for col in current_structure:
            print(f"  {col[0]} | {col[1]} | {col[2]} | {col[3]} | {col[4]} | {col[5]}")
        
        # Check if ID column exists
        has_id = any(col[0] == 'ID' for col in current_structure)
        print(f"\nHas ID column: {has_id}")
        
        # Check backup table structure
        backup_table = "sentences_backup_20250728_113520"
        cursor.execute(f"DESCRIBE {backup_table}")
        backup_structure = cursor.fetchall()
        print(f"\nBackup table ({backup_table}) structure:")
        for col in backup_structure:
            print(f"  {col[0]} | {col[1]} | {col[2]} | {col[3]} | {col[4]} | {col[5]}")
        
        # Count records
        cursor.execute("SELECT COUNT(*) FROM sentences")
        current_count = cursor.fetchone()[0]
        
        cursor.execute(f"SELECT COUNT(*) FROM {backup_table}")
        backup_count = cursor.fetchone()[0]
        
        print(f"\nRecord counts:")
        print(f"  Current sentences table: {current_count}")
        print(f"  Backup table: {backup_count}")
        
        return has_id, current_count, backup_count, backup_table
        
    except mysql.connector.Error as err:
        print(f"Database Error: {err}")
        return False, 0, 0, None
    except Exception as e:
        print(f"Unexpected error: {e}")
        return False, 0, 0, None
    finally:
        if cursor:
            cursor.close()
        if conn and conn.is_connected():
            conn.close()

def create_backup_before_fixing():
    """Create backup before making structural changes"""
    conn = None
    cursor = None
    try:
        conn = mysql.connector.connect(**db_config)
        cursor = conn.cursor()
        
        timestamp = datetime.now().strftime('%Y%m%d_%H%M%S')
        backup_table_name = f"sentences_backup_before_id_fix_{timestamp}"
        
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

def fix_table_structure_and_restore_ids():
    """Fix table structure and restore IDs"""
    conn = None
    cursor = None
    try:
        conn = mysql.connector.connect(**db_config)
        cursor = conn.cursor()
        
        print("\n=== FIXING TABLE STRUCTURE ===")
        
        backup_table = "sentences_backup_20250728_113520"
        
        # Step 1: Add ID column as auto-increment primary key
        print("1. Adding ID column as auto-increment primary key...")
        cursor.execute("ALTER TABLE sentences ADD COLUMN ID INT AUTO_INCREMENT PRIMARY KEY FIRST")
        conn.commit()
        print("✓ ID column added")
        
        # Step 2: Get current data to separate original vs new
        cursor.execute("SELECT * FROM sentences ORDER BY ID")
        current_data = cursor.fetchall()
        
        # Get column names
        cursor.execute("DESCRIBE sentences")
        columns = [col[0] for col in cursor.fetchall()]
        
        print(f"Current sentences table has {len(current_data)} records")
        
        # Step 3: Identify which records came from backup (should restore original IDs)
        # and which are new (should keep new auto-increment IDs)
        
        # Get all zinString values from backup with their original IDs
        cursor.execute(f"SELECT ID, zinString FROM {backup_table}")
        backup_zinstrings = {row[1]: row[0] for row in cursor.fetchall()}
        
        print(f"Backup table has {len(backup_zinstrings)} unique zinString values")
        
        # Step 4: Create temporary table with correct structure
        temp_table = "sentences_temp_id_fix"
        cursor.execute(f"DROP TABLE IF EXISTS {temp_table}")
        
        # Create temp table with same structure as backup
        cursor.execute(f"CREATE TABLE {temp_table} AS SELECT * FROM {backup_table} WHERE 1=0")
        conn.commit()
        
        # Step 5: Process records and assign correct IDs
        original_restored = 0
        new_records = 0
        max_original_id = max(backup_zinstrings.values()) if backup_zinstrings else 0
        next_new_id = max_original_id + 1
        
        for record in current_data:
            record_dict = dict(zip(columns, record))
            zin_string = record_dict.get('zinString', '')
            
            if zin_string in backup_zinstrings:
                # This is an original record - restore original ID
                original_id = backup_zinstrings[zin_string]
                insert_query = f"""
                INSERT INTO {temp_table} (ID, zinID, glosArray, zinArray, sortArray, thema, userId, zinString, label)
                VALUES (%s, %s, %s, %s, %s, %s, %s, %s, %s)
                """
                cursor.execute(insert_query, (
                    original_id,
                    record_dict.get('zinID'),
                    record_dict.get('glosArray'),
                    record_dict.get('zinArray'),
                    record_dict.get('sortArray'),
                    record_dict.get('thema'),
                    record_dict.get('userId'),
                    record_dict.get('zinString'),
                    record_dict.get('label')
                ))
                original_restored += 1
            else:
                # This is a new record - assign new auto-increment ID
                insert_query = f"""
                INSERT INTO {temp_table} (ID, zinID, glosArray, zinArray, sortArray, thema, userId, zinString, label)
                VALUES (%s, %s, %s, %s, %s, %s, %s, %s, %s)
                """
                cursor.execute(insert_query, (
                    next_new_id,
                    record_dict.get('zinID'),
                    record_dict.get('glosArray'),
                    record_dict.get('zinArray'),
                    record_dict.get('sortArray'),
                    record_dict.get('thema'),
                    record_dict.get('userId'),
                    record_dict.get('zinString'),
                    record_dict.get('label')
                ))
                next_new_id += 1
                new_records += 1
        
        conn.commit()
        
        print(f"✓ Processed records:")
        print(f"  - Original records restored: {original_restored}")
        print(f"  - New records with new IDs: {new_records}")
        
        # Step 6: Replace sentences table with temp table
        print("2. Replacing sentences table...")
        cursor.execute("DROP TABLE sentences")
        cursor.execute(f"RENAME TABLE {temp_table} TO sentences")
        
        # Step 7: Set auto-increment to continue from correct value
        cursor.execute(f"ALTER TABLE sentences AUTO_INCREMENT = {next_new_id}")
        conn.commit()
        
        print(f"✓ Table structure fixed, auto-increment set to {next_new_id}")
        
        return original_restored, new_records, next_new_id
        
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

def verify_fixed_structure():
    """Verify the fixed table structure"""
    conn = None
    cursor = None
    try:
        conn = mysql.connector.connect(**db_config)
        cursor = conn.cursor()
        
        print("\n=== VERIFICATION ===")
        
        # Check table structure
        cursor.execute("DESCRIBE sentences")
        structure = cursor.fetchall()
        print("\nFixed sentences table structure:")
        for col in structure:
            print(f"  {col[0]} | {col[1]} | {col[2]} | {col[3]} | {col[4]} | {col[5]}")
        
        # Check auto-increment value
        cursor.execute("SHOW CREATE TABLE sentences")
        create_table = cursor.fetchone()[1]
        auto_increment_pos = create_table.find('AUTO_INCREMENT=')
        if auto_increment_pos != -1:
            auto_increment_value = create_table[auto_increment_pos:].split()[0].split('=')[1]
            print(f"\nAuto-increment value: {auto_increment_value}")
        
        # Count records
        cursor.execute("SELECT COUNT(*) FROM sentences")
        total_count = cursor.fetchone()[0]
        
        cursor.execute("SELECT COUNT(*) FROM sentences WHERE userid = '27'")
        userid27_count = cursor.fetchone()[0]
        
        cursor.execute("SELECT COUNT(*) FROM sentences WHERE label = 'ZNN'")
        znn_count = cursor.fetchone()[0]
        
        # Check ID range
        cursor.execute("SELECT MIN(ID), MAX(ID) FROM sentences")
        min_id, max_id = cursor.fetchone()
        
        print(f"\nRecord counts:")
        print(f"  Total sentences: {total_count}")
        print(f"  Userid=27: {userid27_count}")
        print(f"  Label=ZNN: {znn_count}")
        print(f"  ID range: {min_id} - {max_id}")
        
        # Sample some records
        cursor.execute("SELECT ID, zinString, userid, label FROM sentences ORDER BY ID LIMIT 5")
        sample_start = cursor.fetchall()
        
        cursor.execute("SELECT ID, zinString, userid, label FROM sentences ORDER BY ID DESC LIMIT 5")
        sample_end = cursor.fetchall()
        
        print(f"\nFirst 5 records:")
        for record in sample_start:
            print(f"  ID {record[0]}: {record[1][:50]}... (userid: {record[2]}, label: {record[3]})")
        
        print(f"\nLast 5 records:")
        for record in sample_end:
            print(f"  ID {record[0]}: {record[1][:50]}... (userid: {record[2]}, label: {record[3]})")
        
        return total_count, userid27_count, znn_count, min_id, max_id
        
    except mysql.connector.Error as err:
        print(f"Database Error during verification: {err}")
        return 0, 0, 0, 0, 0
    except Exception as e:
        print(f"Unexpected error during verification: {e}")
        return 0, 0, 0, 0, 0
    finally:
        if cursor:
            cursor.close()
        if conn and conn.is_connected():
            conn.close()

def main():
    """Main function"""
    print("=" * 70)
    print("FIX SENTENCES TABLE ID STRUCTURE")
    print("=" * 70)
    
    # Step 1: Analyze current structure
    has_id, current_count, backup_count, backup_table = analyze_table_structure()
    
    if has_id:
        print("✓ ID column already exists. No fix needed.")
        return
    
    # Step 2: Create backup before fixing
    print(f"\n=== CREATING BACKUP BEFORE FIXING ===")
    backup_name = create_backup_before_fixing()
    if not backup_name:
        print("❌ Backup failed. Aborting fix.")
        sys.exit(1)
    
    # Step 3: Fix structure and restore IDs
    original_restored, new_records, next_id = fix_table_structure_and_restore_ids()
    
    if original_restored == 0 and new_records == 0:
        print("❌ Fix failed.")
        sys.exit(1)
    
    # Step 4: Verify results
    total_count, userid27_count, znn_count, min_id, max_id = verify_fixed_structure()
    
    # Final summary
    print(f"\n" + "=" * 70)
    print("FIX SUMMARY")
    print("=" * 70)
    print(f"Backup created: {backup_name}")
    print(f"Original records restored: {original_restored}")
    print(f"New records with new IDs: {new_records}")
    print(f"Total records after fix: {total_count}")
    print(f"Auto-increment continues from: {next_id}")
    print(f"ID range: {min_id} - {max_id}")
    print("=" * 70)

if __name__ == "__main__":
    main()