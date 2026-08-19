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

def create_backup_before_status_update():
    """Create backup before updating status fields"""
    conn = None
    cursor = None
    try:
        conn = mysql.connector.connect(**db_config)
        cursor = conn.cursor()
        
        timestamp = datetime.now().strftime('%Y%m%d_%H%M%S')
        backup_table_name = f"sentences_backup_before_status_update_{timestamp}"
        
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

def analyze_current_status_fields():
    """Analyze current status of the status fields"""
    conn = None
    cursor = None
    try:
        conn = mysql.connector.connect(**db_config)
        cursor = conn.cursor()
        
        print("\n=== ANALYZING CURRENT STATUS FIELDS ===")
        
        status_fields = ['status', 'status_annotatie', 'status_glos', 'status_video']
        
        for field in status_fields:
            # Count NULL values
            cursor.execute(f"SELECT COUNT(*) FROM sentences WHERE {field} IS NULL")
            null_count = cursor.fetchone()[0]
            
            # Count non-NULL values
            cursor.execute(f"SELECT COUNT(*) FROM sentences WHERE {field} IS NOT NULL")
            non_null_count = cursor.fetchone()[0]
            
            # Get distinct non-NULL values
            cursor.execute(f"SELECT DISTINCT {field}, COUNT(*) FROM sentences WHERE {field} IS NOT NULL GROUP BY {field}")
            distinct_values = cursor.fetchall()
            
            print(f"\n{field}:")
            print(f"  NULL values: {null_count}")
            print(f"  Non-NULL values: {non_null_count}")
            if distinct_values:
                print(f"  Distinct values:")
                for value, count in distinct_values:
                    print(f"    '{value}': {count}")
        
        return True
        
    except mysql.connector.Error as err:
        print(f"Database Error during analysis: {err}")
        return False
    except Exception as e:
        print(f"Unexpected error during analysis: {e}")
        return False
    finally:
        if cursor:
            cursor.close()
        if conn and conn.is_connected():
            conn.close()

def update_null_status_fields():
    """Update NULL status fields to 'Niet Klaar'"""
    conn = None
    cursor = None
    try:
        conn = mysql.connector.connect(**db_config)
        cursor = conn.cursor()
        
        print("\n=== UPDATING NULL STATUS FIELDS ===")
        
        status_fields = ['status', 'status_annotatie', 'status_glos', 'status_video']
        total_updated = 0
        
        for field in status_fields:
            # Count current NULL values
            cursor.execute(f"SELECT COUNT(*) FROM sentences WHERE {field} IS NULL")
            null_count = cursor.fetchone()[0]
            
            if null_count > 0:
                # Update NULL values to 'Niet Klaar'
                update_query = f"UPDATE sentences SET {field} = 'Niet Klaar' WHERE {field} IS NULL"
                cursor.execute(update_query)
                updated_count = cursor.rowcount
                total_updated += updated_count
                
                print(f"✓ Updated {updated_count} NULL values in {field} to 'Niet Klaar'")
            else:
                print(f"✓ No NULL values found in {field}")
        
        conn.commit()
        print(f"\nTotal records updated: {total_updated}")
        
        return total_updated
        
    except mysql.connector.Error as err:
        print(f"Database Error during update: {err}")
        if conn:
            conn.rollback()
        return 0
    except Exception as e:
        print(f"Unexpected error during update: {e}")
        if conn:
            conn.rollback()
        return 0
    finally:
        if cursor:
            cursor.close()
        if conn and conn.is_connected():
            conn.close()

def set_default_values_for_status_columns():
    """Set default values for status columns"""
    conn = None
    cursor = None
    try:
        conn = mysql.connector.connect(**db_config)
        cursor = conn.cursor()
        
        print("\n=== SETTING DEFAULT VALUES ===")
        
        status_fields = ['status', 'status_annotatie', 'status_glos', 'status_video']
        
        for field in status_fields:
            # Alter column to set default value
            alter_query = f"ALTER TABLE sentences MODIFY COLUMN {field} VARCHAR(255) DEFAULT 'Niet Klaar'"
            cursor.execute(alter_query)
            print(f"✓ Set default value 'Niet Klaar' for column {field}")
        
        conn.commit()
        print("✓ All default values set successfully")
        
        return True
        
    except mysql.connector.Error as err:
        print(f"Database Error during default value setting: {err}")
        if conn:
            conn.rollback()
        return False
    except Exception as e:
        print(f"Unexpected error during default value setting: {e}")
        if conn:
            conn.rollback()
        return False
    finally:
        if cursor:
            cursor.close()
        if conn and conn.is_connected():
            conn.close()

