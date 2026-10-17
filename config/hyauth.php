<?php

declare(strict_types=1);

/*
| Hysteria 鉴权服务的运行时参数。
| 可变的部分（注册开关、默认配额…）同时存在 settings 表里、面板可改；这里只给默认值。
*/
return [
    // Redis 前缀与缓存
    'cache_prefix' => env('AUTH_CACHE_PREFIX', 'hyauth'),
    'redis_connection' => env('AUTH_REDIS_CONNECTION', 'auth'),
    // auth 形态：uuid（客户端只填 uuid，可重置）/ password（用户名:密码）/ both
    'auth_mode' => env('AUTH_MODE', 'both'),
    'cache_ttl' => (int) env('AUTH_CACHE_TTL', 60),          // 用户状态快照 TTL（秒）
    'password_cache' => (bool) env('AUTH_PASSWORD_CACHE', false), // 是否缓存密码校验结果（省 bcrypt）
    'online_ttl' => (int) env('ONLINE_TTL', 90),             // 在线判定窗口（秒），与 Hysteria 会话刷新同量级

    // 失败限流
    'max_failures_per_min' => (int) env('AUTH_MAX_FAILURES_PER_MIN', 20),
    'fail_block_seconds' => (int) env('AUTH_FAIL_BLOCK_SECONDS', 60),

    // 兼容上报面
    'traffic_report_token' => env('TRAFFIC_REPORT_TOKEN', ''),

    // 用户自助重置 UUID：开关 + 每小时次数上限（防止会话被盗后被人反复换钥匙）
    'self_uuid_reset' => [
        'enabled' => (bool) env('SELF_UUID_RESET_ENABLED', true),
        'per_hour' => (int) env('SELF_UUID_RESET_PER_HOUR', 5),
    ],

    // 注册
    'registration' => [
        'enabled' => (bool) env('REGISTRATION_ENABLED', false),
        'requires_invite' => (bool) env('REGISTRATION_REQUIRES_INVITE', true),
        'max_per_hour_per_ip' => (int) env('REGISTRATION_MAX_PER_HOUR_PER_IP', 3),
        'min_password_length' => (int) env('REGISTRATION_MIN_PASSWORD', 8),
    ],

    // 新用户默认
    'defaults' => [
        'quota_bytes' => (int) env('DEFAULT_QUOTA_BYTES', 0),
        'valid_days' => (int) env('DEFAULT_VALID_DAYS', 30),
        'max_devices' => (int) env('DEFAULT_MAX_DEVICES', 3),
    ],

    // 审计：拒答事件是否入库（量大，默认只记 Redis 指标 + 关键拒绝）
    'audit' => [
        'log_denials' => (bool) env('AUTH_LOG_DENIALS', true),
    ],
];
