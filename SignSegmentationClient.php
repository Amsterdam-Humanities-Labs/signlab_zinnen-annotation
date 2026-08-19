<?php
/**
 * SignSegmentationClient
 *
 * WebSocket client for communicating with sign language segmentation server.
 * Implements RFC 6455 WebSocket protocol for client-side connections.
 *
 * Protocol:
 * 1. TCP connection to server
 * 2. HTTP upgrade handshake with Sec-WebSocket-Key
 * 3. Send inference_request with hamer file path
 * 4. Receive ACK
 * 5. Receive progress updates (optional)
 * 6. Receive result with base64-encoded VTT
 * 7. Close connection
 *
 * @version 2.0
 * @date 2026-01-03
 */
class SignSegmentationClient {

    private $websocketUrl;
    private $socket;
    private $timeout = 300; // 5 minutes timeout (matching original)

    /**
     * Constructor
     *
     * @param string $websocketUrl WebSocket server URL (e.g., 'ws://localhost:8765')
     */
    public function __construct($websocketUrl = 'ws://localhost:8765') {
        $this->websocketUrl = $websocketUrl;
    }

    /**
     * Process a .hamer file and return VTT segmentation
     *
     * @param string $hamerPath Absolute path to .hamer file on the server
     * @param int $fps Frames per second (default: 60)
     * @param callable|null $progressCallback Optional callback for progress updates
     * @return array Result with 'success', 'vtt_content', 'metadata', or 'error_message'
     */
    public function processHamerFile($hamerPath, $fps = 60, $progressCallback = null) {
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
            $port = $urlParts['port'] ?? 8765;
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

            // 4. Send inference request
            $this->sendInferenceRequest($requestId, $absolutePath, $fps);

            // 5. Wait for ACK or first message
            $firstMessage = $this->receiveMessage();

            // If first message is ACK, wait for result
            // If first message is progress/result, handle it in waitForResult
            if (isset($firstMessage['type']) && $firstMessage['type'] === 'ack') {
                // Got ACK, now wait for result
                $result = $this->waitForResult($requestId, $progressCallback);
            } else if (isset($firstMessage['type']) && $firstMessage['type'] === 'progress') {
                // Server sent progress directly without ACK - handle it
                if ($progressCallback && is_callable($progressCallback)) {
                    call_user_func($progressCallback, $firstMessage);
                }
                // Continue waiting for result
                $result = $this->waitForResult($requestId, $progressCallback);
            } else if (isset($firstMessage['type']) && $firstMessage['type'] === 'result') {
                // Server sent result directly
                $contentField = isset($firstMessage['srt_content']) ? 'srt_content' :
                               (isset($firstMessage['vtt_data']) ? 'vtt_data' : null);

                if ($contentField) {
                    // srt_content is plain text, vtt_data is base64
                    if ($contentField === 'vtt_data') {
                        $vttContent = base64_decode($firstMessage[$contentField]);
                    } else {
                        $vttContent = $firstMessage[$contentField];
                    }
                } else {
                    $vttContent = '';
                }

                $result = [
                    'success' => true,
                    'vtt_content' => $vttContent,
                    'metadata' => $firstMessage['metadata'] ?? []
                ];
            } else if (isset($firstMessage['type']) && $firstMessage['type'] === 'error') {
                // Server sent error
                throw new Exception($firstMessage['message'] ?? 'Unknown error from server');
            } else {
                throw new Exception("Unexpected first message type: " . ($firstMessage['type'] ?? 'unknown'));
            }

            // 7. Close connection
            $this->close();

            return $result;

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
     * Send inference request message
     */
    private function sendInferenceRequest($requestId, $hamerPath, $fps) {
        $message = [
            'type' => 'inference_request',
            'request_id' => $requestId,
            'hamer_path' => $hamerPath,
            'fps' => $fps
        ];

        $this->sendMessage(json_encode($message));
    }

    /**
     * Wait for inference result
     */
    private function waitForResult($requestId, $progressCallback) {
        while (true) {
            $message = $this->receiveMessage();

            if (!isset($message['type'])) {
                throw new Exception("Message missing 'type' field");
            }

            switch ($message['type']) {
                case 'ack':
                    // Ignore ACK messages (already processed or late)
                    break;

                case 'progress':
                    if ($progressCallback && is_callable($progressCallback)) {
                        call_user_func($progressCallback, $message);
                    }
                    break;

                case 'result':
                    // Server sends 'srt_content' as PLAIN TEXT (not base64 encoded)
                    // Old servers may send 'vtt_data' as base64
                    $contentField = isset($message['srt_content']) ? 'srt_content' :
                                   (isset($message['vtt_data']) ? 'vtt_data' : null);

                    $debugInfo = "=== WEBSOCKET RESULT MESSAGE ===\n";
                    $debugInfo .= "Content field used: " . ($contentField ?? 'none') . "\n";
                    $debugInfo .= "Message keys: " . implode(', ', array_keys($message)) . "\n";

                    if ($contentField) {
                        $rawContent = $message[$contentField];
                        $debugInfo .= "Content length (raw): " . strlen($rawContent) . "\n";

                        // Check if content is base64 encoded (vtt_data field)
                        if ($contentField === 'vtt_data') {
                            // vtt_data is base64 encoded
                            $debugInfo .= "Decoding base64 (vtt_data field)...\n";
                            $vttContent = base64_decode($rawContent);
                        } else {
                            // srt_content is plain text (NOT base64)
                            $debugInfo .= "Using plain text (srt_content field)...\n";
                            $vttContent = $rawContent;
                        }

                        $debugInfo .= "Final content length: " . strlen($vttContent) . "\n";
                        $debugInfo .= "Content preview (first 200 chars): " . substr($vttContent, 0, 200) . "\n";
                    } else {
                        $vttContent = '';
                    }

                    $debugInfo .= "================================\n";
                    file_put_contents('/web/zin/debug_segmentation.log', $debugInfo, FILE_APPEND);

                    return [
                        'success' => true,
                        'vtt_content' => $vttContent,
                        'metadata' => $message['metadata'] ?? []
                    ];

                case 'error':
                    return [
                        'success' => false,
                        'error_message' => $message['message'] ?? 'Unknown error',
                        'error_code' => $message['error_code'] ?? null,
                        'details' => $message['details'] ?? []
                    ];

                default:
                    throw new Exception("Unexpected message type: " . $message['type']);
            }
        }
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
