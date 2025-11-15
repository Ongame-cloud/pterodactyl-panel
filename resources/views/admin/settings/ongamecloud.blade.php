@extends('layouts.admin')
@include('partials/admin.settings.nav', ['activeTab' => 'ongamecloud'])

@section('title')
    Ongamecloud Settings
@endsection

@section('content-header')
    <h1>Ongamecloud WebSocket<small>Configure WebSocket server for backend communication.</small></h1>
    <ol class="breadcrumb">
        <li><a href="{{ route('admin.index') }}">Admin</a></li>
        <li><a href="{{ route('admin.settings') }}">Settings</a></li>
        <li class="active">Ongamecloud</li>
    </ol>
@endsection

@section('content')
    @yield('settings::nav')
    
    <div class="row">
        <div class="col-xs-12">
            <div class="box">
                <div class="box-header with-border">
                    <h3 class="box-title">WebSocket Configuration</h3>
                </div>
                <form action="{{ route('admin.settings.ongamecloud') }}" method="POST">
                    <div class="box-body">
                        <div class="row">
                            <div class="form-group col-md-4">
                                <label class="control-label">WebSocket Enabled</label>
                                <div>
                                    <div class="btn-group" data-toggle="buttons">
                                        <label class="btn btn-primary @if($enabled) active @endif">
                                            <input type="radio" name="enabled" value="1" @if($enabled) checked @endif> Enabled
                                        </label>
                                        <label class="btn btn-primary @if(!$enabled) active @endif">
                                            <input type="radio" name="enabled" value="0" @if(!$enabled) checked @endif> Disabled
                                        </label>
                                    </div>
                                    <p class="text-muted"><small>Enable or disable the WebSocket server.</small></p>
                                </div>
                            </div>
                            
                            <div class="form-group col-md-4">
                                <label class="control-label">Host</label>
                                <div>
                                    <input type="text" class="form-control" name="host" value="{{ old('host', $host) }}" />
                                    <p class="text-muted"><small>The host address to bind the WebSocket server to.</small></p>
                                </div>
                            </div>
                            
                            <div class="form-group col-md-4">
                                <label class="control-label">Port</label>
                                <div>
                                    <input type="number" class="form-control" name="port" value="{{ old('port', $port) }}" min="1" max="65535" />
                                    <p class="text-muted"><small>The port to bind the WebSocket server to.</small></p>
                                </div>
                            </div>
                        </div>
                        
                        <div class="row">
                            <div class="form-group col-md-4">
                                <label class="control-label">SSL Enabled</label>
                                <div>
                                    <div class="btn-group" data-toggle="buttons">
                                        <label class="btn btn-primary @if($ssl) active @endif">
                                            <input type="radio" name="ssl" value="1" @if($ssl) checked @endif> Enabled
                                        </label>
                                        <label class="btn btn-primary @if(!$ssl) active @endif">
                                            <input type="radio" name="ssl" value="0" @if(!$ssl) checked @endif> Disabled
                                        </label>
                                    </div>
                                    <p class="text-muted"><small>Enable SSL/TLS for secure connections.</small></p>
                                </div>
                            </div>
                            
                            <div class="form-group col-md-4">
                                <label class="control-label">Max Connections</label>
                                <div>
                                    <input type="number" class="form-control" name="max_connections" value="{{ old('max_connections', $max_connections) }}" min="1" />
                                    <p class="text-muted"><small>Maximum number of concurrent connections.</small></p>
                                </div>
                            </div>
                            
                            <div class="form-group col-md-4">
                                <label class="control-label">Timeout (seconds)</label>
                                <div>
                                    <input type="number" class="form-control" name="timeout" value="{{ old('timeout', $timeout) }}" min="1" />
                                    <p class="text-muted"><small>Connection timeout in seconds.</small></p>
                                </div>
                            </div>
                        </div>
                        
                        <div class="row">
                            <div class="form-group col-md-6">
                                <label class="control-label">Logging Enabled</label>
                                <div>
                                    <div class="btn-group" data-toggle="buttons">
                                        <label class="btn btn-primary @if($log_enabled) active @endif">
                                            <input type="radio" name="log_enabled" value="1" @if($log_enabled) checked @endif> Enabled
                                        </label>
                                        <label class="btn btn-primary @if(!$log_enabled) active @endif">
                                            <input type="radio" name="log_enabled" value="0" @if(!$log_enabled) checked @endif> Disabled
                                        </label>
                                    </div>
                                    <p class="text-muted"><small>Enable logging of WebSocket requests.</small></p>
                                </div>
                            </div>
                            
                            <div class="form-group col-md-6">
                                <label class="control-label">Log Retention (days)</label>
                                <div>
                                    <input type="number" class="form-control" name="log_retention_days" value="{{ old('log_retention_days', $log_retention_days) }}" min="1" />
                                    <p class="text-muted"><small>Number of days to keep logs before automatic deletion.</small></p>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="box-footer">
                        {!! csrf_field() !!}
                        <button type="submit" name="_method" value="PATCH" class="btn btn-sm btn-primary pull-right">Save Settings</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <div class="row">
        <div class="col-xs-12">
            <div class="box">
                <div class="box-header with-border">
                    <h3 class="box-title">WebSocket Connection</h3>
                </div>
                <div class="box-body">
                    <div class="row">
                        <div class="col-md-12">
                            <div class="form-group">
                                <label class="control-label">WebSocket URL</label>
                                <div class="input-group">
                                    <input type="text" class="form-control" id="websocket-url" value="{{ ($ssl ? 'wss://' : 'ws://') . $host . ':' . $port }}" readonly />
                                    <span class="input-group-btn">
                                        <button class="btn btn-success" type="button" onclick="copyWebSocketUrl()">
                                            <i class="fa fa-copy"></i> Copy URL
                                        </button>
                                    </span>
                                </div>
                                <p class="text-muted"><small>Use this URL to connect your backend to the WebSocket server. For Railway, use your public domain instead of {{ $host }}.</small></p>
                            </div>
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-md-12">
                            <div class="form-group">
                                <label class="control-label">Authentication Token</label>
                                <div class="input-group">
                                    <input type="text" class="form-control" id="auth-token" value="{{ $auth_token ?? 'Not configured' }}" readonly />
                                    <span class="input-group-btn">
                                        <button class="btn btn-default" type="button" onclick="copyToken()">
                                            <i class="fa fa-copy"></i> Copy
                                        </button>
                                        <button class="btn btn-primary" type="button" onclick="generateToken()">
                                            <i class="fa fa-refresh"></i> Generate New
                                        </button>
                                    </span>
                                </div>
                                <p class="text-muted"><small>This token must be used by your backend to authenticate WebSocket connections. Store it securely in your backend's .env file as ONGAMECLOUD_WS_AUTH_TOKEN.</small></p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <div class="row">
        <div class="col-md-6">
            <div class="box">
                <div class="box-header with-border">
                    <h3 class="box-title">Server Statistics</h3>
                    <div class="box-tools">
                        <button class="btn btn-sm btn-default" onclick="refreshStats()">
                            <i class="fa fa-refresh"></i> Refresh
                        </button>
                    </div>
                </div>
                <div class="box-body">
                    <div class="row">
                        <div class="col-sm-6">
                            <div class="info-box">
                                <span class="info-box-icon bg-aqua"><i class="fa fa-plug"></i></span>
                                <div class="info-box-content">
                                    <span class="info-box-text">Active Connections</span>
                                    <span class="info-box-number" id="stat-active">{{ $stats['active_connections'] ?? 0 }}</span>
                                </div>
                            </div>
                        </div>
                        <div class="col-sm-6">
                            <div class="info-box">
                                <span class="info-box-icon bg-green"><i class="fa fa-check"></i></span>
                                <div class="info-box-content">
                                    <span class="info-box-text">Total Connections</span>
                                    <span class="info-box-number" id="stat-total">{{ $stats['total_connections'] ?? 0 }}</span>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-sm-4">
                            <div class="info-box">
                                <span class="info-box-icon bg-blue"><i class="fa fa-exchange"></i></span>
                                <div class="info-box-content">
                                    <span class="info-box-text">Total Requests</span>
                                    <span class="info-box-number" id="stat-requests">{{ $stats['total_requests'] ?? 0 }}</span>
                                </div>
                            </div>
                        </div>
                        <div class="col-sm-4">
                            <div class="info-box">
                                <span class="info-box-icon bg-green"><i class="fa fa-check-circle"></i></span>
                                <div class="info-box-content">
                                    <span class="info-box-text">Successful</span>
                                    <span class="info-box-number" id="stat-success">{{ $stats['successful_requests'] ?? 0 }}</span>
                                </div>
                            </div>
                        </div>
                        <div class="col-sm-4">
                            <div class="info-box">
                                <span class="info-box-icon bg-red"><i class="fa fa-times-circle"></i></span>
                                <div class="info-box-content">
                                    <span class="info-box-text">Failed</span>
                                    <span class="info-box-number" id="stat-failed">{{ $stats['failed_requests'] ?? 0 }}</span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <div class="col-md-6">
            <div class="box">
                <div class="box-header with-border">
                    <h3 class="box-title">Active Connections</h3>
                </div>
                <div class="box-body" style="max-height: 300px; overflow-y: auto;">
                    <div id="connections-list">
                        @if(count($connections) > 0)
                            <table class="table table-striped table-condensed">
                                <thead>
                                    <tr>
                                        <th>Connection ID</th>
                                        <th>IP Address</th>
                                        <th>Connected</th>
                                        <th>Requests</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach($connections as $id => $conn)
                                        <tr>
                                            <td><code>{{ substr($id, 0, 8) }}...</code></td>
                                            <td>{{ $conn['ip_address'] }}</td>
                                            <td>{{ $conn['connected_at']->diffForHumans() }}</td>
                                            <td>{{ $conn['requests_count'] }}</td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        @else
                            <p class="text-muted text-center">No active connections</p>
                        @endif
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <div class="row">
        <div class="col-xs-12">
            <div class="box">
                <div class="box-header with-border">
                    <h3 class="box-title">WebSocket Logs</h3>
                    <div class="box-tools">
                        <button class="btn btn-sm btn-warning" onclick="cleanLogs()">
                            <i class="fa fa-trash"></i> Clean Old Logs
                        </button>
                        <button class="btn btn-sm btn-default" onclick="refreshLogs()">
                            <i class="fa fa-refresh"></i> Refresh
                        </button>
                    </div>
                </div>
                <div class="box-body">
                    <div class="row" style="margin-bottom: 10px;">
                        <div class="col-md-3">
                            <select class="form-control" id="filter-status" onchange="refreshLogs()">
                                <option value="">All Status</option>
                                <option value="success">Success</option>
                                <option value="error">Error</option>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <select class="form-control" id="filter-action" onchange="refreshLogs()">
                                <option value="">All Actions</option>
                                <option value="start">Start</option>
                                <option value="stop">Stop</option>
                                <option value="restart">Restart</option>
                                <option value="kill">Kill</option>
                            </select>
                        </div>
                    </div>
                    <div id="logs-container">
                        <table class="table table-striped table-hover">
                            <thead>
                                <tr>
                                    <th>Time</th>
                                    <th>Action</th>
                                    <th>Server</th>
                                    <th>Status</th>
                                    <th>Duration</th>
                                    <th>IP</th>
                                    <th>Details</th>
                                </tr>
                            </thead>
                            <tbody id="logs-body">
                                <tr>
                                    <td colspan="7" class="text-center">Loading logs...</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
