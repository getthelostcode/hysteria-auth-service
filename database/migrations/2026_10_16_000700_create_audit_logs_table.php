<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** 管理动作 + 鉴权拒绝事件（只增不删）。 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->string('actor_type', 16)->default('system');   // admin | user | system | agent
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->string('actor_label')->default('');
            $table->string('action', 64);
            $table->string('target_type', 32)->default('');
            $table->string('target_id', 64)->default('');
            $table->string('target_label')->default('');
            $table->json('meta')->nullable();
            $table->string('ip', 45)->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['action', 'created_at']);
            $table->index(['target_type', 'target_id']);
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
    }
};
