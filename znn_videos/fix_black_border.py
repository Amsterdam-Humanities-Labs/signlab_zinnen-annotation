#!/usr/bin/env python3
"""
Fix black left-edge borders on sign language videos and thumbnails.

Black strips (2-78px) on the left edge are painted over with the blue
studio background color, sampled from each file's top-right corner.

Usage:
    python3 fix_black_border.py --test   # Test on R20251209_1983 only
    python3 fix_black_border.py          # Process all 56 files
"""

import argparse
import json
import os
import shutil
import subprocess
import sys
from pathlib import Path

import numpy as np
from PIL import Image

BASE_PATH = Path("/web/gebarenoverleg_media/studioFilesMini/post")
BACKUP_DIR = BASE_PATH / "backup_borders"
ISSUES_JSON = Path("/web/zin/znn_videos/black_border_issues.json")
TEST_OUTPUT = Path("/web/zin/znn_videos/test_output")
TEST_FILE = "R20251209_1983"
FFMPEG = "/usr/bin/ffmpeg"
SAMPLE_SIZE = 20  # pixels to sample from top-right corner
BLACK_THRESHOLD = 20  # max brightness to consider a pixel "black"
BORDER_PAD = 2  # extra pixels beyond measured max to ensure full coverage


def measure_black_border(img_path: Path) -> int:
    """Scan every row to find the maximum black border width from the left edge.

    The border can be tapered (wider at top, narrower at bottom or vice versa).
    Returns the max width across all rows + BORDER_PAD safety margin.
    """
    img = Image.open(img_path)
    arr = np.array(img)
    brightness = arr.mean(axis=2)  # average RGB per pixel

    max_w = 0
    for row in brightness:
        w = 0
        for px in row:
            if px < BLACK_THRESHOLD:
                w += 1
            else:
                break
        max_w = max(max_w, w)

    return max_w + BORDER_PAD


def sample_color_from_image(img_path: Path) -> tuple[int, int, int]:
    """Sample background color from top-right corner of an image."""
    img = Image.open(img_path)
    w, h = img.size
    # Top-right patch: SAMPLE_SIZE x SAMPLE_SIZE
    x0 = max(0, w - SAMPLE_SIZE)
    y0 = 0
    x1 = w
    y1 = min(h, SAMPLE_SIZE)
    patch = np.array(img.crop((x0, y0, x1, y1)))
    # Median per channel
    r = int(np.median(patch[:, :, 0]))
    g = int(np.median(patch[:, :, 1]))
    b = int(np.median(patch[:, :, 2]))
    return (r, g, b)


def rgb_to_hex(r: int, g: int, b: int) -> str:
    return f"{r:02x}{g:02x}{b:02x}"


def fix_thumbnail(jpg_path: Path, output_path: Path, border_w: int) -> tuple[int, int, int, int]:
    """Paint over left border on thumbnail.

    Measures actual max black border width by scanning all rows, then fills.
    Returns (r, g, b, actual_border_width).
    """
    actual_w = measure_black_border(jpg_path)
    fill_w = max(actual_w, border_w)  # use whichever is larger

    img = Image.open(jpg_path)
    r, g, b = sample_color_from_image(jpg_path)

    from PIL import ImageDraw
    draw = ImageDraw.Draw(img)
    draw.rectangle([0, 0, fill_w - 1, img.height - 1], fill=(r, g, b))

    img.save(output_path, "JPEG", quality=95)
    return (r, g, b, fill_w)


def fix_video(mp4_path: Path, output_path: Path, border_w: int, color_hex: str) -> bool:
    """Paint over left border on video using ffmpeg drawbox filter."""
    cmd = [
        FFMPEG, "-y", "-i", str(mp4_path),
        "-vf", f"drawbox=x=0:y=0:w={border_w}:h=ih:color=0x{color_hex}:t=fill",
        "-c:v", "libx264", "-crf", "18", "-preset", "medium",
        "-c:a", "copy",
        str(output_path),
    ]
    print(f"  ffmpeg cmd: {' '.join(cmd)}")
    result = subprocess.run(cmd, capture_output=True, text=True)
    if result.returncode != 0:
        print(f"  ERROR: ffmpeg failed:\n{result.stderr[-500:]}")
        return False
    return True


def get_file_size(path: Path) -> str:
    if not path.exists():
        return "N/A"
    size = path.stat().st_size
    if size > 1_000_000:
        return f"{size / 1_000_000:.1f}MB"
    return f"{size / 1_000:.1f}KB"


def load_issues() -> list[dict]:
    with open(ISSUES_JSON) as f:
        data = json.load(f)
    return data["issues"]


def deduplicate_issues(issues: list[dict]) -> dict[str, int]:
    """Return {base_filename: border_width} with deduplication."""
    result = {}
    for issue in issues:
        fn = issue["filename"]
        base = fn.rsplit(".", 1)[0]  # e.g. R20251209_1983
        bw = issue["border_width_px"]
        iw = issue.get("image_width", 9999)

        # Skip entries where border covers entire width (corrupt/tiny files)
        if bw >= iw:
            print(f"  SKIP {fn}: border_width ({bw}) >= image_width ({iw})")
            continue

        # If we've seen this base before, take the larger border width
        if base in result:
            result[base] = max(result[base], bw)
        else:
            result[base] = bw
    return result


