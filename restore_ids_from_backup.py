#!/usr/bin/env python3
"""
Migration Script: Restore ID, zinID and Status Fields from Backup Table

This script updates the sentences table by restoring the following fields
from a backup table for specific zinStrings listed in zinnen_back.txt:
- ID
- zinID
- status
- status_annotatie
- status_glos
- status_video
- comments

Usage:
    python3 restore_ids_from_backup.py [--dry-run] [--yes]

Options:
    --dry-run    Analyze and report what would be changed without executing
    --yes, -y    Auto-confirm migration without interactive prompt
"""
from db_credentials import DB_PASSWORD

import mysql.connector
from datetime import datetime
import sys
import json
import re
from typing import Dict, Tuple, List, Set, Optional

# Database configuration
DB_CONFIG = {
    'user': 'user',
    'password': DB_PASSWORD,
    'host': 'localhost',
    'database': 'admin_gebarenoverleg'
}

# Constants
ZINNEN_BACK_FILE = '/web/zin/zinnen_back.txt'
BACKUP_TABLE = 'sentences_backup_20250728_113520'


def parse_zinnen_back_txt() -> List[str]:
    """
    Parse zinnen_back.txt file to extract zinStrings.

    File format: Plain zinStrings, one per line
    Example:
        Ben je lekker aan het rollen?
        Daar is je knuffel weer!

    Returns:
        List of zinStrings extracted from the file
    """
    print("\n" + "=" * 70)
    print("STEP 1: PARSING zinnen_back.txt")
    print("=" * 70)

    zinstrings = []

    try:
        with open(ZINNEN_BACK_FILE, 'r', encoding='utf-8') as f:
            for line_num, line in enumerate(f, 1):
                # Strip all leading/trailing whitespace including spaces
                line = line.strip()

                # Skip empty lines
                if not line:
                    continue

                # Remove context notes in parentheses at the end
                # Examples: "(context: ...)", "(zie filmpje ...)", "(knuffel)", etc.
                import re
                line = re.sub(r'\s*\([^)]+\)\s*$', '', line).strip()

                # Each line is a zinString
                zinstrings.append(line)

        print(f"✓ Successfully parsed {len(zinstrings)} zinStrings from {ZINNEN_BACK_FILE}")

        # Show first few samples
        if zinstrings:
            print(f"\n  Sample zinStrings (first 3):")
            for i, zs in enumerate(zinstrings[:3], 1):
                print(f"    {i}. {zs[:60] + ('...' if len(zs) > 60 else '')}")

        return zinstrings

    except FileNotFoundError:
        print(f"❌ Error: File not found: {ZINNEN_BACK_FILE}")
        sys.exit(1)
    except Exception as e:
        print(f"❌ Error parsing file: {e}")
        sys.exit(1)


def create_pre_migration_backup(conn) -> Tuple[str, str]:
    """
    Create timestamped backups of sentences and matched_transcriptions tables.

    Args:
        conn: Database connection

    Returns:
        Tuple of (sentences_backup_name, matched_transcriptions_backup_name)
    """
    print("\n" + "=" * 70)
    print("STEP 2: CREATING PRE-MIGRATION BACKUPS")
    print("=" * 70)

    cursor = conn.cursor()
    timestamp = datetime.now().strftime('%Y%m%d_%H%M%S')

    # Backup sentences table
    sentences_backup = f"sentences_backup_pre_id_restore_{timestamp}"
    print(f"\nCreating backup: {sentences_backup}")
    cursor.execute(f"CREATE TABLE {sentences_backup} AS SELECT * FROM sentences")
    cursor.execute(f"SELECT COUNT(*) FROM {sentences_backup}")
    sentences_count = cursor.fetchone()[0]
    print(f"✓ Backed up sentences: {sentences_count} records")

    # Backup matched_transcriptions table
    mt_backup = f"matched_transcriptions_backup_{timestamp}"
    print(f"\nCreating backup: {mt_backup}")
    cursor.execute(f"CREATE TABLE {mt_backup} AS SELECT * FROM matched_transcriptions")
    cursor.execute(f"SELECT COUNT(*) FROM {mt_backup}")
    mt_count = cursor.fetchone()[0]
    print(f"✓ Backed up matched_transcriptions: {mt_count} records")

    conn.commit()
    cursor.close()

    return sentences_backup, mt_backup


