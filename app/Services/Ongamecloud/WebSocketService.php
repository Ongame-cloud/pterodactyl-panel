<?php

namespace Pterodactyl\Services\Ongamecloud;

use Exception;
use Pterodactyl\Models\Server;
use Pterodactyl\Models\OngamecloudWebSocketLog;
use Pterodactyl\Repositories\Wings\DaemonPowerRepository;
use Illuminate\Support\Facades\Log;
use Workerman\Connection\TcpConnection;

class WebSocketService
{
    private array $connections = [];
    private array $authenticated = [];
    private array $stats = [
        'total_connections' => 0,
        'active_connections' => 0,
        'total_requests' => 0,
        'failed_requests' => 0,
    ];

    public function __construct(
        private DaemonPowerRepository $powerRepository
    ) {
    }

    public function onConnect(TcpConnection $connection): void
    {
        $connectionId = spl_object_hash($connection);
        $ipAddress = $connection->getRemoteIp();
        
        $this->connections[$connectionId] = [
            'ip' => $ipAddress,
            'connected_at' => now(),
            'request_count' => 0,
        ];
        
        $this->stats['total_connections']++;
        $this->stats['active_connections']++;
        
        Log::info("OngameCloud WebSocket: New connection", [
            'connection_id' => $connectionId,
            'ip' => $ipAddress,
        ]);
        
        $this->send($connection, [
            'type' => 'connected',
            'message' => 'Connected to Ongamecloud WebSocket server',
            'connection_id' => $connectionId,
            'timestamp' => now()->toIso8601String(),
        ]);
    }

    public function onMessage(TcpConnection $connection, string $data): void
    {
        $connectionId = spl_object_hash($connection);
        $ipAddress = $connection->getRemoteIp();
        
        try {
            $decoded = json_decode($data, true);
            
            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new Exception('Invalid JSON format');
            }
            
            if (!isset($decoded['type'])) {
                throw new Exception('Message type is required');
            }
            
            $this->connections[$connectionId]['request_count']++;
            $this->stats['total_requests']++;
            
            Log::info("OngameCloud WebSocket: Message received", [
                'connection_id' => $connectionId,
                'type' => $decoded['type'],
                'ip' => $ipAddress,
            ]);
            
            switch ($decoded['type']) {
                case 'auth':
                    $this->handleAuth($connection, $connectionId, $decoded);
                    break;
                    
                case 'action':
                    if (!isset($this->authenticated[$connectionId])) {
                        $this->send($connection, [
                            'type' => 'error',
                            'error' => 'Not authenticated. Send auth message first.',
                            'timestamp' => now()->toIso8601String(),
                        ]);
                        return;
                    }
                    
                    $response = $this->handleAction($connectionId, $decoded, $ipAddress);
                    $this->send($connection, [
                        'type' => 'action_response',
                        ...$response,
                    ]);
                    break;
                    
                case 'ping':
                    $this->send($connection, [
                        'type' => 'pong',
                        'timestamp' => now()->toIso8601String(),
                    ]);
                    break;
                    
                default:
                    throw new Exception('Unknown message type: ' . $decoded['type']);
            }
        } catch (Exception $e) {
            Log::error("OngameCloud WebSocket: Message error", [
                'connection_id' => $connectionId,
                'error' => $e->getMessage(),
            ]);
            
            $this->stats['failed_requests']++;
            
            $this->send($connection, [
                'type' => 'error',
                'error' => $e->getMessage(),
                'timestamp' => now()->toIso8601String(),
            ]);
        }
    }

    public function onClose(TcpConnection $connection): void
    {
        $connectionId = spl_object_hash($connection);
        
        if (isset($this->connections[$connectionId])) {
            unset($this->connections[$connectionId]);
            $this->stats['active_connections']--;
        }
        
        unset($this->authenticated[$connectionId]);
        
        Log::info("OngameCloud WebSocket: Connection closed", [
            'connection_id' => $connectionId,
        ]);
    }

    private function send(TcpConnection $connection, array $data): void
    {
        $connection->send(json_encode($data));
    }

    private function handleAuth(TcpConnection $connection, string $connectionId, array $data): void
    {
        if (!isset($data['token'])) {
            $this->send($connection, [
                'type' => 'auth_response',
                'success' => false,
                'error' => 'Token is required',
                'timestamp' => now()->toIso8601String(),
            ]);
            return;
        }
        
        $expectedToken = config('ongamecloud.websocket.auth_token');
        
        if ($data['token'] !== $expectedToken) {
            $this->send($connection, [
                'type' => 'auth_response',
                'success' => false,
                'error' => 'Invalid authentication token',
                'timestamp' => now()->toIso8601String(),
            ]);
            
            Log::warning("OngameCloud WebSocket: Failed authentication", [
                'connection_id' => $connectionId,
                'ip' => $this->connections[$connectionId]['ip'] ?? 'unknown',
            ]);
            
            return;
        }
        
        $this->authenticated[$connectionId] = true;
        
        $this->send($connection, [
            'type' => 'auth_response',
            'success' => true,
            'message' => 'Authentication successful',
            'timestamp' => now()->toIso8601String(),
        ]);
        
        Log::info("OngameCloud WebSocket: Successful authentication", [
            'connection_id' => $connectionId,
        ]);
    }

    private function handleAction(string $connectionId, array $data, string $ipAddress): array
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
                'error' => 'Invalid action. Allowed: start, stop, restart, kill',
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
            
            OngamecloudWebSocketLog::create([
                'connection_id' => $connectionId,
                'ip_address' => $ipAddress,
                'action' => $action,
                'server_id' => $server->id,
                'success' => true,
            ]);
            
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
            OngamecloudWebSocketLog::create([
                'connection_id' => $connectionId,
                'ip_address' => $ipAddress,
                'action' => $action,
                'server_id' => $server->id,
                'success' => false,
                'error_message' => $e->getMessage(),
            ]);
            
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

    public function getStats(): array
    {
        return $this->stats;
    }
}
