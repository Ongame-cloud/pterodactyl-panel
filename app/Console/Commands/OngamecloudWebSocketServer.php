<?php

namespace Pterodactyl\Console\Commands;

use Illuminate\Console\Command;
use Pterodactyl\Services\Ongamecloud\WebSocketService;
use Workerman\Worker;
use Workerman\Connection\TcpConnection;

class OngamecloudWebSocketServer extends Command
{
    protected $signature = 'ongamecloud:websocket
                            {--host= : The host to bind to}
                            {--port= : The port to bind to}';

    protected $description = 'Start the Ongamecloud WebSocket server for backend communication';

    public function handle(WebSocketService $service)
    {
        $host = config('app.websocket_host', '0.0.0.0');
        $port = config('app.websocket_port', 8090);

        if (!config('ongamecloud.websocket.enabled')) {
            $this->error('WebSocket server is disabled in configuration');
            return 1;
        }

        if (empty(config('ongamecloud.websocket.auth_token'))) {
            $this->error('No authentication token configured. Set ONGAMECLOUD_WS_AUTH_TOKEN in .env');
            return 1;
        }

        $this->info("Starting Ongamecloud WebSocket server on {$host}:{$port}");
        
        global $argv;
        $argv[1] = 'start';
        $argv[2] = '-d';

        $worker = new Worker("websocket://{$host}:{$port}");
        $worker->count = 1;
        $worker->name = 'ongamecloud-websocket';
        
        $worker->onWorkerStart = function() {
            echo "Ongamecloud WebSocket server started successfully\n";
        };
        
        $worker->onConnect = function(TcpConnection $connection) use ($service) {
            $service->onConnect($connection);
        };
        
        $worker->onMessage = function(TcpConnection $connection, $data) use ($service) {
            $service->onMessage($connection, $data);
        };
        
        $worker->onClose = function(TcpConnection $connection) use ($service) {
            $service->onClose($connection);
        };
        
        $worker->onError = function(TcpConnection $connection, $code, $msg) {
            echo "Error: $msg\n";
        };
        
        Worker::runAll();
        
        return 0;
    }
}
