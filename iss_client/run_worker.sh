#!/bin/bash
# Wrapper script to run Python worker with correct PYTHONPATH

export PYTHONPATH=/home/gomer/.local/lib/python3.12/site-packages:$PYTHONPATH
exec python3 /web/zin/iss_client/iss_worker.py "$@"
