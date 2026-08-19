#!/usr/bin/env python3

"""
ZIN WebSocket Timecode Performance Tester

This script tests the performance and latency of the WebSocket timecode
broadcasting system.

Usage:
    python test_timecode_performance.py

Environment Variables:
    WS_HOST - WebSocket server host (default: localhost)
    WS_PORT - WebSocket server port (default: 8766)
"""

import asyncio
import json
import time
import statistics
import os
import sys
from datetime import datetime
from collections import deque

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

# Performance metrics
message_count = 0
start_time = None
last_message_time = None
latencies = deque(maxlen=100)  # Keep last 100 latencies
intervals = deque(maxlen=100)  # Keep last 100 intervals between messages
last_timecode = None
timecode_diffs = deque(maxlen=100)  # Time differences in video

# Statistics
total_messages = 0
min_interval = float('inf')
max_interval = 0
min_latency = float('inf')
max_latency = 0


def format_timecode(seconds):
    """Format seconds as HH:MM:SS.mmm"""
    hours = int(seconds // 3600)
    minutes = int((seconds % 3600) // 60)
    secs = int(seconds % 60)
    millis = int((seconds % 1) * 1000)
    return f'{hours:02d}:{minutes:02d}:{secs:02d}.{millis:03d}'


def print_stats():
    """Print performance statistics"""
    global message_count, start_time, min_interval, max_interval, min_latency, max_latency

    if message_count == 0 or start_time is None:
        return

    elapsed = time.time() - start_time
    messages_per_sec = message_count / elapsed if elapsed > 0 else 0

    # Calculate statistics
    avg_interval = statistics.mean(intervals) * 1000 if intervals else 0
    avg_latency = statistics.mean(latencies) * 1000 if latencies else 0
    avg_timecode_diff = statistics.mean(timecode_diffs) if timecode_diffs else 0

    # Clear screen and print header
    print("\033[2J\033[H")  # Clear screen and move cursor to top
    print("=" * 80)
    print("ZIN WebSocket Timecode Performance Test")
    print("=" * 80)
    print(f"Server: {WS_URL}")
    print(f"Test Duration: {elapsed:.1f}s")
    print("=" * 80)

    # Connection stats
    print("\n📊 CONNECTION STATISTICS")
    print(f"  Total Messages:     {message_count:,}")
    print(f"  Messages/Second:    {messages_per_sec:.1f} msg/s")
    print(f"  Target Rate:        ~30 msg/s (30fps)")
    print(f"  Rate Efficiency:    {(messages_per_sec/30)*100:.1f}%")

    # Latency stats
    print("\n⚡ LATENCY (Network)")
    if latencies:
        print(f"  Current:            {latencies[-1]*1000:.2f}ms")
        print(f"  Average:            {avg_latency:.2f}ms")
        print(f"  Min:                {min_latency*1000:.2f}ms")
        print(f"  Max:                {max_latency*1000:.2f}ms")
        if len(latencies) > 1:
            stdev = statistics.stdev(latencies) * 1000
            print(f"  Std Dev:            {stdev:.2f}ms")
    else:
        print("  No data yet...")

    # Interval stats (time between messages)
    print("\n📡 MESSAGE INTERVALS")
    if intervals:
        print(f"  Current:            {intervals[-1]*1000:.2f}ms")
        print(f"  Average:            {avg_interval:.2f}ms")
        print(f"  Target:             ~33ms (30fps)")
        print(f"  Min:                {min_interval*1000:.2f}ms")
        print(f"  Max:                {max_interval*1000:.2f}ms")
        if len(intervals) > 1:
            stdev = statistics.stdev(intervals) * 1000
            print(f"  Std Dev:            {stdev:.2f}ms")
            jitter = (stdev / avg_interval * 100) if avg_interval > 0 else 0
            print(f"  Jitter:             {jitter:.1f}%")
    else:
        print("  No data yet...")

    # Video timecode stats
    print("\n🎬 VIDEO TIMECODE")
    if timecode_diffs:
        print(f"  Avg Time Diff:      {avg_timecode_diff*1000:.2f}ms")
        print(f"  Last Timecode:      {format_timecode(last_timecode) if last_timecode else 'N/A'}")

    # Performance rating
    print("\n⭐ PERFORMANCE RATING")
    rating = "EXCELLENT"
    color = "\033[92m"  # Green

    if avg_latency > 100:
        rating = "POOR"
        color = "\033[91m"  # Red
    elif avg_latency > 50:
        rating = "FAIR"
        color = "\033[93m"  # Yellow
    elif avg_latency > 20:
        rating = "GOOD"
        color = "\033[92m"  # Green

    print(f"  {color}{rating}\033[0m")

    if avg_latency < 20:
        print("  ✓ Excellent latency for real-time use")
    elif avg_latency < 50:
        print("  ✓ Good latency for most applications")
    elif avg_latency < 100:
        print("  ⚠ Acceptable but may feel sluggish")
    else:
        print("  ✗ High latency - check network connection")

    print("\n" + "=" * 80)
    print("Press Ctrl+C to exit")
    print("=" * 80)


async def handle_timecode_message(data: dict):
    """Handle timecode messages and calculate performance metrics"""
    global message_count, start_time, last_message_time, last_timecode
    global min_interval, max_interval, min_latency, max_latency

    current_time = time.time()

    # Initialize start time
    if start_time is None:
        start_time = current_time

    # Calculate network latency (time from sender to receiver)
    if 'timestamp' in data:
        latency = current_time - (data['timestamp'] / 1000.0)
        latencies.append(latency)
        min_latency = min(min_latency, latency)
        max_latency = max(max_latency, latency)

    # Calculate interval between messages
    if last_message_time is not None:
        interval = current_time - last_message_time
        intervals.append(interval)
        min_interval = min(min_interval, interval)
        max_interval = max(max_interval, interval)

    # Calculate video timecode differences
    video_time = data.get('currentTime', 0)
    if last_timecode is not None:
        timecode_diff = abs(video_time - last_timecode)
        timecode_diffs.append(timecode_diff)

    last_timecode = video_time
    last_message_time = current_time
    message_count += 1

    # Update display every 10 messages (avoid flickering)
    if message_count % 10 == 0:
        print_stats()


async def handle_message(message: str):
    """Handle incoming WebSocket messages"""
    try:
        data = json.loads(message)
        message_type = data.get('type', 'unknown')

        if message_type == 'timecode':
            await handle_timecode_message(data)
        elif message_type == 'connection':
            print(f"✓ Connected: {data.get('message', '')}")
        elif message_type == 'registered':
            print(f"✓ Registered as: {data.get('clientType', 'unknown')}")

    except json.JSONDecodeError as e:
        print(f"✗ Error parsing JSON: {e}")
    except Exception as e:
        print(f"✗ Error handling message: {e}")


async def send_register_message(websocket):
    """Register this client as a Python receiver"""
    register_msg = json.dumps({
        'type': 'register',
        'clientType': 'python'
    })
    await websocket.send(register_msg)


async def websocket_client():
    """Main WebSocket client loop"""
    retry_delay = 3

    while True:
        try:
            print(f"\n🔌 Connecting to WebSocket server: {WS_URL}")
            print("   Waiting for timecode data...\n")

            async with websockets.connect(WS_URL) as websocket:
                print("✓ Connected successfully!")

                # Register as Python client
                await send_register_message(websocket)

                # Listen for messages
                async for message in websocket:
                    await handle_message(message)

        except websockets.exceptions.ConnectionClosed:
            print(f"\n⚠ Connection closed by server")
            print(f"   Reconnecting in {retry_delay} seconds...")
            await asyncio.sleep(retry_delay)

        except ConnectionRefusedError:
            print(f"\n✗ Connection refused!")
            print(f"   Is the server running on {WS_URL}?")
            print(f"   Retrying in {retry_delay} seconds...")
            await asyncio.sleep(retry_delay)

        except Exception as e:
            print(f"\n✗ Connection error: {e}")
            print(f"   Retrying in {retry_delay} seconds...")
            await asyncio.sleep(retry_delay)


async def main():
    """Main entry point"""
    print("=" * 80)
    print("ZIN WebSocket Timecode Performance Tester")
    print("=" * 80)
    print(f"Server URL: {WS_URL}")
    print("\nThis tool measures:")
    print("  • Network latency (time from server to client)")
    print("  • Message rate (messages per second)")
    print("  • Message intervals (consistency of ~30fps)")
    print("  • Overall performance rating")
    print("\nStarting in 2 seconds...")
    print("=" * 80)

    await asyncio.sleep(2)

    try:
        await websocket_client()
    except KeyboardInterrupt:
        print("\n\n")
        print("=" * 80)
        print("Test stopped by user")
        print("=" * 80)
        if message_count > 0:
            print("\nFinal Statistics:")
            print_stats()


if __name__ == '__main__':
    try:
        asyncio.run(main())
    except KeyboardInterrupt:
        print("\n\nExiting...")
        sys.exit(0)
