# Motion Capture Editor Button - Implementation Documentation

## Overview
Added a "Bewerk Motion Capture" button to zinnen.html that allows users to edit 3D motion capture data for videos that have associated FBX files.

## Implementation Date
January 21, 2026

## Files Modified

### 1. getZinnen.php
**Location:** `/web/zin/getZinnen.php`

**Changes:**
- Added new API action `getLatestMocapFile` (line 134-136)
- Added function `getLatestMocapFile()` (lines 3137-3192)

**Function Purpose:**
- Accepts a video filename (e.g., "M20240925_1824.wav")
- Extracts base filename and searches for matching FBX files
- Returns the FBX file with the highest take number
- Example: For "M20240925_1824.wav", finds files like:
  - M20240925_1824_251217_0.fbx (take 0)
  - M20240925_1824_251217_5.fbx (take 5) ← Returns this one

**API Endpoint:**
```
GET /zin/getZinnen.php?action=getLatestMocapFile&mFile=M20240925_1824.wav
```

**Response Format:**
```json
{
  "success": true,
  "hasMocap": true,
  "fbxFilename": "M20240925_1824_251217_5.fbx",
  "takeNumber": 5
}
```

### 2. zinnen.html
**Location:** `/web/zin/zinnen.html`

**Changes:**

#### Button HTML (lines 1044-1052)
Added conditional button that appears only when `video.has_mocap == 1`:
```html
${video.has_mocap == 1 ? `
    <button class="btn btn-sm btn-info editMocap-btn"
            data-id="${row.ID}"
            data-videoindex="${videoIndex}"
            data-filename="${videoCenter}">
        Bewerk Motion Capture
    </button>
` : ''}
```

#### Event Handler (lines 1300-1320)
Added click handler that:
1. Fetches the latest FBX file via API
2. Redirects to 3DAnn1.html with the FBX file URL
3. Handles errors gracefully

**Button Style:** `btn-info` (cyan color) to distinguish from other editing buttons

## Database Requirements

### Table: matched_transcriptions
**Required Field:** `has_mocap`
- Type: INT or BOOLEAN
- Values:
  - `1` = Video has motion capture data
  - `0` or `NULL` = No motion capture data

**Note:** The `has_mocap` field must be set correctly in the database for buttons to appear.

## File Structure

### FBX File Naming Convention
Pattern: `{video_base}_{date_suffix}_{take_number}.fbx`

**Example:**
```
M20240925_1824_251217_5.fbx
├── M20240925_1824  (base from video file)
├── 251217          (date suffix in YYMMDD format)
└── 5               (take number)
```

### FBX Directory
Location: `/web/gebarenoverleg_media/fbx/`

### Example Files
```
M20240925_1824_251217_0.fbx
M20240925_1824_251217_1.fbx
M20240925_1824_251217_2.fbx
M20240925_1824_251217_3.fbx
M20240925_1824_251217_4.fbx
M20240925_1824_251217_5.fbx  ← Latest (highest take)
```

## User Workflow

1. **User loads zinnen.html**
   - Videos with `has_mocap = 1` display the cyan "Bewerk Motion Capture" button
   - Videos without mocap show no button

2. **User clicks "Bewerk Motion Capture"**
   - JavaScript fetches latest FBX file from API
   - Page redirects to: `3DAnn1.html?glb=/gebarenoverleg_media/fbx/{filename}.fbx`

3. **User edits in 3D annotation editor**
   - 3DAnn1.html loads the FBX file
   - User can edit motion capture annotations
   - User can return to zinnen.html via "Go back to Zinnen" button

## Technical Details

### Button Visibility Logic
- **Database-driven:** Only checks `has_mocap` field during page load
- **Performance:** No API calls until button is clicked
- **Trade-off:** Button may appear even if FBX file was manually deleted from disk

### File Selection Logic
- Uses glob pattern matching: `{base}_*_*.fbx`
- Extracts take numbers using regex: `/_(\d+)\.fbx$/`
- Returns file with maximum take number
- Handles both `.wav` and `.mp4` video extensions (case-insensitive)

### Error Handling
- Missing FBX file → Alert: "No motion capture file found for this video."
- Network error → Alert: "Error loading motion capture data."
- Invalid filename → Returns `hasMocap: false`

## Testing

### Manual Test Cases

#### Test 1: Button Visibility
- Find a video with `has_mocap = 1`
- Verify cyan "Bewerk Motion Capture" button appears
- Find a video with `has_mocap = 0`
- Verify button does NOT appear

