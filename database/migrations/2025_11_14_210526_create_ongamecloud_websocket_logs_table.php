<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ongamecloud_websocket_logs', function (Blueprint $table) {
            $table->id();
            $table->string('connection_id', 64)->index();
            $table->string('action', 32)->index();
            $table->string('server_short_id', 8)->index();
            $table->unsignedBigInteger('server_id')->nullable()->index();
            $table->json('request_data')->nullable();
            $table->json('response_data')->nullable();
            $table->string('status', 16)->index();
            $table->text('error_message')->nullable();
            $table->string('ip_address', 45);
            $table->unsignedInteger('duration_ms')->default(0);
            $table->timestamps();

            $table->foreign('server_id')->references('id')->on('servers')->onDelete('set null');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ongamecloud_websocket_logs');
    }
};