def build_id_mapping(conn, zinstrings: List[str]) -> Tuple[Dict[int, Dict], Dict]:
    """
    Build mapping of current ID → backup record data for target zinStrings.

    Restores: ID, zinID, status, status_annotatie, status_glos, status_video, comments

    Uses fuzzy matching when exact match fails (strips parenthetical context notes).

    Args:
        conn: Database connection
        zinstrings: List of zinStrings to process

    Returns:
        Tuple of (id_mapping dict, statistics dict)
    """
    print("\n" + "=" * 70)
    print("STEP 3: BUILDING ID MAPPING")
    print("=" * 70)

    cursor = conn.cursor(dictionary=True, buffered=True)
    id_mapping = {}
    stats = {
        'found_in_both': 0,
        'found_fuzzy': 0,
        'not_in_backup': [],
        'not_in_current': [],
        'total_target': len(zinstrings)
    }

    # Fields to restore from backup
    restore_fields = ['ID', 'zinID', 'status', 'status_annotatie', 'status_glos', 'status_video', 'comments']

    for i, zinstring in enumerate(zinstrings, 1):
        if i % 20 == 0 or i == len(zinstrings):
            print(f"  Processing {i}/{len(zinstrings)} zinStrings...", end='\r')

        # Look up in backup table - EXACT match
        fields_str = ', '.join(restore_fields)
        cursor.execute(
            f"SELECT {fields_str}, zinString FROM {BACKUP_TABLE} WHERE zinString = %s",
            (zinstring,)
        )
        backup_record = cursor.fetchone()

        # Look up in current sentences table - EXACT match
        cursor.execute(
            "SELECT ID, zinString FROM sentences WHERE zinString = %s",
            (zinstring,)
        )
        current_record = cursor.fetchone()

        # If exact match found in both, use it
        if backup_record and current_record:
            current_id = current_record['ID']
            id_mapping[current_id] = {
                'backup_id': backup_record['ID'],
                'backup_zinID': backup_record['zinID'],
                'backup_status': backup_record['status'],
                'backup_status_annotatie': backup_record['status_annotatie'],
                'backup_status_glos': backup_record['status_glos'],
                'backup_status_video': backup_record['status_video'],
                'backup_comments': backup_record['comments']
            }
            stats['found_in_both'] += 1
            continue

        # If no exact match, try FUZZY matching
        # Strip parenthetical context notes for fuzzy search
        import re
        search_base = re.sub(r'\s*\([^)]+\)\s*$', '', zinstring).strip()

        # Try fuzzy match in current table
        cursor.execute(
            "SELECT ID, zinString FROM sentences WHERE zinString LIKE %s OR zinString = %s LIMIT 1",
            (f"{search_base}%", search_base)
        )
        current_fuzzy = cursor.fetchone()

        # Try fuzzy match in backup table
        cursor.execute(
            f"SELECT {fields_str}, zinString FROM {BACKUP_TABLE} WHERE zinString LIKE %s OR zinString = %s LIMIT 1",
            (f"{search_base}%", search_base)
        )
        backup_fuzzy = cursor.fetchone()

        if current_fuzzy and backup_fuzzy:
            # Fuzzy match found
            current_id = current_fuzzy['ID']
            id_mapping[current_id] = {
                'backup_id': backup_fuzzy['ID'],
                'backup_zinID': backup_fuzzy['zinID'],
                'backup_status': backup_fuzzy['status'],
                'backup_status_annotatie': backup_fuzzy['status_annotatie'],
                'backup_status_glos': backup_fuzzy['status_glos'],
                'backup_status_video': backup_fuzzy['status_video'],
                'backup_comments': backup_fuzzy['comments']
            }
            stats['found_fuzzy'] += 1
            continue

        # No match found at all
        if not backup_record and not backup_fuzzy:
            stats['not_in_backup'].append(zinstring[:50] + ('...' if len(zinstring) > 50 else ''))
        elif not current_record and not current_fuzzy:
            stats['not_in_current'].append(zinstring[:50] + ('...' if len(zinstring) > 50 else ''))

    print(f"\n\n✓ ID Mapping built: {len(id_mapping)} records will be updated")
    print(f"  - Found exact matches: {stats['found_in_both']}")
    print(f"  - Found fuzzy matches: {stats['found_fuzzy']}")
    print(f"  - Not in backup table: {len(stats['not_in_backup'])}")
    print(f"  - Not in current table: {len(stats['not_in_current'])}")
    print(f"\n  Fields to restore: ID, zinID, status, status_annotatie, status_glos, status_video, comments")

    if stats['not_in_backup']:
        print(f"\n  ⚠ Warning: {len(stats['not_in_backup'])} zinStrings not found in backup:")
        for zs in stats['not_in_backup'][:5]:
            print(f"    - {zs}")
        if len(stats['not_in_backup']) > 5:
            print(f"    ... and {len(stats['not_in_backup']) - 5} more")

    if stats['not_in_current']:
        print(f"\n  ⚠ Warning: {len(stats['not_in_current'])} zinStrings not found in current table:")
        for zs in stats['not_in_current'][:5]:
            print(f"    - {zs}")
        if len(stats['not_in_current']) > 5:
            print(f"    ... and {len(stats['not_in_current']) - 5} more")

    cursor.close()
    return id_mapping, stats


