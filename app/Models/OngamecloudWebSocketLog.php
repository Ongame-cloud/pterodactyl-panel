<?php

namespace Pterodactyl\Models;

use Illuminate\Database\Eloquent\Model;

class OngamecloudWebSocketLog extends Model
{
    protected $table = 'ongamecloud_websocket_logs';

    protected $fillable = [
        'connection_id',
        'action',
        'server_short_id',
        'server_id',
        'request_data',
        'response_data',
        'status',
        'error_message',
        'ip_address',
        'duration_ms',
    ];

    protected $casts = [
        'request_data' => 'array',
        'response_data' => 'array',
        'duration_ms' => 'integer',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function server()
    {
        return $this->belongsTo(Server::class, 'server_id');
    }
}