def run_test():
    """Process only the test file, output to test_output/ directory."""
    print(f"=== TEST MODE: {TEST_FILE} ===\n")
    TEST_OUTPUT.mkdir(exist_ok=True)

    jpg_src = BASE_PATH / f"{TEST_FILE}.jpg"
    mp4_src = BASE_PATH / f"{TEST_FILE}.mp4"
    jpg_dst = TEST_OUTPUT / f"{TEST_FILE}.jpg"
    mp4_dst = TEST_OUTPUT / f"{TEST_FILE}.mp4"

    # Find JSON border width for reference
    issues = load_issues()
    json_border_w = None
    for issue in issues:
        if issue["filename"].startswith(TEST_FILE):
            json_border_w = issue["border_width_px"]
            break

    # Measure actual max border by scanning all rows
    measured_w = measure_black_border(jpg_src)
    border_w = max(measured_w, json_border_w or 0)

    print(f"JSON border width: {json_border_w}px")
    print(f"Measured max border (all rows + {BORDER_PAD}px pad): {measured_w}px")
    print(f"Using: {border_w}px")
    print(f"Source jpg: {jpg_src} ({get_file_size(jpg_src)})")
    print(f"Source mp4: {mp4_src} ({get_file_size(mp4_src)})")

    # Fix thumbnail first (also gives us the sampled color)
    print(f"\n--- Fixing thumbnail ---")
    r, g, b, fill_w = fix_thumbnail(jpg_src, jpg_dst, border_w)
    hex_color = rgb_to_hex(r, g, b)
    print(f"Sampled color: RGB({r},{g},{b}) = 0x{hex_color}")
    print(f"Fill width used: {fill_w}px")
    print(f"Output: {jpg_dst} ({get_file_size(jpg_dst)})")

    # Fix video
    print(f"\n--- Fixing video ---")
    ok = fix_video(mp4_src, mp4_dst, fill_w, hex_color)
    if ok:
        print(f"Output: {mp4_dst} ({get_file_size(mp4_dst)})")

        # Verify dimensions
        probe = subprocess.run(
            [FFMPEG, "-i", str(mp4_dst)],
            capture_output=True, text=True,
        )
        for line in probe.stderr.split("\n"):
            if "Video:" in line:
                print(f"  Output video info: {line.strip()}")
                break
    else:
        print("Video fix FAILED")

    print(f"\n=== Test complete. Inspect files in {TEST_OUTPUT}/ ===")


def run_full():
    """Process all files from the issues JSON."""
    print("=== FULL RUN: Processing all affected files ===\n")

    issues = load_issues()
    file_map = deduplicate_issues(issues)
    total = len(file_map)
    print(f"Files to process: {total}\n")

    if total == 0:
        print("Nothing to process.")
        return

    # Create backup directory
    BACKUP_DIR.mkdir(parents=True, exist_ok=True)
    print(f"Backup directory: {BACKUP_DIR}\n")

    success = 0
    failed = 0
    skipped = 0

    for i, (base, border_w) in enumerate(sorted(file_map.items()), 1):
        print(f"[{i}/{total}] {base} (border: {border_w}px)")

        jpg_path = BASE_PATH / f"{base}.jpg"
        mp4_path = BASE_PATH / f"{base}.mp4"

        # Check files exist
        if not jpg_path.exists():
            print(f"  SKIP: {jpg_path} not found")
            skipped += 1
            continue
        if not mp4_path.exists():
            print(f"  SKIP: {mp4_path} not found")
            skipped += 1
            continue

        # Backup originals
        backup_jpg = BACKUP_DIR / f"{base}.jpg"
        backup_mp4 = BACKUP_DIR / f"{base}.mp4"
        if not backup_jpg.exists():
            shutil.copy2(jpg_path, backup_jpg)
        if not backup_mp4.exists():
            shutil.copy2(mp4_path, backup_mp4)

        # Measure actual border from thumbnail
        measured_w = measure_black_border(jpg_path)
        actual_w = max(measured_w, border_w)

        # Fix thumbnail (sample color from it)
        try:
            r, g, b, fill_w = fix_thumbnail(jpg_path, jpg_path, actual_w)
            hex_color = rgb_to_hex(r, g, b)
            print(f"  Measured: {measured_w}px, JSON: {border_w}px, fill: {fill_w}px | Color: RGB({r},{g},{b}) | JPG fixed")
        except Exception as e:
            print(f"  ERROR fixing thumbnail: {e}")
            failed += 1
            continue

        # Fix video to temp file, then replace
        tmp_mp4 = BASE_PATH / f"{base}_fixed.mp4"
        ok = fix_video(mp4_path, tmp_mp4, fill_w, hex_color)
        if ok:
            # Verify dimensions match
            orig_size = mp4_path.stat().st_size
            new_size = tmp_mp4.stat().st_size
            # Replace original
            tmp_mp4.replace(mp4_path)
            print(f"  MP4 fixed ({orig_size / 1_000_000:.1f}MB → {new_size / 1_000_000:.1f}MB)")
            success += 1
        else:
            # Clean up temp file on failure
            if tmp_mp4.exists():
                tmp_mp4.unlink()
            failed += 1

    print(f"\n=== DONE: {success} fixed, {failed} failed, {skipped} skipped ===")


def main():
    parser = argparse.ArgumentParser(description="Fix black left borders on videos/thumbnails")
    parser.add_argument("--test", action="store_true", help="Test mode: process only R20251209_1983")
    args = parser.parse_args()

    if args.test:
        run_test()
    else:
        run_full()


if __name__ == "__main__":
    main()
