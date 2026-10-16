# hysteria-auth-service

Hysteria2 的 **HTTP 鉴权后端**：客户端连不连得上，由它一句话说了算；顺带记账流量、管用户、管到期与配额。
Laravel 11.57 + Filament 3.3 实现，带**用户自助注册**与**管理后台（可禁用用户）**。

```
客户端 ──QUIC──▶ 节点 hysteria-server ──POST /auth ──▶ Cloudflare/隧道 ──▶ nginx ──▶ php-fpm
                      auth.type: http                     │                              │
                      url: https://auth.ipx.sarl/auth      │                    ┌─────────┼──────────┐
                                                           │                    ▼         ▼          ▼
                                                      永远 200 +             Redis      MySQL 8    Filament /admin
                                                  {"ok":true,"id":"alice"}   (缓存/在线/  /SQLite   （管理员登录）
                                                                             计数/限流） 用户/流量/审计
```

| 能力 | 一句话 |
|---|---|
| Hysteria 鉴权 | `POST /auth`，**永远 HTTP 200** + `{"ok":bool,"id":string}`；拒绝原因（禁用/过期/超额/超设备数…）写审计，`reason` 字段只给运维看 |
| 用户注册 | `/register` 自助注册：可开可关、可选强制邀请码、按 IP 限流、默认配额/有效期/设备数可配 |
| 管理后台 | `/admin`：用户列表（状态/到期/用量/在线设备）+ **启用/禁用**、重置密码、加流量、续期、流量归零、踢下线；邀请码、连接记录、审计日志、仪表盘 |
| 禁用秒级生效 | 禁用后**主动清 Redis 快照**并移出在线集合 → 下一次 `/auth` 立刻拒绝，不用等 60 秒 TTL |
| 流量统计 | Hysteria 上报的是**连接累计值**，按会话差分记增量 → Redis 计数 → 每分钟批量落库（`users` / `traffic_daily` / `traffic_reports`） |
| 配额与到期 | 配额判定把**尚未落库**的 Redis 计数一起算（不会因为“还没 flush”而超用）；到期自动拒绝 |
| 设备数限制 | 按客户端 IP 去重统计在线设备，超限拒绝新 IP（同一 IP 重连不算新设备） |
| 防爆破 | 失败计数（按 IP/用户名）+ 短期封禁，全在 Redis，不打库 |
| 兼容旧接口 | 根路径 `/auth`、`/health`、`/traffic_report` 与旧 FastAPI 版同形，切后端不用改节点配置 |
| 旧数据迁移 | 旧库密码是 bcrypt，`users:import-legacy` 直接搬运，用户无需重置密码 |
| 部署 | 一个 `install.sh`：依赖检查 → composer → .env → 迁移+建管理员 → systemd 单元 → 现场契约冒烟 |

> 接口契约：[docs/API.md](docs/API.md)｜架构 / Redis Key 表 / 时序：[docs/DESIGN.md](docs/DESIGN.md)

## 目录

