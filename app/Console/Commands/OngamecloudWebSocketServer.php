<?php

namespace Pterodactyl\Console\Commands;

use Illuminate\Console\Command;
use Ratchet\Server\IoServer;
use Ratchet\Http\HttpServer;
use Ratchet\WebSocket\WsServer;
use Pterodactyl\Services\Ongamecloud\WebSocketService;

class OngamecloudWebSocketServer extends Command
{
    protected $signature = 'ongamecloud:websocket
                            {--host= : The host to bind to}
                            {--port= : The port to bind to}';

    protected $description = 'Start the Ongamecloud WebSocket server for backend communication';

    public function handle(WebSocketService $service)
    {
        if (!config('ongamecloud.websocket.enabled')) {
            $this->error('WebSocket server is disabled in configuration');
            return 1;
        }

        $host = $this->option('host') ?? config('ongamecloud.websocket.host');
        $port = $this->option('port') ?? config('ongamecloud.websocket.port');

        if (empty(config('ongamecloud.websocket.auth_token'))) {
            $this->error('No authentication token configured. Set ONGAMECLOUD_WS_AUTH_TOKEN in .env');
            return 1;
        }

        $this->info("Starting Ongamecloud WebSocket server on {$host}:{$port}");
        $this->info('Press Ctrl+C to stop the server');

        try {
            $server = IoServer::factory(
                new HttpServer(
                    new WsServer($service)
                ),
                $port,
                $host
            );

            $this->info('WebSocket server started successfully');
            $server->run();
        } catch (\Exception $e) {
            $this->error('Failed to start WebSocket server: ' . $e->getMessage());
            return 1;
        }

        return 0;
    }
}
