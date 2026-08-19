#!/usr/bin/env python3
"""
Video Dimension Checker for ZIN Project.

Checks all sign language videos for conformance to 1:1.15 (width:height) aspect ratio.
Uses ffprobe to read dimensions from local video files, parallelized with ThreadPoolExecutor.

Usage: python3 check_dimensions.py
Output: dimension_issues.json
"""

import json
import subprocess
import sys
import os
from concurrent.futures import ThreadPoolExecutor, as_completed
from datetime import datetime, timezone

TARGET_RATIO = 1.15  # width / height (landscape: width is 1.15x height)
TOLERANCE = 0.02
MAX_WORKERS = 8
SCRIPT_DIR = os.path.dirname(os.path.abspath(__file__))
OUTPUT_FILE = os.path.join(SCRIPT_DIR, "dimension_issues.json")
ALL_VIDEOS_FILE = os.path.join(SCRIPT_DIR, "all_videos.json")
FETCH_PHP = os.path.join(SCRIPT_DIR, "fetch_videos.php")


def fetch_video_list():
    """Run fetch_videos.php and return parsed JSON."""
    result = subprocess.run(
        ["php", FETCH_PHP],
        capture_output=True, text=True, timeout=30
    )
    if result.returncode != 0:
        print(f"ERROR: fetch_videos.php failed:\n{result.stderr}", file=sys.stderr)
        sys.exit(1)
    return json.loads(result.stdout)


def get_dimensions(local_path):
    """Use ffprobe to get width and height of a local video file."""
    if not os.path.exists(local_path):
        return None, None, "file not found"
    try:
        result = subprocess.run(
            [
                "ffprobe", "-v", "error",
                "-select_streams", "v:0",
                "-show_entries", "stream=width,height",
                "-of", "json",
                local_path
            ],
            capture_output=True, text=True, timeout=30
        )
        if result.returncode != 0:
            return None, None, f"ffprobe error: {result.stderr.strip()}"
        data = json.loads(result.stdout)
        streams = data.get("streams", [])
        if not streams:
            return None, None, "No video stream found"
        w = streams[0].get("width")
        h = streams[0].get("height")
        return w, h, None
    except subprocess.TimeoutExpired:
        return None, None, "timeout"
    except Exception as e:
        return None, None, str(e)


def check_video(task):
    """Check a single video. Returns result dict with conformance status."""
    sentence_id, zin_string, camera, local_path, web_url, thumbnail_url = task
    w, h, err = get_dimensions(local_path)

    filename = os.path.basename(local_path)
    result = {
        "sentence_id": sentence_id,
        "zinString": zin_string,
        "camera": camera,
        "video_url": web_url,
        "thumbnail_url": thumbnail_url,
        "filename": filename,
        "width": None,
        "height": None,
        "actual_ratio": None,
        "expected_ratio": TARGET_RATIO,
    }

    if err:
        result["error"] = err
        result["status"] = "error"
        return result

    actual_ratio = round(w / h, 4) if h else None
    result["width"] = w
    result["height"] = h
    result["actual_ratio"] = actual_ratio

    if actual_ratio is not None and abs(actual_ratio - TARGET_RATIO) <= TOLERANCE:
        result["status"] = "ok"
    else:
        result["status"] = "issue"

    return result


def main():
    print("Fetching video list from database...", flush=True)
    sentences = fetch_video_list()
    print(f"Found {len(sentences)} sentences with videos.", flush=True)

    # Build task list: (sentence_id, zinString, camera, local_path, web_url, thumbnail_url)
    tasks = []
    for s in sentences:
        for camera, key in [("left", "left"), ("center", "center"), ("right", "right")]:
            local_path = s["videos"][key]
            web_url = s["web_urls"][key]
            thumb = s["thumbnails"][key]
            tasks.append((s["sentence_id"], s["zinString"], camera, local_path, web_url, thumb))

    total = len(tasks)
    print(f"Checking {total} videos with {MAX_WORKERS} workers...", flush=True)

    all_results = []
    checked = 0

    with ThreadPoolExecutor(max_workers=MAX_WORKERS) as executor:
        futures = {executor.submit(check_video, t): t for t in tasks}
        for future in as_completed(futures):
            checked += 1
            if checked % 100 == 0 or checked == total:
                print(f"  Progress: {checked}/{total} ({100*checked//total}%)", flush=True)
            all_results.append(future.result())

    # Separate issues and errors for dimension_issues.json
    camera_order = {"left": 0, "center": 1, "right": 2}
    issues = [r for r in all_results if r["status"] == "issue"]
    errors = [r for r in all_results if r["status"] == "error"]
    issues.sort(key=lambda x: (x["sentence_id"], camera_order.get(x["camera"], 9)))
    errors.sort(key=lambda x: (x["sentence_id"], camera_order.get(x["camera"], 9)))

    # Write dimension_issues.json (non-conforming only)
    issues_output = {
        "generated_at": datetime.now(timezone.utc).isoformat(),
        "target_ratio": TARGET_RATIO,
        "tolerance": TOLERANCE,
        "total_videos_checked": total,
        "total_issues": len(issues),
        "total_errors": len(errors),
        "issues": issues,
        "errors": errors,
    }
    with open(OUTPUT_FILE, "w", encoding="utf-8") as f:
        json.dump(issues_output, f, indent=2, ensure_ascii=False)

    # Build all_videos.json grouped by sentence
    by_sentence = {}
    for r in all_results:
        sid = r["sentence_id"]
        if sid not in by_sentence:
            by_sentence[sid] = {
                "sentence_id": sid,
                "zinString": r["zinString"],
                "left": None, "center": None, "right": None,
            }
        cam_data = {
            "filename": r["filename"],
            "video_url": r["video_url"],
            "thumbnail_url": r["thumbnail_url"],
            "width": r["width"],
            "height": r["height"],
            "ratio": r["actual_ratio"],
            "status": r["status"],
        }
        if "error" in r:
            cam_data["error"] = r["error"]
        by_sentence[sid][r["camera"]] = cam_data

    sentences_list = sorted(by_sentence.values(), key=lambda x: x["sentence_id"])
    all_output = {
        "generated_at": datetime.now(timezone.utc).isoformat(),
        "target_ratio": TARGET_RATIO,
        "tolerance": TOLERANCE,
        "total_sentences": len(sentences_list),
        "total_videos_checked": total,
        "sentences": sentences_list,
    }
    with open(ALL_VIDEOS_FILE, "w", encoding="utf-8") as f:
        json.dump(all_output, f, indent=2, ensure_ascii=False)

    n_ok = sum(1 for r in all_results if r["status"] == "ok")
    print(f"\nDone! Checked {total} videos.")
    print(f"  Issues (wrong ratio): {len(issues)}")
    print(f"  Errors (ffprobe failed): {len(errors)}")
    print(f"  Conforming: {n_ok}")
    print(f"Results written to: {OUTPUT_FILE}")
    print(f"All videos written to: {ALL_VIDEOS_FILE}")


if __name__ == "__main__":
    main()
