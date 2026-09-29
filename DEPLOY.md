# ============================================================
# Hysteria 认证 + 流量上报服务 — 部署文档
# 2号服务器
# ============================================================

## 零、确认事项

| 项目   | 说明                                      |
|--------|-------------------------------------------|
| 2号服务器IP | 运行 MySQL + Redis + FastAPI 服务       |
| 系统   | Linux（Ubuntu/Debian 或 CentOS/RHEL）     |
| 账户   | 具备 sudo 权限                            |
| 端口   | 3306 (MySQL), 6379 (Redis), 8000 (FastAPI) |

---

## 一、安装 MySQL

### 1.1 Ubuntu/Debian

```bash
sudo apt update
sudo apt install -y mysql-server mysql-client
sudo systemctl enable mysql
sudo systemctl start mysql
```

### 1.2 CentOS/RHEL

```bash
sudo yum install -y mysql-server mysql
sudo systemctl enable mysqld
sudo systemctl start mysqld
```

### 1.3 安全配置（可选）

```bash
sudo mysql_secure_installation
```

---

## 二、MySQL 初始化

### 2.1 创建数据库和表

```bash
sudo mysql < /opt/hysteria-auth-service/sql/init.sql
```

或手动执行：

```bash
mysql -u root -p
```

```sql
CREATE DATABASE IF NOT EXISTS hysteria
    CHARACTER SET utf8mb4
    COLLATE utf8mb4_unicode_ci;

USE hysteria;

CREATE TABLE IF NOT EXISTS users (
    id              VARCHAR(64)      PRIMARY KEY,
    password_hash   VARCHAR(255)    NOT NULL,
    enabled         TINYINT(1)      DEFAULT 1,
    created_at      DATETIME        DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS traffic (
    id              BIGINT          AUTO_INCREMENT PRIMARY KEY,
    user_id         VARCHAR(64)     NOT NULL,
    tx_delta        BIGINT          NOT NULL DEFAULT 0,
    rx_delta        BIGINT          NOT NULL DEFAULT 0,
    reported_at     DATETIME        DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_user_time (user_id, reported_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

### 2.2 创建应用用户（推荐）

```sql
CREATE USER 'hysteria_user'@'127.0.0.1' IDENTIFIED BY 'hysteria_pass';
GRANT ALL PRIVILEGES ON hysteria.* TO 'hysteria_user'@'127.0.0.1';
FLUSH PRIVILEGES;
```

### 2.3 添加测试用户（可选）

```sql
-- 生成 bcrypt hash (密码: 123456)
python3 -c "import bcrypt; print(bcrypt.hashpw(b'123456', bcrypt.gensalt()).decode())"

-- 然后插入（替换为实际生成的 hash）
INSERT INTO users (id, password_hash, enabled) VALUES
('testuser', '$2b$12$...', 1);
```

---

## 三、安装 Redis

### 3.1 Ubuntu/Debian

```bash
sudo apt install -y redis-server
sudo systemctl enable redis-server
sudo systemctl start redis-server
```

### 3.2 CentOS/RHEL

```bash
sudo yum install -y redis
sudo systemctl enable redis
sudo systemctl start redis
```

### 3.3 验证

```bash
redis-cli ping   # 应返回 PONG
```

---

## 四、部署 FastAPI 服务

### 4.1 准备目录和文件

```bash
# 创建目录
sudo mkdir -p /opt/hysteria-auth-service/app
sudo mkdir -p /opt/hysteria-auth-service/sql

# 复制文件
sudo cp /root/hysteria-auth-service/sql/init.sql /opt/hysteria-auth-service/sql/
sudo cp /root/hysteria-auth-service/app/main.py /opt/hysteria-auth-service/app/
sudo cp /root/hysteria-auth-service/requirements.txt /opt/hysteria-auth-service/

# 设置权限
sudo chown -R www-data:www-data /opt/hysteria-auth-service
sudo chmod 600 /opt/hysteria-auth-service/sql/init.sql
```

### 4.2 Python 环境

```bash
# 选项一：系统 Python + pip
sudo apt install -y python3 python3-pip python3-venv
pip3 install -r /opt/hysteria-auth-service/requirements.txt

