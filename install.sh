#!/usr/bin/env bash
# ============================================================
# Hysteria2 认证服务（Laravel 11 + Filament v3）一键安装
#
#   sudo bash install.sh                        # 装到 /opt/hysteria-auth-service，SQLite，127.0.0.1:8000
#   sudo bash install.sh --db=mysql --db-host=127.0.0.1 --db-name=hyauth --db-user=x --db-pass=y
#   sudo bash install.sh --mode=fpm             # nginx + php-fpm（默认是 artisan serve 的 systemd 单元）
#   sudo bash install.sh --dir=/srv/hyauth --port=8000 --registration=on
#   sudo bash install.sh --upgrade              # 升级已有部署（composer、迁移、重启服务）
#   sudo bash install.sh --verify               # 只做健康检查/契约冒烟，不改任何东西
#   sudo bash install.sh --uninstall            # 停服务并卸载单元（数据保留）
# ============================================================
set -euo pipefail
# 排查卡点用：DEBUG=1 bash install.sh …（每执行一条命令都打印出来）
[[ "${DEBUG:-0}" == "1" ]] && set -x

# Composer 在 root 下会问「Continue as root/super user [yes]?」并**一直等输入**，
# 在终端里跑（stdin 是 TTY）看起来就是卡死（普通管道里反而会带警告继续，所以本机测不出来）。
# 这里统一关掉交互并允许 root：
export COMPOSER_NO_INTERACTION=1
if [[ "$(id -u)" -eq 0 ]]; then export COMPOSER_ALLOW_SUPERUSER=1; fi

APP_NAME="hysteria-auth-service"
SERVICE_USER="hysteria-auth"
SRC_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

APP_DIR="${APP_DIR:-/opt/hysteria-auth-service}"
PORT="${PORT:-8000}"
HOST="${HOST:-127.0.0.1}"
MODE="${MODE:-serve}"            # serve | fpm
DB_DRIVER="${DB_DRIVER:-sqlite}" # sqlite | mysql
DB_HOST="${DB_HOST:-127.0.0.1}"
DB_PORT="${DB_PORT:-3306}"
DB_NAME="${DB_NAME:-hyauth}"
DB_USER="${DB_USER:-hyauth}"
DB_PASS="${DB_PASS:-}"
REDIS_HOST="${REDIS_HOST:-127.0.0.1}"
REDIS_PORT="${REDIS_PORT:-6379}"
REDIS_AUTH_DB="${REDIS_AUTH_DB:-3}"
TRAFFIC_TOKEN="${TRAFFIC_REPORT_TOKEN:-}"
REGISTRATION="${REGISTRATION:-off}"
ADMIN_EMAIL="${ADMIN_EMAIL:-admin@example.com}"
ADMIN_PASSWORD="${ADMIN_PASSWORD:-}"
INSTALL_SERVICES=1
UPGRADE=0
VERIFY_ONLY=0
UNINSTALL=0
PHP_BIN="${PHP_BIN:-php}"
COMPOSER_BIN="${COMPOSER_BIN:-}"       # 可显式指定 composer 可执行文件/或 composer.phar 路径
COMPOSER_AUTO_INSTALL=1                # 没装 composer 时自动用官方 installer 装一份

log()  { printf '\033[1;34m[%s]\033[0m %s\n' "$(date +%H:%M:%S)" "$*"; }
# 有 timeout 就用它给外部命令加超时（精简系统可能没有 coreutils timeout，此时退化为直跑并提醒）
if command -v timeout >/dev/null 2>&1; then
    run_t() { local secs="$1"; shift; timeout "$secs" "$@"; }
else
    warn_no_timeout=1
    run_t() { local secs="$1"; shift; "$@"; }
