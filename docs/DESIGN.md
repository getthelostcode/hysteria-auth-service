# 设计说明

## 1. 解决什么

| 需求 | 落地 |
|---|---|
| Hysteria2 的 HTTP 鉴权 | `POST /auth`，永远 200 + `{ok,id}`（契约见 [API.md](API.md)） |
| 用户注册 | `/register`（可关、可选邀请码、按 IP 限流） |
| 管理后台 | Filament v3 `/admin`：用户列表 + **启用/禁用**、配额/到期、重置密码、踢下线、流量曲线、邀请码、审计、仪表盘 |
| 可以禁用用户 | 禁用写库后**主动清缓存** + 移出在线集合 + 排入待踢队列 → 下一次 `/auth` 立刻拒绝（秒级，不等 TTL） |
| 流量统计 | `/auth` 带回的 `tx`/`rx` 按**会话差分**进 Redis 计数，每分钟批量落库 |

本仓库取代了原来的 FastAPI + SQLAlchemy + aiomysql 版本（见 tag `legacy-fastapi`）；旧库的 `password_hash` 是 bcrypt，可以直接导入复用。

## 2. 请求路径

```
客户端 ──QUIC──▶ 节点 hysteria-server ──POST /auth (JSON, 永远 200)──▶  Cloudflare/隧道 ─▶ nginx ─▶ php-fpm / artisan serve
                                                                                                  │
                                                            ┌─────────────────────────────────────┼──────────────────────────┐
                                                            ▼                                     ▼                          ▼
                                                        Redis (DB 3)                        MySQL 8 / SQLite          Filament /admin
                                                  缓存/在线/计数/限流                    用户/会话/流量/审计          （admin_users 登录）
```

* 服务默认监听 `127.0.0.1:8000`（和旧的 uvicorn 一样，Cloudflare 回源不用改）；
* 与 `hysteria-server` 可以同机也可以不同机：本服务**不直连**对端 Hysteria 本地 API，踢人只做标记；
* `TRUSTED_PROXIES=127.0.0.1,::1` 必配，否则客户端 IP 全是回源地址。

## 3. 数据模型

| 表 | 关键字段 | 说明 |
|---|---|---|
| `users` | `username`(唯一，= Hysteria id)、`uuid`(唯一，客户端凭据，可重置)、`uuid_rotated_at`、`password_hash`(bcrypt)、`email`、`enabled`、`expires_at`、`quota_bytes`、`used_bytes`、`tx_bytes`、`rx_bytes`、`max_devices`、`note`、`last_online_at`、`last_ip`、`disabled_at`、`disabled_reason`、软删 | Hysteria 用户 |
| `admin_users` | `name`、`email`、`password`、`role`(super-admin/operator/viewer)、`enabled`、`last_login_at` | **后台管理员，与用户表分离**：自助注册永远拿不到面板权限 |
| `user_sessions` | `username`+`addr` 唯一、`ip`、`tx_bytes`、`rx_bytes`、`first_seen_at`、`last_seen_at`、`ended_at` | 连接记录（在线/设备数/踢人） |
| `traffic_reports` | `username`、`addr`、`tx_delta`、`rx_delta`、`source`(auth/api/flush)、`reported_at` | 原始增量账（可审计可重算） |
| `traffic_daily` | `username`+`day` 唯一、`tx`、`rx` | 日汇总（图表；`day` 只存 `Y-m-d`） |
| `invite_codes` | `code`、`max_uses`、`uses`、`expires_at`、`quota_bytes`、`valid_days` | 邀请码（可覆盖新用户默认值） |
| `settings` | `key`(主键)、`value`(JSON) | 面板可改的运行时设置 |
| `audit_logs` | `actor_type`/`actor_label`、`action`、`target_*`、`meta`、`ip`、`created_at` | 管理动作 + 鉴权拒答 |
| 框架表 | `sessions`、`jobs`、`cache`、`personal_access_tokens` | Sanctum token 等 |

## 4. Redis Key 表