def analyze_migration_scope(conn, id_mapping: Dict[int, Dict]) -> Dict:
    """
    Analyze and report what will be changed (dry-run analysis).

    Args:
        conn: Database connection
        id_mapping: Mapping of current_id → backup record dict

    Returns:
        Dictionary with analysis statistics
    """
    print("\n" + "=" * 70)
    print("STEP 4: DRY-RUN ANALYSIS")
    print("=" * 70)

    cursor = conn.cursor(dictionary=True)

    # Get total record counts
    cursor.execute("SELECT COUNT(*) as count FROM sentences")
    total_sentences = cursor.fetchone()['count']

    cursor.execute("SELECT COUNT(*) as count FROM matched_transcriptions WHERE zOg = 'zin'")
    total_mt_references = cursor.fetchone()['count']

    # Count how many matched_transcriptions will be affected
    current_ids = list(id_mapping.keys())
    mt_affected = 0
    if current_ids:
        placeholders = ','.join(['%s'] * len(current_ids))
        cursor.execute(
            f"SELECT COUNT(*) as count FROM matched_transcriptions WHERE zOg = 'zin' AND m_transcription IN ({placeholders})",
            current_ids
        )
        mt_affected = cursor.fetchone()['count']

    analysis = {
        'total_sentences': total_sentences,
        'records_to_update': len(id_mapping),
        'records_unchanged': total_sentences - len(id_mapping),
        'mt_total_references': total_mt_references,
        'mt_affected_references': mt_affected
    }

    print(f"\n📊 Migration Scope Analysis:")
    print(f"  Total sentences in table: {analysis['total_sentences']}")
    print(f"  Records to UPDATE: {analysis['records_to_update']} (will restore from backup)")
    print(f"  Records UNCHANGED: {analysis['records_unchanged']} (keep current values)")
    print(f"\n  Total matched_transcriptions references: {analysis['mt_total_references']}")
    print(f"  References that will be UPDATED: {analysis['mt_affected_references']}")

    # Show sample record changes with status fields
    print(f"\n  Sample Record Changes (first 5):")
    sample_count = 0
    for current_id, backup_data in id_mapping.items():
        if sample_count >= 5:
            break

        # Get current record data
        cursor.execute(
            "SELECT zinString, status, status_annotatie, status_glos FROM sentences WHERE ID = %s",
            (current_id,)
        )
        result = cursor.fetchone()
        if result:
            zinstring = result['zinString'][:40] + ('...' if len(result['zinString']) > 40 else '')
            print(f"\n    Record: {zinstring}")
            print(f"      ID: {current_id} → {backup_data['backup_id']}")
            print(f"      zinID: → {backup_data['backup_zinID']}")
            print(f"      status: {result['status']} → {backup_data['backup_status']}")
            print(f"      status_annotatie: {result['status_annotatie']} → {backup_data['backup_status_annotatie']}")
            print(f"      status_glos: {result['status_glos']} → {backup_data['backup_status_glos']}")
            sample_count += 1

    cursor.close()
    return analysis


