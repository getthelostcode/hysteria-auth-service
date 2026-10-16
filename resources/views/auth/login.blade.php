@extends('layouts.app')

@section('title', '登录 · '.$brand)
@section('content')
    <div class="card">
        <h1>登录</h1>
        <p class="muted">用你连 Hysteria 的那个用户名和密码登录，可以看剩余流量与在线设备。</p>
        <form method="post" action="{{ route('login') }}">
            @csrf
            <label>用户名</label>
            <input name="username" value="{{ old('username') }}" autocomplete="username" required>
            <label>密码</label>
            <input type="password" name="password" autocomplete="current-password" required>
            <button type="submit">登录</button>
        </form>
    </div>
@endsection
