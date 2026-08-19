# Motion Capture Filtering and MCP Status Fields - Implementation Notes

**Date**: 2026-01-21
**Feature**: Add motion capture filtering and two new MCP status fields to zinnen.html

## Changes Made

### Database Schema
**File**: `/web/zin/migrations/add_mcp_status_columns.sql`

Added two new VARCHAR(255) columns to the `sentences` table:
- `mcp_status_postprocessing` - Motion capture postprocessing status
- `mcp_status_tijd_annotatie` - Motion capture time annotation status

**Status**: SQL file created, needs to be executed on database

### Backend Changes
**File**: `/web/zin/getZinnen.php`

1. **New API Actions** (lines 88-93):
   - `saveMcpStatusPostprocessing` - Saves postprocessing status
   - `saveMcpStatusTijdAnnotatie` - Saves tijd annotatie status

2. **Save Functions** (lines 343-393):
   - `saveMcpStatusPostprocessing($conn)` - Updates `mcp_status_postprocessing` in database
   - `saveMcpStatusTijdAnnotatie($conn)` - Updates `mcp_status_tijd_annotatie` in database

3. **fetchSentences Updates**:
   - Added new GET parameters (lines 857-859): `mcpStatusPostprocessing`, `mcpStatusTijdAnnotatie`, `motionCapture`
   - Added fields to SELECT clause (lines 887-888)
   - Added to statusFilters array (lines 904-905)
   - Added motion capture subquery filter (lines 964-981)

### Frontend Changes
**File**: `/web/zin/zinnen.html`

1. **State Variables** (lines 858-860):
   ```javascript
   let motionCaptureFilter = "";
   let mcpStatusPostprocessingFilter = "";
   let mcpStatusTijdAnnotatieFilter = "";
   ```

2. **Filter Dropdowns** (lines 235-265):
   - Motion Capture Filter (All/Has Motion Capture/No Motion Capture)
   - MCP - Status Postprocessing (Leeg/Niet Klaar/Klaar/Check nodig)
   - MCP - Status Tijd Annotatie (Leeg/Niet Klaar/Klaar/Check nodig)

3. **Row Status Dropdowns** (lines 1160-1178, 1713-1731):
   - Added MCP status dropdowns to row templates (2 locations)
   - Displays current status with NULL/empty showing as "Leeg/Empty"

4. **Event Listeners** (lines 2222-2253):
   - Motion Capture Filter change handler
   - MCP Status Postprocessing change handler
   - MCP Status Tijd Annotatie change handler

5. **Event Delegation** (lines 1822-1835):
   - Added handlers in `addStatusSelectEventListeners()` for MCP status dropdowns

6. **Save Functions** (lines 1978-2016):
   - `saveMcpStatusPostprocessing(rowId, mcpStatusPostprocessing)`
   - `saveMcpStatusTijdAnnotatie(rowId, mcpStatusTijdAnnotatie)`

7. **loadSentences Function**:
   - Updated signature (lines 916-919): Added 3 new parameters
   - Updated query parameter construction (lines 938-940)
   - Updated ALL function calls throughout file (14+ locations)

8. **Reset Filters** (lines 838-859, 869-881):
   - Reset button now clears all 3 new filters
   - Resets both UI elements and state variables

9. **State Persistence** (lines 2941-2943, 2989-3010):
   - `saveCurrentState()` saves new filter values to localStorage
   - `restoreState()` restores new filter values on page load

## Testing Checklist

### 1. Database Migration
- [ ] Run SQL migration to add columns
- [ ] Verify columns exist: `DESCRIBE sentences;`

### 2. Motion Capture Filter
- [ ] Select "Has Motion Capture" - verify only sentences with mocap videos appear
- [ ] Select "No Motion Capture" - verify only sentences without mocap videos appear
- [ ] Select "All" - verify all sentences appear

