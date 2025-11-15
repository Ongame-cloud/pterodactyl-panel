<?php

namespace Pterodactyl\Services\Ongamecloud;

use Ratchet\MessageComponentInterface;
use Ratchet\ConnectionInterface;
use Illuminate\Support\Facades\Log;
use Exception;

class WebSocketHandler implements MessageComponentInterface
{
    private WebSocketService $service;

    public function __construct(WebSocketService $service)
    {
        $this->service = $service;
    }

    public function onOpen(ConnectionInterface $conn)
    {
        $connectionId = spl_object_hash($conn);
        $ipAddress = $conn->remoteAddress ?? 'unknown';
        
        $this->service->addConnection($connectionId, $ipAddress);
        
        Log::info("OngameCloud WebSocket: New connection", [
            'connection_id' => $connectionId,
            'ip' => $ipAddress,
        ]);

        $conn->send(json_encode([
            'type' => 'connected',
            'message' => 'Connected to Ongamecloud WebSocket server',
            'connection_id' => $connectionId,
            'timestamp' => now()->toIso8601String(),
        ]));
    }

    public function onMessage(ConnectionInterface $from, $msg)
    {
        $connectionId = spl_object_hash($from);
        $ipAddress = $from->remoteAddress ?? 'unknown';

        try {
            $data = json_decode($msg, true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new Exception('Invalid JSON format');
            }

            if (!isset($data['type'])) {
                throw new Exception('Missing message type');
            }

            switch ($data['type']) {
                case 'auth':
                    $this->handleAuth($from, $data);
                    break;

                case 'action':
                    $this->handleAction($from, $connectionId, $data, $ipAddress);
                    break;

                case 'ping':
                    $from->send(json_encode([
                        'type' => 'pong',
                        'timestamp' => now()->toIso8601String(),
                    ]));
                    break;

                default:
                    throw new Exception('Unknown message type: ' . $data['type']);
            }

        } catch (Exception $e) {
            Log::error("OngameCloud WebSocket: Message error", [
                'connection_id' => $connectionId,
                'error' => $e->getMessage(),
            ]);

            $from->send(json_encode([
                'type' => 'error',
                'error' => $e->getMessage(),
                'timestamp' => now()->toIso8601String(),
            ]));
        }
    }

    private function handleAuth(ConnectionInterface $conn, array $data)
    {
        if (!isset($data['token'])) {
            $conn->send(json_encode([
                'type' => 'auth_response',
                'success' => false,
                'error' => 'Missing authentication token',
            ]));
            return;
        }

        $authenticated = $this->service->authenticate($data['token']);

        $conn->send(json_encode([
            'type' => 'auth_response',
            'success' => $authenticated,
            'message' => $authenticated ? 'Authentication successful' : 'Authentication failed',
            'timestamp' => now()->toIso8601String(),
        ]));

        if (!$authenticated) {
            Log::warning("OngameCloud WebSocket: Failed authentication attempt", [
                'connection_id' => spl_object_hash($conn),
                'ip' => $conn->remoteAddress ?? 'unknown',
            ]);
        }
    }

    private function handleAction(ConnectionInterface $conn, string $connectionId, array $data, string $ipAddress)
    {
        if (!isset($data['authenticated']) || !$data['authenticated']) {
            $conn->send(json_encode([
                'type' => 'error',
                'error' => 'Not authenticated. Send auth message first.',
                'timestamp' => now()->toIso8601String(),
            ]));
            return;
        }

        $this->service->incrementRequestCount($connectionId);

        $response = $this->service->handleMessage($connectionId, $data, $ipAddress);

        $conn->send(json_encode([
            'type' => 'action_response',
            ...$response,
        ]));
    }

    public function onClose(ConnectionInterface $conn)
    {
        $connectionId = spl_object_hash($conn);
        $this->service->removeConnection($connectionId);

        Log::info("OngameCloud WebSocket: Connection closed", [
            'connection_id' => $connectionId,
        ]);
    }

    public function onError(ConnectionInterface $conn, Exception $e)
    {
        Log::error("OngameCloud WebSocket: Connection error", [
            'connection_id' => spl_object_hash($conn),
            'error' => $e->getMessage(),
        ]);

        $conn->close();
    }
}
