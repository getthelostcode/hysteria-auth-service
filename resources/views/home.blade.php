@extends('layouts.app')

@section('title', $brand)
@section('content')
    <div class="card">
        <h1>Hysteria2 认证服务</h1>
        <p class="muted">这个服务是 Hysteria 服务端 <code>auth.type: http</code> 的后端：客户端连接时它来回答「能不能连、你是谁」，并顺手记账流量。</p>
        <div class="row" style="margin-top:16px">
            <div class="stat">当前在线用户<b>{{ $onlineUsers }}</b></div>
            <div class="stat">注册用户总数<b>{{ $totalUsers }}</b></div>
        </div>
        <p style="margin-top:18px">
            @if ($registrationEnabled)
                <a href="{{ route('register') }}">注册账号</a> ·
            @endif
            <a href="{{ route('login') }}">登录查看用量</a>
        </p>
    </div>

    <div class="card">
        <h2>服务端配置（在节点的 /etc/hysteria/config.yaml 里）</h2>
<pre>auth:
  type: http
  http:
    url: {{ url('/auth') }}
    insecure: false</pre>
        <h2 style="margin-top:18px">接口契约</h2>
<pre>POST /auth
{ "addr": "1.2.3.4:5678", "auth": "用户名:密码", "tx": 0 }
→ 200 { "ok": true, "id": "用户名" }     # 拒绝也是 200：{ "ok": false, "id": "" }</pre>
        <p class="muted">管理后台：<a href="/admin">/admin</a>　健康检查：<a href="/api/health">/api/health</a></p>
    </div>
@endsection
