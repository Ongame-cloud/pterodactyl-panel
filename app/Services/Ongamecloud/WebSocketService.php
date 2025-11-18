<?php

namespace Pterodactyl\Services\Ongamecloud;

use Exception;
use Carbon\CarbonImmutable;
use Pterodactyl\Models\User;
use Pterodactyl\Models\Server;
use Pterodactyl\Models\OngamecloudWebSocketLog;
use Pterodactyl\Repositories\Wings\DaemonPowerRepository;
use Pterodactyl\Repositories\Wings\DaemonServerRepository;
use Pterodactyl\Services\Nodes\NodeJWTService;
use Illuminate\Support\Facades\Log;

class WebSocketService
{
    private array $connections = [];
    private array $authenticated = [];
    private array $handshakes = [];
    private array $pendingConfirmations = [];
    private array $followedServers = [];
    private array $followedConsoles = [];
    private array $wingsConnections = [];
    private array $consoleHistory = [];
    private array $permanentWingsConnections = [];
    private array $consoleBatches = [];
    private array $batchTimers = [];

    public function __construct(
        private DaemonPowerRepository $powerRepository,
        private DaemonServerRepository $serverRepository,
        private NodeJWTService $jwtService,
        private ConsoleLogService $consoleLogService
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
                    
                case 'follow_console':
                    if (!isset($this->authenticated[$connectionId])) {
                        $this->send($client, [
                            'type' => 'error',
                            'error' => 'Not authenticated',
                            'timestamp' => now()->toIso8601String(),
                        ]);
                        return;
                    }
                    
                    $response = $this->handleFollowConsole($client, $connectionId, $message);
                    $this->send($client, [
                        'type' => 'follow_console_response',
                        ...$response,
                    ]);
                    break;
                    
                case 'unfollow_console':
                    if (!isset($this->authenticated[$connectionId])) {
                        $this->send($client, [
                            'type' => 'error',
                            'error' => 'Not authenticated',
                            'timestamp' => now()->toIso8601String(),
                        ]);
                        return;
                    }
                    
                    $response = $this->handleUnfollowConsole($connectionId, $message);
                    $this->send($client, [
                        'type' => 'unfollow_console_response',
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
        
        if (isset($this->followedConsoles[$connectionId])) {
            foreach (array_keys($this->followedConsoles[$connectionId]) as $serverShortId) {
                $this->disconnectFromWings($connectionId, $serverShortId);
            }
        }
        
        unset($this->connections[$connectionId]);
        unset($this->authenticated[$connectionId]);
        unset($this->handshakes[$connectionId]);
        unset($this->followedServers[$connectionId]);
        unset($this->followedConsoles[$connectionId]);
        
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
        if (!is_resource($client) || feof($client)) {
            return;
        }
        
        $payload = json_encode($data);
        $frame = $this->encodeFrame($payload);
        @fwrite($client, $frame);
    }

    private function encodeFrame(string $payload, int $opcode = 0x1): string
    {
        $length = strlen($payload);
        $frame = chr(0x80 | $opcode);
        
        if ($length <= 125) {
            $frame .= chr($length);
        } elseif ($length <= 65535) {
            $frame .= chr(126) . pack('n', $length);
        } else {
            $frame .= chr(127) . pack('J', $length);
        }
        
        return $frame . $payload;
    }

    private function encodeFrameForWings(string $payload, int $opcode = 0x1): string
    {
        $length = strlen($payload);
        $frame = chr(0x80 | $opcode);
        
        $mask = pack('N', rand());
        
        if ($length <= 125) {
            $frame .= chr(0x80 | $length);
        } elseif ($length <= 65535) {
            $frame .= chr(0x80 | 126) . pack('n', $length);
        } else {
            $frame .= chr(0x80 | 127) . pack('J', $length);
        }
        
        $frame .= $mask;
        
        for ($i = 0; $i < $length; $i++) {
            $frame .= $payload[$i] ^ $mask[$i % 4];
        }
        
        return $frame;
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

    private function handleFollowConsole($client, int $connectionId, array $data): array
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
        
        if (!isset($this->followedConsoles[$connectionId])) {
            $this->followedConsoles[$connectionId] = [];
        }
        
        $this->followedConsoles[$connectionId][$serverShortId] = [
            'client' => $client,
            'server' => $server,
        ];
        
        $this->connectToWings($connectionId, $serverShortId, $server);
        
        Log::info("OngameCloud WebSocket: Following console", [
            'connection_id' => $connectionId,
            'server' => $serverShortId,
        ]);
        
        return [
            'success' => true,
            'server_short_id' => $serverShortId,
            'timestamp' => now()->toIso8601String(),
        ];
    }

    private function handleUnfollowConsole(int $connectionId, array $data): array
    {
        if (!isset($data['server_short_id'])) {
            return [
                'success' => false,
                'error' => 'server_short_id is required',
                'timestamp' => now()->toIso8601String(),
            ];
        }
        
        $serverShortId = $data['server_short_id'];
        
        if (!isset($this->followedConsoles[$connectionId][$serverShortId])) {
            return [
                'success' => false,
                'error' => 'Not following this console',
                'timestamp' => now()->toIso8601String(),
            ];
        }
        
        $this->disconnectFromWings($connectionId, $serverShortId);
        
        unset($this->followedConsoles[$connectionId][$serverShortId]);
        
        if (empty($this->followedConsoles[$connectionId])) {
            unset($this->followedConsoles[$connectionId]);
        }
        
        Log::info("OngameCloud WebSocket: Unfollowed console", [
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
            }
        }
        
        foreach ($this->followedConsoles as $connectionId => $consoles) {
            foreach ($consoles as $serverShortId => $follow) {
                $this->processWingsMessages($connectionId, $serverShortId);
            }
        }
        
        foreach ($this->permanentWingsConnections as $serverShortId => $conn) {
            $this->processPermanentWingsMessages($serverShortId);
        }
    }
    
    public function connectAllServersToWings(): void
    {
        $servers = Server::all();
        
        foreach ($servers as $server) {
            $serverShortId = $server->uuidShort;
            
            if (isset($this->permanentWingsConnections[$serverShortId])) {
                continue;
            }
            
            $this->connectPermanentWings($serverShortId, $server);
        }
    }

    private function connectToWings(int $connectionId, string $serverShortId, Server $server): void
    {
        try {
            $credentials = $server->node->getConnectionAddress();
            
            $systemUser = User::where('root_admin', 1)->first();
            if (!$systemUser) {
                Log::error("OngameCloud WebSocket: No admin user found for Wings connection");
                return;
            }
            
            $decryptedNodeToken = $server->node->getDecryptedKey();
            
            Log::info("OngameCloud WebSocket: Node token info", [
                'connection_id' => $connectionId,
                'server' => $serverShortId,
                'node_id' => $server->node->id,
                'token_id' => $server->node->daemon_token_id,
                'decrypted_token_length' => strlen($decryptedNodeToken),
                'decrypted_token_preview' => substr($decryptedNodeToken, 0, 10) . '...',
            ]);
            
            $jwtToken = $this->jwtService
                ->setExpiresAt(CarbonImmutable::now()->addHours(1))
                ->setUser($systemUser)
                ->setClaims([
                    'server_uuid' => $server->uuid,
                    'permissions' => [
                        '*',
                        'admin.websocket.errors',
                        'admin.websocket.install',
                        'admin.websocket.transfer',
                    ],
                ])
                ->handle($server->node, $systemUser->id . $server->uuid);
            
            $token = $jwtToken->toString();
            
            Log::info("OngameCloud WebSocket: Attempting Wings connection", [
                'connection_id' => $connectionId,
                'server' => $serverShortId,
                'credentials' => $credentials,
                'user_id' => $systemUser->id,
                'token_length' => strlen($token),
                'jwt_claims' => $jwtToken->claims()->all(),
            ]);
            
            $parsedUrl = parse_url($credentials);
            $host = $parsedUrl['host'];
            $port = $parsedUrl['port'] ?? (($parsedUrl['scheme'] ?? 'https') === 'https' ? 443 : 80);
            $scheme = ($parsedUrl['scheme'] ?? 'https') === 'https' ? 'ssl' : 'tcp';
            
            Log::info("OngameCloud WebSocket: Parsed connection details", [
                'host' => $host,
                'port' => $port,
                'scheme' => $scheme,
            ]);
            
            $context = stream_context_create([
                'ssl' => [
                    'verify_peer' => false,
                    'verify_peer_name' => false,
                ],
            ]);
            
            $socket = @stream_socket_client("{$scheme}://{$host}:{$port}", $errno, $errstr, 10, STREAM_CLIENT_CONNECT, $context);
            
            if (!$socket) {
                Log::error("OngameCloud WebSocket: Failed to connect to Wings", [
                    'connection_id' => $connectionId,
                    'server' => $serverShortId,
                    'host' => $host,
                    'port' => $port,
                    'error' => $errstr,
                ]);
                return;
            }
            
            stream_set_blocking($socket, false);
            
            $client = $this->followedConsoles[$connectionId][$serverShortId]['client'] ?? null;
            
            if (!$client) {
                Log::error("OngameCloud WebSocket: No client found for Wings connection", [
                    'connection_id' => $connectionId,
                    'server' => $serverShortId,
                ]);
                return;
            }
            
            $key = $connectionId . '_' . $serverShortId;
            $this->wingsConnections[$key] = [
                'socket' => $socket,
                'server' => $server,
                'token' => $token,
                'authenticated' => false,
                'handshake_done' => false,
                'buffer' => '',
                'client' => $client,
                'host' => $host,
                'path' => '/api/servers/' . $server->uuid . '/ws',
                'connection_id' => $connectionId,
            ];
            
            if (!isset($this->consoleHistory[$serverShortId])) {
                $this->consoleHistory[$serverShortId] = [];
            }
            
            $this->performWingsHandshake($key);
            
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

    private function performWingsHandshake(string $key): void
    {
        $conn = &$this->wingsConnections[$key];
        $socket = $conn['socket'];
        
        $secKey = base64_encode(random_bytes(16));
        
        $origin = config('app.url');
        
        $request = "GET {$conn['path']} HTTP/1.1\r\n";
        $request .= "Host: {$conn['host']}\r\n";
        $request .= "Origin: {$origin}\r\n";
        $request .= "Upgrade: websocket\r\n";
        $request .= "Connection: Upgrade\r\n";
        $request .= "Sec-WebSocket-Key: {$secKey}\r\n";
        $request .= "Sec-WebSocket-Version: 13\r\n";
        $request .= "\r\n";
        
        Log::info("OngameCloud WebSocket: Sending Wings handshake", [
            'key' => $key,
            'host' => $conn['host'],
            'path' => $conn['path'],
            'origin' => $origin,
        ]);
        
        $written = @fwrite($socket, $request);
        $conn['handshake_done'] = true;
        
        Log::info("OngameCloud WebSocket: Wings handshake sent", [
            'key' => $key,
            'bytes_written' => $written,
        ]);
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
        
        Log::debug("OngameCloud WebSocket: Received Wings data", [
            'connection_id' => $connectionId,
            'server' => $serverShortId,
            'data_length' => strlen($data),
            'authenticated' => $conn['authenticated'],
        ]);
        
        $conn['buffer'] .= $data;
        
        if (!$conn['authenticated'] && str_contains($conn['buffer'], "\r\n\r\n")) {
            $headerEnd = strpos($conn['buffer'], "\r\n\r\n") + 4;
            $headers = substr($conn['buffer'], 0, $headerEnd);
            $conn['buffer'] = substr($conn['buffer'], $headerEnd);
            
            Log::info("OngameCloud WebSocket: Wings handshake response received", [
                'connection_id' => $connectionId,
                'server' => $serverShortId,
                'headers_length' => strlen($headers),
            ]);
            
            $authMessage = json_encode([
                'event' => 'auth',
                'args' => [$conn['token']],
            ]);
            
            $frame = $this->encodeFrameForWings($authMessage);
            @fwrite($socket, $frame);
            
            Log::info("OngameCloud WebSocket: Sent auth to Wings", [
                'connection_id' => $connectionId,
                'server' => $serverShortId,
            ]);
        }
        
        while (strlen($conn['buffer']) >= 2) {
            $result = $this->decodeFrame($conn['buffer']);
            if ($result === null) {
                break;
            }
            
            [$payload, $frameSize] = $result;
            $conn['buffer'] = substr($conn['buffer'], $frameSize);
            
            Log::debug("OngameCloud WebSocket: Wings frame decoded", [
                'connection_id' => $connectionId,
                'server' => $serverShortId,
                'payload' => $payload,
            ]);
            
            $message = json_decode($payload, true);
            if (!is_array($message) || !isset($message['event'])) {
                Log::warning("OngameCloud WebSocket: Invalid Wings message", [
                    'connection_id' => $connectionId,
                    'server' => $serverShortId,
                    'payload' => $payload,
                ]);
                continue;
            }
            
            Log::info("OngameCloud WebSocket: Wings event received", [
                'connection_id' => $connectionId,
                'server' => $serverShortId,
                'event' => $message['event'],
            ]);
            
            if ($message['event'] === 'auth success') {
                $conn['authenticated'] = true;
                
                Log::info("OngameCloud WebSocket: Wings authenticated", [
                    'connection_id' => $connectionId,
                    'server' => $serverShortId,
                ]);
            } elseif ($message['event'] === 'console output' && isset($message['args'][0])) {
                $output = $message['args'][0];
                
                $output = str_replace('Pterodactyl', 'Ongamecloud', $output);
                
                if (!isset($this->consoleHistory[$serverShortId])) {
                    $this->consoleHistory[$serverShortId] = [];
                }
                
                $this->consoleHistory[$serverShortId][] = $output;
                if (count($this->consoleHistory[$serverShortId]) > 50) {
                    array_shift($this->consoleHistory[$serverShortId]);
                }
                
                if (!isset($this->permanentWingsConnections[$serverShortId])) {
                    $this->addToConsoleBatch($serverShortId, $output, $conn['connection_id']);
                }
            } elseif ($message['event'] === 'stats' && isset($message['args'][0])) {
            }
        }
    }
    
    private function connectPermanentWings(string $serverShortId, Server $server): void
    {
        try {
            $credentials = $server->node->getConnectionAddress();
            
            $systemUser = User::where('root_admin', 1)->first();
            if (!$systemUser) {
                Log::error("OngameCloud WebSocket: No admin user found for permanent Wings connection");
                return;
            }
            
            $decryptedNodeToken = $server->node->getDecryptedKey();
            
            $jwtToken = $this->jwtService
                ->setExpiresAt(CarbonImmutable::now()->addHours(1))
                ->setUser($systemUser)
                ->setClaims([
                    'server_uuid' => $server->uuid,
                    'permissions' => [
                        '*',
                        'admin.websocket.errors',
                        'admin.websocket.install',
                        'admin.websocket.transfer',
                    ],
                ])
                ->handle($server->node, $systemUser->id . $server->uuid);
            
            $token = $jwtToken->toString();
            
            $parsedUrl = parse_url($credentials);
            $host = $parsedUrl['host'];
            $port = $parsedUrl['port'] ?? (($parsedUrl['scheme'] ?? 'https') === 'https' ? 443 : 80);
            $scheme = ($parsedUrl['scheme'] ?? 'https') === 'https' ? 'ssl' : 'tcp';
            
            $context = stream_context_create([
                'ssl' => [
                    'verify_peer' => false,
                    'verify_peer_name' => false,
                ],
            ]);
            
            $socket = @stream_socket_client("{$scheme}://{$host}:{$port}", $errno, $errstr, 10, STREAM_CLIENT_CONNECT, $context);
            
            if (!$socket) {
                Log::error("OngameCloud WebSocket: Failed to connect permanent Wings", [
                    'server' => $serverShortId,
                    'host' => $host,
                    'port' => $port,
                    'error' => $errstr,
                ]);
                return;
            }
            
            stream_set_blocking($socket, false);
            
            $this->permanentWingsConnections[$serverShortId] = [
                'socket' => $socket,
                'server' => $server,
                'token' => $token,
                'authenticated' => false,
                'handshake_done' => false,
                'buffer' => '',
                'host' => $host,
                'path' => '/api/servers/' . $server->uuid . '/ws',
                'connected_at' => time(),
            ];
            
            if (!isset($this->consoleHistory[$serverShortId])) {
                $this->consoleHistory[$serverShortId] = [];
            }
            
            $this->performPermanentWingsHandshake($serverShortId);
            
            Log::info("OngameCloud WebSocket: Connected permanent Wings", [
                'server' => $serverShortId,
            ]);
        } catch (Exception $e) {
            Log::error("OngameCloud WebSocket: Permanent Wings connection error", [
                'server' => $serverShortId,
                'error' => $e->getMessage(),
            ]);
        }
    }
    
    private function performPermanentWingsHandshake(string $serverShortId): void
    {
        $conn = &$this->permanentWingsConnections[$serverShortId];
        $socket = $conn['socket'];
        
        $secKey = base64_encode(random_bytes(16));
        
        $origin = config('app.url');
        
        $request = "GET {$conn['path']} HTTP/1.1\r\n";
        $request .= "Host: {$conn['host']}\r\n";
        $request .= "Origin: {$origin}\r\n";
        $request .= "Upgrade: websocket\r\n";
        $request .= "Connection: Upgrade\r\n";
        $request .= "Sec-WebSocket-Key: {$secKey}\r\n";
        $request .= "Sec-WebSocket-Version: 13\r\n";
        $request .= "\r\n";
        
        @fwrite($socket, $request);
        $conn['handshake_done'] = true;
    }
    
    private function processPermanentWingsMessages(string $serverShortId): void
    {
        if (!isset($this->permanentWingsConnections[$serverShortId])) {
            return;
        }
        
        $conn = &$this->permanentWingsConnections[$serverShortId];
        $socket = $conn['socket'];
        
        if (!is_resource($socket) || feof($socket)) {
            unset($this->permanentWingsConnections[$serverShortId]);
            Log::info("OngameCloud WebSocket: Permanent Wings disconnected, will reconnect", [
                'server' => $serverShortId,
            ]);
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
            
            $frame = $this->encodeFrameForWings($authMessage);
            @fwrite($socket, $frame);
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
            
            if ($message['event'] === 'auth success') {
                $conn['authenticated'] = true;
                Log::info("OngameCloud WebSocket: Permanent Wings authenticated", [
                    'server' => $serverShortId,
                ]);
            } elseif ($message['event'] === 'console output' && isset($message['args'][0])) {
                $output = $message['args'][0];
                
                $output = str_replace('Pterodactyl', 'Ongamecloud', $output);
                
                $batchKey = $serverShortId . '_permanent';
                
                if (!isset($this->consoleBatches[$batchKey])) {
                    $this->consoleBatches[$batchKey] = [
                        'server_short_id' => $serverShortId,
                        'outputs' => [],
                        'is_permanent' => true,
                    ];
                }
                
                $this->consoleBatches[$batchKey]['outputs'][] = $output;
                
                if (isset($this->batchTimers[$batchKey])) {
                    swoole_timer_clear($this->batchTimers[$batchKey]);
                }
                
                $this->batchTimers[$batchKey] = swoole_timer_after(100, function() use ($batchKey, $serverShortId) {
                    $this->flushPermanentBatch($batchKey, $serverShortId);
                });
            }
        }
    }

    private function addToConsoleBatch(string $serverShortId, string $output, int $connId): void
    {
        $batchKey = $serverShortId . '_' . $connId;
        
        if (!isset($this->consoleBatches[$batchKey])) {
            $this->consoleBatches[$batchKey] = [
                'server_short_id' => $serverShortId,
                'conn_id' => $connId,
                'outputs' => [],
            ];
        }
        
        $this->consoleBatches[$batchKey]['outputs'][] = $output;
        
        if (isset($this->batchTimers[$batchKey])) {
            swoole_timer_clear($this->batchTimers[$batchKey]);
        }
        
        $this->batchTimers[$batchKey] = swoole_timer_after(100, function() use ($batchKey, $serverShortId, $connId) {
            $this->flushConsoleBatch($batchKey, $serverShortId, $connId);
        });
    }

    private function flushConsoleBatch(string $batchKey, string $serverShortId, int $connId): void
    {
        if (!isset($this->consoleBatches[$batchKey])) {
            return;
        }
        
        $batch = $this->consoleBatches[$batchKey];
        unset($this->consoleBatches[$batchKey]);
        unset($this->batchTimers[$batchKey]);
        
        if (empty($batch['outputs'])) {
            return;
        }
        
        foreach ($batch['outputs'] as $output) {
            $this->consoleLogService->saveLog($serverShortId, $output);
        }
        
        if (isset($this->followedConsoles[$connId][$serverShortId])) {
            $client = $this->followedConsoles[$connId][$serverShortId]['client'];
            
            if (count($batch['outputs']) === 1) {
                $this->send($client, [
                    'type' => 'console_output',
                    'server_short_id' => $serverShortId,
                    'output' => $batch['outputs'][0],
                    'timestamp' => now()->toIso8601String(),
                ]);
            } else {
                $this->send($client, [
                    'type' => 'console_output_batch',
                    'server_short_id' => $serverShortId,
                    'outputs' => $batch['outputs'],
                    'timestamp' => now()->toIso8601String(),
                ]);
            }
        }
    }

    private function flushPermanentBatch(string $batchKey, string $serverShortId): void
    {
        if (!isset($this->consoleBatches[$batchKey])) {
            return;
        }
        
        $batch = $this->consoleBatches[$batchKey];
        unset($this->consoleBatches[$batchKey]);
        unset($this->batchTimers[$batchKey]);
        
        if (empty($batch['outputs'])) {
            return;
        }
        
        foreach ($batch['outputs'] as $output) {
            $this->consoleLogService->saveLog($serverShortId, $output);
        }
    }
}
