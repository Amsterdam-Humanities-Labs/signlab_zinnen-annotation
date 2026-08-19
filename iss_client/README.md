# ISS Server Client for /web/zin

PHP client for communicating with the ISS (Isolated Sign Segmentation) Server.

## Quick Start

### 1. Install Dependencies

```bash
cd /web/zin/iss_client
composer install
```

### 2. Test the Client

Open in browser:
```
https://signcollect.nl/web/zin/iss_client/test.html
```

Or use the command-line test:
```bash
php test_client.php M20241111_6433.hamer
```

## Files

- **iss_client.php** - Standard request/response client (use with AJAX/fetch)
- **iss_client_stream.php** - Streaming client with real-time updates (use with EventSource)
- **JAVASCRIPT_INTEGRATION.md** - Complete guide for JavaScript integration
- **test.html** - Browser-based test page
- **test_client.php** - Command-line test script
- **composer.json** - PHP dependencies
- **config.php** - Configuration

## Usage from JavaScript

### Simple Request

```javascript
fetch('/web/zin/iss_client/iss_client.php', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({
        hamer_path: 'M20241111_6433.hamer',
        fps: 60
    })
})
.then(r => r.json())
.then(data => console.log(data.result));
```

### Streaming with Progress

```javascript
const eventSource = new EventSource(
    '/web/zin/iss_client/iss_client_stream.php?hamer_path=M20241111_6433.hamer&fps=60'
);

eventSource.addEventListener('progress', (e) => {
    const data = JSON.parse(e.data);
    console.log(`${data.stage}: ${data.percentage}%`);
});

eventSource.addEventListener('result', (e) => {
    const data = JSON.parse(e.data);
    console.log('Result:', data);
    eventSource.close();
});
```

See **JAVASCRIPT_INTEGRATION.md** for complete documentation.

## Architecture

```
JavaScript Tool
     ↓
iss_client.php or iss_client_stream.php
     ↓
wss://signcollect.nl/ISS_Server/ws
     ↓
ISS Server (routing)
     ↓
wss://signcollect.nl/ISS_Server/backend
     ↓
Remote Inference Server
     ↓
Downloads files from signcollect.nl
     ↓
Processes with MS-TCN model
     ↓
Uploads results
     ↓
Returns through WebSocket
```

## Response Format

```json
{
  "success": true,
  "request_id": "req-1768830836-2725",
  "result": {
    "type": "result",
    "request_id": "req-1768830836-2725",
    "srt_content": "1\n00:00:00,383 --> 00:00:01,300\nSIGN\n\n",
    "metadata": {
      "segments_detected": 1,
      "total_frames": 180,
      "duration_seconds": 3.0,
      "processing_time": 33.41,
      "fps": 60,
      "confidence_score": 0.95
    }
  },
  "messages": [...]
}
```

## Troubleshooting

**"WebSocket client not available"**
```bash
cd /web/zin/iss_client
composer install
```

**"No healthy backend connections"**
```bash
curl https://signcollect.nl/ISS_Server/health
# Should show: "inbound_backends": 1
```

**"Failed to download files"**

Verify file exists:
```bash
curl -I https://signcollect.nl/gebarenoverleg_media/studioFilesMini/raw/M20241111_6433.hamer
# Should return: HTTP/1.1 200 OK
```

## Support

- **ISS Server Health:** https://signcollect.nl/ISS_Server/health
- **ISS Server Logs:** /home/gomer/ISS_server/logs/server.log
- **Integration Guide:** JAVASCRIPT_INTEGRATION.md