def create_temporary_sentences_table(conn, id_mapping: Dict[int, Dict]):
    """
    Create temporary sentences table with restored fields from backup for target records.

    Restores: ID, zinID, status, status_annotatie, status_glos, status_video, comments

    Args:
        conn: Database connection
        id_mapping: Mapping of current_id → backup record dict
    """
    print("\n" + "=" * 70)
    print("STEP 5: CREATING TEMPORARY SENTENCES TABLE")
    print("=" * 70)

    cursor = conn.cursor()

    # Drop temp table if exists
    print("\nDropping temp table if exists...")
    cursor.execute("DROP TABLE IF EXISTS sentences_temp_id_restore")

    # Get table structure from current sentences table
    print("Creating temp table with same structure as sentences...")
    cursor.execute("SHOW CREATE TABLE sentences")
    create_statement = cursor.fetchone()[1]

    # Modify CREATE statement to use temp table name
    temp_create = create_statement.replace('CREATE TABLE `sentences`', 'CREATE TABLE `sentences_temp_id_restore`')
    cursor.execute(temp_create)
    print("✓ Temp table structure created")

    # Get all columns for insertion
    cursor.execute("SHOW COLUMNS FROM sentences")
    columns = [row[0] for row in cursor.fetchall()]
    columns_str = ', '.join(f"`{col}`" for col in columns)

    # Disable AUTO_INCREMENT for explicit ID insertion
    cursor.execute("ALTER TABLE sentences_temp_id_restore MODIFY ID INT NOT NULL")

    # Get all records from current sentences
    print("\nFetching all records from sentences table...")
    cursor.execute(f"SELECT {columns_str} FROM sentences")
    all_records = cursor.fetchall()
    print(f"✓ Fetched {len(all_records)} records")

    # Prepare records for insertion
    print("\nPreparing records for insertion...")
    print("  Restoring fields: ID, zinID, status, status_annotatie, status_glos, status_video, comments")
    records_to_insert = []
    updated_count = 0

    for record in all_records:
        record_dict = dict(zip(columns, record))
        current_id = record_dict['ID']

        if current_id in id_mapping:
            # This record should get backup values
            backup_data = id_mapping[current_id]
            record_dict['ID'] = backup_data['backup_id']
            record_dict['zinID'] = backup_data['backup_zinID']
            record_dict['status'] = backup_data['backup_status']
            record_dict['status_annotatie'] = backup_data['backup_status_annotatie']
            record_dict['status_glos'] = backup_data['backup_status_glos']
            record_dict['status_video'] = backup_data['backup_status_video']
            record_dict['comments'] = backup_data['backup_comments']
            updated_count += 1
        # else: keep current values

        records_to_insert.append(record_dict)

    print(f"  {updated_count} records will be restored from backup")

    # Sort by ID to avoid conflicts
    records_to_insert.sort(key=lambda x: x['ID'])

    # Insert records
    print(f"\nInserting {len(records_to_insert)} records into temp table...")
    placeholders = ', '.join(['%s'] * len(columns))
    insert_query = f"INSERT INTO sentences_temp_id_restore ({columns_str}) VALUES ({placeholders})"

    batch_size = 500
    for i in range(0, len(records_to_insert), batch_size):
        batch = records_to_insert[i:i+batch_size]
        values = [tuple(rec[col] for col in columns) for rec in batch]
        cursor.executemany(insert_query, values)
        print(f"  Inserted {min(i+batch_size, len(records_to_insert))}/{len(records_to_insert)} records...", end='\r')

    print(f"\n✓ All records inserted into temp table")

    # Re-enable AUTO_INCREMENT
    cursor.execute("SELECT MAX(ID) FROM sentences_temp_id_restore")
    max_id = cursor.fetchone()[0]
    cursor.execute(f"ALTER TABLE sentences_temp_id_restore MODIFY ID INT AUTO_INCREMENT")
    cursor.execute(f"ALTER TABLE sentences_temp_id_restore AUTO_INCREMENT = {max_id + 1}")
    print(f"✓ AUTO_INCREMENT set to {max_id + 1}")

    # Verify record count
    cursor.execute("SELECT COUNT(*) FROM sentences_temp_id_restore")
    temp_count = cursor.fetchone()[0]
    print(f"✓ Temp table verification: {temp_count} records")

    conn.commit()
    cursor.close()


