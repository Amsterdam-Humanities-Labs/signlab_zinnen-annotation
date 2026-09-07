#!/bin/bash
# Wrapper script to run Python worker with correct PYTHONPATH

# Install root. Same resolution the PHP side does, minus the parts bash
# has no way to reach: SC_WEB_ROOT from the environment, else /web.
SC_WEB_ROOT=${SC_WEB_ROOT:-/web}
export PYTHONPATH=/home/gomer/.local/lib/python3.12/site-packages:$PYTHONPATH
exec python3 "$SC_WEB_ROOT/zin/iss_client/iss_worker.py" "$@"
