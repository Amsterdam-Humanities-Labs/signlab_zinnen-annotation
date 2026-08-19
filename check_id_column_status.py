#!/usr/bin/env python3
from db_credentials import DB_PASSWORD
import mysql.connector

# Database configuration
db_config = {
    'user': 'user',
    'password': DB_PASSWORD,
    'host': 'localhost',
    'database': 'admin_gebarenoverleg'
}

def check_id_column_status():
    """Check the status of the ID column in sentences table"""
    conn = None
    cursor = None
    try:
        conn = mysql.connector.connect(**db_config)
        cursor = conn.cursor()
        
        print("=== CHECKING ID COLUMN STATUS ===")
        
        # Check table creation statement
        cursor.execute("SHOW CREATE TABLE sentences")
        create_statement = cursor.fetchone()[1]
        print("\nTable creation statement:")
        print(create_statement)
        
        # Check if ID is auto-increment and primary key
        has_auto_increment = 'AUTO_INCREMENT' in create_statement
        has_primary_key = 'PRIMARY KEY' in create_statement and 'ID' in create_statement
        
        print(f"\nID column analysis:")
        print(f"  Has AUTO_INCREMENT: {has_auto_increment}")
        print(f"  Has PRIMARY KEY: {has_primary_key}")
        
        # Check current ID values
        cursor.execute("SELECT MIN(ID), MAX(ID), COUNT(*) FROM sentences")
        min_id, max_id, count = cursor.fetchone()
        print(f"  ID range: {min_id} - {max_id}")
        print(f"  Record count: {count}")
        
        # Check for duplicate IDs
        cursor.execute("SELECT ID, COUNT(*) FROM sentences GROUP BY ID HAVING COUNT(*) > 1")
        duplicates = cursor.fetchall()
        if duplicates:
            print(f"  Duplicate IDs found: {len(duplicates)}")
            for dup_id, dup_count in duplicates[:5]:  # Show first 5
                print(f"    ID {dup_id}: {dup_count} occurrences")
        else:
            print("  No duplicate IDs found ✓")
        
        # Check for NULL IDs
        cursor.execute("SELECT COUNT(*) FROM sentences WHERE ID IS NULL")
        null_count = cursor.fetchone()[0]
        print(f"  NULL IDs: {null_count}")
        
        # Sample some records
        cursor.execute("SELECT ID, zinString, userid, label FROM sentences ORDER BY ID LIMIT 5")
        sample = cursor.fetchall()
        print(f"\nSample records:")
        for record in sample:
            print(f"  ID {record[0]}: {record[1][:50]}... (userid: {record[2]}, label: {record[3]})")
        
        return has_auto_increment, has_primary_key, min_id, max_id, count, len(duplicates) if duplicates else 0
        
    except mysql.connector.Error as err:
        print(f"Database Error: {err}")
        return False, False, 0, 0, 0, 0
    except Exception as e:
        print(f"Unexpected error: {e}")
        return False, False, 0, 0, 0, 0
    finally:
        if cursor:
            cursor.close()
        if conn and conn.is_connected():
            conn.close()

def main():
    print("=" * 60)
    print("CHECKING ID COLUMN STATUS")
    print("=" * 60)
    
    has_auto_increment, has_primary_key, min_id, max_id, count, duplicates = check_id_column_status()
    
    print(f"\n" + "=" * 60)
    print("SUMMARY")
    print("=" * 60)
    print(f"Auto-increment: {has_auto_increment}")
    print(f"Primary key: {has_primary_key}")
    print(f"ID range: {min_id} - {max_id}")
    print(f"Record count: {count}")
    print(f"Duplicate IDs: {duplicates}")
    
    if not has_auto_increment or not has_primary_key or duplicates > 0:
        print("\n⚠ Issues found - ID column needs fixing")
    else:
        print("\n✓ ID column appears to be properly configured")

if __name__ == "__main__":
    main()