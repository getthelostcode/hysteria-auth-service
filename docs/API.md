# 接口文档

服务职责三件：**Hysteria2 的 HTTP 鉴权后端**、**用户自助注册/查询**、**Filament 管理后台**。
架构、数据模型、Redis Key 表与时序见 [DESIGN.md](DESIGN.md)。

约定：

* Hysteria 面**永远返回 HTTP 200** —— 官方契约要求 200，用 `{"ok":bool,"id":string}` 表达结果；非 200 会被 Hysteria 直接当成鉴权失败；
* 路径两套等价：**根路径**（`/auth`、`/health`、`/traffic_report`，与旧 FastAPI 版同形，节点配置不用改）与 `/api/*` 前缀版（`/api/auth`…）；
* 用户面走 Laravel 常规语义（200/302/422/429）；管理 API 走 Sanctum Bearer；
* 时间 UTC，响应里的时间 RFC3339。

---

## 1. Hysteria 面

### POST /auth —— 鉴权（+ 顺带上报流量）

Hysteria 在**客户端连接时**（`tx=0`）与**断开时**（带该连接的累计字节）都会打这里。

请求：

```json
{ "addr": "123.123.123.123:44556", "auth": "550e8400-e29b-41d4-a716-446655440000", "tx": 123456 }
```

| 字段 | 必填 | 说明 |
|---|---|---|
| `addr` | 是 | 客户端地址 `ip:port`（IPv6 用 `[::1]:443`）；用于在线设备去重与判定 |
| `auth` | 是 | **UUID**（推荐）或 `用户名:密码`；见下表 |
| `tx` | 否 | 上行字节（连接累计值） |
| `rx` | 否 | 下行字节（有些版本/中间件会带；带了就一起记账） |

`auth` 的两种形态由设置项 `auth_mode` 决定（`uuid` / `password` / `both`，默认 `both`）：

| 形态 | 例子 | 校验方式 |
|---|---|---|
| UUID | `550e8400-e29b-41d4-a716-446655440000`（大小写不敏感） | 按 `users.uuid` 查用户，**不再校验密码**（UUID 本身就是凭据） |
| 用户名密码 | `alice:secret`（按**第一个** `:` 切分） | 按 `users.username` 查用户 + bcrypt 校验 |

当前模式不允许的形态会被拒：`reason = uuid_disabled` / `password_disabled`。

响应（**必须 200**）：

```json
{ "ok": true,  "id": "alice" }                 // 通过：id 是流量归类标识（这里 = 用户名）
{ "ok": false, "id": "", "reason": "disabled" } // 拒绝：id 必须为空串；reason 是给运维看的（Hysteria 会忽略）
```

HTTP 状态码：成功/拒绝都是 **200**；只有 Laravel 自身限流（`AUTH_RATE_LIMIT_PER_MIN`，默认 3000/分/IP）才会给 429。

拒绝判定顺序（任一条命中即拒，写审计 + 指标）：

| # | 条件 | `reason` |
|---|---|---|
| 1 | `auth` 既不是合法 UUID，也没有 `:` | `malformed` |
| 1b | 形态被 `auth_mode` 禁止 | `uuid_disabled` / `password_disabled` |
| 2 | 命中失败限流 / 短期封禁（含“配额刚用完”） | `rate_limited` / `quota_exceeded` |
| 3 | 用户不存在（**重置过的旧 UUID 也走这条**） | `unknown_user` |
| 4 | 密码错误 | `bad_password` |
| 5 | 已被禁用 | `disabled` |
| 6 | 已过期 | `expired` |
| 7 | 流量已用完（`used ≥ quota`，含**尚未落库**的 Redis 计数） | `quota_exceeded` |
| 8 | 在线设备（按 IP 去重）已达上限，且这是一个新 IP | `too_many_devices` |

通过时同时做：记在线（IP 集合，TTL 90s）、按**会话差分**记流量、清失败计数、按用户 15 秒门控更新 `last_online_at`/`last_ip`。

### GET /health

```json
{ "status": "ok", "database": "ok", "redis": "ok", "online_users": 3 }
```

### POST /traffic_report（兼容面，需令牌）

给外部采集器 / 旧调用方用：

```
POST /traffic_report
X-Auth-Token: <TRAFFIC_REPORT_TOKEN>

{ "user_id": "alice", "tx_delta": 1024, "rx_delta": 2048 }
→ 200 { "status": "ok" }
```

两个前提：`.env` 里配了 `TRAFFIC_REPORT_TOKEN`，且面板设置里打开「开启 /traffic_report 兼容上报」；否则一律 401（等于关闭）。

### POST /internal/kick（需令牌）

```
{ "username": "alice", "addr": "1.2.3.4:5678", "reason": "欠费" }
→ 200 { "status": "ok" }
```