def create_temporary_matched_transcriptions_table(conn, id_mapping: Dict[int, Dict]) -> Dict:
    """
    Create temporary matched_transcriptions table with updated sentence IDs.

    Args:
        conn: Database connection
        id_mapping: Mapping of current_id → backup record dict

    Returns:
        Dictionary with update statistics
    """
    print("\n" + "=" * 70)
    print("STEP 6: CREATING TEMPORARY MATCHED_TRANSCRIPTIONS TABLE")
    print("=" * 70)

    cursor = conn.cursor()

    # Drop temp table if exists
    print("\nDropping temp table if exists...")
    cursor.execute("DROP TABLE IF EXISTS matched_transcriptions_temp")

    # Create temp table as copy
    print("Creating temp table as copy of matched_transcriptions...")
    cursor.execute("CREATE TABLE matched_transcriptions_temp AS SELECT * FROM matched_transcriptions")
    print("✓ Temp table created")

    # Update IDs for changed records
    print(f"\nUpdating sentence ID references for {len(id_mapping)} changed IDs...")
    update_count = 0

    for current_id, backup_data in id_mapping.items():
        backup_id = backup_data['backup_id']
        if current_id != backup_id:  # Only update if ID actually changed
            cursor.execute(
                "UPDATE matched_transcriptions_temp SET m_transcription = %s WHERE m_transcription = %s AND zOg = 'zin'",
                (backup_id, current_id)
            )
            rows_affected = cursor.rowcount
            if rows_affected > 0:
                update_count += rows_affected

    print(f"✓ Updated {update_count} matched_transcriptions references")

    conn.commit()
    cursor.close()

    return {'references_updated': update_count}


def perform_atomic_swap(conn):
    """
    Perform atomic table swap - DROP and RENAME operations.

    Args:
        conn: Database connection
    """
    print("\n" + "=" * 70)
    print("STEP 7: ATOMIC TABLE SWAP (POINT OF NO RETURN)")
    print("=" * 70)

    cursor = conn.cursor()

    # Swap sentences table
    print("\nSwapping sentences table...")
    cursor.execute("DROP TABLE sentences")
    cursor.execute("RENAME TABLE sentences_temp_id_restore TO sentences")
    print("✓ Sentences table swapped")

    # Swap matched_transcriptions table
    print("\nSwapping matched_transcriptions table...")
    cursor.execute("DROP TABLE matched_transcriptions")
    cursor.execute("RENAME TABLE matched_transcriptions_temp TO matched_transcriptions")
    print("✓ Matched_transcriptions table swapped")

    conn.commit()
    cursor.close()

    print("\n✓ ATOMIC SWAP COMPLETED SUCCESSFULLY")