fi
# 写 .env 的键值：存在就替换（.env 里同一个键出现两次时，phpdotenv 只认第一条！
# 直接 append 会让 .env.example 里的默认值赢，踩过这个坑）
env_set() {
    local key="$1" value="$2"
    if grep -qE "^${key}=" .env; then
        awk -v k="$key" -v v="$value" 'BEGIN{FS=OFS="="} $1==k{print k"="v; next} {print}' .env > .env.tmp && mv .env.tmp .env
    else
        printf '%s=%s\n' "$key" "$value" >> .env
    fi
}
env_del() {
    local key="$1"
    grep -vE "^${key}=" .env > .env.tmp || true
    mv .env.tmp .env
}
warn() { printf '\033[1;33m[warn]\033[0m %s\n' "$*"; }
die()  { printf '\033[1;31m[fail]\033[0m %s\n' "$*" >&2; exit 1; }

usage() {
    sed -n '2,17p' "$0" | sed 's/^# \{0,1\}//'
    cat <<'EOF'

可用参数：
  --dir=<路径>            安装目录（默认 /opt/hysteria-auth-service）
  --host=<地址> --port=<端口>  监听地址（默认 127.0.0.1:8000，前面挂 nginx/Cloudflare）
  --mode=serve|fpm        运行方式：artisan serve 的 systemd 单元（默认）或 nginx+php-fpm
  --db=sqlite|mysql       数据库（默认 sqlite；生产建议 mysql）
  --db-host= --db-port= --db-name= --db-user= --db-pass=
  --redis-host= --redis-port= --redis-db=    Redis（默认 127.0.0.1:6379，DB 3）
  --traffic-token=<串>    开启 /traffic_report 兼容面（留空=关闭）
  --registration=on|off   是否开放自助注册（默认 off）
  --admin-email= --admin-password=   初始化管理员（密码留空则随机并打印）
  --composer-bin=<路径>   指定 composer（可执行文件或 composer.phar）；没装 composer 时会自动下载官方 installer
  --no-composer-install   没装 composer 时不自动下载（只报错）
  --no-services           不安装 systemd 单元
  --upgrade               升级已有部署
  --verify                只验证（健康检查 + /auth 契约），不改文件
  --uninstall             停服务、删单元（保留数据目录）
EOF
}

while [[ $# -gt 0 ]]; do
    case "$1" in
        --dir=*) APP_DIR="${1#*=}" ;;
        --host=*) HOST="${1#*=}" ;;
        --port=*) PORT="${1#*=}" ;;
        --mode=*) MODE="${1#*=}" ;;
        --db=*) DB_DRIVER="${1#*=}" ;;
        --db-host=*) DB_HOST="${1#*=}" ;;
        --db-port=*) DB_PORT="${1#*=}" ;;
        --db-name=*) DB_NAME="${1#*=}" ;;
        --db-user=*) DB_USER="${1#*=}" ;;
        --db-pass=*) DB_PASS="${1#*=}" ;;
        --redis-host=*) REDIS_HOST="${1#*=}" ;;
        --redis-port=*) REDIS_PORT="${1#*=}" ;;
        --redis-db=*) REDIS_AUTH_DB="${1#*=}" ;;
        --traffic-token=*) TRAFFIC_TOKEN="${1#*=}" ;;
        --registration=*) REGISTRATION="${1#*=}" ;;
        --admin-email=*) ADMIN_EMAIL="${1#*=}" ;;
        --admin-password=*) ADMIN_PASSWORD="${1#*=}" ;;
        --composer-bin=*) COMPOSER_BIN="${1#*=}" ;;
        --no-composer-install) COMPOSER_AUTO_INSTALL=0 ;;
        --no-services) INSTALL_SERVICES=0 ;;
        --upgrade) UPGRADE=1 ;;
        --verify) VERIFY_ONLY=1 ;;
        --uninstall) UNINSTALL=1 ;;
        -h|--help) usage; exit 0 ;;
        *) die "未知参数：$1（--help 看用法）" ;;
    esac
    shift
done

BASE_URL="http://${HOST}:${PORT}"

