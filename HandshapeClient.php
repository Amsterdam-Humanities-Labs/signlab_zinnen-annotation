<?php
/**
 * HandshapeClient
 *
 * WebSocket client for communicating with handshape recognition server.
 * Implements RFC 6455 WebSocket protocol for client-side connections.
 *
 * Protocol:
 * 1. TCP connection to server (port 9000)
 * 2. HTTP upgrade handshake with Sec-WebSocket-Key
 * 3. Send handshape_request with hamer file path, start_time, end_time
 * 4. Receive response with left/right hand predictions
 * 5. Close connection
 *
 * @version 1.0
 * @date 2026-01-05
 */
class HandshapeClient {

    private $websocketUrl;
    private $socket;
    private $timeout = 60; // 60 seconds timeout for handshape recognition

    /**
     * Constructor
     *
     * @param string $websocketUrl WebSocket server URL (e.g., 'ws://localhost:9000')
     */
    public function __construct($websocketUrl = 'ws://localhost:9000') {
        $this->websocketUrl = $websocketUrl;
    }

    /**
     * Get handshape predictions for a segment of a .hamer file
     *
     * @param string $hamerPath Absolute path to .hamer file on the server
     * @param float $startTime Start time in seconds
     * @param float $endTime End time in seconds
     * @return array Result with 'success', 'l_hand', 'r_hand', or 'error_message'
     */
    public function getHandshapes($hamerPath, $startTime, $endTime) {
        try {
            // Convert to absolute path
            $absolutePath = realpath($hamerPath);
            if ($absolutePath === false) {
                return [
                    'success' => false,
                    'error_message' => "File not found or cannot be accessed: $hamerPath"
                ];
            }

            // Generate request ID
            $requestId = $this->generateUuid();

            // 1. Parse WebSocket URL
            $urlParts = parse_url($this->websocketUrl);
            $host = $urlParts['host'];
            $port = $urlParts['port'] ?? 9000;
            $path = $urlParts['path'] ?? '/';

            // 2. Open TCP socket connection
            $this->socket = @fsockopen($host, $port, $errno, $errstr, 10);

            if (!$this->socket) {
                throw new Exception("Failed to connect to WebSocket server: $errstr ($errno)");
            }

            // Set socket timeout
            stream_set_timeout($this->socket, $this->timeout);

            // 3. Perform WebSocket handshake
            $this->performHandshake($host, $path);

            // 4. Send handshape request
            $this->sendHandshapeRequest($requestId, $absolutePath, $startTime, $endTime);

            // 5. Wait for response
            $response = $this->receiveMessage();

            // 6. Close connection
            $this->close();

            // 7. Parse response
            if (!isset($response['status'])) {
                throw new Exception("Response missing 'status' field");
            }

            if ($response['status'] === 'success' && isset($response['data'])) {
                return [
                    'success' => true,
                    'l_hand' => $response['data']['l_hand'] ?? ['predictions' => []],
                    'r_hand' => $response['data']['r_hand'] ?? ['predictions' => []]
                ];
            } elseif ($response['status'] === 'error') {
                return [
                    'success' => false,
                    'error_message' => $response['message'] ?? 'Unknown error from server'
                ];
            } else {
                throw new Exception("Unexpected response format");
            }

        } catch (Exception $e) {
            if ($this->socket) {
                $this->close();
            }
            return [
                'success' => false,
                'error_message' => $e->getMessage()
            ];
        }
    }