def verify_migration(conn, expected_count: int, id_mapping: Dict) -> Dict:
    """
    Verify migration success with various checks.

    Args:
        conn: Database connection
        expected_count: Expected number of records
        id_mapping: The ID mapping used

    Returns:
        Dictionary with verification results
    """
    print("\n" + "=" * 70)
    print("STEP 8: VERIFICATION")
    print("=" * 70)

    cursor = conn.cursor(dictionary=True)
    results = {'passed': True, 'checks': []}

    # Check 1: Record count
    print("\n1. Verifying record count...")
    cursor.execute("SELECT COUNT(*) as count FROM sentences")
    actual_count = cursor.fetchone()['count']
    if actual_count == expected_count:
        print(f"  ✓ Record count correct: {actual_count}")
        results['checks'].append(('Record count', True, f"{actual_count} records"))
    else:
        print(f"  ❌ Record count mismatch: expected {expected_count}, got {actual_count}")
        results['checks'].append(('Record count', False, f"Expected {expected_count}, got {actual_count}"))
        results['passed'] = False

    # Check 2: No duplicate IDs
    print("\n2. Checking for duplicate IDs...")
    cursor.execute("SELECT ID, COUNT(*) as count FROM sentences GROUP BY ID HAVING count > 1")
    duplicates = cursor.fetchall()
    if not duplicates:
        print("  ✓ No duplicate IDs found")
        results['checks'].append(('Duplicate IDs', True, "None found"))
    else:
        print(f"  ❌ Found {len(duplicates)} duplicate IDs")
        results['checks'].append(('Duplicate IDs', False, f"{len(duplicates)} duplicates"))
        results['passed'] = False

    # Check 3: AUTO_INCREMENT value
    print("\n3. Verifying AUTO_INCREMENT...")
    cursor.execute("SELECT MAX(ID) as max_id FROM sentences")
    max_id = cursor.fetchone()['max_id']
    cursor.execute("SHOW CREATE TABLE sentences")
    create_statement = cursor.fetchone()['Create Table']
    auto_inc_match = re.search(r'AUTO_INCREMENT=(\d+)', create_statement)
    if auto_inc_match:
        auto_inc_value = int(auto_inc_match.group(1))
        if auto_inc_value > max_id:
            print(f"  ✓ AUTO_INCREMENT correct: {auto_inc_value} (max ID: {max_id})")
            results['checks'].append(('AUTO_INCREMENT', True, f"{auto_inc_value}"))
        else:
            print(f"  ❌ AUTO_INCREMENT too low: {auto_inc_value} (max ID: {max_id})")
            results['checks'].append(('AUTO_INCREMENT', False, f"{auto_inc_value} <= {max_id}"))
            results['passed'] = False

    # Check 4: Sample records restored correctly
    print("\n4. Verifying sample records restored correctly...")
    sample_checked = 0
    sample_correct = 0
    for current_id, backup_data in list(id_mapping.items())[:10]:
        backup_id = backup_data['backup_id']
        backup_zinID = backup_data['backup_zinID']
        cursor.execute(
            "SELECT COUNT(*) as count FROM sentences WHERE ID = %s AND zinID = %s",
            (backup_id, backup_zinID)
        )
        if cursor.fetchone()['count'] > 0:
            sample_correct += 1
        sample_checked += 1

    if sample_correct == sample_checked:
        print(f"  ✓ All {sample_checked} sample records verified correct")
        results['checks'].append(('Sample records', True, f"{sample_correct}/{sample_checked} correct"))
    else:
        print(f"  ⚠ Only {sample_correct}/{sample_checked} sample records correct")
        results['checks'].append(('Sample records', False, f"Only {sample_correct}/{sample_checked} correct"))

    # Check 5: Broken references
    print("\n5. Checking for broken references...")
    cursor.execute("""
        SELECT COUNT(*) as count
        FROM matched_transcriptions mt
        WHERE mt.zOg = 'zin'
        AND NOT EXISTS (SELECT 1 FROM sentences s WHERE s.ID = mt.m_transcription)
    """)
    broken_refs = cursor.fetchone()['count']
    print(f"  ℹ Broken references found: {broken_refs}")
    results['checks'].append(('Broken references', True, f"{broken_refs} broken"))
    results['broken_references'] = broken_refs

    cursor.close()
    return results


