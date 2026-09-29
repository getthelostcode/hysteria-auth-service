# ============================================================
# Hysteria 认证 + 流量上报服务 — FastAPI 应用
# ============================================================

import os
import re
from contextlib import asynccontextend
from typing import Optional

import bcrypt
import redis.asyncio as redis
from fastapi import FastAPI, HTTPException
from pydantic import BaseModel, Field
from sqlalchemy import Column, BigInteger, DateTime, Integer, String, Text, create_engine, text
from sqlalchemy.ext.asyncio import AsyncSession, async_sessionmaker, create_async_engine
from sqlalchemy.orm import declarative_base

# ============================================================
# 配置
# ============================================================
DATABASE_URL = os.getenv(
    "DATABASE_URL",
    "mysql+aiomysql://hysteria_user:hysteria_pass@127.0.0.1:3306/hysteria"
)
REDIS_URL = os.getenv("REDIS_URL", "redis://127.0.0.1:6379/0")
REDIS_CACHE_TTL = int(os.getenv("REDIS_CACHE_TTL", "60"))  # 秒

# ============================================================
# SQLAlchemy 模型
# ============================================================
Base = declarative_base()


class User(Base):
    __tablename__ = "users"

    id = Column(String(64), primary_key=True)
    password_hash = Column(String(255), nullable=False)
    enabled = Column(Integer, default=1)
    created_at = Column(DateTime, default=text("CURRENT_TIMESTAMP"))


class TrafficRecord(Base):
    __tablename__ = "traffic"

    id = Column(BigInteger, primary_key=True, autoincrement=True)
    user_id = Column(String(64), nullable=False)
    tx_delta = Column(BigInteger, nullable=False, default=0)
    rx_delta = Column(BigInteger, nullable=False, default=0)
    reported_at = Column(DateTime, default=text("CURRENT_TIMESTAMP"))


# ============================================================
# Pydantic Schema
# ============================================================
class AuthRequest(BaseModel):
    addr: str
    auth: str            # 格式: "userid:password"
    tx: int


class AuthResponse(BaseModel):
    ok: bool
    id: str


class TrafficReportRequest(BaseModel):
    user_id: str
    tx_delta: int = Field(ge=0)
    rx_delta: int = Field(ge=0)


class TrafficReportResponse(BaseModel):
    status: str = "ok"


# ============================================================
# 数据库引擎 + 会话工厂
# ============================================================
engine = create_async_engine(DATABASE_URL, echo=False, pool_pre_ping=True)
async_session_factory = async_sessionmaker(engine, class_=AsyncSession, expire_on_commit=False)


# ============================================================
# Redis 客户端
# ============================================================
redis_client: redis.Redis = None


# ============================================================
# 生命周期管理
# ============================================================
@asynccontextend
async def lifespan(app: FastAPI):
    global redis_client
    # 初始化 Redis
    redis_client = redis.from_url(REDIS_URL, decode_responses=True)
    await redis_client.ping()
    print(f"[OK] Redis 连接成功: {REDIS_URL}")

    # 测试数据库连接
    async with async_session_factory() as session:
        await session.execute(text("SELECT 1"))
    print(f"[OK] MySQL 连接成功: {DATABASE_URL}")

    yield

    await redis_client.close()


app = FastAPI(
    title="Hysteria Auth & Traffic Service",
    lifespan=lifespan,
)


# ============================================================
# 辅助函数
# ============================================================
def parse_auth(auth_str: str) -> tuple[str, str]:
    """解析 'userid:password' 格式"""
    parts = auth_str.split(":", 1)
    if len(parts) != 2:
        raise HTTPException(status_code=400, detail="auth 格式必须为 userid:password")
    return parts[0], parts[1]


async def check_user_password(user_id: str, password: str) -> bool:
    """
    校验用户密码：
    1. 查 Redis 缓存
    2. 未命中则查 MySQL + bcrypt 比对
    3. 写缓存
    """
    cache_key = f"auth:{user_id}"

    # 1. 查缓存
    if redis_client:
        try:
            cached = await redis_client.get(cache_key)
            if cached is not None:
                return cached == "1"
        except Exception as e:
            print(f"[WARN] Redis 缓存查询失败: {e}")

    # 2. 查数据库
    async with async_session_factory() as session:
        user = await session.get(User, user_id)
        if user is None:
            result = False
        elif user.enabled == 0:
            result = False
        else:
            try:
                result = bcrypt.checkpw(password.encode(), user.password_hash.encode())
            except Exception:
                result = False

        # 3. 写缓存
        if redis_client:
            try:
                await redis_client.set(cache_key, "1" if result else "0", ex=REDIS_CACHE_TTL)
            except Exception as e:
                print(f"[WARN] Redis 缓存写入失败: {e}")

    return result


# ============================================================
# 接口：POST /auth
# ============================================================
@app.post("/auth", response_model=AuthResponse)
async def auth_endpoint(req: AuthRequest):
    """
    Hysteria 客户端认证接口。
    接收 JSON: {"addr": "...", "auth": "userid:password", "tx": 数值}
    返回: {"ok": true/false, "id": "userid"}
    """
    try:
        user_id, password = parse_auth(req.auth)
    except HTTPException:
        raise
    except Exception as e:
        raise HTTPException(status_code=400, detail=f"解析 auth 失败: {e}")

    ok = await check_user_password(user_id, password)

    return AuthResponse(ok=ok, id=user_id if ok else "")


# ============================================================
# 接口：POST /traffic_report
# ============================================================
@app.post("/traffic_report", response_model=TrafficReportResponse)
async def traffic_report_endpoint(req: TrafficReportRequest):
    """
    流量上报接口。
    接收 JSON: {"user_id": "...", "tx_delta": ..., "rx_delta": ...}
    写入 MySQL traffic 表，返回 {"status": "ok"}
    """
    async with async_session_factory() as session:
        record = TrafficRecord(
            user_id=req.user_id,
            tx_delta=req.tx_delta,
            rx_delta=req.rx_delta,
        )
        session.add(record)
        await session.commit()

    return TrafficReportResponse(status="ok")


# ============================================================
# 健康检查
# ============================================================
@app.get("/health")
async def health():
    return {"status": "ok"}


# ============================================================
# main
# ============================================================
if __name__ == "__main__":
    import uvicorn
    uvicorn.run(app, host="0.0.0.0", port=8000)
