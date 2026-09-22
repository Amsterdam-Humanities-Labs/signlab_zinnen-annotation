import os
import shutil
import re
import sys
from sc_paths import sc_path
# The heartbeat client. Prefer the installed signlab-client-monitor package,
# and fall back to the copy in pythonCron's checkout - which is what this line
# has always done, and what still happens on any host where the package has
# not been installed.
try:
    from signlab_client_monitor import ClientMonitor
except ImportError:
    sys.path.insert(0, '/home/gomer/pythonCron')
    from python_client import ClientMonitor

# Initialize Client Monitor
monitor = ClientMonitor(
    api_url="https://signcollect.nl/client_monitor_api/api.php",
    client_id="copy-ab-files",
    client_name="Copy AB Files",
    description="Copies MP4 files from AB folders to destination directory",
    heartbeat_interval=21600  # 360 minutes (6 hours)
)

SOURCE_ROOT = sc_path("media", "studioFiles")
DEST_DIR = sc_path("media_post")
os.makedirs(DEST_DIR, exist_ok=True)

try:
    files_copied = 0
    for root, dirs, files in os.walk(SOURCE_ROOT):
        if "AB" in dirs:
            ab_folder = os.path.join(root, "AB")
            for filename in os.listdir(ab_folder):
                print(ab_folder)
                if filename.upper().endswith(".MP4"):
                    src = os.path.join(ab_folder, filename)
                    new_name = re.sub(r'_p(?=\.MP4$)', '', filename, flags=re.IGNORECASE)
                    # Force file extension to lowercase
                    if new_name.upper().endswith(".MP4"):
                        new_name = new_name[:-4] + ".mp4"
                    dst = os.path.join(DEST_DIR, new_name)
                    # Already copied and complete -> skip. Without this the job
                    # re-copies every file on every run and can never finish.
                    if os.path.exists(dst) and os.path.getsize(dst) == os.path.getsize(src):
                        continue
                    # copy2() also copies timestamps/mode, which the destination
                    # mount rejects with EPERM. Copy the data, then apply the
                    # metadata on a best-effort basis.
                    shutil.copyfile(src, dst)
                    try:
                        shutil.copystat(src, dst)
                    except OSError:
                        pass
                    print(f"Copied {src} -> {dst}")
                    files_copied += 1
        # Prevent recursion into the post folder
        if "post" in dirs:
            dirs.remove("post")

    # Send success heartbeat with files copied stats
    monitor.send_heartbeat_with_stats(
        status="success",
        message=f"AB file copy completed. Files copied: {files_copied}",
        stats={
            "files_copied": files_copied
        }
    )

except Exception as e:
    # Send error heartbeat
    monitor.send_heartbeat_with_stats(
        status="error",
        message=f"AB file copy failed: {str(e)}",
        stats={"error_type": type(e).__name__}
    )
    raise  # Re-raise to maintain existing error behavior
