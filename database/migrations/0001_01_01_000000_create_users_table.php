<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Hysteria 认证用户表（本表就是 Hysteria 认的「用户」：id = username）。
 * 后台管理员是另一张表（admin_users），两者刻意分开：自助注册拿不到面板权限。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('username', 64)->unique();
            $table->string('password_hash');
            $table->string('email')->nullable()->unique();
            $table->boolean('enabled')->default(true);
            $table->timestamp('expires_at')->nullable();
            $table->unsignedBigInteger('quota_bytes')->default(0);   // 0 = 不限
            $table->unsignedBigInteger('used_bytes')->default(0);
            $table->unsignedBigInteger('tx_bytes')->default(0);
            $table->unsignedBigInteger('rx_bytes')->default(0);
            $table->unsignedInteger('max_devices')->default(0);      // 0 = 不限
            $table->string('note')->default('');
            $table->timestamp('last_online_at')->nullable();
            $table->string('last_ip', 45)->nullable();
            $table->timestamp('disabled_at')->nullable();
            $table->string('disabled_reason')->default('');
            $table->unsignedBigInteger('invite_code_id')->nullable();
            $table->rememberToken();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['enabled', 'expires_at']);
            $table->index('last_online_at');
        });

        // 用户自助面（/me）与后台都要会话
        Schema::create('sessions', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->foreignId('user_id')->nullable()->index();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity')->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sessions');
        Schema::dropIfExists('users');
    }
};
