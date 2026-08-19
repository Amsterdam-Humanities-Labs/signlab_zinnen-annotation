#!/usr/bin/env python3
"""
Simple standalone test for ISS Server
Usage: python3 test_iss_simple.py
"""

import asyncio
import websockets
import json
import sys

async def test_iss():
    # Configuration
    websocket_url = 'wss://signcollect.nl/ISS_Server/ws'
    hamer_url = 'https://signcollect.nl/gebarenoverleg_media/studioFilesMini/raw/M20260115_0367.hamer'
    fps = 60
    request_id = f'test-simple-{int(asyncio.get_event_loop().time())}'

    print("=" * 60)
    print("ISS Server Test")
    print("=" * 60)
    print(f"Request ID: {request_id}")
    print(f"HaMeR URL: {hamer_url}")
    print(f"FPS: {fps}")
    print("")

    try:
        print("Connecting to ISS Server...")
        async with websockets.connect(websocket_url, ping_interval=20) as websocket:
            print("✓ Connected!")
            print("")

            # Send inference request
            request = {
                'type': 'inference_request',
                'request_id': request_id,
                'hamer_path': hamer_url,
                'fps': fps
            }

            await websocket.send(json.dumps(request))
            print("✓ Request sent")
            print("")
            print("Waiting for response...")
            print("-" * 60)

            # Receive messages
            while True:
                message = await websocket.recv()
                data = json.loads(message)
                msg_type = data.get('type', 'unknown')

                if msg_type == 'ack':
                    print(f"✓ ACK: {data.get('message', '')}")

                elif msg_type == 'progress':
                    stage = data.get('stage', 'unknown')
                    percentage = data.get('percentage', 0)
                    msg = data.get('message', '')
                    bar = '█' * int(percentage / 5) + '░' * (20 - int(percentage / 5))
                    print(f"\r[{bar}] {percentage:3d}% - {stage}: {msg}", end='', flush=True)

                elif msg_type == 'result':
                    print("\n")
                    print("=" * 60)
                    print("✓ SUCCESS! Result received")
                    print("=" * 60)

                    metadata = data.get('metadata', {})
                    print(f"Segments detected: {metadata.get('segments_detected', 'N/A')}")
                    print(f"Total frames: {metadata.get('total_frames', 'N/A')}")
                    print(f"Duration: {metadata.get('duration_seconds', 'N/A')}s")
                    print(f"Processing time: {metadata.get('processing_time', 'N/A')}s")
                    print("")

                    if 'srt_content' in data:
                        print("SRT Content (first 500 chars):")
                        print("-" * 60)
                        print(data['srt_content'][:500])
                        print("-" * 60)

                    return 0

                elif msg_type == 'error':
                    print("\n")
                    print("=" * 60)
                    print("✗ ERROR")
                    print("=" * 60)
                    print(f"Message: {data.get('message', 'Unknown error')}")
                    if 'details' in data:
                        print(f"Details: {data['details']}")
                    return 1

    except Exception as e:
        print(f"\n✗ Error: {e}")
        return 1

if __name__ == '__main__':
    exit_code = asyncio.run(test_iss())
    sys.exit(exit_code)