### 3. MCP Status Filters (Top Filter Bar)
- [ ] Filter by "MCP - Status Postprocessing = Klaar"
- [ ] Filter by "MCP - Status Tijd Annotatie = Niet Klaar"
- [ ] Test "Leeg/Empty" option (should show NULL/empty values)
- [ ] Test combinations with other filters

### 4. Row Status Dropdowns
- [ ] Change MCP Status Postprocessing on a row
- [ ] Verify console shows success message
- [ ] Refresh page and verify status persisted
- [ ] Repeat for MCP Status Tijd Annotatie

### 5. Filter Combinations
- [ ] Test Motion Capture + MCP Status filters together
- [ ] Test with existing status filters (Status, Nederlands, Glossen, GvG)
- [ ] Test with search query
- [ ] Test with thema filter

### 6. Reset Filters
- [ ] Set all filters including new ones
- [ ] Click "Reset Filters"
- [ ] Verify all filters reset to default
- [ ] Verify all rows reappear

### 7. State Persistence
- [ ] Set filters including MCP filters
- [ ] Refresh page
- [ ] Verify filters persist via localStorage

### 8. Backend API Testing
Test with curl:
```bash
# Test save postprocessing status
curl -X POST "https://leffe.science.uva.nl:8043/zin/getZinnen.php" \
  -H "Content-Type: application/x-www-form-urlencoded" \
  -d "action=saveMcpStatusPostprocessing&rowId=1&mcpStatusPostprocessing=Klaar"

# Test save tijd annotatie status
curl -X POST "https://leffe.science.uva.nl:8043/zin/getZinnen.php" \
  -H "Content-Type: application/x-www-form-urlencoded" \
  -d "action=saveMcpStatusTijdAnnotatie&rowId=1&mcpStatusTijdAnnotatie=Niet Klaar"

# Expected response: {"success":true}
```

### 9. Database Verification
```sql
-- Check that status values are being saved
SELECT ID, mcp_status_postprocessing, mcp_status_tijd_annotatie
FROM sentences
WHERE mcp_status_postprocessing IS NOT NULL
   OR mcp_status_tijd_annotatie IS NOT NULL
LIMIT 10;
```

## Files Modified

1. **New**: `/web/zin/migrations/add_mcp_status_columns.sql`
2. **Modified**: `/web/zin/getZinnen.php`
   - Lines 88-93: New case statements
   - Lines 343-393: New save functions
   - Lines 857-859: New GET parameters
   - Lines 887-888: Added to SELECT
   - Lines 904-905: Added to statusFilters
   - Lines 964-981: Motion capture filter logic

3. **Modified**: `/web/zin/zinnen.html`
   - Lines 858-860: State variables
   - Lines 235-265: Filter dropdowns
   - Lines 1160-1178, 1713-1731: Row dropdowns
   - Lines 2222-2253: Event listeners
   - Lines 1822-1835: Event delegation
   - Lines 1978-2016: Save functions
   - Lines 916-919: loadSentences signature
   - All loadSentences calls updated (14+ locations)
   - Lines 2941-2943, 2989-3010: State persistence

## Rollback Instructions

If issues occur, rollback in this order:

1. **Revert Code Changes**:
   ```bash
   git checkout HEAD -- /web/zin/zinnen.html
   git checkout HEAD -- /web/zin/getZinnen.php
   ```

2. **Remove Database Columns** (if migration was applied):
   ```sql
   ALTER TABLE sentences
   DROP COLUMN mcp_status_postprocessing,
   DROP COLUMN mcp_status_tijd_annotatie;
   ```

## Known Limitations

- Motion capture filter uses subquery which may be slow on very large datasets
- Filter persistence uses localStorage (browser-specific)
- NULL and empty string both display as "Leeg/Empty"

## Future Enhancements

- Add bulk update for MCP statuses (like existing status fields)
- Add statistics/counts for MCP statuses
- Consider indexing new columns if queries are slow
- Add export functionality for MCP status reports
