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
        return $this->view->make('admin.settings.ongamecloud', [
            'enabled' => config('ongamecloud.websocket.enabled'),
            'host' => config('ongamecloud.websocket.host'),
            'port' => config('ongamecloud.websocket.port'),
            'ssl' => config('ongamecloud.websocket.ssl'),
            'auth_token' => config('ongamecloud.websocket.auth_token'),
            'max_connections' => config('ongamecloud.websocket.max_connections'),
            'timeout' => config('ongamecloud.websocket.timeout'),
            'log_enabled' => config('ongamecloud.websocket.log_enabled'),
            'log_retention_days' => config('ongamecloud.websocket.log_retention_days'),
            'stats' => $this->websocketService->getStats(),
            'connections' => $this->websocketService->getConnections(),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'enabled' => 'boolean',
            'host' => 'nullable|string',
            'port' => 'nullable|integer|min:1|max:65535',
            'ssl' => 'boolean',
            'max_connections' => 'nullable|integer|min:1',
            'timeout' => 'nullable|integer|min:1',
            'log_enabled' => 'boolean',
            'log_retention_days' => 'nullable|integer|min:1',
        ]);

        foreach ($validated as $key => $value) {
            $this->settings->set('ongamecloud::websocket::' . $key, $value);
        }

        $this->alert->success('Ongamecloud WebSocket settings updated successfully')->flash();

        return redirect()->route('admin.settings.ongamecloud');
    }

    public function generateToken(): JsonResponse
    {
        $token = $this->websocketService->generateAuthToken();

        return response()->json([
            'success' => true,
            'token' => $token,
        ]);
    }

    public function logs(Request $request): JsonResponse
    {
        $perPage = $request->input('per_page', 50);
        $status = $request->input('status');
        $action = $request->input('action');

        $query = OngamecloudWebSocketLog::with('server')
            ->orderBy('created_at', 'desc');

        if ($status) {
            $query->where('status', $status);
        }

        if ($action) {
            $query->where('action', $action);
        }

        $logs = $query->paginate($perPage);

        return response()->json($logs);
    }

    public function cleanLogs(): JsonResponse
    {
        $deleted = $this->websocketService->cleanOldLogs();

        return response()->json([
            'success' => true,
            'deleted' => $deleted,
            'message' => "Deleted {$deleted} old log entries",
        ]);
    }

    public function stats(): JsonResponse
    {
        return response()->json([
            'stats' => $this->websocketService->getStats(),
            'connections' => $this->websocketService->getConnections(),
        ]);
    }
}
