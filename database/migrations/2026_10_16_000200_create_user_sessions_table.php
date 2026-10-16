<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** 一个 (user, addr) 一条：在线设备数、踢人、单连接流量都靠它。 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('username', 64);
            $table->string('addr', 64);          // ip:port（Hysteria 上报的客户端地址）
            $table->string('ip', 45)->nullable();
            $table->unsignedBigInteger('tx_bytes')->default(0);
            $table->unsignedBigInteger('rx_bytes')->default(0);
            $table->timestamp('first_seen_at')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamp('ended_at')->nullable();
            $table->timestamps();

            $table->unique(['username', 'addr']);
            $table->index(['username', 'last_seen_at']);
            $table->index('ended_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_sessions');
    }
};
