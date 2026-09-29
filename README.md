# Hysteria 认证 + 流量上报服务

2号服务器上运行的 FastAPI 服务，提供两个 HTTP 接口：

- **POST /auth** — 供 Hysteria2 server 调用，验证用户身份
- **POST /traffic_report** — 供1号服务器的流量采集脚本调用，写入流量记录

技术栈：FastAPI + SQLAlchemy (Async) + aiomysql + Redis (缓存) + bcrypt

## 目录结构

```
hysteria-auth-service/
├── sql/
│   └── init.sql                    # MySQL 建表脚本
├── app/
│   └── main.py                     # FastAPI 主程序
├── requirements.txt                # Python 依赖
├── hysteria-auth.service           # systemd 服务单元
├── .gitignore
└── README.md
```

## 快速开始

### 一键部署（Ubuntu/Debian）

```bash
# 1. 安装 MySQL + Redis
sudo apt update
sudo apt install -y mysql-server redis-server
sudo systemctl enable --now mysql redis-server

# 2. 初始化数据库
sudo mysql < sql/init.sql

# 3. 创建应用用户（可选，但推荐）
mysql -u root -p -e "
  CREATE USER 'hysteria_user'@'127.0.0.1' IDENTIFIED BY 'hysteria_pass';
  GRANT ALL ON hysteria.* TO 'hysteria_user'@'127.0.0.1';
  FLUSH PRIVILEGES;
"

# 4. 添加测试用户（密码: 123456）
mysql -u root -p hysteria -e "
  INSERT INTO users (id, password_hash, enabled) VALUES
  ('testuser', '\$2b\$12\$OWUJ0kN5.gArV2uZOHfR3uB.I7Z7.0JxO4C /dUHc4QevplvhWzL6', 1);
"
# 注：上方 hash 是 bcrypt 示例，实际使用请自行生成

# 5. 安装 Python 依赖
sudo apt install -y python3 python3-pip python3-venv
pip3 install -r requirements.txt

# 6. 部署系统服务
sudo cp hysteria-auth.service /etc/systemd/system/
sudo systemctl daemon-reload
sudo systemctl enable --now hysteria-auth

# 7. 验证
curl http://127.0.0.1:8000/health
# → {"status": "ok"}
```

### 测试接口

```bash
# 认证测试（密码正确）
curl -s -X POST http://127.0.0.1:8000/auth \
  -H "Content-Type: application/json" \
  -d '{"addr": "1.2.3.4:44556", "auth": "testuser:123456", "tx": 100000}'
# → {"ok": true, "id": "testuser"}

# 认证测试（密码错误）
curl -s -X POST http://127.0.0.1:8000/auth \
  -H "Content-Type: application/json" \
  -d '{"addr": "1.2.3.4:44556", "auth": "testuser:wrongpass", "tx": 100000}'
# → {"ok": false, "id": ""}

# 流量上报测试
curl -s -X POST http://127.0.0.1:8000/traffic_report \
  -H "Content-Type: application/json" \
  -d '{"user_id": "testuser", "tx_delta": 10485760, "rx_delta": 0}'
# → {"status": "ok"}

# 检查 MySQL 记录
mysql -u hysteria_user -p hysteria -e "SELECT * FROM traffic ORDER BY id DESC LIMIT 3;"
```

## 配置

通过环境变量修改（systemd service 中已设置默认值）：

| 变量 | 默认值 | 说明 |
|------|--------|------|
| `DATABASE_URL` | `mysql+aiomysql://hysteria_user:hysteria_pass@127.0.0.1:3306/hysteria` | MySQL 连接 |
| `REDIS_URL` | `redis://127.0.0.1:6379/0` | Redis 连接 |
| `REDIS_CACHE_TTL` | `60` | 认证缓存 TTL（秒） |

## Redis 缓存策略

- Key 格式：`auth:{user_id}`
- Value：`1`（认证成功）或 `0`（失败）
- TTL：60 秒（`REDIS_CACHE_TTL` 环境变量控制）
- 优先查缓存，未命中则查 MySQL + bcrypt 比对，结果写缓存

## 许可

根据你的项目需求自行指定。