verify() {
    log "健康检查 ${BASE_URL}/api/health"
    local health
    health="$(curl -s -m 10 "${BASE_URL}/api/health" || true)"
    [[ -n "$health" ]] || die "健康检查无响应：${health:-空}（服务没起来？看 journalctl -u ${APP_NAME}）"
    echo "      $health"
    echo "$health" | grep -q '"redis":"ok"' || warn "Redis 未就绪（鉴权会退化成每次查库）"

    log "鉴权契约冒烟 ${BASE_URL}/auth"
    local code body
    body="$(curl -s -m 10 -w '\n%{http_code}' -X POST "${BASE_URL}/auth" \
        -H 'Content-Type: application/json' \
        -d '{"addr":"127.0.0.1:1","auth":"__probe__:__probe__","tx":0}' || true)"
    code="$(echo "$body" | tail -n1)"
    body="$(echo "$body" | head -n-1)"
    [[ "$code" == "200" ]] || die "/auth 返回 $code（契约要求永远 200）"
    echo "$body" | grep -q '"ok"' || die "/auth 响应里没有 ok 字段：$body"
    echo "      $body"
    log "契约正常：状态码 200 + {\"ok\":false,\"id\":\"\"}（探针账号不存在，被拒是预期结果）"
}

uninstall() {
    log "停用并删除 systemd 单元"
    for unit in "${APP_NAME}.service" "${APP_NAME}-scheduler.service"; do
        systemctl disable --now "$unit" 2>/dev/null || true
        rm -f "/etc/systemd/system/$unit"
    done
    systemctl daemon-reload || true
    log "已卸载（数据与代码保留在 ${APP_DIR}）"
}

if [[ "$VERIFY_ONLY" -eq 1 ]]; then
    verify
    exit 0
fi

if [[ "$UNINSTALL" -eq 1 ]]; then
    uninstall
    exit 0
fi

# ---------------- 1. 依赖检查 ----------------
log "检查依赖（每个探测都有超时；下面每步都会先说“正在做什么”）"
[[ "${warn_no_timeout:-0}" == "1" ]] && warn "本机没有 timeout 命令，探测不会自动超时（建议装 coreutils）；如果你看到长时间停着，用 Ctrl-C 后加 DEBUG=1 重跑定位"

log "  → 找 php（$PHP_BIN）…"
command -v "$PHP_BIN" >/dev/null || die "找不到 php（或用 PHP_BIN=/usr/bin/php8.2 指定）"
log "  → 读 PHP 版本…"
PHP_VERSION="$(run_t 20 "$PHP_BIN" -r 'echo PHP_VERSION;' 2>/dev/null || true)"
[[ -n "$PHP_VERSION" ]] || die "php 执行无输出（可能是包装脚本卡住）：试 timeout 5 $PHP_BIN -v"
"$PHP_BIN" -r 'exit(version_compare(PHP_VERSION, "8.2.0", ">=") ? 0 : 1);' \
    || die "需要 PHP >= 8.2，当前 $PHP_VERSION"
log "PHP $PHP_VERSION ✓"

log "  → 检查 composer…"
COMPOSER_CMD=()
# 一律转成绝对路径：后面会 cd 到安装目录，相对路径会失效
abs_path() { local p="$1"; [[ -e "$p" ]] && realpath "$p" 2>/dev/null || echo "$p"; }
if [[ -n "$COMPOSER_BIN" ]]; then
    if [[ "$COMPOSER_BIN" == *.phar ]]; then COMPOSER_CMD=("$PHP_BIN" "$(abs_path "$COMPOSER_BIN")"); else COMPOSER_CMD=("$(abs_path "$COMPOSER_BIN")"); fi
elif command -v composer >/dev/null 2>&1; then
    COMPOSER_CMD=("$(command -v composer)")
