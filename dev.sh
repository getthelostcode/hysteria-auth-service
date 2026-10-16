#!/usr/bin/env bash
# ============================================================
# 本机开发/试用：一条命令把项目跑起来（不用装 systemd）
#   刚 clone / 下载 ZIP 后直接 `php artisan serve` 会报 vendor/autoload.php 不存在，
#   因为 vendor/ 和 .env 都不在仓库里。这个脚本负责补上：
#     composer install → .env → APP_KEY → SQLite → 迁移 + 种子 → 前台 serve
#   用法：bash dev.sh          （PORT=8013 bash dev.sh 换端口）
# ============================================================
set -euo pipefail
cd "$(dirname "${BASH_SOURCE[0]}")"

PORT="${PORT:-8000}"
HOST="${HOST:-127.0.0.1}"

log() { printf '\033[1;34m[dev]\033[0m %s\n' "$*"; }

# root 下 Composer 会问 “Continue as root/super user [yes]?” 并一直等输入
export COMPOSER_NO_INTERACTION=1
[[ "$(id -u)" -eq 0 ]] && export COMPOSER_ALLOW_SUPERUSER=1

command -v php >/dev/null || { echo "需要 PHP >= 8.2（cli）" >&2; exit 1; }
php -r 'exit(version_compare(PHP_VERSION, "8.2.0", ">=") ? 0 : 1);' || { echo "需要 PHP >= 8.2，当前 $(php -r 'echo PHP_VERSION;')" >&2; exit 1; }

if [[ ! -d vendor ]]; then
    command -v composer >/dev/null || { echo "需要 composer：apt install -y composer" >&2; exit 1; }
    log "vendor/ 不存在（仓库不带依赖），先 composer install…"
    composer install --no-dev --prefer-dist --no-interaction </dev/null
fi

if [[ ! -f .env ]]; then
    log "生成 .env"
    cp .env.example .env
    php artisan key:generate --force >/dev/null
fi

# .env 里 DB_DATABASE 的默认值指向 /opt 的正式安装目录，本机试用要改成本目录
if grep -qE '^DB_CONNECTION=sqlite' .env; then
    mkdir -p database && touch database/database.sqlite
    if grep -qE '^DB_DATABASE=' .env; then
        awk -v v="$(pwd)/database/database.sqlite" 'BEGIN{FS=OFS="="} $1=="DB_DATABASE"{print "DB_DATABASE="v; next} {print}' .env > .env.tmp && mv .env.tmp .env
    else
        echo "DB_DATABASE=$(pwd)/database/database.sqlite" >> .env
    fi
fi

log "迁移 + 造数据（幂等）"
php artisan migrate --force >/dev/null
php artisan db:seed --force >/dev/null || true

ADMIN_EMAIL="$(grep -E '^ADMIN_EMAIL=' .env | cut -d= -f2- || true)"
[[ -n "$ADMIN_EMAIL" ]] || ADMIN_EMAIL='admin@example.com'

cat <<EOF

──────────── 本机试用 ────────────
后台：      http://${HOST}:${PORT}/admin        （${ADMIN_EMAIL} / $(grep -E '^ADMIN_PASSWORD=' .env | cut -d= -f2- || echo password)）
自助面：    http://${HOST}:${PORT}/register
接口：      POST http://${HOST}:${PORT}/auth     GET /health
节点配置示例：
  auth:
    type: http
    http:
      url: http://${HOST}:${PORT}/auth
提示：Redis 是必须的（缓存/在线/计数/限流）。Ctrl-C 停止。
EOF

exec php artisan serve --host="$HOST" --port="$PORT"