#### Test 2: API Endpoint
```bash
curl "https://leffe.science.uva.nl:8043/zin/getZinnen.php?action=getLatestMocapFile&mFile=M20240925_1824.wav"
```
Expected response:
```json
{"success":true,"hasMocap":true,"fbxFilename":"M20240925_1824_251217_5.fbx","takeNumber":5}
```

#### Test 3: Button Click
- Click "Bewerk Motion Capture" button
- Verify redirect to: `3DAnn1.html?glb=/gebarenoverleg_media/fbx/M20240925_1824_251217_5.fbx`
- Verify 3DAnn1.html loads the FBX file

#### Test 4: Multiple Takes
- Video with takes 0-5 → Should select take 5
- Video with only take 0 → Should select take 0

#### Test 5: Edge Cases
- Video with no FBX files → Alert shown
- `.wav` extension → Works correctly
- `.mp4` extension → Works correctly
- Uppercase extensions → Works correctly

### Automated Tests (PHP)
```php
// Test filename parsing
$tests = [
    'M20240925_1824.wav' => 'M20240925_1824',
    'M20240925_1824.mp4' => 'M20240925_1824',
    'M20240925_1824.WAV' => 'M20240925_1824',
];

foreach ($tests as $input => $expected) {
    $result = preg_replace('/\.(wav|mp4)$/i', '', $input);
    assert($result === $expected);
}
```

## Backup Files
Created backup before modification:
```
/web/zin/zinnen.html.backup_YYYYMMDD_HHMMSS
```

## Rollback Instructions
If issues occur:
```bash
# Restore from backup
cp /web/zin/zinnen.html.backup_* /web/zin/zinnen.html

# Comment out the new API action in getZinnen.php
# Find lines 134-136 and comment them out:
# case 'getLatestMocapFile':
#     getLatestMocapFile($conn);
#     break;

# Clear browser cache
```

## Security Considerations

### Path Traversal Prevention
- Uses `basename()` to strip directory components
- Validates m_file parameter exists
- Only allows `.fbx` extension in glob pattern

### Input Validation
- Filename regex: `/\.(wav|mp4)$/i`
- Take number regex: `/_(\d+)\.fbx$/`
- No user input in file paths (uses fixed directory)

### Access Control
- No authentication required (consistent with existing system)
- FBX files served from standard web directory
- Relies on database `has_mocap` flag for visibility

## Performance

### Page Load
- **No additional overhead** during initial page load
- Button visibility determined by existing database field
- No API calls until button clicked

### Button Click
- **One API call** to get latest FBX file
- Response time: < 100ms (glob + regex on ~6 files)
- Minimal server load

## Future Enhancements

### Potential Improvements
1. **Caching:** Cache FBX file lookups for frequently accessed videos
2. **Preview:** Show motion capture preview thumbnail
3. **Take Selection:** UI to choose specific take number
4. **Validation:** Periodic sync of `has_mocap` field with actual files
5. **Loading State:** Show loading spinner during API call

### Database Optimization
Consider adding index:
```sql
CREATE INDEX idx_has_mocap ON matched_transcriptions(has_mocap, m_transcription);
```

## Support

### Common Issues

**Issue:** Button appears but clicking shows "No motion capture file found"
- **Cause:** FBX file was manually deleted from disk
- **Solution:** Update `has_mocap = 0` in database or restore FBX file

**Issue:** Button doesn't appear for video with FBX files
- **Cause:** Database `has_mocap` field not set
- **Solution:** Update database: `UPDATE matched_transcriptions SET has_mocap = 1 WHERE m_file = 'filename.wav'`

**Issue:** Wrong FBX file loaded (not latest take)
- **Cause:** File naming doesn't match expected pattern
- **Solution:** Rename FBX files to follow: `{base}_{date}_{take}.fbx`

## Change Log

### Version 1.0 (2026-01-21)
- Initial implementation
- Database-driven button visibility
- Dynamic FBX file selection
- Integration with 3DAnn1.html

## References
- Plan file: `/home/gomer/.claude/projects/-web-zin/14737144-c362-4e24-896a-80221410d7d8.jsonl`
- 3D Annotation Editor: `/web/zin/3DAnn1.html`
- API Handler: `/web/zin/getZinnen.php`
- FBX Directory: `/web/gebarenoverleg_media/fbx/`