只是把「该用户/该连接需要被踢」写进 Redis 待踢队列（`hyauth:kick:{username}`）。**真正断开连接**由节点侧执行（hysteria 本地 API `POST /kick`，通常经控制面/agent）——本服务可能跑在另一台机器上，拿不到对方的 `127.0.0.1:8080`。

---

## 2. 用户自助面

| Method | 路径 | 说明 |
|---|---|---|
| GET | `/` | 落地页：在线人数、服务端配置片段、入口 |
| GET/POST | `/register` | 注册（关闭时 404；可要求邀请码；同 IP 限流） |
| GET/POST | `/login` | 用户登录（被禁用/密码错都拒绝） |
| POST | `/logout` | 退出 |
| GET | `/me` | 我的用量、到期、在线设备、最近 14 天流量 |
| PATCH | `/me/password` | 改密码（新旧密码判定缓存同时失效） |
| POST | `/me/uuid/reset` | **用户自助重置自己的 UUID**：表单字段 `current_password`（必填，重新验证身份）、`disconnect`（可选，`1` = 同时把当前在线连接排入待踢队列）。成功后 flash `uuid_rotated` = 新 UUID，审计 `user.uuid_self_reset`（actor 为该用户）。按用户限流（默认 5 次/小时，`SELF_UUID_RESET_PER_HOUR`）；管理员可在面板关掉（403） |

注册规则（面板「面板设置」可改）：用户名 `^[a-zA-Z0-9_][a-zA-Z0-9_.-]{2,31}$` 且唯一（它就是 Hysteria 的 `id`，创建后不可改）；密码最小长度默认 8；新用户默认配额/有效期/设备数取设置项；邀请码可覆盖配额与天数且用完即失效。

---

## 3. 管理 API（Sanctum Bearer）

```bash
TOKEN=$(curl -s -X POST https://auth.example.com/api/v1/auth/login \
  -H 'Content-Type: application/json' \
  -d '{"email":"admin@example.com","password":"…"}' | jq -r .token)
```

| Method | 路径 | 权限 | 说明 |
|---|---|---|---|
| POST | `/api/v1/auth/login` | — | 签发 token（含角色） |
| GET | `/api/v1/auth/me` | 任意 | 当前身份 |
| POST | `/api/v1/auth/logout` | 任意 | 撤销当前 token |
| GET | `/api/v1/stats` | 任意 | 用户数/启用/禁用/过期/超额/在线/今日流量 |
| GET | `/api/v1/audit?action=&username=` | 任意 | 审计日志（分页） |
| GET | `/api/v1/users?status=&search=&per_page=` | 任意 | 用户列表（含用量、在线设备数） |
| POST | `/api/v1/users` | 运维+ | 建用户（`password` 留空则随机，返回一次明文） |
| GET | `/api/v1/users/{username}` | 任意 | 详情（含未落库用量与在线 IP） |
| PATCH | `/api/v1/users/{username}` | 运维+ | 改邮箱/配额/到期/设备上限/备注 |
| POST | `/api/v1/users/{username}/enable` | 运维+ | 启用（秒级生效） |
| POST | `/api/v1/users/{username}/disable` | 运维+ | **禁用**（`reason` 入审计；立即失效缓存 + 移出在线集合 + 排入待踢队列） |
| POST | `/api/v1/users/{username}/password` | 运维+ | 重置密码（`password` 留空则随机，返回一次明文） |
| POST | `/api/v1/users/{username}/uuid/reset` | 运维+ | **重置 UUID**（可传 `uuid` 指定；不传则随机）。返回 `{"uuid":"<新值>","data":{…}}`；**旧 UUID 立刻失效**（`unknown_user`），审计 `user.uuid_reset` 记 old/new |
| POST | `/api/v1/users/{username}/quota/reset` | 运维+ | 用量归零（新周期） |
| POST | `/api/v1/users/{username}/kick` | 运维+ | 排入待踢队列 |
| DELETE | `/api/v1/users/{username}` | 运维+ | 软删除（流量与审计保留） |
| GET | `/api/v1/users/{username}/traffic?days=30` | 任意 | 每日流量（图表数据源） |
| GET/POST | `/api/v1/invites`、DELETE `/api/v1/invites/{id}` | 运维+ | 邀请码 |

角色：`super-admin`（全部，含设置与管理员）、`operator`（管用户/配额/密码/邀请码）、`viewer`（只读）。越权 403，未认证 401，校验失败 422。

---

## 4. 面板（Filament v3，`/admin`）

