<?php

declare(strict_types=1);

namespace Tests;

use App\Services\AuthCache;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // 测试用独立的 Redis 库（phpunit.xml 里 REDIS_AUTH_DB=15）+ 独立前缀，
        // 每个用例开始前清干净，避免鉴权快照/在线集合互相串味。
        try {
            app(AuthCache::class)->flushPrefix();
        } catch (\Throwable) {
            // 没装 Redis 也不该让整个测试套件挂掉；涉及 Redis 的断言自己会失败
        }
    }
}