前缀固定 `hyauth:`（见 `config/hyauth.php` 的 `cache_prefix`），连接用独立的 `auth`（默认 DB 3）。
**`REDIS_PREFIX` 必须留空**：Laravel 默认会给 key 加 `<app>_database_` 前缀，我们已经在代码里拼了 `hyauth:`，双层前缀会让 `DEL`/`EXISTS` 对不上（这个坑真出现过：`keys()` 能看到、`del()` 删不掉）。

| Key | 类型 / TTL | 写入者 | 用途 |
|---|---|---|---|
| `hyauth:user:{username}` | string(JSON) / `AUTH_CACHE_TTL`(60s) | `/auth` 未命中时回填 | 用户快照（enabled/到期/配额/已用/设备上限/密码哈希/uuid）→ 命中即判定，不查库 |
| `hyauth:uuid:{uuid}` | string(JSON) / 同上 | `/auth`（UUID 形态）未命中时回填 | **同一份快照**，按 uuid 索引；重置 uuid 时新旧两个键都要删 |
| `hyauth:pwd:{username}:{sha256(密码)/24}` | string / 60s | `/auth`（开关 `AUTH_PASSWORD_CACHE`） | 密码校验结果缓存（默认关） |
| `hyauth:used:tx:{u}` / `hyauth:used:rx:{u}` | counter | `/auth`、`/traffic_report` | 未落库的流量增量 |
| `hyauth:sess:{u}:{addr}` | hash / ≥10min | `/auth` | 该连接的**上次累计值**（会话差分基准）+ 最后活跃时间 |
| `hyauth:dirty` | set | `/auth` | 待落库用户集合（flush 用） |
| `hyauth:reports` | list(≤20000) | `/auth` | 待落库的原始增量明细 |
| `hyauth:dev:{u}` | zset(ip→ts) / 4×online_ttl | `/auth` | 在线设备（判定上限、面板显示） |
| `hyauth:addr:{u}` | zset(addr→ts) | `/auth` | 连接的客户端地址 |
| `hyauth:online` | zset(username→ts) | `/auth` | 全网在线用户数 |
| `hyauth:fail:ip:{ip}` / `hyauth:fail:user:{u}` | counter / 60s | 拒绝路径 | 失败计数与限流 |
| `hyauth:block:{u}` | string / 60s | 配额用尽 / 手动 | 短期封禁（避免每次都回库判配额） |
| `hyauth:kick:{u}` | list / 1h | 面板/管理 API | 待踢队列（节点侧或控制面来取） |
| `hyauth:seen:{u}` | string / 15s | `/auth` | `last_online_at` 写库门控 |
| `hyauth:reg:{ip}` | counter / 1h | `/register` | 注册限流 |
| `hyauth:deny:{reason}:{hash}` | string / 10min | 拒答审计 | 同一身份同一原因只记一条审计 |
| `hyauth:lock:flush` | string / 55s | `traffic:flush` | 防并发 flush |
| `hyauth:settings` | string(JSON) | 面板设置页 | 运行时设置快照（Laravel Cache，进程内） |
| `hyauth-test:*` | — | 测试 | phpunit 用 `REDIS_AUTH_DB=15` + 独立前缀，每个用例前清空 |

**主动失效**（与旧版最关键的差别）：禁用/启用/改配额/改到期/改密码/**重置 uuid**/删除 → 立即 `DEL hyauth:user:{u}` + `hyauth:uuid:{uuid}`（新旧两个）+ `hyauth:pwd:{u}:*` + `hyauth:block:{u}`；禁用时还会 `ZREM hyauth:online` 并清空设备集合。所以生效是**秒级**，不是等 60 秒 TTL。

> 快照挂两个键是刻意的：UUID 形态的请求手里没有用户名，只能按 uuid 命中缓存；两个键内容相同，任何状态变更必须同时失效（`UserStateCache::forget($username, $uuid)`），漏一个就会出现「禁用/重置了但还能连」。

## 5. 时序

### 5.1 客户端连接 → 鉴权

