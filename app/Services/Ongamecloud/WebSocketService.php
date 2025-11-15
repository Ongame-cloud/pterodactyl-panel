<?php

namespace Pterodactyl\Services\Ongamecloud;

use Exception;
use Pterodactyl\Models\Server;
use Pterodactyl\Models\OngamecloudWebSocketLog;
use Pterodactyl\Repositories\Wings\DaemonPowerRepository;
use Illuminate\Support\Facades\Log;

class WebSocketService
{
    private array $connections = [];
    private array $authenticated = [];
    private array $handshakes = [];

    public function __construct(
        private DaemonPowerRepository $powerRepository
    ) {
    }

    public function onConnect($client): void
    {
        $connectionId = (int)$client;
        $this->connections[$connectionId] = [
            'connected_at' => now(),
            'request_count' => 0,
        ];
        $this->handshakes[$connectionId] = false;
        
        Log::info("OngameCloud WebSocket: New connection", ['connection_id' => $connectionId]);
    }

    public function onMessage($client, string $data): void
    {
        $connectionId = (int)$client;
        
        if (!$this->handshakes[$connectionId]) {
            $this->performHandshake($client, $data, $connectionId);
            return;
        }
        
        try {
            $decoded = $this->decodeFrame($data);
            if ($decoded === null) {
                return;
            }
            
            $message = json_decode($decoded, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new Exception('Invalid JSON format');
            }
            
            if (!isset($message['type'])) {
                throw new Exception('Message type is required');
            }
            
            $this->connections[$connectionId]['request_count']++;
            
            Log::info("OngameCloud WebSocket: Message received", [
                'connection_id' => $connectionId,
                'type' => $message['type'],
            ]);
            
            switch ($message['type']) {
                case 'auth':
                    $this->handleAuth($client, $connectionId, $message);
                    break;
                    
                case 'action':
                    if (!isset($this->authenticated[$connectionId])) {
                        $this->send($client, [
                            'type' => 'error',
                            'error' => 'Not authenticated',
                            'timestamp' => now()->toIso8601String(),
                        ]);
                        return;
                    }
                    
                    $response = $this->handleAction($connectionId, $message);
                    $this->send($client, [
                        'type' => 'action_response',
                        ...$response,
                    ]);
                    break;
                    
                case 'ping':
                    $this->send($client, [
                        'type' => 'pong',
                        'timestamp' => now()->toIso8601String(),
                    ]);
                    break;
                    
                default:
                    throw new Exception('Unknown message type: ' . $message['type']);
            }
        } catch (Exception $e) {
            Log::error("OngameCloud WebSocket: Message error", [
                'connection_id' => $connectionId,
                'error' => $e->getMessage(),
            ]);
            
            $this->send($client, [
                'type' => 'error',
                'error' => $e->getMessage(),
                'timestamp' => now()->toIso8601String(),
            ]);
        }
    }

    public function onClose($client): void
    {
        $connectionId = (int)$client;
        
        unset($this->connections[$connectionId]);
        unset($this->authenticated[$connectionId]);
        unset($this->handshakes[$connectionId]);
        
        Log::info("OngameCloud WebSocket: Connection closed", ['connection_id' => $connectionId]);
    }

    private function performHandshake($client, string $data, int $connectionId): void
    {
        preg_match('/Sec-WebSocket-Key: (.*)\r\n/', $data, $matches);
        if (empty($matches[1])) {
            fclose($client);
            return;
        }
        
        $key = $matches[1];
        $acceptKey = base64_encode(sha1($key . '258EAFA5-E914-47DA-95CA-C5AB0DC85B11', true));
        
        $response = "HTTP/1.1 101 Switching Protocols\r\n";
        $response .= "Upgrade: websocket\r\n";
        $response .= "Connection: Upgrade\r\n";
        $response .= "Sec-WebSocket-Accept: {$acceptKey}\r\n\r\n";
        
        fwrite($client, $response);
        $this->handshakes[$connectionId] = true;
        
        $this->send($client, [
            'type' => 'connected',
            'message' => 'Connected to Ongamecloud WebSocket server',
            'connection_id' => $connectionId,
            'timestamp' => now()->toIso8601String(),
        ]);
    }

    private function send($client, array $data): void
    {
        $payload = json_encode($data);
        $frame = $this->encodeFrame($payload);
        fwrite($client, $frame);
    }

