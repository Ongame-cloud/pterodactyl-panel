<?php

namespace Pterodactyl\Services\Ongamecloud;

use Illuminate\Support\Facades\Log;
use Predis\Client as PredisClient;

class ConsoleLogService
{
    private const MAX_LOGS = 100;
    private const LOG_EXPIRY = 86400;
    
    private ?PredisClient $redis = null;

    private function getRedis(): ?PredisClient
    {
        if ($this->redis !== null) {
            return $this->redis;
        }

        try {
            $redisUrl = env('ONGAMECLOUD_REDIS');
            
            if (!$redisUrl) {
                Log::warning("ONGAMECLOUD_REDIS environment variable not set");
                return null;
            }

            $this->redis = new PredisClient($redisUrl);
            return $this->redis;
        } catch (\Exception $e) {
            Log::error("Failed to connect to Ongamecloud Redis", [
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    public function saveLog(string $serverShortId, string $output): void
    {
        try {
            $redis = $this->getRedis();
            if (!$redis) {
                return;
            }

            $logKey = "server:{$serverShortId}:console_logs";
            
            $redis->rpush($logKey, [$output]);
            $redis->ltrim($logKey, -self::MAX_LOGS, -1);
            $redis->expire($logKey, self::LOG_EXPIRY);
        } catch (\Exception $e) {
            Log::error("Failed to save console log to Redis", [
                'server' => $serverShortId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    public function clearLogs(string $serverShortId): void
    {
        try {
            $redis = $this->getRedis();
            if (!$redis) {
                return;
            }

            $logKey = "server:{$serverShortId}:console_logs";
            $redis->del([$logKey]);
        } catch (\Exception $e) {
            Log::error("Failed to clear console logs in Redis", [
                'server' => $serverShortId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    public function setOfflineMessage(string $serverShortId): void
    {
        try {
            $redis = $this->getRedis();
            if (!$redis) {
                return;
            }

            $logKey = "server:{$serverShortId}:console_logs";
            $offlineMsg = "Server is currently offline. Press the action button in the bar on your Ongamecloud dashboard to start it.";
            
            $redis->del([$logKey]);
            $redis->rpush($logKey, [$offlineMsg]);
            $redis->expire($logKey, self::LOG_EXPIRY);
        } catch (\Exception $e) {
            Log::error("Failed to set offline message in Redis", [
                'server' => $serverShortId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    public function getLogs(string $serverShortId): array
    {
        try {
            $redis = $this->getRedis();
            if (!$redis) {
                return [];
            }

            $logKey = "server:{$serverShortId}:console_logs";
            return $redis->lrange($logKey, 0, -1);
        } catch (\Exception $e) {
            Log::error("Failed to get console logs from Redis", [
                'server' => $serverShortId,
                'error' => $e->getMessage(),
            ]);
            return [];
        }
    }
}