elif [[ -f "$SRC_DIR/composer.phar" ]]; then
    COMPOSER_CMD=("$PHP_BIN" "$(abs_path "$SRC_DIR/composer.phar")")
elif [[ -f "$APP_DIR/composer.phar" ]]; then
    COMPOSER_CMD=("$PHP_BIN" "$(abs_path "$APP_DIR/composer.phar")")
fi

composer_probe() {   # 返回 composer -V 的输出（stdout+stderr），失败为空
    [[ ${#COMPOSER_CMD[@]} -eq 0 ]] && return 1
    COMPOSER_ALLOW_SUPERUSER=1 COMPOSER_NO_INTERACTION=1 run_t 20 "${COMPOSER_CMD[@]}" -V --no-interaction </dev/null 2>&1 || true
}

if [[ ${#COMPOSER_CMD[@]} -eq 0 ]] || [[ -z "$(composer_probe)" ]]; then
    # 情况一：没装；情况二：装了但跑不起来（坏 phar / 包装脚本卡住 / 被环境变量影响）
    if [[ ${#COMPOSER_CMD[@]} -ne 0 ]]; then
        warn "composer 在 $(command -v composer 2>/dev/null || echo "$COMPOSER_BIN")，但 20 秒内没有正常输出："
        ls -l "$(command -v composer 2>/dev/null || echo "$COMPOSER_BIN")" 2>/dev/null | sed 's/^/      /' || true
        head -1 "$(command -v composer 2>/dev/null || echo "$COMPOSER_BIN")" 2>/dev/null | sed 's/^/      首行: /' || true
        warn "换成 composer --version 手动跑一次看报错；或用 --composer-bin=/路径/composer.phar 指定一个干净的 phar"
    fi

    if [[ "$COMPOSER_AUTO_INSTALL" -eq 0 ]]; then
        die "没有可用的 composer。装法：apt install -y composer / dnf install -y composer，或用官方 installer 下载 composer.phar 后 --composer-bin=./composer.phar"
    fi

    log "  没有可用的 composer，尝试用官方 installer 装一份（sha384 校验）…"
    COMPOSER_DEST_DIR="$APP_DIR"
    if [[ "$(id -u)" -eq 0 ]]; then COMPOSER_DEST_DIR="/usr/local/bin"; fi
    mkdir -p "$COMPOSER_DEST_DIR" 2>/dev/null || true
    TMPC="$(mktemp -d)"
    if run_t 60 "$PHP_BIN" -r "copy('https://getcomposer.org/installer', '$TMPC/composer-setup.php');" </dev/null 2>/dev/null \
       && [[ -s "$TMPC/composer-setup.php" ]]; then
        EXPECTED="$(run_t 30 curl -fsSL https://composer.github.io/installer.sig 2>/dev/null || true)"
        ACTUAL="$("$PHP_BIN" -r "echo hash_file('sha384', '$TMPC/composer-setup.php');" 2>/dev/null || true)"
        if [[ -n "$EXPECTED" && "$EXPECTED" == "$ACTUAL" ]]; then
            if run_t 180 "$PHP_BIN" "$TMPC/composer-setup.php" --install-dir="$COMPOSER_DEST_DIR" --filename=composer.phar </dev/null >/dev/null 2>&1 \
               && [[ -s "$COMPOSER_DEST_DIR/composer.phar" ]]; then
                COMPOSER_CMD=("$PHP_BIN" "$COMPOSER_DEST_DIR/composer.phar")
                log "  已安装 composer.phar 到 $COMPOSER_DEST_DIR（调用方式：$PHP_BIN $COMPOSER_DEST_DIR/composer.phar）"
            else
                warn "composer installer 执行失败"
            fi
        else
            warn "composer installer 的 sha384 校验不通过（expected=${EXPECTED:-空} actual=${ACTUAL:-空}），放弃自动安装"
        fi
        rm -f "$TMPC/composer-setup.php"
    else
        warn "下不到 https://getcomposer.org/installer（网络受限？）"
    fi
    rmdir "$TMPC" 2>/dev/null || true

    [[ ${#COMPOSER_CMD[@]} -ne 0 ]] && [[ -n "$(composer_probe)" ]] \
        || die "装不上 composer。请手动装（apt/dnf install composer），或下载 composer.phar 后用 --composer-bin=/路径/composer.phar 重跑"
fi
log "composer $(COMPOSER_ALLOW_SUPERUSER=1 COMPOSER_NO_INTERACTION=1 "${COMPOSER_CMD[@]}" -V --no-interaction </dev/null 2>/dev/null | awk '{print $3}') ✓（$([[ "${COMPOSER_CMD[0]}" == "$PHP_BIN" ]] && echo "phar: ${COMPOSER_CMD[1]}" || echo "${COMPOSER_CMD[0]}")）"

REQUIRED_EXT=(pdo mbstring curl json openssl tokenizer xml ctype fileinfo redis)
if [[ "$DB_DRIVER" == "sqlite" ]]; then REQUIRED_EXT+=(pdo_sqlite); else REQUIRED_EXT+=(pdo_mysql); fi
# 一次取回模块列表再比对：`php -m | grep -q` 在 pipefail 下会因为 grep 提前退出产生
# SIGPIPE 假失败（明明装了扩展却报缺），而且每个扩展起一次 php 也慢。
log "  → 读 PHP 扩展列表（timeout 20s）…"
PHP_MODULES="$(run_t 20 "$PHP_BIN" -m 2>/dev/null || true)"
[[ -n "$PHP_MODULES" ]] || die "php -m 无输出（PHP 安装异常）"
MISSING=()
for ext in "${REQUIRED_EXT[@]}"; do
    grep -qiE "^${ext}$" <<<"$PHP_MODULES" || MISSING+=("$ext")
done
[[ ${#MISSING[@]} -eq 0 ]] || die "缺少 PHP 扩展：${MISSING[*]}
  Debian/Ubuntu: apt install -y php-cli php-curl php-mbstring php-xml php-intl php-zip php-redis php-sqlite3|php-mysql
  RHEL/Rocky:    dnf install -y php-cli php-curl php-mbstring php-xml php-intl php-zip php-pecl-redis5 php-pdo"
log "PHP 扩展齐备（${#REQUIRED_EXT[@]} 项）✓"

# Redis 连通性：只做提示，不阻断安装；但必须加超时 ——
# redis-cli 对「丢包/黑洞」的地址会挂到 TCP 超时（实测 >25s，看起来就像安装卡死）。
if command -v redis-cli >/dev/null 2>&1; then
    log "  → Redis 连通性（timeout 3s）…"
    if run_t 3 redis-cli -h "$REDIS_HOST" -p "$REDIS_PORT" ping >/dev/null 2>&1; then
        log "Redis 连通 $REDIS_HOST:$REDIS_PORT ✓"
    else
        warn "3 秒内连不上 Redis（$REDIS_HOST:$REDIS_PORT）—— 鉴权缓存/在线状态/流量计数/限流都依赖它；
      本机装的 Redis 就用默认值；若 Redis 在别处，请加 --redis-host= --redis-port=（别让地址走黑洞，会等到 TCP 超时）"
    fi
else
    warn "没装 redis-cli，跳过连通性检查（应用仍强依赖 Redis，请自行确认 ${REDIS_HOST}:${REDIS_PORT} 可用）"
fi

# ---------------- 2. 代码就位 ----------------
log "安装代码到 ${APP_DIR}"
if [[ "$(realpath "$SRC_DIR")" == "$(realpath "${APP_DIR}" 2>/dev/null || echo /nonexistent)" ]]; then
    log "源码目录就是目标目录，跳过复制"
else
    mkdir -p "$APP_DIR"
    # 只同步应用代码：不要带上 .env、vendor、数据库文件与运行期缓存，
    # 否则新装机会沿用上一台机器的库路径/端口/密钥（踩过：数据库还指着旧目录）。
    tar -C "$SRC_DIR" \
        --exclude=.git --exclude=vendor --exclude=node_modules \
        --exclude=.env --exclude='database/*.sqlite' --exclude='storage/logs/*' \
        --exclude='storage/framework/cache/data/*' --exclude='storage/framework/views/*' \
        --exclude='storage/framework/sessions/*' \
        -cf - . | tar -C "$APP_DIR" -xf -
fi

# 运行用户
if ! id "$SERVICE_USER" >/dev/null 2>&1; then
    useradd --system --home-dir "$APP_DIR" --shell /usr/sbin/nologin "$SERVICE_USER" || true
fi

cd "$APP_DIR"

# ---------------- 3. 依赖 ----------------
log "composer install（--no-dev，正在下载依赖；视网络 30 秒~3 分钟，不要中断）"
COMPOSER_ALLOW_SUPERUSER=1 COMPOSER_NO_INTERACTION=1 run_t 900 "${COMPOSER_CMD[@]}" install --no-dev --prefer-dist --no-interaction --optimize-autoloader </dev/null \
    || die "composer install 失败或超时（在 ${APP_DIR} 手动跑一次看报错）"

# ---------------- 4. .env ----------------
if [[ ! -f .env ]]; then
    log "生成 .env"
    [[ -n "$ADMIN_PASSWORD" ]] || ADMIN_PASSWORD="$(head -c 12 /dev/urandom | base64 | tr -d '/+=' | head -c 16)"
    [[ -n "$TRAFFIC_TOKEN" ]] || TRAFFIC_TOKEN="$(head -c 24 /dev/urandom | base64 | tr -d '/+=' | head -c 32)"

    cp .env.example .env

    env_set APP_URL "${BASE_URL}"
    env_set DB_CONNECTION "${DB_DRIVER}"
    env_set REDIS_HOST "${REDIS_HOST}"
    env_set REDIS_PORT "${REDIS_PORT}"
    env_set REDIS_AUTH_DB "${REDIS_AUTH_DB}"
    env_set ADMIN_EMAIL "${ADMIN_EMAIL}"
    env_set ADMIN_PASSWORD "${ADMIN_PASSWORD}"
    env_set TRAFFIC_REPORT_TOKEN "${TRAFFIC_TOKEN}"
    env_set REGISTRATION_ENABLED "$([[ "$REGISTRATION" == "on" ]] && echo true || echo false)"

    if [[ "$DB_DRIVER" == "sqlite" ]]; then
        env_set DB_DATABASE "${APP_DIR}/database/database.sqlite"
    else
        env_set DB_HOST "${DB_HOST}"
        env_set DB_PORT "${DB_PORT}"
        env_set DB_DATABASE "${DB_NAME}"
        env_set DB_USERNAME "${DB_USER}"
        env_set DB_PASSWORD "${DB_PASS}"
    fi

    "$PHP_BIN" artisan key:generate --force
    "$PHP_BIN" artisan filament:upgrade >/dev/null 2>&1 || warn "Filament 资源发布失败，可稍后手动跑 php artisan filament:upgrade"
else
    log ".env 已存在，沿用（升级不会覆盖你的配置；只同步监听地址与注册开关）"
    ADMIN_PASSWORD="$(grep -E '^ADMIN_PASSWORD=' .env | cut -d= -f2- || true)"
    env_set APP_URL "${BASE_URL}"
    env_set REGISTRATION_ENABLED "$([[ "$REGISTRATION" == "on" ]] && echo true || echo false)"
fi

[[ "$DB_DRIVER" == "sqlite" ]] && touch database/database.sqlite

# ---------------- 5. 迁移与种子 ----------------
log "迁移 + 初始化管理员"
run_t 120 "$PHP_BIN" artisan migrate --force || die "migrate 失败/超时（数据库连不上？检查 .env 里的 DB_*）"
run_t 120 "$PHP_BIN" artisan db:seed --force \
    || warn "db:seed 失败：管理员可能已存在（可忽略），或者 Redis 连不上（设置项要走 Redis 缓存）——确认后手动跑 php artisan db:seed"

chown -R "$SERVICE_USER":"$SERVICE_USER" "$APP_DIR" 2>/dev/null || true
chmod -R ug+rwX "$APP_DIR/storage" "$APP_DIR/bootstrap/cache" 2>/dev/null || true
chmod 600 "$APP_DIR/.env"

# ---------------- 6. systemd ----------------
if [[ "$INSTALL_SERVICES" -eq 1 ]] && command -v systemctl >/dev/null 2>&1; then
    log "安装 systemd 单元（${MODE} 模式）"
    if [[ "$MODE" == "fpm" ]]; then
        install -m0644 deploy/nginx/hysteria-auth.conf /etc/nginx/sites-available/hysteria-auth
        ln -sf /etc/nginx/sites-available/hysteria-auth /etc/nginx/sites-enabled/hysteria-auth
        nginx -t && systemctl reload nginx || warn "nginx 配置校验/重载失败，请手动检查"
        systemctl enable --now php8.2-fpm 2>/dev/null || warn "请确认 php-fpm 已安装并启动"
    else
        sed -e "s#^WorkingDirectory=.*#WorkingDirectory=${APP_DIR}#" \
            -e "s#^ExecStart=.*#ExecStart=${PHP_BIN} artisan serve --host=${HOST} --port=${PORT}#" \
            -e "s#^User=.*#User=${SERVICE_USER}#" \
            -e "s#^Group=.*#Group=${SERVICE_USER}#" \
            deploy/systemd/"${APP_NAME}.service" > /etc/systemd/system/"${APP_NAME}.service"
    fi

    sed -e "s#^WorkingDirectory=.*#WorkingDirectory=${APP_DIR}#" \
        -e "s#^User=.*#User=${SERVICE_USER}#" \
        -e "s#^Group=.*#Group=${SERVICE_USER}#" \
        deploy/systemd/"${APP_NAME}-scheduler.service" > /etc/systemd/system/"${APP_NAME}-scheduler.service"

    systemctl daemon-reload
    systemctl enable --now "${APP_NAME}-scheduler.service" || warn "scheduler 启动失败，请手动检查"
    if [[ "$MODE" == "serve" ]]; then
        systemctl enable --now "${APP_NAME}.service" || warn "服务启动失败，请手动检查 journalctl -u ${APP_NAME}"
    fi
    sleep 2
fi

# ---------------- 7. 验证 ----------------
if curl -s -m 5 "${BASE_URL}/api/health" >/dev/null 2>&1; then
    verify
else
    warn "服务还没起来（可能没装 systemd 单元），用 --verify 稍后再试"
fi

cat <<EOF

──────────── 安装完成 ────────────
代码：      ${APP_DIR}
监听：      ${HOST}:${PORT}（前面挂 nginx/Cloudflare，节点填 https://<域名>/auth）
后台：      http://${HOST}:${PORT}/admin      账号 ${ADMIN_EMAIL} / ${ADMIN_PASSWORD}
自助注册：  http://${HOST}:${PORT}/register  （当前 ${REGISTRATION}）
节点配置：
  auth:
    type: http
    http:
      url: https://<你的域名>/auth
数据库：    ${DB_DRIVER}${DB_DRIVER:+ }$([[ "$DB_DRIVER" == "mysql" ]] && echo "${DB_NAME}@${DB_HOST}:${DB_PORT}")
定时任务：  systemctl status ${APP_NAME}-scheduler  （每分钟 traffic:flush）
排障：      journalctl -u ${APP_NAME} -n 50 --no-pager
EOF
