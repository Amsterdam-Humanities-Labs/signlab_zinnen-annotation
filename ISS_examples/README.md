# ISS Server - JavaScript Client Examples

This directory contains JavaScript + HTML examples for connecting to the ISS (Intermediary Server for Sign Segmentation) Server.

## Files

### 1. `simple_example.html`
**Minimal example** - Bare-bones implementation showing the essential code.

- Minimal UI with just two buttons
- Shows basic WebSocket and HTTP+SSE implementations
- Best for understanding the core protocol
- No dependencies, pure vanilla JavaScript

**Usage:**
```bash
# Open in browser
firefox simple_example.html
# Or
google-chrome simple_example.html
```

### 2. `websocket_example.html`
**WebSocket client** - Full-featured WebSocket implementation with UI.

- Real-time bidirectional communication
- Progress bar visualization
- Colored console-style logging
- Detailed result display
- Error handling

**Features:**
- Configurable WebSocket URL
- Custom HaMeR file path and FPS settings
- Real-time progress updates
- SRT content preview

### 3. `http_sse_example.html`
**HTTP + SSE client** - HTTP POST request with Server-Sent Events monitoring.

- HTTP request submission
- Server-Sent Events (SSE) for progress monitoring
- Progress bar visualization
- Colored logging
- Detailed result display

**Features:**
- Configurable base URL
- Custom HaMeR file path and FPS settings
- Real-time SSE progress updates
- SRT content preview

### 4. `combined_example.html`
**Combined client** - Interactive UI with both protocols and environment switching.

- Tab-based interface (WebSocket / HTTP+SSE)
- Environment switcher (Local / Production)
- Automatic URL configuration
- Progress bar and result display
- Comprehensive logging

**Features:**
- Switch between WebSocket and HTTP+SSE protocols
- Switch between local (localhost:9102) and production (signcollect.nl)
- All features from both protocols in one interface

## Quick Start

### 1. Ensure ISS Server is Running

```bash
# Check if server is running
curl http://localhost:9102/health

# Expected response:
# {"status":"healthy","backend_connections":2,"active_connections":0,...}
```

### 2. Open Examples in Browser

Simply open any HTML file in your web browser:

```bash
# Firefox
firefox combined_example.html

# Chrome
google-chrome combined_example.html

# Or just double-click the file
```

### 3. Configure Settings

- **Local testing**: Use `ws://localhost:9102/ws` or `http://localhost:9102`
- **Production**: Use `wss://signcollect.nl/ISS_Server/ws` or `https://signcollect.nl/ISS_Server`
- **HaMeR Path**: Enter the path to your .hamer file
- **FPS**: Set frames per second (default: 60)

### 4. Start Inference

Click the button to connect and start the inference request. You'll see:
- Connection status
- Acknowledgment message
- Progress updates with stage and percentage
- Final result with metadata and SRT content

## Protocol Details

### WebSocket Protocol

**Endpoint**: `ws://localhost:9102/ws` or `wss://signcollect.nl/ISS_Server/ws`

**Client sends:**
```javascript
{
  "type": "inference_request",
  "request_id": "js-1234567890-5678",
  "hamer_path": "/path/to/file.hamer",
  "fps": 60
}
```

**Server sends:**
```javascript
// Acknowledgment
{"type": "ack", "request_id": "...", "message": "Request received"}

// Progress updates
{"type": "progress", "request_id": "...", "stage": "inference", "percentage": 75}

// Final result
{"type": "result", "request_id": "...", "srt_content": "...", "metadata": {...}}

// Error
{"type": "error", "request_id": "...", "error_code": "...", "message": "..."}
```

### HTTP + SSE Protocol

**Step 1: Submit request**
```javascript
POST http://localhost:9102/inference
Content-Type: application/json

{
  "hamer_path": "/path/to/file.hamer",
  "fps": 60
}

// Response:
{
  "request_id": "abc-123",
  "status": "processing",
  "progress_url": "/progress/abc-123"
}
```

