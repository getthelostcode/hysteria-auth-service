@extends('layouts.app')

@section('title', '注册 · '.$brand)
@section('content')
    <div class="card">
        <h1>注册账号</h1>
        <p class="muted">
            用户名就是你连接 Hysteria 时的账号（<code>用户名:密码</code>），创建后不可修改。
            @if ($defaultQuota > 0)
                默认配额 {{ round($defaultQuota / 1073741824, 2) }} GiB
                @if ($defaultDays > 0)
                    ，有效期 {{ $defaultDays }} 天
                @endif
                。
            @elseif ($defaultDays > 0)
                默认有效期 {{ $defaultDays }} 天，流量不限。
            @endif
        </p>

        @if ($invitePreview)
            @if ($invitePreview['valid'])
                <p class="badge ok">
                    邀请码有效
                    @if (! empty($invitePreview['quota_bytes']))
                        ：{{ round($invitePreview['quota_bytes'] / 1073741824, 2) }} GiB
                    @endif
                    @if (! empty($invitePreview['valid_days']))
                        ·{{ $invitePreview['valid_days'] }} 天
                    @endif
                </p>
            @else
                <p class="badge bad">邀请码无效或已用完</p>
            @endif
        @endif

        <form method="post" action="{{ route('register') }}">
            @csrf
            <label>用户名</label>
            <input name="username" value="{{ old('username') }}" required
                   pattern="[a-zA-Z0-9_][a-zA-Z0-9_.-]{2,31}" autocomplete="username">
            <label>邮箱（可选）</label>
            <input type="email" name="email" value="{{ old('email') }}">
            @if ($requiresInvite)
                <label>邀请码</label>
                <input name="invite_code" value="{{ old('invite_code', request('invite')) }}" required>
            @else
                <label>邀请码（可选）</label>
                <input name="invite_code" value="{{ old('invite_code', request('invite')) }}">
            @endif
            <label>密码（至少 {{ $minPassword }} 位）</label>
            <input type="password" name="password" required autocomplete="new-password">
            <label>确认密码</label>
            <input type="password" name="password_confirmation" required autocomplete="new-password">
            <button type="submit">注册</button>
        </form>
    </div>
@endsection