    private function encodeFrame(string $payload): string
    {
        $length = strlen($payload);
        $frame = chr(0x81);
        
        if ($length <= 125) {
            $frame .= chr($length);
        } elseif ($length <= 65535) {
            $frame .= chr(126) . pack('n', $length);
        } else {
            $frame .= chr(127) . pack('J', $length);
        }
        
        return $frame . $payload;
    }

    private function decodeFrame(string $data): ?string
    {
        if (strlen($data) < 2) {
            return null;
        }
        
        $byte1 = ord($data[0]);
        $byte2 = ord($data[1]);
        
        $masked = ($byte2 & 0x80) !== 0;
        $length = $byte2 & 0x7F;
        $offset = 2;
        
        if ($length === 126) {
            if (strlen($data) < 4) return null;
            $length = unpack('n', substr($data, 2, 2))[1];
            $offset = 4;
        } elseif ($length === 127) {
            if (strlen($data) < 10) return null;
            $length = unpack('J', substr($data, 2, 8))[1];
            $offset = 10;
        }
        
        if ($masked) {
            $mask = substr($data, $offset, 4);
            $offset += 4;
        }
        
        if (strlen($data) < $offset + $length) {
            return null;
        }
        
        $payload = substr($data, $offset, $length);
        
        if ($masked) {
            for ($i = 0; $i < $length; $i++) {
                $payload[$i] = $payload[$i] ^ $mask[$i % 4];
            }
        }
        
        return $payload;
    }

    private function handleAuth($client, int $connectionId, array $data): void
    {
        if (!isset($data['token'])) {
            $this->send($client, [
                'type' => 'auth_response',
                'success' => false,
                'error' => 'Token is required',
                'timestamp' => now()->toIso8601String(),
            ]);
            return;
        }
        
        $expectedToken = config('ongamecloud.websocket.auth_token');
        
        if ($data['token'] !== $expectedToken) {
            $this->send($client, [
                'type' => 'auth_response',
                'success' => false,
                'error' => 'Invalid authentication token',
                'timestamp' => now()->toIso8601String(),
            ]);
            
            Log::warning("OngameCloud WebSocket: Failed authentication", ['connection_id' => $connectionId]);
            return;
        }
        
        $this->authenticated[$connectionId] = true;
        
        $this->send($client, [
            'type' => 'auth_response',
            'success' => true,
            'message' => 'Authentication successful',
            'timestamp' => now()->toIso8601String(),
        ]);
        
        Log::info("OngameCloud WebSocket: Successful authentication", ['connection_id' => $connectionId]);
    }

    private function handleAction(int $connectionId, array $data): array
    {
        if (!isset($data['action']) || !isset($data['server_short_id'])) {
            return [
                'success' => false,
                'error' => 'Action and server_short_id are required',
                'timestamp' => now()->toIso8601String(),
            ];
        }
        
        $action = $data['action'];
        $serverShortId = $data['server_short_id'];
        
        if (!in_array($action, ['start', 'stop', 'restart', 'kill'])) {
            return [
                'success' => false,
                'error' => 'Invalid action',
                'timestamp' => now()->toIso8601String(),
            ];
        }
        
        $server = Server::where('uuidShort', $serverShortId)->first();
        
        if (!$server) {
            return [
                'success' => false,
                'error' => 'Server not found',
                'timestamp' => now()->toIso8601String(),
            ];
        }
        
        try {
            $this->powerRepository->setServer($server)->send($action);
            
            Log::info("OngameCloud WebSocket: Action executed", [
                'connection_id' => $connectionId,
                'action' => $action,
                'server' => $serverShortId,
            ]);
            
            return [
                'success' => true,
                'action' => $action,
                'server_short_id' => $serverShortId,
                'message' => "Action '{$action}' executed successfully",
                'timestamp' => now()->toIso8601String(),
            ];
        } catch (Exception $e) {
            Log::error("OngameCloud WebSocket: Action failed", [
                'connection_id' => $connectionId,
                'action' => $action,
                'server' => $serverShortId,
                'error' => $e->getMessage(),
            ]);
            
            return [
                'success' => false,
                'error' => $e->getMessage(),
                'timestamp' => now()->toIso8601String(),
            ];
        }
    }
}
