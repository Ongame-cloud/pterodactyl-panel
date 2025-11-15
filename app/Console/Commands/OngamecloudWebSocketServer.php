<?php

namespace Pterodactyl\Console\Commands;

use Illuminate\Console\Command;
use React\EventLoop\Loop;
use React\Socket\SocketServer;
use React\Http\HttpServer;
use React\Http\Message\Response;
use Psr\Http\Message\ServerRequestInterface;
use Pterodactyl\Services\Ongamecloud\WebSocketService;
use Ratchet\RFC6455\Messaging\Frame;
use Ratchet\RFC6455\Handshake\ServerNegotiator;
use Ratchet\RFC6455\Handshake\RequestVerifier;

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
            $negotiator = new ServerNegotiator(new RequestVerifier());
            
            $server = new HttpServer(function (ServerRequestInterface $request) use ($service, $negotiator) {
                $psrResponse = $negotiator->handshake($request);
                
                if ($psrResponse->getStatusCode() !== 101) {
                    return new Response(
                        $psrResponse->getStatusCode(),
                        $psrResponse->getHeaders(),
                        (string) $psrResponse->getBody()
                    );
                }
                
                return $service->handleUpgrade($request, $psrResponse);
            });

            $socket = new SocketServer("{$host}:{$port}");
            $server->listen($socket);

            $this->info('WebSocket server started successfully');
            Loop::run();
        } catch (\Exception $e) {
            $this->error('Failed to start WebSocket server: ' . $e->getMessage());
            return 1;
        }

        return 0;
    }
}
