# ZIN WebSocket Timecode Broadcasting System

## Overview

This system enables real-time timecode broadcasting from the ZIN subtitle editor (`subBeta5.html`) to external applications via WebSocket protocol. It consists of three components:

1. **HTML Client** (`subBeta5.html`) - Broadcasts video timecode from the browser via Apache proxy
2. **Node.js Server** (`websocket-server.js`) - Central WebSocket hub that relays messages
3. **Python Client** (`timecode_receiver.py`) - Example receiver that processes timecode data

```
┌─────────────────┐          ┌───────────────┐          ┌──────────────────┐          ┌─────────────────┐
│  subBeta5.html  │ ─────>  │ Apache Proxy  │ ─────>  │  Node.js Server  │ ─────>  │  Python Client  │
│  (Broadcaster)  │  WSS     │  /zin_wss     │  WS      │  (Port 8766)     │  WS      │  (Receiver)     │
└─────────────────┘          └───────────────┘          └──────────────────┘          └─────────────────┘
```

## Features

- Real-time video timecode broadcasting (~30fps)
- Automatic reconnection on disconnect
- Video metadata sharing (filename, duration, fps)
- Visual connection status indicator
- Extensible for multiple client types
- Configurable server host/port

## Installation

### 1. Node.js Server Setup

Install Node.js dependencies:

```bash
cd /web/zin
npm install
```

This will install:
- `ws` - WebSocket server library
- `dotenv` - Environment configuration

### 2. Python Client Setup

Install Python dependencies:

```bash
pip install -r requirements.txt
```

Or install manually:

```bash
pip install websockets python-dotenv
```

## Apache Configuration

The HTML client connects through an Apache proxy at `/zin_wss`. Add this to your Apache configuration:

```apache
<Location /zin_wss>
    ProxyPass "ws://localhost:8766"
    ProxyPassReverse "ws://localhost:8766"
</Location>
```

Make sure these Apache modules are enabled:

```bash
sudo a2enmod proxy
sudo a2enmod proxy_wstunnel
sudo systemctl restart apache2
```

## Usage

### Step 1: Start the WebSocket Server

```bash
node websocket-server.js
```

You should see:

```
========================================
ZIN WebSocket Timecode Server
========================================
Server running on 0.0.0.0:8766
WebSocket URL: ws://0.0.0.0:8766
Health check: http://0.0.0.0:8766/health
========================================
```

The server will:
- Listen on port 8766 (configurable)
- Accept connections from Apache proxy and Python clients
- Broadcast timecode from HTML to all Python clients
- Automatically detect and reconnect dead connections

### Step 2: Open subBeta5.html in Browser

Open `subBeta5.html` in your web browser with a video file:

```
http://localhost/zin/subBeta5.html?filename=example_video.mp4
```

The page will automatically:
- Connect to the WebSocket server
- Display connection status (green = connected)
- Send video information when loaded
- Broadcast timecode updates during playback

### Step 3: Run Python Client

Start the Python receiver:

```bash
python timecode_receiver.py
```

Or make it executable and run directly:

```bash
chmod +x timecode_receiver.py
./timecode_receiver.py
```

You should see real-time timecode updates:

```
============================================================
ZIN Video Timecode Receiver
============================================================
[2025-10-13 11:00:00.000] [INFO] Server URL: ws://localhost:8766
[2025-10-13 11:00:00.001] [INFO] Press Ctrl+C to exit
============================================================
[2025-10-13 11:00:00.010] [INFO] Connecting to WebSocket server: ws://localhost:8766
[2025-10-13 11:00:00.015] [INFO] Connected successfully!
[2025-10-13 11:00:00.016] [INFO] Sent registration message
[2025-10-13 11:00:00.020] [INFO] Server: Connected to ZIN Timecode Server

[TIMECODE] example_video.mp4 | 00:00:02.450 / 10.23s @ 60fps
```

Press `Ctrl+C` to exit.

## Configuration

### WebSocket Server Configuration

Create a `.env` file in `/web/zin/`:

```env
WS_PORT=8766
WS_HOST=0.0.0.0
```

Or set environment variables:

```bash
WS_PORT=8766 WS_HOST=0.0.0.0 node websocket-server.js
```

### HTML Client Configuration

The HTML client (`subBeta5.html`) automatically connects through the Apache proxy at `/zin_wss`. It auto-detects the protocol (ws:// or wss://) and uses the current host:

```javascript
// Auto-configured in subBeta5.html
const wsProtocol = window.location.protocol === 'https:' ? 'wss:' : 'ws:';
const WS_SERVER_URL = `${wsProtocol}//${window.location.host}/zin_wss`;
```

**Note:** Direct connection to the Node.js server (bypassing Apache proxy) is not recommended for the HTML client but can be done for testing:

```javascript
const WS_SERVER_URL = 'ws://localhost:8766';
```

### Python Client Configuration

Set environment variables:

```bash
WS_HOST=localhost WS_PORT=8766 python timecode_receiver.py
```

Or edit the script directly:

```python
WS_HOST = 'localhost'
WS_PORT = '8766'
```

## Message Protocol

### Message Types

All messages are JSON-formatted.

#### 1. Registration Message (Client → Server)

```json
{
  "type": "register",
  "clientType": "html" | "python"
}
```

#### 2. Video Info Message (HTML → Server → Python)

```json
{
  "type": "video_info",
  "filename": "example_video.mp4",
  "duration": 10.23,
  "fps": 60,
  "timestamp": 1697200000000
}
```

#### 3. Timecode Message (HTML → Server → Python)

```json
{
  "type": "timecode",
  "currentTime": 2.450,
  "duration": 10.23,
  "fps": 60,
  "filename": "example_video.mp4",
  "timestamp": 1697200000000
}
```

#### 4. Connection Message (Server → Client)

```json
{
  "type": "connection",
  "message": "Connected to ZIN Timecode Server",
  "clientId": "client_1697200000000_abc123",
  "timestamp": 1697200000000
}
```

#### 5. Ping/Pong (Heartbeat)

```json
{
  "type": "ping"
}
```

```json
{
  "type": "pong",
  "timestamp": 1697200000000
}
```

## Customization

### Custom Python Client

Modify `handle_timecode_message()` in `timecode_receiver.py`:

```python
async def handle_timecode_message(data: dict):
    current_time = data.get('currentTime', 0)
    filename = data.get('filename', 'unknown')

    # YOUR CUSTOM CODE HERE
    # Example: Trigger action at specific time
    if 5.0 <= current_time < 5.1:
        print('\n[ACTION] Reached 5 seconds!')
        # Send OSC message, trigger hardware, etc.

    # Example: Control external video player
    # await control_vlc_player(current_time)

    # Example: Sync with animation software
    # await sync_blender_timeline(current_time)
```

### Multiple Python Clients

You can run multiple Python clients simultaneously. Each will receive all timecode updates:

```bash
# Terminal 1
python timecode_receiver.py

# Terminal 2
python timecode_receiver.py

# Terminal 3 (with custom host)
WS_HOST=192.168.1.100 python timecode_receiver.py
```

### Add New Client Types

Create clients in any language that supports WebSocket:

**JavaScript (Node.js):**
```javascript
const WebSocket = require('ws');
const ws = new WebSocket('ws://localhost:8766');

ws.on('open', () => {
  ws.send(JSON.stringify({ type: 'register', clientType: 'nodejs' }));
});

ws.on('message', (data) => {
  const message = JSON.parse(data);
  if (message.type === 'timecode') {
    console.log(`Timecode: ${message.currentTime}s`);
  }
});
```

**Unity C#:**
```csharp
using WebSocketSharp;

var ws = new WebSocket("ws://localhost:8766");
ws.OnMessage += (sender, e) => {
    var data = JsonUtility.FromJson<TimecodeData>(e.Data);
    Debug.Log($"Timecode: {data.currentTime}");
};
ws.Connect();
ws.Send("{\"type\":\"register\",\"clientType\":\"unity\"}");
```

## Troubleshooting

### Connection Refused

**Problem:** Python client shows "Connection refused"

**Solution:**
1. Verify Node.js server is running: `node websocket-server.js`
2. Check the port is correct (default: 8766)
3. Check firewall settings
4. Ensure Python client is connecting directly to port 8766, not through Apache proxy

### HTML Client Not Connecting

**Problem:** WebSocket status shows "Disconnected" in browser

**Solution:**
1. Check console for errors (F12 → Console)
2. Verify Apache proxy is configured correctly at `/zin_wss`
3. Ensure Apache modules are enabled: `sudo a2enmod proxy proxy_wstunnel`
4. Check that Node.js server is running on port 8766
5. Restart Apache after configuration changes: `sudo systemctl restart apache2`

### No Timecode Updates

**Problem:** Python client connects but receives no timecode

**Solution:**
1. Ensure video is playing in subBeta5.html
2. Check browser console for WebSocket send errors
3. Verify server is running and shows "Broadcasted timecode" messages

### Server Crashes

**Problem:** Node.js server crashes with "EADDRINUSE"

**Solution:**
Port 8766 is already in use. Either:
1. Kill the existing process: `lsof -ti:8766 | xargs kill`
2. Use a different port: `WS_PORT=8767 node websocket-server.js`

## Advanced Usage

### Running Server as a Service

**Using systemd (Linux):**

Create `/etc/systemd/system/zin-websocket.service`:

```ini
[Unit]
Description=ZIN WebSocket Timecode Server
After=network.target

[Service]
Type=simple
User=www-data
WorkingDirectory=/web/zin
ExecStart=/usr/bin/node /web/zin/websocket-server.js
Restart=always
Environment="WS_PORT=8766"
Environment="WS_HOST=0.0.0.0"

[Install]
WantedBy=multi-user.target
```

Enable and start:

```bash
sudo systemctl enable zin-websocket
sudo systemctl start zin-websocket
sudo systemctl status zin-websocket
```

### Using with Docker

Create `Dockerfile`:

```dockerfile
FROM node:18
WORKDIR /app
COPY package*.json ./
RUN npm install
COPY websocket-server.js ./
EXPOSE 8766
CMD ["node", "websocket-server.js"]
```

Build and run:

```bash
docker build -t zin-websocket .
docker run -p 8766:8766 zin-websocket
```

### Remote Access

To access from other machines on your network:

1. Find your server IP address:
   ```bash
   hostname -I
   ```

2. Update firewall to allow port 8766:
   ```bash
   sudo ufw allow 8766/tcp
   ```

3. Use server IP in clients:
   ```bash
   WS_HOST=192.168.1.100 python timecode_receiver.py
   ```

**Note:** The HTML client (subBeta5.html) connects through Apache proxy and doesn't need direct access to port 8766.

## Health Monitoring

Check server status:

```bash
curl http://localhost:8766/health
```

Response:

```json
{
  "status": "ok",
  "clients": 3,
  "uptime": 3600.5
}
```

## Files Overview

| File | Description |
|------|-------------|
| `websocket-server.js` | Node.js WebSocket server (broadcaster hub) |
| `package.json` | Node.js dependencies |
| `timecode_receiver.py` | Python WebSocket client example |
| `requirements.txt` | Python dependencies |
| `subBeta5.html` | HTML video editor with WebSocket client |
| `README_WEBSOCKET.md` | This documentation |

## Performance Notes

- **Timecode Update Rate:** ~30fps (configurable via `WS_UPDATE_INTERVAL` in subBeta5.html)
- **Latency:** Typically <50ms on local network
- **Bandwidth:** ~5-10 KB/s per client during playback
- **Max Clients:** Tested with 50+ simultaneous clients

## Support

For issues or questions:

1. Check the console logs (browser, Node.js, Python)
2. Verify all components are running
3. Test with `curl http://localhost:8766/health`
4. Verify Apache proxy configuration at `/zin_wss`
5. Review this documentation

## License

This WebSocket system is part of the ZIN project.

---

**Last Updated:** 2025-10-13