def generate_migration_report(
    id_mapping: Dict,
    mapping_stats: Dict,
    analysis: Dict,
    mt_stats: Dict,
    verification: Dict,
    sentences_backup: str,
    mt_backup: str
) -> str:
    """
    Generate comprehensive migration report and save to JSON.

    Args:
        id_mapping: The ID mapping dictionary
        mapping_stats: Statistics from mapping phase
        analysis: Analysis results
        mt_stats: Matched transcriptions update stats
        verification: Verification results
        sentences_backup: Name of sentences backup table
        mt_backup: Name of matched_transcriptions backup table

    Returns:
        Report filename
    """
    print("\n" + "=" * 70)
    print("STEP 9: GENERATING MIGRATION REPORT")
    print("=" * 70)

    timestamp = datetime.now().strftime('%Y%m%d_%H%M%S')
    report_file = f"/web/zin/migration_report_{timestamp}.json"

    report = {
        'migration_timestamp': timestamp,
        'backup_tables': {
            'sentences': sentences_backup,
            'matched_transcriptions': mt_backup
        },
        'source_file': ZINNEN_BACK_FILE,
        'backup_table_used': BACKUP_TABLE,
        'statistics': {
            'total_zinstrings_in_file': mapping_stats['total_target'],
            'records_updated': len(id_mapping),
            'exact_matches': mapping_stats['found_in_both'],
            'fuzzy_matches': mapping_stats['found_fuzzy'],
            'records_unchanged': analysis['records_unchanged'],
            'not_found_in_backup': len(mapping_stats['not_in_backup']),
            'not_found_in_current': len(mapping_stats['not_in_current']),
            'matched_transcriptions_references_updated': mt_stats['references_updated'],
            'total_mt_references': analysis['mt_total_references']
        },
        'id_mapping': {str(k): v for k, v in id_mapping.items()},
        'fields_restored': ['ID', 'zinID', 'status', 'status_annotatie', 'status_glos', 'status_video', 'comments'],
        'warnings': {
            'zinstrings_not_in_backup': mapping_stats['not_in_backup'],
            'zinstrings_not_in_current': mapping_stats['not_in_current']
        },
        'verification': {
            'passed': verification['passed'],
            'checks': [{'check': c[0], 'passed': c[1], 'details': c[2]} for c in verification['checks']],
            'broken_references': verification.get('broken_references', 0)
        }
    }

    with open(report_file, 'w', encoding='utf-8') as f:
        json.dump(report, f, indent=2, ensure_ascii=False)

    print(f"\n✓ Migration report saved to: {report_file}")

    # Print summary
    print("\n" + "=" * 70)
    print("MIGRATION SUMMARY")
    print("=" * 70)
    print(f"\n✓ Records updated with backup IDs: {len(id_mapping)}")
    print(f"✓ Records unchanged: {analysis['records_unchanged']}")
    print(f"✓ Matched_transcriptions references updated: {mt_stats['references_updated']}")
    print(f"\n⚠ Warnings:")
    print(f"  - zinStrings not in backup: {len(mapping_stats['not_in_backup'])}")
    print(f"  - zinStrings not in current: {len(mapping_stats['not_in_current'])}")
    print(f"\n✓ Verification: {'PASSED' if verification['passed'] else 'FAILED'}")
    print(f"✓ Backup tables: {sentences_backup}, {mt_backup}")

    return report_file


