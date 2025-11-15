<?php

namespace Pterodactyl\Console\Commands;

use Illuminate\Console\Command;
use Pterodactyl\Services\Ongamecloud\WebSocketService;

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

        if (empty(config('ongamecloud.websocket.auth_token'))) {
            $this->error('No authentication token configured. Set ONGAMECLOUD_WS_AUTH_TOKEN in .env');
            return 1;
        }

        $this->info("Starting Ongamecloud WebSocket server on {$host}:{$port}");

        $address = "tcp://{$host}:{$port}";
        $socket = stream_socket_server($address, $errno, $errstr);

        if (!$socket) {
            $this->error("Failed to create socket: {$errstr} ({$errno})");
            return 1;
        }

        $this->info('WebSocket server started successfully');
        
        $clients = [];
        
        while (true) {
            $clients = array_filter($clients, fn($c) => is_resource($c) && !feof($c));
            
            $read = array_merge([$socket], $clients);
            $write = null;
            $except = null;
            
            if (stream_select($read, $write, $except, 0, 200000) < 1) {
                continue;
            }
            
            if (in_array($socket, $read)) {
                $client = stream_socket_accept($socket, -1);
                if ($client) {
                    $clients[] = $client;
                    $service->onConnect($client);
                }
                unset($read[array_search($socket, $read)]);
            }
            
            foreach ($read as $client) {
                $data = @fread($client, 8192);
                if ($data === false || $data === '') {
                    $service->onClose($client);
                    $key = array_search($client, $clients);
                    if ($key !== false) {
                        unset($clients[$key]);
                    }
                    @fclose($client);
                } else {
                    $service->onMessage($client, $data);
                }
            }
        }

        return 0;
    }
}
