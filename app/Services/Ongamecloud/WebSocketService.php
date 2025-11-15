<?php

namespace Pterodactyl\Services\Ongamecloud;

use Exception;
use Pterodactyl\Models\Server;
use Pterodactyl\Models\OngamecloudWebSocketLog;
use Pterodactyl\Repositories\Wings\DaemonPowerRepository;
use Pterodactyl\Repositories\Wings\DaemonServerRepository;
use Illuminate\Support\Facades\Log;

class WebSocketService
{
    private array $connections = [];
    private array $authenticated = [];
    private array $handshakes = [];
    private array $pendingConfirmations = [];

    public function __construct(
        private DaemonPowerRepository $powerRepository,
        private DaemonServerRepository $serverRepository
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

    public function onMessage($client, string &$data): void
    {
        $connectionId = (int)$client;
        
        if (!isset($this->handshakes[$connectionId]) || !$this->handshakes[$connectionId]) {
            $this->performHandshake($client, $data, $connectionId);
            return;
        }
        
        try {
            $result = $this->decodeFrame($data);
            if ($result === null) {
                Log::debug("OngameCloud WebSocket: Incomplete frame, waiting for more data", [
                    'connection_id' => $connectionId,
                    'data_length' => strlen($data)
                ]);
                return;
            }
            
            [$decoded, $frameSize] = $result;
            $data = substr($data, $frameSize);
            
            Log::info("OngameCloud WebSocket: Frame decoded", [
                'connection_id' => $connectionId,
                'decoded' => $decoded,
                'decoded_hex' => bin2hex($decoded),
                'frame_size' => $frameSize,
                'remaining' => strlen($data)
            ]);
            
            if (empty($decoded)) {
                Log::warning("OngameCloud WebSocket: Empty payload", ['connection_id' => $connectionId]);
                return;
            }
            
            $message = json_decode($decoded, true);
            if (!is_array($message)) {
                Log::error("OngameCloud WebSocket: Invalid JSON", [
                    'connection_id' => $connectionId,
                    'decoded' => $decoded,
                    'decoded_hex' => bin2hex($decoded),
                    'json_error' => json_last_error_msg()
                ]);
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
                    
                    $response = $this->handleAction($client, $connectionId, $message);
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
        unset($this->pendingConfirmations[$connectionId]);
        
        Log::info("OngameCloud WebSocket: Connection closed", ['connection_id' => $connectionId]);
    }


    private function performHandshake($client, string &$data, int $connectionId): void
    {
        if (!str_contains($data, "\r\n\r\n")) {
            Log::debug("OngameCloud WebSocket: Incomplete handshake data", [
                "connection_id" => $connectionId,
                "data_length" => strlen($data)
            ]);
            return;
        }
        
        preg_match('/Sec-WebSocket-Key:\s*(.+?)\r\n/i', $data, $matches);
        if (empty($matches[1])) {
            $headers = substr($data, 0, strpos($data, "\r\n\r\n"));
            Log::error("OngameCloud WebSocket: Invalid handshake - missing Sec-WebSocket-Key", [
                "connection_id" => $connectionId,
                "data_length" => strlen($data),
                "headers" => $headers
            ]);
            fclose($client);
            return;
        }
        
        preg_match('/Upgrade:\s*(.+?)\r\n/i', $data, $upgradeMatches);
        preg_match('/Connection:\s*(.+?)\r\n/i', $data, $connectionMatches);
        
        if (empty($upgradeMatches[1]) || stripos($upgradeMatches[1], 'websocket') === false) {
            Log::error("OngameCloud WebSocket: Invalid Upgrade header", [
                "connection_id" => $connectionId,
                "upgrade" => $upgradeMatches[1] ?? 'missing'
            ]);
            fclose($client);
            return;
        }
        
        $key = trim($matches[1]);
        $acceptKey = base64_encode(sha1($key . '258EAFA5-E914-47DA-95CA-C5AB0DC85B11', true));
        
        $response = "HTTP/1.1 101 Switching Protocols\r\n";
        $response .= "Upgrade: websocket\r\n";
        $response .= "Connection: Upgrade\r\n";
        $response .= "Sec-WebSocket-Accept: {$acceptKey}\r\n\r\n";
        
        fwrite($client, $response);
        $this->handshakes[$connectionId] = true;
        
        Log::info("OngameCloud WebSocket: Handshake successful", [
            "connection_id" => $connectionId,
            "key_length" => strlen($key)
        ]);
        
        $data = '';
        
        $this->send($client, [
            'type' => 'connected',
            'connection_id' => $connectionId,
            'timestamp' => now()->toIso8601String(),
        ]);
        
        Log::debug("OngameCloud WebSocket: Buffer cleared after handshake", [
            "connection_id" => $connectionId
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

    private function decodeFrame(string $data): ?array
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
        
        $frameSize = $offset + $length;
        return [$payload, $frameSize];
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
            'timestamp' => now()->toIso8601String(),
        ]);
        
        Log::info("OngameCloud WebSocket: Successful authentication", ['connection_id' => $connectionId]);
    }

    private function handleAction($client, int $connectionId, array $data): array
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
            
            if (in_array($action, ['start', 'restart'])) {
                $this->scheduleStatusCheck($client, $connectionId, $server, $action);
            }
            
            return [
                'success' => true,
                'action' => $action,
                'server_short_id' => $serverShortId,
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

    private function scheduleStatusCheck($client, int $connectionId, Server $server, string $action): void
    {
        $this->pendingConfirmations[$connectionId] = [
            'client' => $client,
            'server' => $server,
            'action' => $action,
            'started_at' => time(),
            'checks' => 0,
        ];
    }

    public function checkPendingConfirmations(): void
    {
        foreach ($this->pendingConfirmations as $connectionId => $pending) {
            $elapsed = time() - $pending['started_at'];
            
            if ($elapsed > 60) {
                Log::warning("OngameCloud WebSocket: Confirmation timeout", [
                    'connection_id' => $connectionId,
                    'action' => $pending['action'],
                ]);
                unset($this->pendingConfirmations[$connectionId]);
                continue;
            }
            
            if ($pending['checks'] >= 30) {
                Log::warning("OngameCloud WebSocket: Max checks reached", [
                    'connection_id' => $connectionId,
                    'action' => $pending['action'],
                ]);
                unset($this->pendingConfirmations[$connectionId]);
                continue;
            }
            
            $this->pendingConfirmations[$connectionId]['checks']++;
            
            try {
                $status = $this->serverRepository->setServer($pending['server'])->getDetails();
                
                Log::debug("OngameCloud WebSocket: Status check", [
                    'connection_id' => $connectionId,
                    'current_state' => $status['current_state'] ?? 'unknown',
                    'check_number' => $pending['checks'],
                ]);
                
                if (isset($status['current_state']) && $status['current_state'] === 'running') {
                    $this->send($pending['client'], [
                        'type' => 'action_confirmed',
                        'action' => $pending['action'],
                        'server_short_id' => $pending['server']->uuidShort,
                        'status' => 'running',
                        'timestamp' => now()->toIso8601String(),
                    ]);
                    
                    Log::info("OngameCloud WebSocket: Action confirmed", [
                        'connection_id' => $connectionId,
                        'action' => $pending['action'],
                        'server' => $pending['server']->uuidShort,
                        'checks_needed' => $pending['checks'],
                    ]);
                    
                    unset($this->pendingConfirmations[$connectionId]);
                }
            } catch (Exception $e) {
                Log::error("OngameCloud WebSocket: Status check failed", [
                    'connection_id' => $connectionId,
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                ]);
            }
        }
    }
}
