<?php

namespace Pterodactyl\Services\Ongamecloud;

use Exception;
use Pterodactyl\Models\Server;
use Pterodactyl\Models\OngamecloudWebSocketLog;
use Pterodactyl\Repositories\Wings\DaemonPowerRepository;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class WebSocketService
{
    private array $connections = [];
    private array $stats = [
        'total_connections' => 0,
        'active_connections' => 0,
        'total_requests' => 0,
        'successful_requests' => 0,
        'failed_requests' => 0,
    ];

    private array $authenticated = [];

    public function __construct(
        private DaemonPowerRepository $powerRepository
    ) {
    }

    public function handleConnection($connection): void
    {
        $connectionId = spl_object_hash($connection);
        $ipAddress = $connection->getRemoteAddress() ?? 'unknown';
        
        $this->addConnection($connectionId, $ipAddress);
        
        Log::info("OngameCloud WebSocket: New connection", [
            'connection_id' => $connectionId,
            'ip' => $ipAddress,
        ]);

        $connection->write(json_encode([
            'type' => 'connected',
            'message' => 'Connected to Ongamecloud WebSocket server',
            'connection_id' => $connectionId,
            'timestamp' => now()->toIso8601String(),
        ]) . "\n");

        $connection->on('data', function ($data) use ($connection, $connectionId, $ipAddress) {
            $this->handleData($connection, $connectionId, $data, $ipAddress);
        });

        $connection->on('close', function () use ($connectionId) {
            $this->removeConnection($connectionId);
            Log::info("OngameCloud WebSocket: Connection closed", [
                'connection_id' => $connectionId,
            ]);
        });

        $connection->on('error', function (\Exception $e) use ($connectionId) {
            Log::error("OngameCloud WebSocket: Connection error", [
                'connection_id' => $connectionId,
                'error' => $e->getMessage(),
            ]);
        });
    }

    private function handleData($connection, string $connectionId, string $data, string $ipAddress): void
    {
        try {
            $messages = explode("\n", trim($data));
            
            foreach ($messages as $msg) {
                if (empty($msg)) continue;
                
                $decoded = json_decode($msg, true);
                
                if (json_last_error() !== JSON_ERROR_NONE) {
                    throw new Exception('Invalid JSON format');
                }

                if (!isset($decoded['type'])) {
                    throw new Exception('Missing message type');
                }

                switch ($decoded['type']) {
                    case 'auth':
                        $this->handleAuth($connection, $connectionId, $decoded);
                        break;

                    case 'action':
                        if (!isset($this->authenticated[$connectionId]) || !$this->authenticated[$connectionId]) {
                            $connection->write(json_encode([
                                'type' => 'error',
                                'error' => 'Not authenticated. Send auth message first.',
                                'timestamp' => now()->toIso8601String(),
                            ]) . "\n");
                            return;
                        }
                        
                        $this->incrementRequestCount($connectionId);
                        $response = $this->handleMessage($connectionId, $decoded, $ipAddress);
                        $connection->write(json_encode([
                            'type' => 'action_response',
                            ...$response,
                        ]) . "\n");
                        break;

                    case 'ping':
                        $connection->write(json_encode([
                            'type' => 'pong',
                            'timestamp' => now()->toIso8601String(),
                        ]) . "\n");
                        break;

                    default:
                        throw new Exception('Unknown message type: ' . $decoded['type']);
                }
            }
        } catch (Exception $e) {
            Log::error("OngameCloud WebSocket: Message error", [
                'connection_id' => $connectionId,
                'error' => $e->getMessage(),
            ]);

            $connection->write(json_encode([
                'type' => 'error',
                'error' => $e->getMessage(),
                'timestamp' => now()->toIso8601String(),
            ]) . "\n");
        }
    }

    private function handleAuth($connection, string $connectionId, array $data): void
    {
        if (!isset($data['token'])) {
            $connection->write(json_encode([
                'type' => 'auth_response',
                'success' => false,
                'error' => 'Missing authentication token',
            ]) . "\n");
            return;
        }

        $authenticated = $this->authenticate($data['token']);
        $this->authenticated[$connectionId] = $authenticated;

        $connection->write(json_encode([
            'type' => 'auth_response',
            'success' => $authenticated,
            'message' => $authenticated ? 'Authentication successful' : 'Authentication failed',
            'timestamp' => now()->toIso8601String(),
        ]) . "\n");

        if (!$authenticated) {
            Log::warning("OngameCloud WebSocket: Failed authentication attempt", [
                'connection_id' => $connectionId,
            ]);
        }
    }

    public function authenticate(string $token): bool
    {
        $configToken = config('ongamecloud.websocket.auth_token');
        
        if (empty($configToken)) {
            Log::error('OngameCloud WebSocket: No auth token configured');
            return false;
        }

        return hash_equals($configToken, $token);
    }

    public function handleMessage(string $connectionId, array $data, string $ipAddress): array
    {
        $startTime = microtime(true);
        
        try {
            if (!isset($data['action']) || !isset($data['server_short_id'])) {
                throw new Exception('Missing required fields: action and server_short_id');
            }

            $action = $data['action'];
            $shortId = $data['server_short_id'];

            if (!in_array($action, ['start', 'stop', 'restart', 'kill'])) {
                throw new Exception('Invalid action. Allowed: start, stop, restart, kill');
            }

            $server = Server::where('uuidShort', $shortId)->first();

            if (!$server) {
                throw new Exception("Server not found with short_id: {$shortId}");
            }

            $this->powerRepository->setServer($server)->send($action);

            $duration = round((microtime(true) - $startTime) * 1000, 2);

            $response = [
                'success' => true,
                'action' => $action,
                'server_short_id' => $shortId,
                'server_uuid' => $server->uuid,
                'server_name' => $server->name,
                'timestamp' => now()->toIso8601String(),
                'duration_ms' => $duration,
            ];

            $this->logRequest($connectionId, $action, $shortId, $server->id, $data, $response, 'success', null, $ipAddress, $duration);
            $this->stats['successful_requests']++;

            return $response;

        } catch (Exception $e) {
            $duration = round((microtime(true) - $startTime) * 1000, 2);
            
            $response = [
                'success' => false,
                'error' => $e->getMessage(),
                'timestamp' => now()->toIso8601String(),
                'duration_ms' => $duration,
            ];

            $this->logRequest(
                $connectionId,
                $data['action'] ?? 'unknown',
                $data['server_short_id'] ?? 'unknown',
                null,
                $data,
                $response,
                'error',
                $e->getMessage(),
                $ipAddress,
                $duration
            );
            
            $this->stats['failed_requests']++;

            return $response;
        }
    }

    private function logRequest(
        string $connectionId,
        string $action,
        string $shortId,
        ?int $serverId,
        array $requestData,
        array $responseData,
        string $status,
        ?string $errorMessage,
        string $ipAddress,
        float $duration
    ): void {
        if (!config('ongamecloud.websocket.log_enabled')) {
            return;
        }

        try {
            OngamecloudWebSocketLog::create([
                'connection_id' => $connectionId,
                'action' => $action,
                'server_short_id' => $shortId,
                'server_id' => $serverId,
                'request_data' => $requestData,
                'response_data' => $responseData,
                'status' => $status,
                'error_message' => $errorMessage,
                'ip_address' => $ipAddress,
                'duration_ms' => (int) $duration,
            ]);
        } catch (Exception $e) {
            Log::error('Failed to log WebSocket request: ' . $e->getMessage());
        }
    }

    public function addConnection(string $connectionId, string $ipAddress): void
    {
        $this->connections[$connectionId] = [
            'connected_at' => now(),
            'ip_address' => $ipAddress,
            'requests_count' => 0,
        ];
        
        $this->stats['total_connections']++;
        $this->stats['active_connections']++;
    }

    public function removeConnection(string $connectionId): void
    {
        unset($this->connections[$connectionId]);
        $this->stats['active_connections']--;
    }

    public function incrementRequestCount(string $connectionId): void
    {
        if (isset($this->connections[$connectionId])) {
            $this->connections[$connectionId]['requests_count']++;
        }
        $this->stats['total_requests']++;
    }

    public function getStats(): array
    {
        return $this->stats;
    }

    public function getConnections(): array
    {
        return $this->connections;
    }

    public function generateAuthToken(): string
    {
        return Str::random(64);
    }

    public function cleanOldLogs(): int
    {
        $retentionDays = config('ongamecloud.websocket.log_retention_days', 7);
        
        return OngamecloudWebSocketLog::where('created_at', '<', now()->subDays($retentionDays))
            ->delete();
    }
}
