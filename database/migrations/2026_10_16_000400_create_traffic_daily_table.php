<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** 日汇总，喂面板图表（按 (user, day) 幂等 upsert）。 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('traffic_daily', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('username', 64);
            $table->date('day');
            $table->unsignedBigInteger('tx')->default(0);
            $table->unsignedBigInteger('rx')->default(0);
            $table->timestamps();

            $table->unique(['username', 'day']);
            $table->index('day');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('traffic_daily');
    }
};