@endsection

@section('footer-scripts')
    @parent
    <script>
        function copyWebSocketUrl() {
            const urlInput = document.getElementById('websocket-url');
            urlInput.select();
            document.execCommand('copy');
            
            swal({
                type: 'success',
                title: 'Copied!',
                text: 'WebSocket URL copied to clipboard',
                timer: 2000,
                showConfirmButton: false
            });
        }

        function copyToken() {
            const tokenInput = document.getElementById('auth-token');
            tokenInput.select();
            document.execCommand('copy');
            
            swal({
                type: 'success',
                title: 'Copied!',
                text: 'Authentication token copied to clipboard',
                timer: 2000,
                showConfirmButton: false
            });
        }

        function generateToken() {
            swal({
                type: 'warning',
                title: 'Generate New Token?',
                text: 'This will invalidate the current token. Your backend will need to be updated with the new token.',
                showCancelButton: true,
                confirmButtonText: 'Generate',
                confirmButtonColor: '#d9534f'
            }).then((result) => {
                if (result.value) {
                    $.ajax({
                        url: '{{ route('admin.settings.ongamecloud.generate-token') }}',
                        method: 'POST',
                        headers: {
                            'X-CSRF-TOKEN': '{{ csrf_token() }}'
                        },
                        success: function(data) {
                            document.getElementById('auth-token').value = data.token;
                            swal({
                                type: 'success',
                                title: 'Token Generated',
                                text: 'New token has been generated. Make sure to update your backend configuration!'
                            });
                        },
                        error: function() {
                            swal({
                                type: 'error',
                                title: 'Error',
                                text: 'Failed to generate token'
                            });
                        }
                    });
                }
            });
        }

        function refreshStats() {
            $.ajax({
                url: '{{ route('admin.settings.ongamecloud.stats') }}',
                method: 'GET',
                success: function(data) {
                    $('#stat-active').text(data.stats.active_connections);
                    $('#stat-total').text(data.stats.total_connections);
                    $('#stat-requests').text(data.stats.total_requests);
                    $('#stat-success').text(data.stats.successful_requests);
                    $('#stat-failed').text(data.stats.failed_requests);
                    
                    let connectionsHtml = '';
                    if (Object.keys(data.connections).length > 0) {
                        connectionsHtml = '<table class="table table-striped table-condensed"><thead><tr><th>Connection ID</th><th>IP Address</th><th>Connected</th><th>Requests</th></tr></thead><tbody>';
                        for (const [id, conn] of Object.entries(data.connections)) {
                            connectionsHtml += `<tr>
                                <td><code>${id.substring(0, 8)}...</code></td>
                                <td>${conn.ip_address}</td>
                                <td>${conn.connected_at}</td>
                                <td>${conn.requests_count}</td>
                            </tr>`;
                        }
                        connectionsHtml += '</tbody></table>';
                    } else {
                        connectionsHtml = '<p class="text-muted text-center">No active connections</p>';
                    }
                    $('#connections-list').html(connectionsHtml);
                }
            });
        }

        function refreshLogs() {
            const status = $('#filter-status').val();
            const action = $('#filter-action').val();
            
            $.ajax({
                url: '{{ route('admin.settings.ongamecloud.logs') }}',
                method: 'GET',
                data: { status: status, action: action, per_page: 50 },
                success: function(data) {
                    let logsHtml = '';
                    if (data.data.length > 0) {
                        data.data.forEach(log => {
                            const statusBadge = log.status === 'success' 
                                ? '<span class="label label-success">Success</span>' 
                                : '<span class="label label-danger">Error</span>';
                            
                            logsHtml += `<tr>
                                <td>${new Date(log.created_at).toLocaleString()}</td>
                                <td><code>${log.action}</code></td>
                                <td>${log.server ? log.server.name : log.server_short_id}</td>
                                <td>${statusBadge}</td>
                                <td>${log.duration_ms}ms</td>
                                <td>${log.ip_address}</td>
                                <td><button class="btn btn-xs btn-default" onclick="showLogDetails(${log.id})"><i class="fa fa-eye"></i></button></td>
                            </tr>`;
                        });
                    } else {
                        logsHtml = '<tr><td colspan="7" class="text-center">No logs found</td></tr>';
                    }
                    $('#logs-body').html(logsHtml);
                }
            });
        }

        function cleanLogs() {
            swal({
                type: 'warning',
                title: 'Clean Old Logs?',
                text: 'This will delete logs older than the configured retention period.',
                showCancelButton: true,
                confirmButtonText: 'Clean Logs',
                confirmButtonColor: '#f39c12'
            }).then((result) => {
                if (result.value) {
                    $.ajax({
                        url: '{{ route('admin.settings.ongamecloud.clean-logs') }}',
                        method: 'POST',
                        headers: {
                            'X-CSRF-TOKEN': '{{ csrf_token() }}'
                        },
                        success: function(data) {
                            swal({
                                type: 'success',
                                title: 'Logs Cleaned',
                                text: data.message
                            });
                            refreshLogs();
                        },
                        error: function() {
                            swal({
                                type: 'error',
                                title: 'Error',
                                text: 'Failed to clean logs'
                            });
                        }
                    });
                }
            });
        }

        $(document).ready(function() {
            refreshLogs();
            setInterval(refreshStats, 5000);
            setInterval(refreshLogs, 10000);
        });
    </script>
@endsection