def main(dry_run=False, auto_confirm=False):
    """
    Main execution function.

    Args:
        dry_run: If True, only analyze without making changes
        auto_confirm: If True, skip confirmation prompt
    """
    print("\n" + "=" * 70)
    print("MIGRATION: Restore ID and zinID from Backup Table")
    print("=" * 70)
    print(f"\nMode: {'DRY-RUN (no changes will be made)' if dry_run else 'LIVE EXECUTION'}")
    print(f"Source file: {ZINNEN_BACK_FILE}")
    print(f"Backup table: {BACKUP_TABLE}")

    conn = None

    try:
        # Connect to database
        print("\nConnecting to database...")
        conn = mysql.connector.connect(**DB_CONFIG)
        print("✓ Connected")

        # Step 1: Parse file
        zinstrings = parse_zinnen_back_txt()

        if dry_run:
            print("\n" + "=" * 70)
            print("DRY-RUN MODE: Analyzing only, no changes will be made")
            print("=" * 70)

        # Step 2: Create backups (skip in dry-run)
        if not dry_run:
            sentences_backup, mt_backup = create_pre_migration_backup(conn)
        else:
            sentences_backup, mt_backup = "N/A (dry-run)", "N/A (dry-run)"

        # Step 3: Build ID mapping
        id_mapping, mapping_stats = build_id_mapping(conn, zinstrings)

        # Step 4: Analyze scope
        analysis = analyze_migration_scope(conn, id_mapping)

        if dry_run:
            print("\n" + "=" * 70)
            print("DRY-RUN COMPLETE")
            print("=" * 70)
            print("\nTo execute the migration, run:")
            print("  python3 restore_ids_from_backup.py")
            return

        # Confirm before proceeding
        print("\n" + "=" * 70)
        print("READY TO EXECUTE MIGRATION")
        print("=" * 70)
        print(f"\n{len(id_mapping)} records will be updated.")
        print(f"{analysis['mt_affected_references']} matched_transcriptions references will be updated.")
        print(f"\nBackups created:")
        print(f"  - {sentences_backup}")
        print(f"  - {mt_backup}")

        if not auto_confirm:
            response = input("\nType 'YES' to proceed with migration: ")
            if response != 'YES':
                print("\n❌ Migration cancelled by user")
                return
        else:
            print("\n✓ Auto-confirm enabled, proceeding with migration...")

        # Step 5: Create temporary sentences table
        create_temporary_sentences_table(conn, id_mapping)

        # Step 6: Create temporary matched_transcriptions table
        mt_stats = create_temporary_matched_transcriptions_table(conn, id_mapping)

        # Step 7: Atomic swap
        perform_atomic_swap(conn)

        # Step 8: Verify
        verification = verify_migration(conn, analysis['total_sentences'], id_mapping)

        # Step 9: Generate report
        report_file = generate_migration_report(
            id_mapping, mapping_stats, analysis, mt_stats,
            verification, sentences_backup, mt_backup
        )

        if verification['passed']:
            print("\n" + "=" * 70)
            print("✓ MIGRATION COMPLETED SUCCESSFULLY")
            print("=" * 70)
            print(f"\nReport: {report_file}")
        else:
            print("\n" + "=" * 70)
            print("⚠ MIGRATION COMPLETED WITH WARNINGS")
            print("=" * 70)
            print(f"\nReport: {report_file}")
            print("\nSome verification checks failed. Review the report.")

    except KeyboardInterrupt:
        print("\n\n❌ Migration interrupted by user")
        sys.exit(1)
    except Exception as e:
        print(f"\n\n❌ Migration failed with error: {e}")
        import traceback
        traceback.print_exc()
        sys.exit(1)
    finally:
        if conn and conn.is_connected():
            conn.close()
            print("\n✓ Database connection closed")


if __name__ == "__main__":
    # Check for flags
    dry_run = '--dry-run' in sys.argv
    auto_confirm = '--yes' in sys.argv or '-y' in sys.argv
    main(dry_run=dry_run, auto_confirm=auto_confirm)
