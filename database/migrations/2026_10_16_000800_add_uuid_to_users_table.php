<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Hysteria 的 `auth` 可以直接是**一个 UUID**（客户端配置里只填 uuid，不填 用户名:密码）。
 * 这里给每个用户一条 uuid 作为凭据；重置 uuid 就等于换一把钥匙（旧的立刻失效）。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->char('uuid', 36)->nullable()->unique()->after('username');
            $table->timestamp('uuid_rotated_at')->nullable()->after('uuid');
        });

        // 老数据补 uuid（分块，避免一次性全表更新）
        DB::table('users')->whereNull('uuid')->orderBy('id')->chunkById(500, function ($rows) {
            foreach ($rows as $row) {
                DB::table('users')->where('id', $row->id)->update(['uuid' => (string) Str::uuid()]);
            }
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['uuid']);
            $table->dropColumn(['uuid', 'uuid_rotated_at']);
        });
    }
};
