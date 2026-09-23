#!/usr/bin/env python3
"""
Background worker for ISS WebSocket communication
Usage: python3 iss_worker.py <request_id> <hamer_path> <fps>
"""

import sys
import json
import time
import asyncio
import websockets
from pathlib import Path

def update_progress(progress_file, data):
    """Update progress file"""
    data['updated_at'] = int(time.time())
    with open(progress_file, 'w') as f:
        json.dump(data, f)

async def main():
    if len(sys.argv) < 4:
        print("Usage: python3 iss_worker.py <request_id> <hamer_path> <fps>")
        sys.exit(1)

    request_id = sys.argv[1]
    hamer_path = sys.argv[2]
    fps = int(sys.argv[3])

    # Progress file - use /var/tmp which has better permissions
    progress_dir = Path('/var/tmp/iss_progress')
    progress_dir.mkdir(exist_ok=True, mode=0o777)
    progress_file = progress_dir / f'{request_id}.json'

    try:
        # Update: Connecting
        update_progress(progress_file, {
            'status': 'connecting',
            'percentage': 5,
            'message': 'Connecting to ISS Server...',
            'request_id': request_id
        })

        # Connect to ISS Server
        websocket_url = 'wss://signcollect.nl/ISS_Server/ws'

        async with websockets.connect(websocket_url, ping_interval=20, ping_timeout=10) as websocket:
            # Update: Connected
            update_progress(progress_file, {
                'status': 'connected',
                'percentage': 10,
                'message': 'Connected to ISS Server',
                'request_id': request_id
            })

            # Send request
            request = {
                'type': 'inference_request',
                'request_id': request_id,
                'hamer_path': hamer_path,
                'fps': fps
            }

            await websocket.send(json.dumps(request))

            # Update: Request sent
            update_progress(progress_file, {
                'status': 'request_sent',
                'percentage': 15,
                'message': 'Request sent to ISS Server',
                'request_id': request_id
            })

            # Receive messages
            start_time = time.time()
            max_runtime = 300  # 5 minutes

            while True:
                # Check timeout
                if time.time() - start_time > max_runtime:
                    update_progress(progress_file, {
                        'status': 'error',
                        'percentage': 0,
                        'message': 'Processing timeout after 5 minutes',
                        'request_id': request_id
                    })
                    sys.exit(1)

                try:
                    # Wait for message with timeout
                    message = await asyncio.wait_for(websocket.recv(), timeout=5.0)
                except asyncio.TimeoutError:
                    # No message, continue waiting
                    continue

                data = json.loads(message)
                msg_type = data.get('type', '')

                if msg_type == 'ack':
                    update_progress(progress_file, {
                        'status': 'acknowledged',
                        'percentage': 20,
                        'message': data.get('message', 'Request acknowledged'),
                        'request_id': request_id
                    })

                elif msg_type == 'progress':
                    update_progress(progress_file, {
                        'status': 'progress',
                        'stage': data.get('stage', 'processing'),
                        'percentage': min(95, max(20, data.get('percentage', 50))),
                        'message': data.get('message', 'Processing...'),
                        'request_id': request_id
                    })

                elif msg_type == 'result':
                    update_progress(progress_file, {
                        'status': 'completed',
                        'percentage': 100,
                        'message': 'Segmentation complete!',
                        'request_id': request_id,
                        'result': data
                    })
                    sys.exit(0)

                elif msg_type == 'error':
                    update_progress(progress_file, {
                        'status': 'error',
                        'percentage': 0,
                        'message': data.get('message', 'Segmentation failed'),
                        'request_id': request_id,
                        'error': data
                    })
                    sys.exit(1)

    except Exception as e:
        update_progress(progress_file, {
            'status': 'error',
            'percentage': 0,
            'message': f'Error: {str(e)}',
            'request_id': request_id
        })
        sys.exit(1)

if __name__ == '__main__':
    asyncio.run(main())
