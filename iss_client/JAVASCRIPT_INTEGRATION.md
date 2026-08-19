# JavaScript Integration Guide for ISS Client

This guide shows how to integrate the ISS Server PHP client into your JavaScript annotation tool.

## Table of Contents

- [Installation](#installation)
- [Method 1: Standard Request (AJAX)](#method-1-standard-request-ajax)
- [Method 2: Streaming Updates (SSE)](#method-2-streaming-updates-sse)
- [Complete Example](#complete-example)
- [Error Handling](#error-handling)
- [UI Integration](#ui-integration)

---

## Installation

### 1. Install PHP Dependencies

```bash
cd /web/zin/iss_client
composer install
```

This installs the `textalk/websocket` library required for WebSocket communication.

### 2. Verify Installation

Check that the files are in place:

```bash
ls -la /web/zin/iss_client/
```

You should see:
- `iss_client.php` - Standard request/response
- `iss_client_stream.php` - Streaming with real-time updates
- `composer.json` - Dependencies
- `vendor/` - Installed libraries (after composer install)

---

## Method 1: Standard Request (AJAX)

This method sends a request and waits for the complete result. Best for simple integrations.

### PHP Endpoint

**URL:** `/web/zin/iss_client/iss_client.php`

**Method:** POST

**Parameters:**
```javascript
{
  "hamer_path": "M20241111_6433.hamer",  // Required: filename or path
  "fps": 60,                              // Optional: default 60
  "request_id": "custom-id-123"           // Optional: auto-generated if not provided
}
```

### JavaScript Example (Fetch API)

```javascript
async function sendInferenceRequest(hamerPath, fps = 60) {
    try {
        const response = await fetch('/web/zin/iss_client/iss_client.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json'
            },
            body: JSON.stringify({
                hamer_path: hamerPath,
                fps: fps
            })
        });

        const data = await response.json();

        if (data.success) {
            console.log('✓ Inference successful!');
            console.log('Result:', data.result);
            console.log('Segments:', data.result.metadata.segments_detected);
            console.log('SRT:', data.result.srt_content);
            return data.result;
        } else {
            console.error('✗ Inference failed:', data.error);
            throw new Error(data.error.message);
        }

    } catch (error) {
        console.error('Request failed:', error);
        throw error;
    }
}

// Usage
sendInferenceRequest('M20241111_6433.hamer', 60)
    .then(result => {
        console.log('Processing complete!');
        // Handle result...
    })
    .catch(error => {
        console.error('Error:', error);
    });
```

### jQuery Example

```javascript
function sendInferenceRequest(hamerPath, fps) {
    $.ajax({
        url: '/web/zin/iss_client/iss_client.php',
        method: 'POST',
        contentType: 'application/json',
        data: JSON.stringify({
            hamer_path: hamerPath,
            fps: fps || 60
        }),
        success: function(data) {
            if (data.success) {
                console.log('Result:', data.result);
                // Process result...
            } else {
                console.error('Error:', data.error);
            }
        },
        error: function(xhr, status, error) {
            console.error('Request failed:', error);
        }
    });
}
```

---

## Method 2: Streaming Updates (SSE)

This method provides real-time progress updates. Best for showing progress to users.

### PHP Endpoint

**URL:** `/web/zin/iss_client/iss_client_stream.php`

**Method:** GET

**Parameters:** (URL query string)
```
?hamer_path=M20241111_6433.hamer&fps=60&request_id=custom-id-123
```

### JavaScript Example (EventSource)

```javascript
function sendInferenceRequestStreaming(hamerPath, fps = 60, callbacks = {}) {
    const requestId = 'req-' + Date.now() + '-' + Math.floor(Math.random() * 10000);

    // Build URL
    const params = new URLSearchParams({
        hamer_path: hamerPath,
        fps: fps,
        request_id: requestId
    });

    const url = `/web/zin/iss_client/iss_client_stream.php?${params}`;

    // Create EventSource
    const eventSource = new EventSource(url);

    // Handle different event types
    eventSource.addEventListener('connecting', (e) => {
        const data = JSON.parse(e.data);
        console.log('Connecting:', data.message);
        if (callbacks.onConnecting) callbacks.onConnecting(data);
    });

    eventSource.addEventListener('connected', (e) => {
        const data = JSON.parse(e.data);
        console.log('Connected:', data.message);
        if (callbacks.onConnected) callbacks.onConnected(data);
    });

    eventSource.addEventListener('request_sent', (e) => {
        const data = JSON.parse(e.data);
        console.log('Request sent:', data.request_id);
        if (callbacks.onRequestSent) callbacks.onRequestSent(data);
    });

    eventSource.addEventListener('ack', (e) => {
        const data = JSON.parse(e.data);
        console.log('ACK:', data.message);
        if (callbacks.onAck) callbacks.onAck(data);
    });

    eventSource.addEventListener('progress', (e) => {
        const data = JSON.parse(e.data);
        console.log(`Progress: ${data.stage} (${data.percentage}%) - ${data.message}`);
        if (callbacks.onProgress) callbacks.onProgress(data);
    });

    eventSource.addEventListener('result', (e) => {
        const data = JSON.parse(e.data);
        console.log('Result received:', data);
        if (callbacks.onResult) callbacks.onResult(data);
    });

    eventSource.addEventListener('error', (e) => {
        const data = JSON.parse(e.data);
        console.error('Error:', data);
        if (callbacks.onError) callbacks.onError(data);
    });

    eventSource.addEventListener('done', (e) => {
        console.log('Stream complete');
        eventSource.close();
        if (callbacks.onDone) callbacks.onDone();
    });

    // Handle connection errors
    eventSource.onerror = (error) => {
        console.error('EventSource error:', error);
        eventSource.close();
        if (callbacks.onConnectionError) callbacks.onConnectionError(error);
    };

    return {
        eventSource,
        requestId,
        close: () => eventSource.close()
    };
}

// Usage
const request = sendInferenceRequestStreaming('M20241111_6433.hamer', 60, {
    onConnecting: (data) => {
        console.log('Status: Connecting...');
    },
    onProgress: (data) => {
        updateProgressBar(data.percentage, data.stage, data.message);
    },
    onResult: (data) => {
        console.log('Segments detected:', data.metadata.segments_detected);
        console.log('SRT content:', data.srt_content);
        displayResults(data);
    },
    onError: (data) => {
        showError(data.message);
    },
    onDone: () => {
        console.log('Processing complete!');
    }
});

// Can cancel if needed
// request.close();
```

---

## Complete Example

Full integration with UI updates:

```html
<!DOCTYPE html>
<html>
<head>
    <title>ISS Inference Client</title>
    <style>
        .progress-container {
            width: 100%;
            background-color: #f0f0f0;
            border-radius: 5px;
            margin: 20px 0;
        }
        .progress-bar {
            height: 30px;
            background-color: #4CAF50;
            border-radius: 5px;
            text-align: center;
            line-height: 30px;
            color: white;
            width: 0%;
            transition: width 0.3s;
        }
        .status {
            margin: 10px 0;
            padding: 10px;
            border-radius: 5px;
        }
        .status.success { background-color: #d4edda; color: #155724; }
        .status.error { background-color: #f8d7da; color: #721c24; }
        .status.info { background-color: #d1ecf1; color: #0c5460; }
    </style>
</head>
<body>
    <h1>Sign Segmentation Inference</h1>

    <div>
        <label>HaMeR File:</label>
        <input type="text" id="hamerPath" value="M20241111_6433.hamer">
        <label>FPS:</label>
        <input type="number" id="fps" value="60">
        <button onclick="startInference()">Start Inference</button>
    </div>

    <div id="statusArea"></div>

    <div class="progress-container" id="progressContainer" style="display: none;">
        <div class="progress-bar" id="progressBar">0%</div>
    </div>

    <div id="resultArea"></div>

    <script>
    let currentRequest = null;

    function startInference() {
        const hamerPath = document.getElementById('hamerPath').value;
        const fps = parseInt(document.getElementById('fps').value);

        if (!hamerPath) {
            alert('Please enter a HaMeR file path');
            return;
        }

        // Clear previous results
        document.getElementById('statusArea').innerHTML = '';
        document.getElementById('resultArea').innerHTML = '';
        document.getElementById('progressContainer').style.display = 'block';
        updateProgress(0, 'Initializing...');

        // Start streaming request
        currentRequest = sendInferenceRequestStreaming(hamerPath, fps, {
            onConnecting: (data) => {
                showStatus('info', 'Connecting to ISS Server...');
            },
            onConnected: (data) => {
                showStatus('success', 'Connected! Sending request...');
            },
            onAck: (data) => {
                showStatus('info', data.message);
            },
            onProgress: (data) => {
                updateProgress(data.percentage, `${data.stage}: ${data.message}`);
            },
            onResult: (data) => {
                showStatus('success', 'Inference complete!');
                displayResult(data);
                document.getElementById('progressContainer').style.display = 'none';
            },
            onError: (data) => {
                showStatus('error', 'Error: ' + data.message);
                document.getElementById('progressContainer').style.display = 'none';
            },
            onDone: () => {
                console.log('Stream closed');
            }
        });
    }

    function updateProgress(percentage, message) {
        const bar = document.getElementById('progressBar');
        bar.style.width = percentage + '%';
        bar.textContent = Math.round(percentage) + '%';
        showStatus('info', message);
    }

    function showStatus(type, message) {
        const statusArea = document.getElementById('statusArea');
        const div = document.createElement('div');
        div.className = 'status ' + type;
        div.textContent = new Date().toLocaleTimeString() + ': ' + message;
        statusArea.insertBefore(div, statusArea.firstChild);
    }

    function displayResult(data) {
        const resultArea = document.getElementById('resultArea');

        const html = `
            <h2>Result</h2>
            <p><strong>Request ID:</strong> ${data.request_id}</p>
            <p><strong>Segments Detected:</strong> ${data.metadata.segments_detected}</p>
            <p><strong>Total Frames:</strong> ${data.metadata.total_frames}</p>
            <p><strong>Duration:</strong> ${data.metadata.duration_seconds}s</p>
            <p><strong>Processing Time:</strong> ${data.metadata.processing_time}s</p>
            <p><strong>FPS:</strong> ${data.metadata.fps}</p>
            <p><strong>Confidence Score:</strong> ${data.metadata.confidence_score || 'N/A'}</p>
            <h3>SRT Content:</h3>
            <pre>${data.srt_content}</pre>
        `;

        resultArea.innerHTML = html;
    }

    // Function definition from earlier
    function sendInferenceRequestStreaming(hamerPath, fps, callbacks) {
        const requestId = 'req-' + Date.now() + '-' + Math.floor(Math.random() * 10000);
        const params = new URLSearchParams({
            hamer_path: hamerPath,
            fps: fps,
            request_id: requestId
        });
        const url = `/web/zin/iss_client/iss_client_stream.php?${params}`;
        const eventSource = new EventSource(url);

        eventSource.addEventListener('connecting', (e) => {
            const data = JSON.parse(e.data);
            if (callbacks.onConnecting) callbacks.onConnecting(data);
        });
        eventSource.addEventListener('connected', (e) => {
            const data = JSON.parse(e.data);
            if (callbacks.onConnected) callbacks.onConnected(data);
        });
        eventSource.addEventListener('request_sent', (e) => {
            const data = JSON.parse(e.data);
            if (callbacks.onRequestSent) callbacks.onRequestSent(data);
        });
        eventSource.addEventListener('ack', (e) => {
            const data = JSON.parse(e.data);
            if (callbacks.onAck) callbacks.onAck(data);
        });
        eventSource.addEventListener('progress', (e) => {
            const data = JSON.parse(e.data);
            if (callbacks.onProgress) callbacks.onProgress(data);
        });
        eventSource.addEventListener('result', (e) => {
            const data = JSON.parse(e.data);
            if (callbacks.onResult) callbacks.onResult(data);
        });
        eventSource.addEventListener('error', (e) => {
            const data = JSON.parse(e.data);
            if (callbacks.onError) callbacks.onError(data);
        });
        eventSource.addEventListener('done', (e) => {
            eventSource.close();
            if (callbacks.onDone) callbacks.onDone();
        });
        eventSource.onerror = (error) => {
            eventSource.close();
            if (callbacks.onConnectionError) callbacks.onConnectionError(error);
        };

        return { eventSource, requestId, close: () => eventSource.close() };
    }
    </script>
</body>
</html>
```

---

## Error Handling

### Common Errors and Solutions

**1. "WebSocket client not available"**
```javascript
{
  "error": "WebSocket client not available",
  "details": "Please install: composer require textalk/websocket"
}
```
**Solution:** Run `composer install` in `/web/zin/iss_client/`

**2. "No healthy backend connections available"**
```javascript
{
  "error": "No healthy backend connections available"
}
```
**Solution:** Remote inference server is offline. Check ISS Server health:
```bash
curl https://signcollect.nl/ISS_Server/health
```

**3. "Failed to download files"**
```javascript
{
  "error": "Processing failed: Failed to download files"
}
```
**Solution:** File doesn't exist on server. Verify file exists at:
`https://signcollect.nl/gebarenoverleg_media/studioFilesMini/raw/{filename}`

### Robust Error Handling Example

```javascript
async function sendInferenceWithRetry(hamerPath, fps = 60, maxRetries = 3) {
    for (let attempt = 1; attempt <= maxRetries; attempt++) {
        try {
            console.log(`Attempt ${attempt}/${maxRetries}...`);

            const response = await fetch('/web/zin/iss_client/iss_client.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ hamer_path: hamerPath, fps: fps })
            });

            const data = await response.json();

            if (data.success) {
                return data.result;
            } else {
                const errorMsg = data.error.message || 'Unknown error';

                // Don't retry certain errors
                if (errorMsg.includes('not available') ||
                    errorMsg.includes('Missing required parameter')) {
                    throw new Error(errorMsg);
                }

                // Retry on other errors
                if (attempt < maxRetries) {
                    console.warn(`Error: ${errorMsg}. Retrying in 2s...`);
                    await new Promise(resolve => setTimeout(resolve, 2000));
                    continue;
                }

                throw new Error(errorMsg);
            }

        } catch (error) {
            if (attempt === maxRetries) {
                throw error;
            }
            console.warn(`Request failed: ${error.message}. Retrying...`);
            await new Promise(resolve => setTimeout(resolve, 2000));
        }
    }
}
```

---

## UI Integration

### Progress Bar Stages

Map backend stages to user-friendly messages:

```javascript
const stageMessages = {
    'downloading': 'Downloading video files...',
    'validation': 'Validating files...',
    'feature_extraction': 'Extracting pose features...',
    'inference': 'Running sign segmentation...',
    'vtt_generation': 'Generating subtitles...',
    'uploading': 'Uploading results...',
    'finalizing': 'Finalizing...'
};

function onProgress(data) {
    const message = stageMessages[data.stage] || data.message;
    updateProgressBar(data.percentage, message);
}
```

### Confidence Score Display

```javascript
function displayConfidenceScore(score) {
    const percentage = (score * 100).toFixed(1);
    const color = score >= 0.8 ? 'green' : score >= 0.6 ? 'orange' : 'red';

    return `<span style="color: ${color}; font-weight: bold;">
        ${percentage}% confidence
    </span>`;
}
```

### SRT Preview

```javascript
function formatSRTPreview(srtContent, maxSegments = 3) {
    const segments = srtContent.split('\n\n').filter(s => s.trim());
    const preview = segments.slice(0, maxSegments).join('\n\n');
    const hasMore = segments.length > maxSegments;

    return `
        <pre>${preview}${hasMore ? '\n\n... (' + (segments.length - maxSegments) + ' more segments)' : ''}</pre>
    `;
}
```

---

## Testing

### Quick Test

```javascript
// Test connection
fetch('/web/zin/iss_client/iss_client.php', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({
        hamer_path: 'M20241111_6433.hamer',
        fps: 60
    })
})
.then(r => r.json())
.then(data => console.log(data))
.catch(err => console.error(err));
```

### Check ISS Server Status

```javascript
fetch('https://signcollect.nl/ISS_Server/health')
    .then(r => r.json())
    .then(data => {
        console.log('ISS Server Status:', data.status);
        console.log('Backend connections:', data.total_backends);
    });
```

---

## Summary

**Use Standard Request when:**
- Simple integration
- Don't need progress updates
- Want all data at once

**Use Streaming when:**
- Want real-time progress
- Long processing times (>10 seconds)
- Better user experience with progress bar

**Both methods:**
- ✅ Work with the same backend infrastructure
- ✅ Return the same result format
- ✅ Support the same parameters
- ✅ Handle errors consistently

For most annotation tools, **streaming is recommended** for better UX.