**Step 2: Monitor via SSE**
```javascript
GET http://localhost:9102/progress/abc-123
Accept: text/event-stream

// Server-Sent Events:
event: ack
data: {"type": "ack", "message": "Request received"}

event: progress
data: {"type": "progress", "stage": "inference", "percentage": 75}

event: result
data: {"type": "result", "srt_content": "...", "metadata": {...}}

event: done
data: {"type": "done"}
```

## Code Snippets

### Basic WebSocket Example

```javascript
const ws = new WebSocket('ws://localhost:9102/ws');

ws.onopen = () => {
    ws.send(JSON.stringify({
        type: 'inference_request',
        request_id: 'test-' + Date.now(),
        hamer_path: '/path/to/file.hamer',
        fps: 60
    }));
};

ws.onmessage = (event) => {
    const data = JSON.parse(event.data);
    console.log(data.type, data);
};
```

### Basic HTTP + SSE Example

```javascript
// Submit request
const response = await fetch('http://localhost:9102/inference', {
    method: 'POST',
    headers: {'Content-Type': 'application/json'},
    body: JSON.stringify({hamer_path: '/path/to/file.hamer', fps: 60})
});
const {request_id} = await response.json();

// Monitor progress
const es = new EventSource(`http://localhost:9102/progress/${request_id}`);

es.addEventListener('progress', (event) => {
    const data = JSON.parse(event.data);
    console.log(`Progress: ${data.stage} ${data.percentage}%`);
});

es.addEventListener('result', (event) => {
    const data = JSON.parse(event.data);
    console.log('Result:', data);
    es.close();
});
```

## Browser Compatibility

All examples work in modern browsers:
- Chrome/Edge 60+
- Firefox 55+
- Safari 12+
- Opera 47+

**Note:** WebSocket and Server-Sent Events are standard browser APIs, no external libraries required.

## CORS Considerations

If accessing ISS Server from a different domain, ensure CORS is configured:

```python
# ISS Server configuration (app/config.py)
cors_origins = ["*"]  # Or specific domains
cors_allow_credentials = True
cors_allow_methods = ["*"]
cors_allow_headers = ["*"]
```

## Troubleshooting

### Connection Refused

**Problem:** `WebSocket connection failed` or `fetch failed`

**Solution:**
1. Check ISS Server is running: `curl http://localhost:9102/health`
2. Verify URL is correct (ws:// for WebSocket, http:// for HTTP)
3. Check browser console for errors

### CORS Errors

**Problem:** `CORS policy: No 'Access-Control-Allow-Origin' header`

**Solution:**
- Access from same domain as ISS Server
- Or configure CORS in ISS Server settings

### SSL/TLS Errors

**Problem:** `wss://` or `https://` connection fails

**Solution:**
- For local testing, use `ws://` and `http://` (non-secure)
- For production, ensure valid SSL certificate
- Check browser console for certificate errors

### Backend Not Available

**Problem:** Server responds but says "backend_unavailable"

**Solution:**
1. Check backend inference server is running on port 8765
2. Check ISS Server logs: `tail -f /home/gomer/ISS_server/logs/server.log`
3. Verify backend connection in health endpoint

## Example URLs

### Local Development
- WebSocket: `ws://localhost:9102/ws`
- HTTP: `http://localhost:9102`
- Health: `http://localhost:9102/health`

### Production
- WebSocket: `wss://signcollect.nl/ISS_Server/ws`
- HTTP: `https://signcollect.nl/ISS_Server`
- Health: `https://signcollect.nl/ISS_Server/health`

## Next Steps

1. **Start with `simple_example.html`** to understand the basics
2. **Try `combined_example.html`** for full-featured testing
3. **Integrate into your application** using the code patterns from these examples
4. **Check ISS Server documentation** for more details: `/home/gomer/ISS_server/README.md`

## Related Documentation

- ISS Server: `/home/gomer/ISS_server/README.md`
- PHP Examples: `/home/gomer/ISS_server/tests/php/`
- Remote Backend Setup: `/home/gomer/ISS_server/REMOTE_BACKEND_SETUP.md`

## Support

For issues or questions:
- Check ISS Server logs: `/home/gomer/ISS_server/logs/server.log`
- Verify server health: `curl http://localhost:9102/health`
- Review browser console for JavaScript errors
