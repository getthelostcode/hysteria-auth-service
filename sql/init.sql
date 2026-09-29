-- ============================================================
-- Hysteria 认证服务 — MySQL 初始化脚本
-- 数据库: hysteria
-- ============================================================

CREATE DATABASE IF NOT EXISTS hysteria
    CHARACTER SET utf8mb4
    COLLATE utf8mb4_unicode_ci;

USE hysteria;

-- 用户账户表（供 /auth 接口认证使用）
CREATE TABLE IF NOT EXISTS users (
    id              VARCHAR(64)      PRIMARY KEY COMMENT '用户标识（即 Hysteria 中的 id）',
    password_hash   VARCHAR(255)    NOT NULL COMMENT 'bcrypt 哈希值',
    enabled         TINYINT(1)      DEFAULT 1 COMMENT '是否启用',
    created_at      DATETIME        DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 流量记录表（供 /traffic_report 接口写入）
CREATE TABLE IF NOT EXISTS traffic (
    id              BIGINT          AUTO_INCREMENT PRIMARY KEY,
    user_id         VARCHAR(64)     NOT NULL COMMENT '用户标识',
    tx_delta        BIGINT          NOT NULL DEFAULT 0 COMMENT '上传字节增量',
    rx_delta        BIGINT          NOT NULL DEFAULT 0 COMMENT '下载字节增量',
    reported_at     DATETIME        DEFAULT CURRENT_TIMESTAMP COMMENT '上报时间',
    INDEX idx_user_time (user_id, reported_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 示例：添加一个测试用户（bcrypt 密码: "123456"）
-- 生成方式: python -c "import bcrypt; print(bcrypt.hashpw(b'123456', bcrypt.gensalt()).decode())"
-- ============================================================
-- INSERT INTO users (id, password_hash, enabled) VALUES
-- ('testuser', '$2b$12$eImiTXWSuper.long.hash.here', 1);
