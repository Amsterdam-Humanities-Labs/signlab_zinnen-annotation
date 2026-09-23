#!/usr/bin/env node

/**
 * WebSocket Server for ZIN Video Timecode Broadcasting
 *
 * This server receives timecode updates from subBeta5.html and broadcasts
 * them to all connected Python clients (or any WebSocket client).
 *
 * Usage:
 *   node websocket-server.js
 *
 * Environment Variables:
 *   WS_PORT - WebSocket server port (default: 8766)
 *   WS_HOST - WebSocket server host (default: 0.0.0.0)
 */

const WebSocket = require('ws');
const http = require('http');
require('dotenv').config();

// Configuration
const PORT = process.env.WS_PORT || 8766;
const HOST = process.env.WS_HOST || '0.0.0.0';

// Create HTTP server for health checks
const server = http.createServer((req, res) => {
  if (req.url === '/health') {
    res.writeHead(200, { 'Content-Type': 'application/json' });
    res.end(JSON.stringify({
      status: 'ok',
      clients: wss.clients.size,
      uptime: process.uptime()
    }));
  } else {
    res.writeHead(200, { 'Content-Type': 'text/plain' });
    res.end('ZIN WebSocket Timecode Server\n');
  }
});

// Create WebSocket server
const wss = new WebSocket.Server({ server });

// Store client metadata
const clients = new Map();

// Client types
const CLIENT_TYPES = {
  HTML: 'html',      // subBeta5.html (broadcaster)
  PYTHON: 'python',  // Python receivers
  UNKNOWN: 'unknown'
};

wss.on('connection', (ws, req) => {
  const clientId = generateClientId();
  const clientIp = req.socket.remoteAddress;

  console.log(`[${new Date().toISOString()}] New connection: ${clientId} from ${clientIp}`);

  // Initialize client metadata
  clients.set(ws, {
    id: clientId,
    type: CLIENT_TYPES.UNKNOWN,
    ip: clientIp,
    connectedAt: new Date()
  });

  // Send welcome message
  ws.send(JSON.stringify({
    type: 'connection',
    message: 'Connected to ZIN Timecode Server',
    clientId: clientId,
    timestamp: Date.now()
  }));

  // Handle incoming messages
  ws.on('message', (data) => {
    try {
      const message = JSON.parse(data);
      handleMessage(ws, message);
    } catch (error) {
      console.error(`[${clientId}] Error parsing message:`, error.message);
      ws.send(JSON.stringify({
        type: 'error',
        message: 'Invalid JSON format'
      }));
    }
  });

  // Handle client disconnect
  ws.on('close', () => {
    const client = clients.get(ws);
    console.log(`[${new Date().toISOString()}] Client disconnected: ${client.id} (${client.type})`);
    clients.delete(ws);
  });

  // Handle errors
  ws.on('error', (error) => {
    console.error(`[${clientId}] WebSocket error:`, error.message);
  });
});

/**
 * Handle incoming messages from clients
 */
function handleMessage(ws, message) {
  const client = clients.get(ws);

  switch (message.type) {
    case 'register':
      // Client registration with type
      client.type = message.clientType || CLIENT_TYPES.UNKNOWN;
      console.log(`[${client.id}] Registered as: ${client.type}`);

      // Send acknowledgment
      ws.send(JSON.stringify({
        type: 'registered',
        clientType: client.type,
        clientId: client.id
      }));
      break;

    case 'timecode':
      // Timecode update from HTML client
      if (client.type === CLIENT_TYPES.UNKNOWN) {
        client.type = CLIENT_TYPES.HTML;
      }

      // Log timecode in readable format
      const currentTime = message.currentTime || 0;
      const hours = Math.floor(currentTime / 3600);
      const minutes = Math.floor((currentTime % 3600) / 60);
      const seconds = Math.floor(currentTime % 60);
      const milliseconds = Math.floor((currentTime % 1) * 1000);
      const timecode = `${String(hours).padStart(2, '0')}:${String(minutes).padStart(2, '0')}:${String(seconds).padStart(2, '0')}.${String(milliseconds).padStart(3, '0')}`;

      // Display on same line (overwrite)
      process.stdout.write(`\r[TIMECODE] ${message.filename || 'unknown'} | ${timecode} / ${(message.duration || 0).toFixed(2)}s @ ${message.fps || 60}fps    `);

      // Broadcast to all Python clients
      broadcastToClients(message, CLIENT_TYPES.PYTHON);
      break;

    case 'video_info':
      // Video information from HTML client
      console.log('\n'); // New line before video info to separate from timecode display
      console.log(`[${client.id}] Video info:`, {
        filename: message.filename,
        duration: message.duration,
        fps: message.fps
      });

      // Broadcast to all Python clients
      broadcastToClients(message, CLIENT_TYPES.PYTHON);
      break;

    case 'ping':
      // Respond to ping with pong
      ws.send(JSON.stringify({
        type: 'pong',
        timestamp: Date.now()
      }));
      break;

    default:
      console.log(`[${client.id}] Unknown message type: ${message.type}`);
  }
}

/**
 * Broadcast message to specific client types
 */
function broadcastToClients(message, targetType = null) {
  let count = 0;

  wss.clients.forEach((client) => {
    if (client.readyState === WebSocket.OPEN) {
      const clientData = clients.get(client);

      // Send to all clients if no target type specified
      // Otherwise, only send to matching client types
      if (!targetType || clientData.type === targetType) {
        client.send(JSON.stringify(message));
        count++;
      }
    }
  });

  // Log broadcast stats (only for non-timecode messages to avoid spam)
  if (message.type !== 'timecode') {
    console.log(`Broadcasted ${message.type} to ${count} client(s)`);
  }
}

/**
 * Generate unique client ID
 */
function generateClientId() {
  return `client_${Date.now()}_${Math.random().toString(36).substr(2, 9)}`;
}

/**
 * Periodic heartbeat to detect dead connections
 */
setInterval(() => {
  wss.clients.forEach((ws) => {
    if (ws.isAlive === false) {
      const client = clients.get(ws);
      console.log(`[${client.id}] Connection timeout, terminating`);
      return ws.terminate();
    }

    ws.isAlive = false;
    ws.ping();
  });
}, 30000); // Every 30 seconds

wss.on('connection', (ws) => {
  ws.isAlive = true;
  ws.on('pong', () => {
    ws.isAlive = true;
  });
});

// Start server
server.listen(PORT, HOST, () => {
  console.log('========================================');
  console.log('ZIN WebSocket Timecode Server');
  console.log('========================================');
  console.log(`Server running on ${HOST}:${PORT}`);
  console.log(`WebSocket URL: ws://${HOST}:${PORT}`);
  console.log(`Health check: http://${HOST}:${PORT}/health`);
  console.log('========================================');
});

// Graceful shutdown
process.on('SIGTERM', () => {
  console.log('SIGTERM received, closing server...');
  server.close(() => {
    console.log('Server closed');
    process.exit(0);
  });
});

process.on('SIGINT', () => {
  console.log('\nSIGINT received, closing server...');
  server.close(() => {
    console.log('Server closed');
    process.exit(0);
  });
});