# 选项二：虚拟环境（推荐生产环境）
sudo apt install -y python3-venv
python3 -m venv /opt/hysteria-auth-service/venv
source /opt/hysteria-auth-service/venv/bin/activate
pip install -r /opt/hysteria-auth-service/requirements.txt
deactivate
```

> 若使用虚拟环境，systemd service 中的 `ExecStart` 需改为：
> `ExecStart=/opt/hysteria-auth-service/venv/bin/python -m uvicorn app.main:app ...`

### 4.3 systemd 服务

```bash
sudo cp /root/hysteria-auth-service/hysteria-auth.service /etc/systemd/system/
sudo systemctl daemon-reload
sudo systemctl enable hysteria-auth
sudo systemctl start hysteria-auth
```

### 4.4 验证

```bash
# 服务状态
sudo systemctl status hysteria-auth

# 健康检查
curl http://127.0.0.1:8000/health
# 预期: {"status": "ok"}

# Swagger 文档
curl http://127.0.0.1:8000/docs
```

---

## 五、防火墙

确保2号服务器防火墙允许：

- **本地回路**：3306, 6379, 8000（仅本机访问，无需暴露到外网）
- 如需从1号服务器访问，可限制来源IP：

```bash
# ufw 示例
sudo ufw allow from 1号服务器IP to any port 8000 proto tcp

# iptables 示例
sudo iptables -A INPUT -p tcp -s 1号服务器IP --dport 8000 -j ACCEPT
```

---

## 六、测试接口

### 6.1 POST /auth

```bash
curl -s -X POST http://127.0.0.1:8000/auth \
  -H "Content-Type: application/json" \
  -d '{"addr": "123.123.123.123:44556", "auth": "testuser:123456", "tx": 100000}' \
  | python3 -m json.tool

# 正确密码 → {"ok": true, "id": "testuser"}
# 错误密码 → {"ok": false, "id": ""}
```

### 6.2 POST /traffic_report

```bash
curl -s -X POST http://127.0.0.1:8000/traffic_report \
  -H "Content-Type: application/json" \
  -d '{"user_id": "testuser", "tx_delta": 10485760, "rx_delta": 0}' \
  | python3 -m json.tool

# 返回: {"status": "ok"}

# 检查 MySQL
mysql -u hysteria_user -p hysteria -e "SELECT * FROM traffic ORDER BY id DESC LIMIT 5;"
```

### 6.3 Redis 缓存验证

```bash
# 认证成功后，查看缓存
redis-cli GET "auth:testuser"
# 应返回 "1"（认证成功时）

# 60 秒后自动过期
```

---

## 七、配置说明

所有配置通过环境变量控制（systemd service 中已设置，亦可在 `/etc/systemd/system/hysteria-auth.service` 中修改）：

| 变量 | 默认值 | 说明 |
|------|--------|------|
| `DATABASE_URL` | `mysql+aiomysql://hysteria_user:hysteria_pass@127.0.0.1:3306/hysteria` | MySQL 连接串 |
| `REDIS_URL` | `redis://127.0.0.1:6379/0` | Redis 连接串 |
| `REDIS_CACHE_TTL` | `60` | 认证缓存 TTL（秒） |

修改后需重启服务：

```bash
sudo systemctl daemon-reload
sudo systemctl restart hysteria-auth
```

---

## 八、故障排查

| 现象 | 可能原因 | 检查方法 |
|------|----------|----------|
| 服务无法启动 | MySQL/Redis 未运行、连接串错误 | `journalctl -u hysteria-auth -n 50` |
| /auth 返回 400 | auth 格式错误 | 检查请求 JSON 中的 `auth` 字段 |
| /auth 始终返回 false | 密码 hash 不匹配、用户未启用 | 检查 `users` 表 `enabled` 字段和 `password_hash` |
| /traffic_report 返回 500 | MySQL 写入失败 | 检查 `traffic` 表权限和磁盘空间 |
| Redis 缓存不生效 | Redis 未运行或连接错误 | `redis-cli ping` |

---

## 九、项目结构

```
hysteria-auth-service/
├── sql/
│   └── init.sql                    # MySQL 建表脚本
├── app/
│   └── main.py                     # FastAPI 主程序
├── requirements.txt                # Python 依赖
├── hysteria-auth.service           # systemd 服务单元
└── DEPLOY.md                       # 本文档
```
