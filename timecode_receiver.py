#!/usr/bin/env python3

"""
ZIN Video Timecode Receiver

This Python script connects to the ZIN WebSocket server and receives
real-time timecode updates from subBeta5.html.

Usage:
    python timecode_receiver.py

Environment Variables:
    WS_HOST - WebSocket server host (default: localhost)
    WS_PORT - WebSocket server port (default: 8766)

Example:
    # Start the receiver
    python timecode_receiver.py

    # Or with custom host/port
    WS_HOST=192.168.1.100 WS_PORT=8766 python timecode_receiver.py
"""

import asyncio
import json
import signal
import sys
from datetime import datetime
from typing import Optional
import os

try:
    import websockets
except ImportError:
    print("Error: websockets library not found.")
    print("Install with: pip install websockets")
    sys.exit(1)

# Configuration
WS_HOST = os.getenv('WS_HOST', 'localhost')
WS_PORT = os.getenv('WS_PORT', '8766')
WS_URL = f'ws://{WS_HOST}:{WS_PORT}'

# Global state
websocket_connection: Optional[object] = None
should_reconnect = True
current_video_info = {}


def log(message: str, level: str = 'INFO'):
    """Log a message with timestamp"""
    timestamp = datetime.now().strftime('%Y-%m-%d %H:%M:%S.%f')[:-3]
    print(f'[{timestamp}] [{level}] {message}')


async def handle_timecode_message(data: dict):
    """
    Handle timecode update messages

    Customize this function to process timecode data for your application.
    """
    current_time = data.get('currentTime', 0)
    duration = data.get('duration', 0)
    fps = data.get('fps', 60)
    filename = data.get('filename', 'unknown')

    # Example: Print timecode in HH:MM:SS.mmm format
    hours = int(current_time // 3600)
    minutes = int((current_time % 3600) // 60)
    seconds = int(current_time % 60)
    milliseconds = int((current_time % 1) * 1000)

    timecode_str = f'{hours:02d}:{minutes:02d}:{seconds:02d}.{milliseconds:03d}'

    # Print on same line (overwrite)
    print(f'\r[TIMECODE] {filename} | {timecode_str} / {duration:.2f}s @ {fps}fps', end='', flush=True)

    # ===== YOUR CUSTOM CODE HERE =====
    # Example: Send timecode to another application
    # await send_to_your_application(current_time, filename)

    # Example: Trigger actions at specific times
    # if current_time >= 5.0 and current_time < 5.1:
    #     print('\n[ACTION] Reached 5 seconds!')

    # Example: Save timecode to file
    # with open('timecode_log.txt', 'a') as f:
    #     f.write(f'{timecode_str},{filename}\n')


async def handle_video_info_message(data: dict):
    """Handle video information messages"""
    global current_video_info

    current_video_info = {
        'filename': data.get('filename', 'unknown'),
        'duration': data.get('duration', 0),
        'fps': data.get('fps', 60)
    }

    print()  # New line after timecode updates
    log(f"Video Info: {current_video_info['filename']}")
    log(f"  Duration: {current_video_info['duration']:.2f}s")
    log(f"  FPS: {current_video_info['fps']}")


async def handle_message(message: str):
    """Handle incoming WebSocket messages"""
    try:
        data = json.loads(message)
        message_type = data.get('type', 'unknown')

        if message_type == 'connection':
            log(f"Server: {data.get('message', '')}")

        elif message_type == 'registered':
            log(f"Registered as: {data.get('clientType', 'unknown')}")

        elif message_type == 'timecode':
            await handle_timecode_message(data)

        elif message_type == 'video_info':
            await handle_video_info_message(data)

        elif message_type == 'pong':
            pass  # Ignore pong messages (heartbeat)

        else:
            log(f"Unknown message type: {message_type}", 'WARN')

    except json.JSONDecodeError as e:
        log(f"Error parsing JSON: {e}", 'ERROR')
    except Exception as e:
        log(f"Error handling message: {e}", 'ERROR')


async def send_register_message(websocket):
    """Register this client as a Python receiver"""
    register_msg = json.dumps({
        'type': 'register',
        'clientType': 'python'
    })
    await websocket.send(register_msg)
    log('Sent registration message')


async def websocket_client():
    """Main WebSocket client loop"""
    global websocket_connection

    retry_delay = 3  # seconds

    while should_reconnect:
        try:
            log(f'Connecting to WebSocket server: {WS_URL}')

            async with websockets.connect(WS_URL) as websocket:
                websocket_connection = websocket
                log('Connected successfully!')

                # Register as Python client
                await send_register_message(websocket)

                # Listen for messages
                async for message in websocket:
                    await handle_message(message)

        except websockets.exceptions.ConnectionClosed:
            log('Connection closed by server', 'WARN')
            websocket_connection = None

        except ConnectionRefusedError:
            log(f'Connection refused. Is the server running on {WS_URL}?', 'ERROR')
            websocket_connection = None

        except Exception as e:
            log(f'Connection error: {e}', 'ERROR')
            websocket_connection = None

        if should_reconnect:
            log(f'Reconnecting in {retry_delay} seconds...')
            await asyncio.sleep(retry_delay)


def signal_handler(sig, frame):
    """Handle Ctrl+C gracefully"""
    global should_reconnect
    print()  # New line
    log('Shutting down...', 'INFO')
    should_reconnect = False
    sys.exit(0)


async def main():
    """Main entry point"""
    global should_reconnect

    print('=' * 60)
    print('ZIN Video Timecode Receiver')
    print('=' * 60)
    log(f'Server URL: {WS_URL}')
    log('Press Ctrl+C to exit')
    print('=' * 60)

    # Register signal handler for graceful shutdown
    signal.signal(signal.SIGINT, signal_handler)
    signal.signal(signal.SIGTERM, signal_handler)

    try:
        await websocket_client()
    except KeyboardInterrupt:
        should_reconnect = False
        log('Interrupted by user', 'INFO')
    finally:
        if websocket_connection:
            await websocket_connection.close()
        log('Disconnected', 'INFO')


if __name__ == '__main__':
    try:
        asyncio.run(main())
    except KeyboardInterrupt:
        log('Exiting...', 'INFO')
        sys.exit(0)