| 页面 | 内容 |
|---|---|
| 总览 | 在线用户、用户总数（启用/禁用）、今日流量、需要处理（过期 + 超额）；7 天流量曲线；3 天内到期/用完的清单 |
| 用户管理 → 用户 | 列表：状态、启用开关、到期、已用/配额、在线设备数、最后在线；行操作：禁用（填原因）、启用、重置密码、加流量、续期、流量归零、踢下线、查看、编辑、删除；批量：启用/禁用/加流量/续期 |
| 用户管理 → 邀请码 | 生成/停用，复制带邀请码的注册链接 |
| 用户管理 → 连接记录 | 只读：`username + addr` 一条，可筛「只看在线」 |
| 系统 → 面板设置 | 注册开关/是否要邀请码/限流、新用户默认值、鉴权缓存 TTL、密码校验缓存开关、拒答审计开关、兼容上报开关、品牌；顶部还有「清空鉴权缓存」 |
| 系统 → 管理员与角色 | 仅 super-admin 可见 |
| 系统 → 审计日志 | 管理动作 + 鉴权拒绝（按 (原因, 身份) 10 分钟去重） |

---

## 5. 命令与调度

```bash
php artisan traffic:flush [--batch=500]     # 把 Redis 流量计数落库（每分钟，调度已配）
php artisan hyauth:prune [--days=30]        # 清理明细账/拒答审计/历史会话（每天 03:30）
php artisan users:import-legacy --dsn=mysql://user:pass@host:3306/hysteria [--dry-run]
```

`schedule:run`（crontab 或 `php artisan schedule:work`）必须常驻，否则流量只在进程内计数、不落库。

---

## 6. 关键环境变量

| 变量 | 默认 | 说明 |
|---|---|---|
| `APP_URL` | — | 对外地址（Cloudflare 后面的域名） |
| `DB_CONNECTION` / `DB_*` | `sqlite` | 生产建议 MySQL 8；SQLite 适合单机小规模 |
| `REDIS_*` / `REDIS_AUTH_DB` | `6379` / `3` | 鉴权缓存、在线集合、计数器、限流 |
| `REDIS_PREFIX` | 空 | 必须为空：服务自己拼 `hyauth:*` 前缀，双层前缀会让 DEL/EXISTS 对不上 |
| `TRUSTED_PROXIES` | `127.0.0.1,::1` | 反代/隧道回源时必须配，否则审计 IP 全是 127.0.0.1 |
| `AUTH_MODE` | `both` | 凭据形态：`uuid` / `password` / `both` |
| `SELF_UUID_RESET_ENABLED` / `SELF_UUID_RESET_PER_HOUR` | `true` / `5` | 自助重置 UUID 的开关与限流 |
| `AUTH_CACHE_TTL` | 60 | 用户快照 TTL（禁用/改配额会主动清，不靠它） |
| `AUTH_PASSWORD_CACHE` | false | 是否缓存密码校验结果（省 bcrypt CPU，注意风险） |
| `ONLINE_TTL` | 90 | 在线判定窗口（秒） |
| `AUTH_MAX_FAILURES_PER_MIN` / `AUTH_FAIL_BLOCK_SECONDS` | 20 / 60 | 失败限流与短期封禁 |
| `AUTH_RATE_LIMIT_PER_MIN` | 3000 | `/auth` 的粗保护上限（按 IP）；防爆破靠失败计数 |
| `AUTH_LOG_DENIALS` | true | 是否记拒答审计 |
| `TRAFFIC_REPORT_TOKEN` | 空 | 兼容上报面的共享密钥（空=关闭） |
| `REGISTRATION_ENABLED` / `REGISTRATION_REQUIRES_INVITE` | false / true | 自助注册开关（面板里也能改） |
| `REGISTRATION_MAX_PER_HOUR_PER_IP` / `REGISTRATION_MIN_PASSWORD` | 3 / 8 | 注册限流与密码长度 |
| `DEFAULT_QUOTA_BYTES` / `DEFAULT_VALID_DAYS` / `DEFAULT_MAX_DEVICES` | 0 / 30 / 3 | 新用户默认 |
| `ADMIN_EMAIL` / `ADMIN_PASSWORD` | — | 首次 seed 的管理员（装完可在面板改） |

---

## 7. 节点侧配置

```yaml
# /etc/hysteria/config.yaml（节点）
auth:
  type: http
  http:
    url: https://auth.example.com/auth     # 根路径，与旧 FastAPI 版一致
    insecure: false
```

客户端（用户侧）的 `auth` 填用户的那串 **UUID**（在 `/me` 页面或后台用户列表里复制），例如：

```yaml
# 客户端 hysteria 配置
server: auth.example.com:443
auth: 550e8400-e29b-41d4-a716-446655440000
```

切换后端时只需要改 nginx/隧道的上游（uvicorn → php），**节点配置一个字都不用动**。
`trafficStats` 与本服务无关：它是 Hysteria 自己的本地统计 API（`GET /traffic`），要不要开都行。
