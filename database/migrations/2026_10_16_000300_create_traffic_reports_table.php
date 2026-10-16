<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** 原始增量账：可审计、可重算（Hysteria 上报的是连接累计值，这里只落差分）。 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('traffic_reports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('username', 64);
            $table->string('addr', 64)->nullable();
            $table->unsignedBigInteger('tx_delta')->default(0);
            $table->unsignedBigInteger('rx_delta')->default(0);
            $table->string('source', 16)->default('auth');       // auth | api | collector
            $table->timestamp('reported_at')->nullable();
            $table->timestamps();

            $table->index(['username', 'reported_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('traffic_reports');
    }
};