```
客户端        hysteria-server           Laravel /auth                 Redis             MySQL
  │ QUIC 连接 ─▶│                          │                            │                 │
  │             │ {addr,auth,tx:0}         │                            │                 │
  │             ├─────────────────────────▶│ 1 解析形态：uuid ⇒ 按 uuid  │                 │
  │             │                          │   查快照；含 ':' ⇒ 用户名密码│                 │
  │             │                          │ 2 限流/封禁检查 ──────────▶│                 │
  │             │                          │ 3 GET hyauth:uuid|user ───▶│                 │
  │             │                          │   └ 未命中 → SELECT users ─────────────────▶│
  │             │                          │ 4 bcrypt（未命中快照才做）   │                 │
  │             │                          │ 5 判 enabled/到期/配额/设备  │                 │
  │             │                          │ 6 ZADD 在线集合 ──────────▶│                 │
  │             │  200 {"ok":true,"id":"u"}│   （id 始终是用户名）        │                 │
  │             │◀─────────────────────────┤（拒绝也 200：ok:false+reason）                │
  │◀─ 放行 ─────┤                          │                            │                 │
```

### 5.2 断开 → 流量入账 → 每分钟落库

```
hysteria ──POST /auth {addr,auth,tx:123456}──▶ Laravel
                                   │ 读 hyauth:sess:{u}:{addr} 的上次累计值
                                   │ delta = 本次 - 上次（累计值 ⇒ 只记增量；计数归零则按新值记）
                                   │ INCRBY hyauth:used:tx/rx:{u} ；SADD hyauth:dirty ；RPUSH hyauth:reports
                                   │ 超配额 ⇒ SET hyauth:block:{u}=quota
                                   ▼
                        schedule: traffic:flush（每分钟）
                          ├─ GETSET 计数 → users.used_bytes/tx_bytes/rx_bytes
                          ├─ traffic_daily（当日 upsert）
                          └─ traffic_reports（原始增量账）
```

### 5.3 后台禁用 → 秒级生效

```
管理员(面板/API) ─▶ UserManager::disable()
                      ├─ UPDATE users SET enabled=0, disabled_at, disabled_reason
                      ├─ INSERT audit_logs(user.disabled)
                      ├─ DEL hyauth:user:{u} / hyauth:pwd:{u}:* / hyauth:block:{u}
                      ├─ ZREM hyauth:online {u} + DEL 设备集合
                      └─ RPUSH hyauth:kick:{u}（待踢）
                                   ▼
下一次 /auth: 未命中缓存 → 查库 enabled=0 → 200 {"ok":false,"id":"","reason":"disabled"}
```

### 5.4 注册

```
GET /register（关闭则 404）
POST /register ─▶ 限流(hyauth:reg:{ip}) → 用户名/密码校验 → 邀请码校验与消耗
               ─▶ INSERT users（默认配额/天数/设备数；邀请码可覆盖）→ 审计 user.registered
               ─▶ 登录态 → /me 展示到期、配额、在线设备、14 天流量
```

## 6. 与其它组件的关系

| 组件 | 关系 |
|---|---|
| 节点 `hysteria-server` | 只认 `POST /auth`（`auth.type: http`）；`trafficStats` 是本服务之外的独立机制 |
| `hysteria-control-plane` | 面板模板里的 `{{auth_url}}` 指向本服务；它的「用户」是面板账号，与本服务用户库各自独立，需要互通就走管理 API |
| 节点本地 `trafficStats` API | 如果继续用它，可用 `POST /traffic_report` 把增量喂进来（本项目不直连对端本地 API） |
| 待踢队列 `hyauth:kick:{u}` | 真正的断开由节点侧执行（hysteria 本地 `POST /kick`），本服务只标记 |

## 7. 性能与规模

* 热路径（`/auth`）命中 Redis 快照时**不查库、不算 bcrypt**；写库动作只有 15 秒门控的 `last_online_at`；
* 流量不入库、只加 Redis 计数器，每分钟一次批量 flush（一个事务一个用户）；
* 失败限流与封禁全在 Redis，防爆破不打库；
* 单机（php-fpm/artisan serve + Redis + SQLite/MySQL）足以支撑几千用户的鉴权峰值；要再往上加：把 `AUTH_PASSWORD_CACHE` 打开、MySQL 换独立实例、php-fpm 池加 worker。
