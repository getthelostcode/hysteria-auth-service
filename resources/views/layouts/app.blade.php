<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title', $brand ?? 'Hysteria 认证服务')</title>
    <style>
        :root { color-scheme: dark; }
        * { box-sizing: border-box; }
        body { margin: 0; background: #0b1020; color: #e6e9f2; font: 15px/1.6 -apple-system, "Segoe UI", Roboto, "Helvetica Neue", "PingFang SC", "Microsoft YaHei", sans-serif; }
        a { color: #7cc4ff; text-decoration: none; }
        a:hover { text-decoration: underline; }
        .wrap { max-width: 880px; margin: 0 auto; padding: 24px 20px 60px; }
        header { display: flex; align-items: center; justify-content: space-between; gap: 16px; padding: 16px 20px; border-bottom: 1px solid #1d2740; background: #0e1428; }
        header .brand { font-weight: 700; letter-spacing: .3px; }
        nav a { margin-left: 14px; font-size: 14px; }
        .card { background: #111a30; border: 1px solid #1d2740; border-radius: 12px; padding: 20px; margin: 18px 0; }
        h1 { font-size: 22px; margin: 0 0 8px; }
        h2 { font-size: 16px; margin: 0 0 12px; color: #b8c2d9; font-weight: 600; }
        .muted { color: #8b96ad; font-size: 13px; }
        .row { display: flex; flex-wrap: wrap; gap: 18px; }
        .stat { flex: 1 1 160px; background: #0e1428; border: 1px solid #1d2740; border-radius: 10px; padding: 14px; }
        .stat b { display: block; font-size: 22px; margin-top: 4px; }
        label { display: block; margin: 12px 0 4px; font-size: 13px; color: #b8c2d9; }
        input, select { width: 100%; padding: 10px 12px; border-radius: 8px; border: 1px solid #26314f; background: #0b1020; color: #e6e9f2; font-size: 14px; }
        button { margin-top: 16px; padding: 10px 18px; border: 0; border-radius: 8px; background: #2b6cff; color: #fff; font-size: 14px; cursor: pointer; }
        button:hover { background: #3d7cff; }
        button.ghost { background: #22304f; }
        .badge { display: inline-block; padding: 2px 8px; border-radius: 999px; font-size: 12px; background: #22304f; }
        .badge.ok { background: #14432b; color: #7fe0a5; }
        .badge.warn { background: #4a3a12; color: #ffd479; }
        .badge.bad { background: #4a1717; color: #ff9b9b; }
        .flash { background: #123a26; border: 1px solid #1f6b45; padding: 10px 14px; border-radius: 8px; margin: 14px 0; }
        .errors { background: #3a1414; border: 1px solid #7a2a2a; padding: 10px 14px; border-radius: 8px; margin: 14px 0; }
        .errors ul { margin: 6px 0 0 18px; padding: 0; }
        table { width: 100%; border-collapse: collapse; font-size: 13px; }
        th, td { text-align: left; padding: 8px 10px; border-bottom: 1px solid #1d2740; }
        th { color: #8b96ad; font-weight: 600; }
        pre { background: #0b1020; border: 1px solid #1d2740; border-radius: 8px; padding: 12px; overflow: auto; font-size: 12px; }
        .bar { height: 8px; border-radius: 999px; background: #22304f; overflow: hidden; }
        .bar > span { display: block; height: 100%; background: #2b6cff; }
        footer { text-align: center; color: #66718a; font-size: 12px; padding: 24px 0; }
    </style>
</head>
<body>
<header>
    <div class="brand">{{ $brand ?? 'Hysteria 认证服务' }}</div>
    <nav>
        <a href="{{ route('home') }}">首页</a>
        @auth('web')
            <a href="{{ route('me') }}">我的</a>
            <form action="{{ route('logout') }}" method="post" style="display:inline">@csrf<button class="ghost" style="margin:0;padding:4px 10px;font-size:13px">退出</button></form>
        @else
            <a href="{{ route('login') }}">登录</a>
            @if ($registrationEnabled ?? false)
                <a href="{{ route('register') }}">注册</a>
            @endif
        @endauth
    </nav>
</header>

<div class="wrap">
    @if (session('status'))
        <div class="flash">{{ session('status') }}</div>
    @endif

    @if ($errors->any())
        <div class="errors">
            提交失败：
            <ul>
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    @yield('content')
</div>

<footer>{{ $brand ?? '' }} · Hysteria2 HTTP 鉴权后端 · 管理后台 <a href="/admin">/admin</a></footer>
</body>
</html>
