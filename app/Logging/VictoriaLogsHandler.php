<?php

namespace Pterodactyl\Logging;

use Monolog\Handler\AbstractProcessingHandler;
use Monolog\Logger;
use Monolog\LogRecord;

class VictoriaLogsHandler extends AbstractProcessingHandler
{
    private $url;
    private $username;
    private $password;
    private $serviceName;
    private $environment;
    private $buffer = [];
    private $batchSize;
    private $lastFlush;
    private $flushInterval;

    public function __construct(
        string $url,
        string $username = '',
        string $password = '',
        string $serviceName = 'pterodactyl',
        string $environment = 'production',
        int $batchSize = 100,
        int $flushInterval = 5,
        $level = Logger::DEBUG,
        bool $bubble = true
    ) {
        parent::__construct($level, $bubble);
        
        if (!str_starts_with($url, 'http://') && !str_starts_with($url, 'https://')) {
            $url = 'http://' . $url;
        }
        
        $this->url = $url;
        $this->username = $username;
        $this->password = $password;
        $this->serviceName = $serviceName;
        $this->environment = $environment;
        $this->batchSize = $batchSize;
        $this->flushInterval = $flushInterval;
        $this->lastFlush = time();
    }

    protected function write(LogRecord $record): void
    {
        $log = [
            'timestamp' => $record->datetime->format('Y-m-d\TH:i:s.u\Z'),
            'level' => strtolower($record->level->getName()),
            'message' => $record->message,
            'service' => $this->serviceName,
            'environment' => $this->environment,
            'hostname' => gethostname() ?: 'unknown',
        ];

        if (!empty($record->context)) {
            $log['context'] = $record->context;
        }

        if (!empty($record->extra)) {
            $log['extra'] = $record->extra;
        }

        $this->buffer[] = $log;

        if (count($this->buffer) >= $this->batchSize || (time() - $this->lastFlush) >= $this->flushInterval) {
            $this->flush();
        }
    }

    private function flush(): void
    {
        if (empty($this->buffer)) {
            return;
        }

        $payload = '';
        foreach ($this->buffer as $log) {
            $payload .= json_encode($log) . "\n";
        }

        $ch = curl_init($this->url . '/insert/jsonline?_msg_field=message&_time_field=timestamp&_stream_fields=service,environment');
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');
        curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/stream+json',
        ]);

        if ($this->username !== '' && $this->password !== '') {
            curl_setopt($ch, CURLOPT_USERPWD, $this->username . ':' . $this->password);
        }

        curl_setopt($ch, CURLOPT_TIMEOUT, 5);

        $result = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode < 200 || $httpCode >= 300) {
            error_log("Failed to send logs to VictoriaLogs: HTTP $httpCode");
        }

        $this->buffer = [];
        $this->lastFlush = time();
    }

    public function __destruct()
    {
        $this->flush();
    }

    public function close(): void
    {
        $this->flush();
        parent::close();
    }
}