- [1. 快速开始](#1-快速开始)
- [2. 它每天在做什么](#2-它每天在做什么)
- [3. 鉴权契约](#3-鉴权契约)
- [4. 用户注册](#4-用户注册)
- [5. 管理后台](#5-管理后台)
- [6. 流量与配额](#6-流量与配额)
- [7. 禁用用户与踢下线](#7-禁用用户与踢下线)
- [8. 部署与运维](#8-部署与运维)
- [9. 配置参考](#9-配置参考)
- [10. 排错](#10-排错)
- [11. 测试](#11-测试)
- [12. 目录结构](#12-目录结构)

## 1. 快速开始

### 1.1 环境依赖（装之前先看）

**必备**

| 组件 | 版本 | 为什么需要 |
|---|---|---|
| PHP CLI | **≥ 8.2**（实测 8.2.33） | 框架要求；迁移、调度、队列都靠它 |
| Composer | 2.x | 安装 PHP 依赖（需要能访问 packagist；离线环境可以直接拷 `vendor/`） |
| **Redis** | ≥ 6（实测 7.0.15） | **硬依赖**：鉴权缓存、在线集合、流量计数、失败限流全在 Redis 上，没有它 `/auth` 会退化成每次查库且无法计数 |
| 数据库 | **SQLite 3** 或 **MySQL 8** | 二选一；生产建议 MySQL 8（SQLite 是库级锁，适合单机小规模） |
| 基础工具 | `git`、`curl`、`unzip`（或 `zip`）、`ca-certificates` | 拉代码、健康检查、Composer 解压 dist 包、HTTPS 证书 |

**PHP 扩展**（前 12 项是 `composer check-platform-reqs` 给出的框架要求，后 3 项是本服务自己加的）

| 扩展 | 用途 |
|---|---|
| `ctype` `filter` `hash` `iconv` `json` `pcre`(内置) `session` `tokenizer` `phar` | Laravel 11 / Composer 的基础能力 |
| `dom` `libxml` `xml` `xmlreader` `xmlwriter` | Symfony / Filament 的 XML 处理 |
| `mbstring` | 多字节（中文）字符串处理 |
| `openssl` | `APP_KEY` 加解密、出站 HTTPS |
| `fileinfo` | 文件类型识别 |
| `intl` | Filament 的本地化与数字/日期格式（**实测是硬要求**，缺了面板会报错） |
| `zip` | Composer 解压 `.zip` 分发包 |
| `curl` | 健康检查与出站请求 |
| `pdo` + `pdo_sqlite`（SQLite）**或** `pdo_mysql`（MySQL） | 数据库驱动 |
| **`redis`（phpredis 扩展）** | 鉴权热路径的缓存/计数/限流 |

**Debian / Ubuntu 一键装**

```bash
sudo apt update
sudo apt install -y git unzip curl ca-certificates redis-server composer \
    php-cli php-curl php-mbstring php-xml php-intl php-zip php-redis \
    php-sqlite3          # SQLite 模式用这个
    # php-mysql          # MySQL 模式改用它（两个都装也行）
```

> Debian 12 自带 PHP 8.2、Ubuntu 24.04 自带 PHP 8.3，都满足 ≥ 8.2；扩展包名不带版本号会跟着发行版的默认 PHP 走。

**RHEL / Rocky / Alma（以 Remi 源为例）**

```bash
sudo dnf install -y epel-release https://rpms.remirepo.net/enterprise/remi-release-9.rpm
sudo dnf module reset php -y && sudo dnf module enable php:remi-8.2 -y
sudo dnf install -y php php-cli php-curl php-mbstring php-xml php-intl php-zip php-pecl-redis5 php-pdo
sudo dnf install -y git unzip curl redis
# MySQL 模式再加：php-mysqlnd      SQLite 模式再加：php-sqlite3
```

**可选依赖**

| 组件 | 什么时候需要 |
|---|---|
| nginx + php-fpm | 用 `install.sh --mode=fpm` 部署时（**默认的 serve 模式不需要**，直接用 PHP 内置服务器 + systemd） |
| MySQL 8 服务端 | 想用 `--db=mysql` 时（SQLite 也能跑完整功能） |
| Node.js 18+ / npm | **只有**要自定义 Filament 主题（改 CSS/JS）才需要编译前端；默认主题不需要 npm |
| Cloudflare Tunnel / nginx 反代 | 把 `https://auth.<你的域名>/auth` 指到本机 `127.0.0.1:8000`（节点侧走 HTTPS 访问） |

**装完自检**

```bash
php -v
php -m | grep -xE 'pdo|pdo_sqlite|pdo_mysql|redis|intl|zip|curl|mbstring'   # 缺哪个这里就没有
composer check-platform-reqs        # 逐条列出 PHP 版本 + 扩展是否满足（权威清单）
redis-cli ping                      # 期望 PONG
bash install.sh --verify            # 健康检查 + /auth 契约冒烟
```

`install.sh` 会检查同一份清单，缺什么直接报 `缺少 PHP 扩展：xxx` 并退出，不会装到一半才炸。

**实测版本组合**（本仓库就是在这一套上开发并跑测试的）：PHP 8.2.33 · Laravel 11.57.0 · Filament 3.3.57 · Livewire 3.8.10 · Sanctum 4.3.3 · Redis 7.0.15（phpredis） · SQLite 3 / MySQL 8.0.43。

### 1.2 一键安装（推荐）

```bash
git clone https://github.com/getthelostcode/hysteria-auth-service.git
cd hysteria-auth-service
sudo bash install.sh                                    # SQLite + 127.0.0.1:8000 + systemd（serve 模式）
sudo bash install.sh --db=mysql --db-name=hyauth --db-user=hyauth --db-pass=*** \
     --registration=on --admin-email=me@example.com     # 生产：MySQL + 开放注册
sudo bash install.sh --verify                           # 只做健康检查 + /auth 契约冒烟
sudo bash install.sh --upgrade                          # 升级已有部署（composer + 迁移 + 重启）
sudo bash install.sh --uninstall                        # 停服务、删单元（数据保留）
```

装完会打印后台地址与管理员密码，并当场验证：

```
[健康检查] {"status":"ok","database":"ok","redis":"ok","online_users":0}
[契约冒烟] {"ok":false,"id":"","reason":"unknown_user"}      # 探针账号不存在，被拒是预期结果
```

常用参数：`--dir=`（默认 `/opt/hysteria-auth-service`）、`--host/--port`、`--mode=serve|fpm`、`--db=sqlite|mysql`、`--db-*`、`--redis-host/--redis-port/--redis-db`、`--registration=on|off`、`--traffic-token=`、`--admin-email/--admin-password`、`--no-services`。`--help` 看全量。

### 1.3 本机先跑起来（不装服务）

```bash
composer install
cp .env.example .env && php artisan key:generate
touch database/database.sqlite && php artisan migrate --seed
php artisan serve --host=127.0.0.1 --port=8000
```

* 后台 `http://127.0.0.1:8000/admin`（本机默认 `admin@example.com` / `password`；`local` 环境还会建 operator/viewer 与演示用户 `demo/demo12345`）；
* 自助面 `/`、`/register`、`/login`、`/me`；接口契约 `/auth`、`/api/health`；
* Redis 是**必需**的（缓存/在线集合/计数/限流都在上面）；`redis-cli ping` 有 PONG 就行。

### 1.4 节点怎么接

```yaml
# /etc/hysteria/config.yaml（节点）
auth:
  type: http
  http:
    url: https://auth.example.com/auth      # 根路径，与旧 FastAPI 版一致
    insecure: false
```

节点侧**不需要改配置**就能从旧版切过来：把 nginx/隧道的上游从 uvicorn 指到 php-fpm（或本服务），路径一模一样。

## 2. 它每天在做什么

| 周期 | 位置 | 说明 |
|---|---|---|
| 每次连接/断开 | `/auth` | 判定放行与否；断开那次带着该连接的累计 `tx`，据此记账 |
| 每 15 秒（每用户） | `/auth` 门控 | 更新 `last_online_at`/`last_ip`（不每请求写库） |
| 每 90 秒 | `ONLINE_TTL` | 在线窗口：超过 90 秒没上报就不算在线 |
| 每 60 秒 | `traffic:flush`（调度） | Redis 计数 → `users.used_bytes` / `traffic_daily` / `traffic_reports` |
| 每天 03:30 | `hyauth:prune`（调度） | 清理明细账（30 天）、拒答审计（90 天）、历史会话（7 天） |
| 实时 | 面板 | 仪表盘 20 秒轮询；启用/禁用/改配额**立刻**影响 `/auth` |

```
客户端连接            Laravel /auth                        Redis           MySQL
   │  POST /auth {addr,auth,tx:0}   │                      │                │
   ├───────────────────────────────▶│ 解析「用户名:密码」    │                │
   │                                │ 限流/封禁检查 ────────▶│                │
   │                                │ 用户快照 ──命中────────▶│                │
   │                                │      └─未命中→查库 ────────────────────▶│
   │                                │ bcrypt（未命中快照才做）│                │
   │                                │ 判 启用/到期/配额/设备数│                │
   │                                │ 记在线 +（断开时）记流量▶│                │
   │ ◀── 200 {"ok":true,"id":"alice"}│                      │                │
   ▼                                ▼                      ▼                ▼
 放行                 被拒也 200：{"ok":false,"id":"","reason":"..."}
```

## 3. 鉴权契约

请求（Hysteria 官方契约，字段照抄不误）：

```json
{ "addr": "123.123.123.123:44556", "auth": "alice:secret", "tx": 123456 }
```

响应（**必须 200**，官方明确要求；`ok:false` 就是拒绝）：

```json
{ "ok": true,  "id": "alice" }
{ "ok": false, "id": "", "reason": "quota_exceeded" }
```

判定顺序与拒绝原因：

| # | 条件 | `reason` | 备注 |
|---|---|---|---|
| 1 | `auth` 格式不对（没有 `:`） | `malformed` | 密码里可以含 `:`，按第一个切分 |
| 2 | 命中失败限流/短期封禁 | `rate_limited` / `quota_exceeded` | 防爆破；配额刚用完会短期封禁避免刷库 |
| 3 | 用户不存在 | `unknown_user` | |
| 4 | 密码错误 | `bad_password` | |
| 5 | 已禁用 | `disabled` | **秒级生效**，见 [§7](#7-禁用用户与踢下线) |
| 6 | 已过期 | `expired` | |
| 7 | 流量用完 | `quota_exceeded` | 把未落库的 Redis 计数也算进去 |
| 8 | 在线设备数超限（新 IP） | `too_many_devices` | 按 IP 去重 |

`id` 必须稳定且唯一：本服务用 **用户名**（`users.username`，创建后不可改，面板里是禁用编辑的）。

## 4. 用户注册

| 开关 | 默认 | 作用 |
|---|---|---|
| 开放自助注册 | 关（`REGISTRATION_ENABLED`） | 关掉时 `/register` 直接 404 |
| 注册必须携带邀请码 | 开 | 邀请码可带固定配额/天数，用完即失效 |
| 同 IP 每小时最多注册 | 3 | Redis 计数限流 |
| 密码最小长度 | 8 | |
| 新用户默认配额 / 有效期 / 设备数 | 0（不限）/ 30 天 / 3 | 邀请码可覆盖配额与天数 |

用户注册后可以在 `/me` 看：已用/配额与百分比、到期时间、**当前在线的设备 IP**、最近 14 天流量，并自行改密码（改完新旧密码判定缓存同时失效）。

邀请码在后台「用户管理 → 邀请码」生成，可以一键复制带参数的注册链接：`/register?invite=inv_xxx`（页面会先校验并显示它能给到的配额/天数）。

## 5. 管理后台

`/admin`，Filament v3 面板，登录用的是 **`admin_users` 表**（和 Hysteria 用户表彻底分开：自助注册的账号永远拿不到面板权限）。

| 分组 | 页面 | 能做什么 |
|---|---|---|
| — | 总览 | 在线用户 / 用户总数（启用·禁用）/ 今日流量 / 需要处理（过期+超额）；7 天流量曲线；3 天内到期或流量用完的清单 |
| 用户管理 | 用户 | 列表：状态、启用开关、到期、已用/配额、在线设备、最后在线；行操作：**禁用**（填原因）、启用、重置密码、加流量、续期、流量归零、踢下线、查看/编辑/删除；批量：启用/禁用/加流量/续期 |
| 用户管理 | 邀请码 | 生成、停用、复制注册链接 |
| 用户管理 | 连接记录 | 只读：`用户名 + 客户端地址`，可筛「只看在线」 |
| 系统 | 面板设置 | 注册开关/邀请码/限流、新用户默认值、鉴权缓存 TTL、密码校验缓存、拒答审计、兼容上报开关、品牌；另有一键「清空鉴权缓存」 |
| 系统 | 管理员与角色 | 仅 super-admin |
| 系统 | 审计日志 | 管理动作 + 鉴权拒绝（同一身份同一原因 10 分钟只记一条） |

**权限**：`super-admin` 全部；`operator` 管用户/配额/密码/邀请码；`viewer` 只读（看不到设置与管理员页）。
同一个能力也在管理 API 上生效（Sanctum Bearer → `/api/v1/*`，见 [docs/API.md](docs/API.md)），方便脚本化：批量禁用、加流量、导流量报表都能用 curl 干。

> 用户详情页还会显示**尚未落库的用量**（Redis 计数）与**当前在线 IP**，以及最近 30 天日汇总和最近 20 条原始上报。

## 6. 流量与配额

Hysteria 在**断开连接时**把该连接的累计 `tx`（有些版本还带 `rx`）发回 `/auth`。如果直接累加，同一连接的多次上报会把流量算重复。

所以本服务按**会话差分**记账：

```
sess:{username}:{addr} 存「上次看到的值」
delta = 本次值 - 上次值        （累计值 => 只记增量）
若本次值 < 上次值（连接重启/计数器归零）=> 直接按本次值记（不产生负数）
```

记账链路：

```
/auth ─▶ INCRBY hyauth:used:tx|rx:{u}   （外加 SADD dirty、RPUSH reports）
      ─▶ 配额判定 = users.used_bytes + 未落库计数     ← 这一步保证配额不会被“还没 flush”绕过
每分钟 traffic:flush ─▶ users.used_bytes/tx_bytes/rx_bytes
                     ─▶ traffic_daily（当日 upsert，图表用）
                     ─▶ traffic_reports（原始增量账，审计/重算用）
```

另外提供兼容面 `POST /traffic_report`（`X-Auth-Token` + 面板开关）给外部采集器按增量喂数据。

## 7. 禁用用户与踢下线

**禁用为什么是秒级的**：热路径读的是 Redis 用户快照（默认 60 秒 TTL），如果只改库，最多要等 60 秒才生效。所以任何状态变更都会**主动失效**：

```
UserManager::disable()
  ├─ UPDATE users SET enabled=0, disabled_at, disabled_reason
  ├─ INSERT audit_logs(user.disabled)
  ├─ DEL hyauth:user:{u} / hyauth:pwd:{u}:* / hyauth:block:{u}
  ├─ ZREM hyauth:online {u}（清在线集合与设备集合）
  └─ RPUSH hyauth:kick:{u}（待踢）
⇒ 下一次 /auth：未命中缓存 → 查库 enabled=0 → {"ok":false,"id":"","reason":"disabled"}
```

同一套失效逻辑也用在：启用、改配额/到期/设备上限、重置密码、用量归零、软删除。

**踢下线**只是把「该用户/该连接需要断开」写进 Redis 待踢队列：本服务可能跑在**另一台机器**上，拿不到节点上 `127.0.0.1:8080` 的 hysteria 本地 API secret。真正的断开由节点侧执行（hysteria 本地 `POST /kick`，通常经控制面/agent 触发）。禁用用户时不需要踢——他下次连就进不来；已经在线的连接要踢，配一下节点侧消费队列即可。

## 8. 部署与运维

### 8.1 两种运行方式

（依赖清单见 [§1.1 环境依赖](#11-环境依赖装之前先看)：PHP ≥ 8.2 + 扩展、Composer、**Redis**、SQLite 或 MySQL。）

| 方式 | 命令 | 适用 |
|---|---|---|
| `--mode=serve`（默认） | systemd 单元跑 `php artisan serve --host=127.0.0.1 --port=8000` | 单机、和旧 uvicorn 一样的部署形态；前面挂 Cloudflare/nginx |
| `--mode=fpm` | nginx 站点（`deploy/nginx/hysteria-auth.conf`）+ php-fpm | 更生产一些；同样监听 `127.0.0.1:8000` |

两种方式都会装一个 **`hysteria-auth-scheduler.service`**（`php artisan schedule:work`）—— **流量落库靠它**，没有它 `/auth` 计数会一直留在 Redis 里。

```bash
systemctl status hysteria-auth
journalctl -u hysteria-auth -n 50 --no-pager
systemctl status hysteria-auth-scheduler
php artisan traffic:flush          # 手动落一次账，看输出是否符合预期
```

### 8.2 命令

```bash
php artisan traffic:flush [--batch=500]     # Redis 计数 → 数据库（每分钟，调度已配）
php artisan hyauth:prune [--days=30]        # 清理明细账 / 拒答审计 / 历史会话（每天 03:30）
php artisan users:import-legacy --dsn=mysql://user:pass@127.0.0.1:3306/hysteria [--dry-run]
                                            # 从旧 FastAPI 版导入：用户名/启用状态/bcrypt 密码 + 旧流量汇总
```

`users:import-legacy` 说明：旧库 `users.password_hash` 就是 bcrypt，Laravel 的 `Hash::check` 直接认，所以**密码原样搬运，用户不用改密码**；旧 `traffic` 表按用户汇总进 `tx_bytes`/`rx_bytes`/`used_bytes`（明细不搬）。先加 `--dry-run` 看一眼。

### 8.3 反代与真实 IP

* 节点 → Cloudflare/隧道 → nginx → 本服务：`.env` 里 `TRUSTED_PROXIES=127.0.0.1,::1`（install.sh 已写默认值）；
* `/auth` 请求非常频繁，nginx 配置里对这个路径关掉了 access_log；
* 如果客户端 IP 全是 `127.0.0.1`，先看 `TRUSTED_PROXIES`，再看隧道有没有传 `X-Forwarded-For`。

### 8.4 升级与回滚

```bash
cd /opt/hysteria-auth-service && git pull        # 或重新同步代码
sudo bash install.sh --upgrade                   # composer + 迁移 + 重启服务（不动 .env 里的配置）
sudo bash install.sh --verify                    # 当场验健康检查与 /auth 契约
```

`--upgrade` 只同步 `APP_URL` 与注册开关，其它 `.env` 值保持原样（不会覆盖你的数据库口令/令牌）。

## 9. 配置参考

### 9.1 环境变量（`.env`）

| 变量 | 默认 | 说明 |
|---|---|---|
| `APP_URL` | — | 对外地址（面板首页与自检用） |
| `DB_CONNECTION` / `DB_*` | `sqlite` | 生产建议 MySQL 8；SQLite 适合单机小规模（库级锁、无并发写） |
| `REDIS_HOST/PORT` / `REDIS_AUTH_DB` | `127.0.0.1:6379` / `3` | 鉴权缓存、在线集合、计数、限流 |
| `REDIS_PREFIX` | 空 | **必须为空**：代码自己拼 `hyauth:*`，双层前缀会让 DEL/EXISTS 对不上（踩过） |
| `TRUSTED_PROXIES` | `127.0.0.1,::1` | 反代/隧道回源 |
| `AUTH_CACHE_TTL` | 60 | 用户快照 TTL（状态变更会主动失效，不靠它） |
| `AUTH_PASSWORD_CACHE` | false | 缓存密码校验结果，省 bcrypt CPU（注意风险） |
| `ONLINE_TTL` | 90 | 在线窗口（秒） |
| `AUTH_MAX_FAILURES_PER_MIN` / `AUTH_FAIL_BLOCK_SECONDS` | 20 / 60 | 失败限流与短期封禁 |
| `AUTH_RATE_LIMIT_PER_MIN` | 3000 | `/auth` 粗保护（按 IP）；防爆破另有失败计数 |
| `AUTH_LOG_DENIALS` | true | 拒答入审计（10 分钟同身份去重） |
| `TRAFFIC_REPORT_TOKEN` | 空 | 兼容上报面密钥（空=关闭） |
| `REGISTRATION_ENABLED` / `REGISTRATION_REQUIRES_INVITE` | false / true | 注册开关 |
| `REGISTRATION_MAX_PER_HOUR_PER_IP` / `REGISTRATION_MIN_PASSWORD` | 3 / 8 | |
| `DEFAULT_QUOTA_BYTES` / `DEFAULT_VALID_DAYS` / `DEFAULT_MAX_DEVICES` | 0 / 30 / 3 | 新用户默认 |
| `ADMIN_EMAIL` / `ADMIN_PASSWORD` | — | 首次 `db:seed` 的管理员 |

### 9.2 面板设置（存 `settings` 表，改完立即生效）

注册开关/邀请码要求/注册限流/密码最小长度、新用户默认配额·有效期·设备数、鉴权缓存 TTL、密码校验缓存开关、拒答审计开关、兼容上报开关、品牌名与页脚。

## 10. 排错

| 现象 | 定位 | 处理 |
|---|---|---|
| Hysteria 连不上，日志里 `authentication failed` | 手动打一次契约：`curl -s -X POST .../auth -H 'Content-Type: application/json' -d '{"addr":"1.2.3.4:1","auth":"user:pass","tx":0}'` | 看 `ok`/`reason`，对照 [§3](#3-鉴权契约) 的原因表 |
| `/auth` 返回**非 200**（例如 500/419） | 契约要求 200；419 说明路由落到了 web 组（CSRF） | 用推荐的根路径或 `/api/auth`；本仓库的这两条路由都在 api 中间件组内 |
| 禁用了用户但还能连 | 看下一次 `/auth` 的 `reason` 是不是 `disabled`；确认改的是同一个用户名 | 面板禁用会自动清缓存；如果是**直接改数据库**（绕过面板/API），要等 `AUTH_CACHE_TTL` 或手动清缓存 |
| 流量一直是 0 | `php artisan traffic:flush` 有没有在跑（`systemctl status hysteria-auth-scheduler`） | 调度没跑 → `/auth` 只加 Redis 计数不落库；`schedule:run` 必须常驻 |
| 面板用量与节点不一致 | 面板显示的是 `users.used_bytes`（已落库）；用户详情页额外给出「未落库」 | 这属于正常现象，10 秒~1 分钟内对齐 |
| 客户端 IP 全是 `127.0.0.1` | `TRUSTED_PROXIES` 没配或隧道没传 `X-Forwarded-For` | 见 [§8.3](#83-反代与真实-ip) |
| 设备数限制误伤（同一 WiFi 多人） | 设备数按 **IP** 去重：NAT 后面多个客户端共享一个 IP | 把 `max_devices` 调大或设 0（不限） |
| 突然大量 `rate_limited` | 有人在内网爆破，或某客户端密码错但狂重连 | 看审计 `auth.bad_password` / `auth.rate_limited`；必要时临时调 `AUTH_MAX_FAILURES_PER_MIN` |
| `redis-cli` 能看到键但 `DEL`/`EXISTS` 说没有 | 双层 Redis 前缀：`REDIS_PREFIX` 不为空 + 代码里已有 `hyauth:` | 把 `REDIS_PREFIX=` 置空（本仓库默认就是空），见 [docs/DESIGN.md §4](docs/DESIGN.md) |
| 面板页 500：`syntax error, unexpected token` | Blade 里 `@if` 紧跟在字母后面（如 `GiB@if(...)`）不会被编译 | 指令前后留空格/换行 |
| 注册页 404 | 注册被关（`REGISTRATION_ENABLED=false` 或面板里关了） | 面板设置里打开，或 `install.sh --registration=on` |
| 注册报「用户名已被占用」 | 用户名即 Hysteria id，含软删除记录也会占用 | 换名字，或在面板里恢复/彻底删掉旧用户 |
| 老用户登录不了 | 密码不是 bcrypt（例如旧版用了别的哈希），或没导入 | 用 `users:import-legacy`，或面板重置密码 |
| Redis 挂了会怎样 | `/health` 里 `redis:"error"`；鉴权会退化成每次查库并**拒绝写在线/计数** | `systemctl status redis-server`；Redis 是本服务的硬依赖（缓存+计数+限流） |

## 11. 测试

```bash
php artisan test                 # 40 tests / 174 assertions（SQLite 内存库 + Redis DB 15）
./vendor/bin/phpunit --testdox   # 看得更清楚
php artisan test --testsuite=Unit
```

覆盖的关键行为（都是这次实测通过的）：

* **契约**：正确密码放行并回 `id`；错密码/不存在/格式错/禁用/过期/超额/超设备数**一律 200** 且 `id` 为空；
* **秒级生效**：先成功一次把快照写进 Redis，再禁用 → 下一次立刻 `disabled`；重新启用立刻放行；改配额同理；
* **配额**：把未落库的 Redis 计数算进判定；连续上报累计值只记最终增量（1000+5000 而不是 100+300+1000+5000）；
* **设备数**：同一 IP 重连不算新设备，换 IP 超限就拒；
* **限流**：连续 20 次错密码后，即使密码对了也被拒；
* **审计**：同一身份的同一拒绝 10 分钟只记一条；开关能关掉；
* **注册**：关闭时 404；邀请码必需且只消耗一次；用户名/密码规则；同 IP 限流；注册后能立刻通过 `/auth`；改密码后旧密码失效；
* **管理 API**：登录/鉴权、401/403 语义、禁用即时生效、重置密码返回一次明文、viewer 不能写、stats 数字正确、兼容上报面需令牌；
* **面板**：8 个页面以 super-admin 身份渲染、列表显示状态、**Hysteria 用户访问面板被弹回登录页**、被禁用管理员 403、viewer 打不开设置页、设置页保存、从面板建用户后能通过 `/auth`；
* **落库**：`traffic:flush` 把计数写进 `users`/`traffic_daily`，可重复执行，未知用户不炸，计数器归零被正确处理。

> 测试需要本机 Redis（用 DB 15 + `hyauth-test:` 前缀隔离，每个用例前清空）。

## 12. 目录结构

```
app/
├── Console/Commands/      traffic:flush、hyauth:prune、users:import-legacy
├── Filament/
│   ├── Pages/ManageSettings.php          面板设置（注册/配额/缓存/审计/品牌）
│   ├── Resources/                        UserResource、InviteCodeResource、
│   │                                     UserSessionResource、AuditLogResource、AdminUserResource
│   └── Widgets/                          StatsOverview、TrafficTrendChart、ExpiringSoonTable
├── Http/
│   ├── Controllers/Hysteria/             AuthController（契约核心）、TrafficReport、Kick、Health
│   ├── Controllers/Web/                  首页、注册、登录、/me（自助面）
│   ├── Controllers/Api/Admin/            管理 API（Sanctum）
│   ├── Middleware/ForceJsonResponse.php
│   └── Resources/Admin/UserResource.php  API 输出形状
├── Models/                 User、AdminUser、UserSession、TrafficReport、TrafficDaily、
│                           InviteCode、Setting、AuditLog
├── Services/               HysteriaAuthService（判定链）、UserStateCache（快照/密码缓存）、
│                           TrafficService（会话差分+flush）、OnlineTracker、FailureTracker、
│                           UserManager（禁用/配额/密码，自带缓存失效）、RegistrationService、
│                           SettingStore、AuditLogger、AuthCache（Redis 入口）、AuthResult
└── Providers/              AppServiceProvider（Gates+限流）、Filament/AdminPanelProvider（admin guard）

bootstrap/app.php           路由装配 + 根路径别名 + 可信代理 + 强制 JSON
config/hyauth.php           鉴权参数（缓存 TTL/在线窗口/限流/注册默认值）
database/migrations/        用户、管理员、会话、流量、邀请码、设置、审计
database/seeders/           管理员 + 设置初始化（local 环境另有演示数据）
deploy/                     systemd 单元（服务 + 调度）、nginx 站点
docs/                       API.md（接口）、DESIGN.md（架构/Redis Key/时序）
tests/                      40 个用例（契约/注册/管理/面板/落库）
install.sh                  一键安装/升级/验证/卸载
```

## 13. 与旧版的关系

本仓库之前是 FastAPI + SQLAlchemy + aiomysql 的实现（`/auth` 只查密码、流量走单独的 `/traffic_report`）。现在按需求重写为 **Laravel 11.57 + Filament v3**：

* 旧代码完整保留在 git 历史里，打了一个 tag：**`legacy-fastapi`**（`git show legacy-fastapi` / `git checkout legacy-fastapi`）；
* 接口保持同形（`/auth`、`/traffic_report`、`/health`），切后端只需把 nginx 上游从 uvicorn 改到 php；
* 数据用 `users:import-legacy` 迁移（bcrypt 密码直接复用，不重置）。