def verify_status_updates():
    """Verify the status field updates"""
    conn = None
    cursor = None
    try:
        conn = mysql.connector.connect(**db_config)
        cursor = conn.cursor()
        
        print("\n=== VERIFICATION ===")
        
        status_fields = ['status', 'status_annotatie', 'status_glos', 'status_video']
        
        # Check table structure for default values
        cursor.execute("DESCRIBE sentences")
        table_structure = cursor.fetchall()
        
        print("\nColumn defaults:")
        for col_info in table_structure:
            col_name, col_type, null_allowed, key, default, extra = col_info
            if col_name in status_fields:
                print(f"  {col_name}: default = '{default}' ✓" if default == 'Niet Klaar' else f"  {col_name}: default = '{default}' ❌")
        
        print("\nCurrent status distribution:")
        for field in status_fields:
            # Count NULL values (should be 0)
            cursor.execute(f"SELECT COUNT(*) FROM sentences WHERE {field} IS NULL")
            null_count = cursor.fetchone()[0]
            
            # Count 'Niet Klaar' values
            cursor.execute(f"SELECT COUNT(*) FROM sentences WHERE {field} = 'Niet Klaar'")
            niet_klaar_count = cursor.fetchone()[0]
            
            # Get all distinct values
            cursor.execute(f"SELECT DISTINCT {field}, COUNT(*) FROM sentences GROUP BY {field} ORDER BY COUNT(*) DESC")
            all_values = cursor.fetchall()
            
            print(f"\n  {field}:")
            print(f"    NULL values: {null_count} {'✓' if null_count == 0 else '❌'}")
            print(f"    'Niet Klaar' values: {niet_klaar_count}")
            print(f"    All values:")
            for value, count in all_values:
                print(f"      '{value}': {count}")
        
        # Test default value by inserting a test record (then removing it)
        print(f"\nTesting default values:")
        cursor.execute("""
        INSERT INTO sentences (zinString, thema, userId) 
        VALUES ('TEST_RECORD_FOR_DEFAULT_CHECK', 'TEST', '999')
        """)
        
        # Get the inserted record to check defaults
        cursor.execute("""
        SELECT status, status_annotatie, status_glos, status_video 
        FROM sentences 
        WHERE zinString = 'TEST_RECORD_FOR_DEFAULT_CHECK'
        """)
        test_record = cursor.fetchone()
        
        if test_record:
            status_val, annotatie_val, glos_val, video_val = test_record
            print(f"  New record defaults:")
            print(f"    status: '{status_val}' {'✓' if status_val == 'Niet Klaar' else '❌'}")
            print(f"    status_annotatie: '{annotatie_val}' {'✓' if annotatie_val == 'Niet Klaar' else '❌'}")
            print(f"    status_glos: '{glos_val}' {'✓' if glos_val == 'Niet Klaar' else '❌'}")
            print(f"    status_video: '{video_val}' {'✓' if video_val == 'Niet Klaar' else '❌'}")
        
        # Remove test record
        cursor.execute("DELETE FROM sentences WHERE zinString = 'TEST_RECORD_FOR_DEFAULT_CHECK'")
        conn.commit()
        
        return null_count == 0
        
    except mysql.connector.Error as err:
        print(f"Database Error during verification: {err}")
        return False
    except Exception as e:
        print(f"Unexpected error during verification: {e}")
        return False
    finally:
        if cursor:
            cursor.close()
        if conn and conn.is_connected():
            conn.close()

def main():
    """Main function"""
    print("=" * 70)
    print("UPDATE STATUS FIELDS TO 'NIET KLAAR'")
    print("=" * 70)
    
    # Step 1: Create backup
    print("1. Creating backup before status updates...")
    backup_name = create_backup_before_status_update()
    if not backup_name:
        print("❌ Backup failed. Aborting updates.")
        sys.exit(1)
    
    # Step 2: Analyze current status
    print("2. Analyzing current status fields...")
    if not analyze_current_status_fields():
        print("❌ Analysis failed.")
        sys.exit(1)
    
    # Step 3: Update NULL values
    print("3. Updating NULL status fields...")
    updated_count = update_null_status_fields()
    
    # Step 4: Set default values
    print("4. Setting default values...")
    if not set_default_values_for_status_columns():
        print("❌ Setting default values failed.")
        sys.exit(1)
    
    # Step 5: Verify updates
    print("5. Verifying status updates...")
    success = verify_status_updates()
    
    # Final summary
    print(f"\n" + "=" * 70)
    print("UPDATE SUMMARY")
    print("=" * 70)
    print(f"Backup created: {backup_name}")
    print(f"Records updated: {updated_count}")
    print(f"Default values set for: status, status_annotatie, status_glos, status_video")
    print(f"Status: {'SUCCESS ✓' if success else 'PARTIAL SUCCESS - CHECK VERIFICATION'}")
    print("=" * 70)

if __name__ == "__main__":
    main()