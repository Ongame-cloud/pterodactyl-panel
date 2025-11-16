@extends('layouts.admin')
@include('partials/admin.settings.nav', ['activeTab' => 'ongamecloud'])

@section('title')
    Ongamecloud Logs
@endsection

@section('content-header')
    <h1>Ongamecloud Logs<small>View WebSocket and Redis save system logs.</small></h1>
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
                    <h3 class="box-title">WebSocket Logs</h3>
                    <div class="box-tools">
                        <button class="btn btn-sm btn-default" onclick="refreshWebSocketLogs()">
                            <i class="fa fa-refresh"></i> Refresh
                        </button>
                    </div>
                </div>
                <div class="box-body" style="max-height: 500px; overflow-y: auto;">
                    <pre id="websocket-logs" style="background: #1e1e1e; color: #d4d4d4; padding: 15px; border-radius: 4px; font-size: 12px; line-height: 1.5;">Loading WebSocket logs...</pre>
                </div>
            </div>
        </div>
    </div>
    
    <div class="row">
        <div class="col-xs-12">
            <div class="box">
                <div class="box-header with-border">
                    <h3 class="box-title">Redis Save System Logs</h3>
                    <div class="box-tools">
                        <button class="btn btn-sm btn-default" onclick="refreshRedisLogs()">
                            <i class="fa fa-refresh"></i> Refresh
                        </button>
                    </div>
                </div>
                <div class="box-body" style="max-height: 500px; overflow-y: auto;">
                    <pre id="redis-logs" style="background: #1e1e1e; color: #d4d4d4; padding: 15px; border-radius: 4px; font-size: 12px; line-height: 1.5;">Loading Redis logs...</pre>
                </div>
            </div>
        </div>
    </div>
@endsection

@section('footer-scripts')
    @parent
    <script>
        function refreshWebSocketLogs() {
            $.ajax({
                url: '{{ route('admin.settings.ongamecloud.websocket-logs') }}',
                method: 'GET',
                success: function(data) {
                    $('#websocket-logs').text(data.logs || 'No logs available');
                },
                error: function() {
                    $('#websocket-logs').text('Error loading WebSocket logs');
                }
            });
        }

        function refreshRedisLogs() {
            $.ajax({
                url: '{{ route('admin.settings.ongamecloud.redis-logs') }}',
                method: 'GET',
                success: function(data) {
                    $('#redis-logs').text(data.logs || 'No logs available');
                },
                error: function() {
                    $('#redis-logs').text('Error loading Redis logs');
                }
            });
        }

        $(document).ready(function() {
            refreshWebSocketLogs();
            refreshRedisLogs();
            setInterval(refreshWebSocketLogs, 5000);
            setInterval(refreshRedisLogs, 5000);
        });
    </script>
@endsection
