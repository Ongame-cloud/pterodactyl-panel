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
    private array $followedServers = [];
    private array $wingsConnections = [];

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
                    
                case 'follow':
                    if (!isset($this->authenticated[$connectionId])) {
                        $this->send($client, [
                            'type' => 'error',
                            'error' => 'Not authenticated',
                            'timestamp' => now()->toIso8601String(),
                        ]);
                        return;
                    }
                    
                    $response = $this->handleFollow($client, $connectionId, $message);
                    $this->send($client, [
                        'type' => 'follow_response',
                        ...$response,
                    ]);
                    break;
                    
                case 'unfollow':
                    if (!isset($this->authenticated[$connectionId])) {
                        $this->send($client, [
                            'type' => 'error',
                            'error' => 'Not authenticated',
                            'timestamp' => now()->toIso8601String(),
                        ]);
                        return;
                    }
                    
                    $response = $this->handleUnfollow($connectionId, $message);
                    $this->send($client, [
                        'type' => 'unfollow_response',
                        ...$response,
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
        
        foreach (array_keys($this->pendingConfirmations) as $key) {
            if (str_starts_with($key, $connectionId . '_')) {
                unset($this->pendingConfirmations[$key]);
            }
        }
        
        if (isset($this->followedServers[$connectionId])) {
            foreach (array_keys($this->followedServers[$connectionId]) as $serverShortId) {
                $this->disconnectFromWings($connectionId, $serverShortId);
            }
        }
        
        unset($this->connections[$connectionId]);
        unset($this->authenticated[$connectionId]);
        unset($this->handshakes[$connectionId]);
        unset($this->followedServers[$connectionId]);
        
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
        
        $pendingKey = $connectionId . '_' . $serverShortId;
        if (isset($this->pendingConfirmations[$pendingKey])) {
            return [
                'success' => false,
                'error' => 'Action already in progress for this server',
                'timestamp' => now()->toIso8601String(),
            ];
        }
        
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
            
            if (in_array($action, ['start', 'restart', 'stop'])) {
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
        $pendingKey = $connectionId . '_' . $server->uuidShort;
        $this->pendingConfirmations[$pendingKey] = [
            'client' => $client,
            'connection_id' => $connectionId,
            'server' => $server,
            'action' => $action,
            'started_at' => time(),
            'checks' => 0,
        ];
    }

    public function checkPendingConfirmations(): void
    {
        foreach ($this->pendingConfirmations as $pendingKey => $pending) {
            $elapsed = time() - $pending['started_at'];
            
            if ($elapsed > 60) {
                Log::warning("OngameCloud WebSocket: Confirmation timeout", [
                    'connection_id' => $pending['connection_id'],
                    'server' => $pending['server']->uuidShort,
                    'action' => $pending['action'],
                ]);
                unset($this->pendingConfirmations[$pendingKey]);
                continue;
            }
            
            if ($pending['checks'] >= 30) {
                Log::warning("OngameCloud WebSocket: Max checks reached", [
                    'connection_id' => $pending['connection_id'],
                    'server' => $pending['server']->uuidShort,
                    'action' => $pending['action'],
                ]);
                unset($this->pendingConfirmations[$pendingKey]);
                continue;
            }
            
            $this->pendingConfirmations[$pendingKey]['checks']++;
            
            try {
                $status = $this->serverRepository->setServer($pending['server'])->getDetails();
                
                Log::debug("OngameCloud WebSocket: Status check", [
                    'connection_id' => $pending['connection_id'],
                    'server' => $pending['server']->uuidShort,
                    'state' => $status['state'] ?? 'unknown',
                    'check_number' => $pending['checks'],
                ]);
                
                $expectedState = in_array($pending['action'], ['start', 'restart']) ? 'running' : 'offline';
                
                if (isset($status['state']) && $status['state'] === $expectedState) {
                    $this->send($pending['client'], [
                        'type' => 'action_confirmed',
                        'action' => $pending['action'],
                        'server_short_id' => $pending['server']->uuidShort,
                        'status' => $status['state'],
                        'timestamp' => now()->toIso8601String(),
                    ]);
                    
                    Log::info("OngameCloud WebSocket: Action confirmed", [
                        'connection_id' => $pending['connection_id'],
                        'action' => $pending['action'],
                        'server' => $pending['server']->uuidShort,
                        'checks_needed' => $pending['checks'],
                        'final_state' => $status['state'],
                    ]);
                    
                    unset($this->pendingConfirmations[$pendingKey]);
                }
            } catch (Exception $e) {
                Log::error("OngameCloud WebSocket: Status check failed", [
                    'connection_id' => $pending['connection_id'],
                    'server' => $pending['server']->uuidShort,
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                ]);
            }
        }
    }

    private function handleFollow($client, int $connectionId, array $data): array
    {
        if (!isset($data['server_short_id'])) {
            return [
                'success' => false,
                'error' => 'server_short_id is required',
                'timestamp' => now()->toIso8601String(),
            ];
        }
        
        $serverShortId = $data['server_short_id'];
        $server = Server::where('uuidShort', $serverShortId)->first();
        
        if (!$server) {
            return [
                'success' => false,
                'error' => 'Server not found',
                'timestamp' => now()->toIso8601String(),
            ];
        }
        
        if (!isset($this->followedServers[$connectionId])) {
            $this->followedServers[$connectionId] = [];
        }
        
        $this->followedServers[$connectionId][$serverShortId] = [
            'client' => $client,
            'server' => $server,
            'last_update' => 0,
        ];
        
        $this->connectToWings($connectionId, $serverShortId, $server);
        
        Log::info("OngameCloud WebSocket: Following server", [
            'connection_id' => $connectionId,
            'server' => $serverShortId,
            'total_followed' => count($this->followedServers[$connectionId]),
        ]);
        
        return [
            'success' => true,
            'server_short_id' => $serverShortId,
            'timestamp' => now()->toIso8601String(),
        ];
    }

    private function handleUnfollow(int $connectionId, array $data): array
    {
        if (!isset($data['server_short_id'])) {
            return [
                'success' => false,
                'error' => 'server_short_id is required',
                'timestamp' => now()->toIso8601String(),
            ];
        }
        
        $serverShortId = $data['server_short_id'];
        
        if (!isset($this->followedServers[$connectionId][$serverShortId])) {
            return [
                'success' => false,
                'error' => 'Not following this server',
                'timestamp' => now()->toIso8601String(),
            ];
        }
        
        $this->disconnectFromWings($connectionId, $serverShortId);
        
        unset($this->followedServers[$connectionId][$serverShortId]);
        
        if (empty($this->followedServers[$connectionId])) {
            unset($this->followedServers[$connectionId]);
        }
        
        Log::info("OngameCloud WebSocket: Unfollowed server", [
            'connection_id' => $connectionId,
            'server' => $serverShortId,
        ]);
        
        return [
            'success' => true,
            'server_short_id' => $serverShortId,
            'timestamp' => now()->toIso8601String(),
        ];
    }

    public function updateFollowedServers(): void
    {
        $now = time();
        
        foreach ($this->followedServers as $connectionId => $servers) {
            foreach ($servers as $serverShortId => $follow) {
                if ($now - $follow['last_update'] < 1) {
                    continue;
                }
                
                $this->followedServers[$connectionId][$serverShortId]['last_update'] = $now;
                
                try {
                    $status = $this->serverRepository->setServer($follow['server'])->getDetails();
                    
                    $this->send($follow['client'], [
                        'type' => 'resources',
                        'server_short_id' => $follow['server']->uuidShort,
                        'state' => $status['state'] ?? 'offline',
                        'resources' => [
                            'memory_bytes' => $status['utilization']['memory_bytes'] ?? 0,
                            'cpu_absolute' => $status['utilization']['cpu_absolute'] ?? 0,
                            'uptime' => $status['utilization']['uptime'] ?? 0,
                        ],
                        'timestamp' => now()->toIso8601String(),
                    ]);
                } catch (Exception $e) {
                    Log::debug("OngameCloud WebSocket: Failed to get server resources", [
                        'connection_id' => $connectionId,
                        'server' => $serverShortId,
                        'error' => $e->getMessage(),
                    ]);
                }
                
                $this->processWingsMessages($connectionId, $serverShortId);
            }
        }
    }

    private function connectToWings(int $connectionId, string $serverShortId, Server $server): void
    {
        try {
            $credentials = $server->node->getConnectionAddress();
            $token = $server->node->daemon_token_id . '.' . decrypt($server->node->daemon_token);
            
            $wsUrl = str_replace(['https://', 'http://'], 'wss://', $credentials) . '/api/servers/' . $server->uuid . '/ws';
            
            $context = stream_context_create([
                'ssl' => [
                    'verify_peer' => false,
                    'verify_peer_name' => false,
                ],
            ]);
            
            $socket = @stream_socket_client($wsUrl, $errno, $errstr, 5, STREAM_CLIENT_CONNECT | STREAM_CLIENT_ASYNC_CONNECT, $context);
            
            if (!$socket) {
                Log::error("OngameCloud WebSocket: Failed to connect to Wings", [
                    'connection_id' => $connectionId,
                    'server' => $serverShortId,
                    'error' => $errstr,
                ]);
                return;
            }
            
            stream_set_blocking($socket, false);
            
            $key = $connectionId . '_' . $serverShortId;
            $this->wingsConnections[$key] = [
                'socket' => $socket,
                'server' => $server,
                'token' => $token,
                'authenticated' => false,
                'handshake_done' => false,
                'buffer' => '',
                'client' => $this->followedServers[$connectionId][$serverShortId]['client'],
            ];
            
            $this->performWingsHandshake($key, $wsUrl);
            
            Log::info("OngameCloud WebSocket: Connected to Wings", [
                'connection_id' => $connectionId,
                'server' => $serverShortId,
            ]);
        } catch (Exception $e) {
            Log::error("OngameCloud WebSocket: Wings connection error", [
                'connection_id' => $connectionId,
                'server' => $serverShortId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function disconnectFromWings(int $connectionId, string $serverShortId): void
    {
        $key = $connectionId . '_' . $serverShortId;
        
        if (isset($this->wingsConnections[$key])) {
            if (is_resource($this->wingsConnections[$key]['socket'])) {
                @fclose($this->wingsConnections[$key]['socket']);
            }
            unset($this->wingsConnections[$key]);
            
            Log::info("OngameCloud WebSocket: Disconnected from Wings", [
                'connection_id' => $connectionId,
                'server' => $serverShortId,
            ]);
        }
    }

    private function performWingsHandshake(string $key, string $wsUrl): void
    {
        $conn = &$this->wingsConnections[$key];
        $socket = $conn['socket'];
        
        $host = parse_url($wsUrl, PHP_URL_HOST);
        $path = parse_url($wsUrl, PHP_URL_PATH);
        $secKey = base64_encode(random_bytes(16));
        
        $request = "GET {$path} HTTP/1.1\r\n";
        $request .= "Host: {$host}\r\n";
        $request .= "Upgrade: websocket\r\n";
        $request .= "Connection: Upgrade\r\n";
        $request .= "Sec-WebSocket-Key: {$secKey}\r\n";
        $request .= "Sec-WebSocket-Version: 13\r\n";
        $request .= "\r\n";
        
        @fwrite($socket, $request);
        $conn['handshake_done'] = true;
    }

    private function processWingsMessages(int $connectionId, string $serverShortId): void
    {
        $key = $connectionId . '_' . $serverShortId;
        
        if (!isset($this->wingsConnections[$key])) {
            return;
        }
        
        $conn = &$this->wingsConnections[$key];
        $socket = $conn['socket'];
        
        if (!is_resource($socket) || feof($socket)) {
            $this->disconnectFromWings($connectionId, $serverShortId);
            return;
        }
        
        $data = @fread($socket, 8192);
        if ($data === false || $data === '') {
            return;
        }
        
        $conn['buffer'] .= $data;
        
        if (!$conn['authenticated'] && str_contains($conn['buffer'], "\r\n\r\n")) {
            $headerEnd = strpos($conn['buffer'], "\r\n\r\n") + 4;
            $conn['buffer'] = substr($conn['buffer'], $headerEnd);
            
            $authMessage = json_encode([
                'event' => 'auth',
                'args' => [$conn['token']],
            ]);
            
            $frame = $this->encodeFrame($authMessage);
            @fwrite($socket, $frame);
            
            $conn['authenticated'] = true;
            
            Log::info("OngameCloud WebSocket: Wings authenticated", [
                'connection_id' => $connectionId,
                'server' => $serverShortId,
            ]);
            
            $logsRequest = json_encode([
                'event' => 'send logs',
                'args' => [null],
            ]);
            @fwrite($socket, $this->encodeFrame($logsRequest));
            
            return;
        }
        
        if (!$conn['authenticated']) {
            return;
        }
        
        while (strlen($conn['buffer']) >= 2) {
            $result = $this->decodeFrame($conn['buffer']);
            if ($result === null) {
                break;
            }
            
            [$payload, $frameSize] = $result;
            $conn['buffer'] = substr($conn['buffer'], $frameSize);
            
            $message = json_decode($payload, true);
            if (!is_array($message) || !isset($message['event'])) {
                continue;
            }
            
            if ($message['event'] === 'console output' && isset($message['args'][0])) {
                $this->send($conn['client'], [
                    'type' => 'console_output',
                    'server_short_id' => $serverShortId,
                    'output' => $message['args'][0],
                    'timestamp' => now()->toIso8601String(),
                ]);
            }
        }
    }
}
