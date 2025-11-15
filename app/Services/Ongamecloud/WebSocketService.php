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

    public function __construct(
        private DaemonPowerRepository $powerRepository
    ) {
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
