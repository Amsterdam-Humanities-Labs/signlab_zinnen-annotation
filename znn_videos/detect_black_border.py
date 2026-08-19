#!/usr/bin/env python3
"""
Black Border Detector for ZIN video thumbnails.

Detects pure-black strips on the left edge of JPG thumbnails, which occur
when cropped sign language videos shift past the edge of the raw video.

Usage:
  python3 detect_black_border.py --test   # Test on known samples
  python3 detect_black_border.py          # Full scan of all thumbnails
"""

import json
import subprocess
import sys
import os
from concurrent.futures import ThreadPoolExecutor, as_completed
from datetime import datetime, timezone

try:
    from PIL import Image
    import numpy as np
except ImportError:
    print("ERROR: Requires Pillow and numpy. Install with: pip3 install Pillow numpy", file=sys.stderr)
    sys.exit(1)

THRESHOLD = 5  # Mean pixel value below this = black border
STRIP_WIDTH = 3  # Columns to sample for initial detection (handles JPEG artifacts)
MAX_WORKERS = 8
SCRIPT_DIR = os.path.dirname(os.path.abspath(__file__))
OUTPUT_FILE = os.path.join(SCRIPT_DIR, "black_border_issues.json")
FETCH_PHP = os.path.join(SCRIPT_DIR, "fetch_videos.php")
THUMB_BASE = "/web/gebarenoverleg_media/studioFilesMini/post/"
WEB_BASE = "https://media.signcollect.nl/"

# Known test samples
KNOWN_BAD = os.path.join(THUMB_BASE, "M20250204_4257.jpg")
KNOWN_GOOD = os.path.join(THUMB_BASE, "M20250522_8328.jpg")


def detect_border(image_path):
    """Check a thumbnail for a black left-edge border.

    Returns (border_width_px, image_width, image_height, error_string).
    border_width_px is 0 if no black border detected.
    """
    if not os.path.exists(image_path):
        return 0, None, None, "file not found"
    try:
        img = Image.open(image_path).convert("RGB")
        pixels = np.array(img)
        h, w, _ = pixels.shape

        # Quick check: mean of first STRIP_WIDTH columns
        strip = pixels[:, :STRIP_WIDTH, :]
        if strip.mean() >= THRESHOLD:
            return 0, w, h, None

        # Measure exact border width: scan columns left-to-right
        border_width = 0
        for col in range(w):
            col_mean = pixels[:, col, :].mean()
            if col_mean >= THRESHOLD:
                break
            border_width += 1

        return border_width, w, h, None
    except Exception as e:
        return 0, None, None, str(e)


def run_test():
    """Test detection on known-good and known-bad samples."""
    print("=== Black Border Detection Test ===\n")

    for label, path, expect_border in [
        ("KNOWN-BAD", KNOWN_BAD, True),
        ("KNOWN-GOOD", KNOWN_GOOD, False),
    ]:
        print(f"  {label}: {os.path.basename(path)}")
        border_w, img_w, img_h, err = detect_border(path)
        if err:
            print(f"    ERROR: {err}")
            continue
        print(f"    Image: {img_w}x{img_h}")
        print(f"    Border width: {border_w}px")
        detected = border_w > 0
        status = "PASS" if detected == expect_border else "FAIL"
        print(f"    Result: {status} (detected={detected}, expected={expect_border})")
        print()

    # Verify known-bad has ~45px border
    border_w, _, _, _ = detect_border(KNOWN_BAD)
    if 40 <= border_w <= 50:
        print(f"Border width sanity check: PASS ({border_w}px, expected ~45px)")
    else:
        print(f"Border width sanity check: WARN ({border_w}px, expected ~45px)")


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


def check_thumbnail(task):
    """Check a single thumbnail. Returns result dict or None if OK."""
    sentence_id, zin_string, camera, local_thumb, web_video, web_thumb = task
    filename = os.path.basename(local_thumb)
    border_w, img_w, img_h, err = detect_border(local_thumb)

    if err:
        return {"type": "error", "sentence_id": sentence_id, "filename": filename, "error": err}
    if border_w == 0:
        return None  # No issue

    return {
        "type": "issue",
        "sentence_id": sentence_id,
        "zinString": zin_string,
        "camera": camera,
        "filename": filename,
        "video_url": web_video,
        "thumbnail_url": web_thumb,
        "border_width_px": border_w,
        "image_width": img_w,
        "image_height": img_h,
    }


def run_full_scan():
    """Scan all thumbnails from the database."""
    print("Fetching video list from database...", flush=True)
    sentences = fetch_video_list()
    print(f"Found {len(sentences)} sentences.", flush=True)

    # Build task list: (sentence_id, zinString, camera, local_thumb, web_video, web_thumb)
    tasks = []
    for s in sentences:
        for camera, key in [("left", "left"), ("center", "center"), ("right", "right")]:
            # Derive local thumbnail path from local video path
            local_video = s["videos"][key]
            local_thumb = local_video.replace(".mp4", ".jpg")
            web_video = s["web_urls"][key]
            web_thumb = s["thumbnails"][key]
            tasks.append((s["sentence_id"], s["zinString"], camera, local_thumb, web_video, web_thumb))

    total = len(tasks)
    print(f"Checking {total} thumbnails with {MAX_WORKERS} workers...", flush=True)

    issues = []
    errors = []
    checked = 0

    with ThreadPoolExecutor(max_workers=MAX_WORKERS) as executor:
        futures = {executor.submit(check_thumbnail, t): t for t in tasks}
        for future in as_completed(futures):
            checked += 1
            if checked % 500 == 0 or checked == total:
                print(f"  Progress: {checked}/{total} ({100*checked//total}%)", flush=True)
            result = future.result()
            if result is None:
                continue
            if result["type"] == "error":
                errors.append(result)
            else:
                del result["type"]
                issues.append(result)

    camera_order = {"left": 0, "center": 1, "right": 2}
    issues.sort(key=lambda x: (x["sentence_id"], camera_order.get(x["camera"], 9)))

    output = {
        "generated_at": datetime.now(timezone.utc).isoformat(),
        "threshold": THRESHOLD,
        "total_checked": total,
        "total_issues": len(issues),
        "total_errors": len(errors),
        "issues": issues,
    }
    if errors:
        output["errors"] = errors

    with open(OUTPUT_FILE, "w", encoding="utf-8") as f:
        json.dump(output, f, indent=2, ensure_ascii=False)

    print(f"\nDone! Checked {total} thumbnails.")
    print(f"  Issues (black border): {len(issues)}")
    print(f"  Errors: {len(errors)}")
    print(f"  Clean: {total - len(issues) - len(errors)}")
    print(f"Results written to: {OUTPUT_FILE}")


if __name__ == "__main__":
    if "--test" in sys.argv:
        run_test()
    else:
        run_full_scan()
