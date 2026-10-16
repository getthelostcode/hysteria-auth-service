<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\TrafficDaily;
use App\Models\User;
use App\Services\TrafficService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TrafficFlushTest extends TestCase
{
    use RefreshDatabase;

    public function test_flush_moves_redis_counters_into_tables(): void
    {
        $user = User::query()->create(['username' => 'alice', 'password_hash' => bcrypt('x'), 'enabled' => true]);
        $traffic = app(TrafficService::class);

        $traffic->ingest('alice', '203.0.113.1:1', 100, 200);
        $traffic->ingest('alice', '203.0.113.1:1', 300, 400);

        $this->assertSame(700, $traffic->pending('alice'));

        $this->artisan('traffic:flush')->assertSuccessful();

        $user->refresh();
        $this->assertSame(700, $user->used_bytes);
        $this->assertSame(300, $user->tx_bytes);
        $this->assertSame(400, $user->rx_bytes);
        $this->assertSame(0, $traffic->pending('alice'), 'flush 之后热计数归零');

        $daily = TrafficDaily::query()->where('username', 'alice')->where('day', now()->toDateString())->first();
        $this->assertNotNull($daily);
        $this->assertSame(300, $daily->tx);
        $this->assertSame(400, $daily->rx);
    }

    public function test_flush_is_idempotent_and_survives_unknown_users(): void
    {
        app(TrafficService::class)->ingest('ghost', '203.0.113.1:1', 10, 10);

        $this->artisan('traffic:flush')->assertSuccessful();
        $this->artisan('traffic:flush')->assertSuccessful();

        $this->assertSame(0, User::query()->count());
    }

    public function test_counter_reset_on_new_connection_is_handled(): void
    {
        $traffic = app(TrafficService::class);

        // 第一连接：累计到 1000
        $traffic->ingest('alice', '203.0.113.9:1', 1000, 0);
        // 同一 addr 新连接，累计值从 0 重新开始 → 不能产生负数，直接按新值计
        $traffic->ingest('alice', '203.0.113.9:1', 40, 0);

        $this->assertSame(1040, $traffic->pending('alice'));
    }
}
