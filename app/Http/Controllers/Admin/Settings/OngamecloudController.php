<?php

namespace Pterodactyl\Http\Controllers\Admin\Settings;

use Illuminate\View\View;
use Illuminate\Http\Request;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\JsonResponse;
use Prologue\Alerts\AlertsMessageBag;
use Illuminate\View\Factory as ViewFactory;
use Pterodactyl\Http\Controllers\Controller;
use Pterodactyl\Services\Ongamecloud\WebSocketService;
use Pterodactyl\Models\OngamecloudWebSocketLog;
use Pterodactyl\Contracts\Repository\SettingsRepositoryInterface;

class OngamecloudController extends Controller
{
    public function __construct(
        private AlertsMessageBag $alert,
        private ViewFactory $view,
        private WebSocketService $websocketService,
        private SettingsRepositoryInterface $settings,
    ) {
    }

    public function index(): View
    {
        return $this->view->make('admin.settings.ongamecloud');
    }

    public function websocketLogs(): JsonResponse
    {
        $logFile = storage_path('logs/laravel.log');
        
        if (!file_exists($logFile)) {
            return response()->json(['logs' => 'No log file found']);
        }
        
        $lines = [];
        $file = new \SplFileObject($logFile, 'r');
        $file->seek(PHP_INT_MAX);
        $lastLine = $file->key();
        $startLine = max(0, $lastLine - 100);
        
        $file->seek($startLine);
        while (!$file->eof()) {
            $line = $file->current();
            if (stripos($line, 'OngameCloud WebSocket') !== false || 
                stripos($line, 'websocket') !== false) {
                $lines[] = $line;
            }
            $file->next();
        }
        
        return response()->json([
            'logs' => implode('', array_slice($lines, -50))
        ]);
    }

    public function redisLogs(): JsonResponse
    {
        $logFile = storage_path('logs/laravel.log');
        
        if (!file_exists($logFile)) {
            return response()->json(['logs' => 'No log file found']);
        }
        
        $lines = [];
        $file = new \SplFileObject($logFile, 'r');
        $file->seek(PHP_INT_MAX);
        $lastLine = $file->key();
        $startLine = max(0, $lastLine - 100);
        
        $file->seek($startLine);
        while (!$file->eof()) {
            $line = $file->current();
            if (stripos($line, 'Redis') !== false || 
                stripos($line, 'console log') !== false ||
                stripos($line, 'saveLog') !== false) {
                $lines[] = $line;
            }
            $file->next();
        }
        
        return response()->json([
            'logs' => implode('', array_slice($lines, -50))
        ]);
    }
}
