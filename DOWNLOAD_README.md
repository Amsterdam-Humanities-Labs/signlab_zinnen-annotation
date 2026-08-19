# Download All Sentences and Videos

Two scripts are available to download all sentences and their associated video metadata from the ZIN API.

## Option 1: Python Script (Recommended)

**Requirements:** Python 3 with `requests` library

### Install dependencies:
```bash
pip3 install requests
```

### Run the script:
```bash
python3 download_all_sentences.py
```

### Features:
- Downloads all sentences from all pages
- Saves complete data as JSON
- Creates a CSV summary
- Generates a video file list
- Optional video file downloading (set `DOWNLOAD_VIDEOS = True` in the script)

### Output files (in `downloaded_data/` folder):
- `all_sentences_YYYYMMDD_HHMMSS.json` - Complete data
- `sentences_summary_YYYYMMDD_HHMMSS.csv` - CSV summary
- `video_list_YYYYMMDD_HHMMSS.txt` - List of all video filenames

---

## Option 2: Bash Script

**Requirements:** `curl` and `jq`

### Install dependencies (Ubuntu/Debian):
```bash
sudo apt-get install curl jq
```

### Run the script:
```bash
./download_all_sentences.sh
```

### Features:
- Downloads all sentences from all pages
- Saves combined JSON file
- Saves individual page files for debugging

### Output files (in `downloaded_data/` folder):
- `all_sentences_YYYYMMDD_HHMMSS.json` - Combined data
- `page_*.json` - Individual page data

---

## API Endpoint

The scripts use: `https://signcollect.nl/zin/getZinnen.php`

### Available parameters:
- `page` - Page number (default: 1, 25 items per page)
- `thema` - Filter by theme
- `search` - Search in sentence text
- `status`, `statusVideo`, `statusAnnotatie`, `statusGlos` - Filter by status
- `searchGloss` - Search in glosses
- `gvg` - Search in gesture-by-gesture annotations

### Example manual API calls:
```bash
# Get page 1
curl "https://signcollect.nl/zin/getZinnen.php?page=1"

# Search for specific text
curl "https://signcollect.nl/zin/getZinnen.php?search=hallo"

# Filter by theme
curl "https://signcollect.nl/zin/getZinnen.php?thema=onderwijs"
```

---

## Notes

- Data is paginated at 25 items per page
- The scripts automatically detect total pages and loop through all of them
- Video file URLs are included in the response (if available)
- Actual video file downloading is optional in the Python script