    /**
     * Generate a simple UUID v4
     */
    private function generateUuid() {
        return sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            mt_rand(0, 0xffff), mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0x0fff) | 0x4000,
            mt_rand(0, 0x3fff) | 0x8000,
            mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
        );
    }

    /**
     * Send handshape request message
     */
    private function sendHandshapeRequest($requestId, $hamerPath, $startTime, $endTime) {
        $message = [
            'request_id' => $requestId,
            'hamer_file_path' => $hamerPath,
            'start_time' => $startTime,
            'end_time' => $endTime
        ];

        $this->sendMessage(json_encode($message));
    }

    /**
     * Receive and parse JSON message
     */
    private function receiveMessage() {
        $rawMessage = $this->receiveWebSocketFrame();
        $message = json_decode($rawMessage, true);

        if ($message === null) {
            throw new Exception("Failed to parse WebSocket message");
        }

        return $message;
    }

    /**
     * Perform WebSocket handshake
     */
    private function performHandshake($host, $path) {
        $key = base64_encode(openssl_random_pseudo_bytes(16));

        $headers = "GET $path HTTP/1.1\r\n";
        $headers .= "Host: $host\r\n";
        $headers .= "Upgrade: websocket\r\n";
        $headers .= "Connection: Upgrade\r\n";
        $headers .= "Sec-WebSocket-Key: $key\r\n";
        $headers .= "Sec-WebSocket-Version: 13\r\n";
        $headers .= "\r\n";

        fwrite($this->socket, $headers);

        // Read handshake response
        $response = '';
        while (($line = fgets($this->socket)) !== false) {
            $response .= $line;
            if (trim($line) === '') break;
        }

        if (!preg_match('/101 Switching Protocols/', $response)) {
            throw new Exception("WebSocket handshake failed");
        }
    }

    /**
     * Send a WebSocket text message
     */
    private function sendMessage($message) {
        $frameHead = [];
        $payloadLength = strlen($message);

        // FIN=1, opcode=1 (text frame)
        $frameHead[0] = 0x81;

        // Mask=1, payload length
        if ($payloadLength <= 125) {
            $frameHead[1] = 0x80 | $payloadLength;
        } elseif ($payloadLength <= 65535) {
            $frameHead[1] = 0x80 | 126;
            $frameHead[2] = ($payloadLength >> 8) & 0xFF;
            $frameHead[3] = $payloadLength & 0xFF;
        } else {
            // Extended payload length (8 bytes for large messages)
            $frameHead[1] = 0x80 | 127;
            // Pack as 64-bit big-endian integer
            for ($i = 7; $i >= 0; $i--) {
                $frameHead[] = ($payloadLength >> ($i * 8)) & 0xFF;
            }
        }

        // Generate masking key
        $mask = [];
        for ($i = 0; $i < 4; $i++) {
            $mask[$i] = rand(0, 255);
            $frameHead[] = $mask[$i];
        }

        // Build frame header
        $frame = '';
        foreach ($frameHead as $byte) {
            $frame .= chr($byte);
        }

        // Mask payload
        for ($i = 0; $i < $payloadLength; $i++) {
            $frame .= chr(ord($message[$i]) ^ $mask[$i % 4]);
        }

        fwrite($this->socket, $frame);
    }

    /**
     * Receive a WebSocket frame and return payload
     */
    private function receiveWebSocketFrame() {
        while (true) {
            // Read first 2 bytes
            $header = fread($this->socket, 2);
            if (strlen($header) < 2) {
                throw new Exception("Connection closed unexpectedly");
            }

            $byte1 = ord($header[0]);
            $byte2 = ord($header[1]);

            // Check FIN bit and opcode
            $fin = ($byte1 & 0x80) >> 7;
            $opcode = $byte1 & 0x0F;

            // Handle control frames
            if ($opcode === 0x08) {
                throw new Exception("Connection closed by server");
            } elseif ($opcode === 0x09) {
                // PING frame - send PONG and continue reading
                $this->handlePingFrame($byte2);
                continue;
            } elseif ($opcode === 0x0A) {
                // PONG frame - ignore and continue reading
                $this->consumeFrame($byte2);
                continue;
            }

            // Get payload length
            $masked = ($byte2 & 0x80) >> 7;
            $payloadLength = $byte2 & 0x7F;

        if ($payloadLength === 126) {
            $len = fread($this->socket, 2);
            $payloadLength = unpack('n', $len)[1];
        } elseif ($payloadLength === 127) {
            $len = fread($this->socket, 8);
            $payloadLength = unpack('J', $len)[1];
        }

        // Read masking key if present (should not be for server->client)
        if ($masked) {
            $maskKey = fread($this->socket, 4);
        }

        // Read payload
        $payload = '';
        $remaining = $payloadLength;
        while ($remaining > 0) {
            $chunk = fread($this->socket, min($remaining, 8192));
            if ($chunk === false || strlen($chunk) === 0) {
                throw new Exception("Failed to read payload");
            }
            $payload .= $chunk;
            $remaining -= strlen($chunk);
        }

        // Unmask if necessary
        if ($masked) {
            $unmasked = '';
            for ($i = 0; $i < $payloadLength; $i++) {
                $unmasked .= chr(ord($payload[$i]) ^ ord($maskKey[$i % 4]));
            }
            return $unmasked;
        }

        return $payload;
        }
    }

    /**
     * Handle PING frame by sending PONG
     */
    private function handlePingFrame($byte2) {
        // Read payload length
        $payloadLength = $byte2 & 0x7F;

        if ($payloadLength === 126) {
            $len = fread($this->socket, 2);
            $payloadLength = unpack('n', $len)[1];
        } elseif ($payloadLength === 127) {
            $len = fread($this->socket, 8);
            $payloadLength = unpack('J', $len)[1];
        }

        // Read payload
        $payload = '';
        if ($payloadLength > 0) {
            $payload = fread($this->socket, $payloadLength);
        }

        // Send PONG with same payload
        $frame = chr(0x8A); // FIN=1, opcode=0x0A (PONG)
        $frame .= chr(0x80 | $payloadLength); // MASK=1, length

        // Add mask
        $mask = [rand(0, 255), rand(0, 255), rand(0, 255), rand(0, 255)];
        foreach ($mask as $byte) {
            $frame .= chr($byte);
        }

        // Mask payload
        for ($i = 0; $i < $payloadLength; $i++) {
            $frame .= chr(ord($payload[$i]) ^ $mask[$i % 4]);
        }

        fwrite($this->socket, $frame);
    }

    /**
     * Consume and discard a frame
     */
    private function consumeFrame($byte2) {
        // Read payload length
        $payloadLength = $byte2 & 0x7F;

        if ($payloadLength === 126) {
            $len = fread($this->socket, 2);
            $payloadLength = unpack('n', $len)[1];
        } elseif ($payloadLength === 127) {
            $len = fread($this->socket, 8);
            $payloadLength = unpack('J', $len)[1];
        }

        // Discard payload
        if ($payloadLength > 0) {
            fread($this->socket, $payloadLength);
        }
    }

    /**
     * Close WebSocket connection
     */
    private function close() {
        if ($this->socket) {
            // Send close frame
            $closeFrame = chr(0x88) . chr(0x80) . pack('N', rand());
            @fwrite($this->socket, $closeFrame);
            @fclose($this->socket);
            $this->socket = null;
        }
    }
}
?>
