#!/usr/bin/env python3
"""
Download all sentences and videos from the ZIN API
"""

import requests
import json
import os
from pathlib import Path
from datetime import datetime

# Configuration
API_URL = "https://signcollect.nl/zin/getZinnen.php"
OUTPUT_DIR = "downloaded_data"
DOWNLOAD_VIDEOS = False  # Set to True if you want to download actual video files

def fetch_page(page_num):
    """Fetch a single page of sentences"""
    try:
        response = requests.get(API_URL, params={'page': page_num}, timeout=30)
        response.raise_for_status()
        return response.json()
    except requests.exceptions.RequestException as e:
        print(f"Error fetching page {page_num}: {e}")
        return None

def download_video_file(url, output_path):
    """Download a single video file"""
    try:
        response = requests.get(url, stream=True, timeout=60)
        response.raise_for_status()

        with open(output_path, 'wb') as f:
            for chunk in response.iter_content(chunk_size=8192):
                f.write(chunk)
        return True
    except Exception as e:
        print(f"Error downloading {url}: {e}")
        return False

def main():
    # Create output directory
    Path(OUTPUT_DIR).mkdir(exist_ok=True)

    print("Fetching page 1 to get total pages...")
    first_page = fetch_page(1)

    if not first_page or not first_page.get('success'):
        print("Failed to fetch first page")
        return

    total_pages = first_page['pagination']['totalPages']
    total_count = first_page['pagination']['totalCount']

    print(f"Total pages: {total_pages}")
    print(f"Total sentences: {total_count}")
    print(f"Downloading all data...\n")

    all_sentences = []
    all_sentences.extend(first_page['rows'])

    # Fetch remaining pages
    for page in range(2, total_pages + 1):
        print(f"Fetching page {page}/{total_pages}...")
        page_data = fetch_page(page)

        if page_data and page_data.get('success'):
            all_sentences.extend(page_data['rows'])
        else:
            print(f"Warning: Failed to fetch page {page}")

    print(f"\nTotal sentences downloaded: {len(all_sentences)}")

    # Count videos
    video_count = sum(len(s.get('videos', [])) for s in all_sentences)
    print(f"Total videos: {video_count}")

    # Save all data to JSON file
    timestamp = datetime.now().strftime('%Y%m%d_%H%M%S')
    json_filename = f"{OUTPUT_DIR}/all_sentences_{timestamp}.json"

    with open(json_filename, 'w', encoding='utf-8') as f:
        json.dump({
            'downloaded_at': datetime.now().isoformat(),
            'total_sentences': len(all_sentences),
            'total_videos': video_count,
            'sentences': all_sentences
        }, f, ensure_ascii=False, indent=2)

    print(f"\nAll data saved to: {json_filename}")

    # Create a CSV summary
    csv_filename = f"{OUTPUT_DIR}/sentences_summary_{timestamp}.csv"
    with open(csv_filename, 'w', encoding='utf-8') as f:
        f.write("ID,ZinID,Thema,VideoCount,Status,StatusVideo,StatusAnnotatie,StatusGlos\n")
        for s in all_sentences:
            zin_array = s.get('zinArray', [])
            zin_text = ' '.join(zin_array) if isinstance(zin_array, list) else str(zin_array)
            f.write(f"{s.get('ID', '')},{s.get('zinID', '')},"
                   f"\"{s.get('thema', '')}\",{s.get('video_count', 0)},"
                   f"{s.get('status', '')},{s.get('status_video', '')},"
                   f"{s.get('status_annotatie', '')},{s.get('status_glos', '')}\n")

    print(f"Summary CSV saved to: {csv_filename}")

    # Create video list
    video_list_filename = f"{OUTPUT_DIR}/video_list_{timestamp}.txt"
    with open(video_list_filename, 'w', encoding='utf-8') as f:
        for s in all_sentences:
            for video in s.get('videos', []):
                m_file = video.get('m_file', '')
                if m_file:
                    # Construct the likely video URL (adjust if needed)
                    f.write(f"{m_file}\n")

    print(f"Video list saved to: {video_list_filename}")

    # Optionally download actual video files
    if DOWNLOAD_VIDEOS:
        print("\nDownloading video files...")
        video_dir = f"{OUTPUT_DIR}/videos"
        Path(video_dir).mkdir(exist_ok=True)

        downloaded = 0
        for i, s in enumerate(all_sentences):
            for video in s.get('videos', []):
                m_file = video.get('m_file', '')
                if m_file:
                    # You'll need to construct the actual video URL
                    # This depends on your server structure
                    video_url = f"https://signcollect.nl/zin/videos/{m_file}"
                    output_path = f"{video_dir}/{m_file}"

                    if not os.path.exists(output_path):
                        print(f"Downloading: {m_file}")
                        if download_video_file(video_url, output_path):
                            downloaded += 1

        print(f"\nDownloaded {downloaded} video files to {video_dir}")

    print("\n✓ Done!")

if __name__ == "__main__":
    main()
