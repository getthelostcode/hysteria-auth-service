@extends('layouts.app')

@section('title', '我的 · '.$brand)
@section('content')
    @php($mode = app(\App\Services\SettingStore::class)->string('auth_mode'))

    <div class="card">
        <h1>{{ $user->username }} <span class="badge {{ $user->isUsable() ? 'ok' : 'bad' }}">{{ $user->statusLabel() }}</span></h1>

        <div class="row" style="margin-top:16px">
            <div class="stat">
                已用流量
                <b>{{ round($user->used_bytes / 1073741824, 2) }} GiB</b>
                <span class="muted">@if ($user->quota_bytes > 0) 配额 {{ round($user->quota_bytes / 1073741824, 2) }} GiB @else 不限量 @endif</span>
            </div>
            <div class="stat">
                到期时间
                <b>{{ $user->expires_at?->toDateString() ?? '不限' }}</b>
                <span class="muted">{{ $user->expires_at ? ($user->expires_at->isPast() ? '已过期' : '剩余 '.now()->diffInDays($user->expires_at).' 天') : '' }}</span>
            </div>
            <div class="stat">
                在线设备
                <b>{{ count($devices) }} @if ($maxDevices > 0)/ {{ $maxDevices }}@endif</b>
                <span class="muted">按 IP 去重</span>
            </div>
        </div>

        @if ($user->quota_bytes > 0)
            <div style="margin-top:16px">
                <div class="bar"><span style="width: {{ min(100, $user->quotaPercent() ?? 0) }}%"></span></div>
                <p class="muted">已用 {{ $user->quotaPercent() ?? 0 }}%</p>
            </div>
        @endif

        @if (! empty($devices))
            <h2 style="margin-top:22px">正在线的设备</h2>
            <table>
                <tr><th>IP</th><th>最后活跃</th></tr>
                @foreach ($devices as $ip => $ts)
                    <tr><td>{{ $ip }}</td><td>{{ \Illuminate\Support\Carbon::createFromTimestamp($ts)->diffForHumans() }}</td></tr>
                @endforeach
            </table>
        @endif
    </div>

    <div class="card">
        <h2>我的凭据（客户端 auth 填这个）</h2>

        @if ($mode === 'password')
            <p>当前是「用户名密码」模式，客户端 <code>auth</code> 填：<code>{{ $user->username }}:你的密码</code></p>
            <p class="muted">管理员把凭据形态改成 UUID 后，这里会显示可以随时重置的那串 UUID。</p>
        @else
            <p>客户端 <code>auth</code> 填：</p>
            <p><code style="user-select:all; word-break:break-all">{{ $user->uuid }}</code></p>
            <table style="margin-top:10px">
                <tr><td class="muted">最近重置</td><td>{{ $user->uuid_rotated_at?->toDateTimeString() ?? '—' }}</td></tr>
            </table>

            @if (session('uuid_rotated'))
                <p class="badge ok" style="margin-top:10px">新 UUID 已生效：<code>{{ session('uuid_rotated') }}</code></p>
            @endif

            @if ($selfUuidResetEnabled)
                <h2 style="margin-top:22px">重置 UUID（换一把钥匙）</h2>
                <p class="muted">
                    重置后**旧 UUID 立刻失效**，客户端要用新的一串重新配置；正在连着的会话不受影响（除非勾选下面的选项）。
                    需要重新输入当前密码 —— 防止别人拿你的登录会话把钥匙换掉。
                </p>
                <form method="post" action="{{ route('me.uuid.reset') }}">
                    @csrf
                    <label>当前密码</label>
                    <input type="password" name="current_password" required autocomplete="current-password">
                    <label style="display:flex; align-items:center; gap:8px; margin-top:12px">
                        <input type="checkbox" name="disconnect" value="1" style="width:auto">
                        同时断开我当前的在线连接（换手机/丢设备时用）
                    </label>
                    <button type="submit">重置 UUID</button>
                    <span class="muted">（每小时最多 {{ $uuidResetPerHour }} 次）</span>
                </form>
            @else
                <p class="muted">管理员已关闭自助重置；需要换钥匙请联系管理员。</p>
            @endif
        @endif
    </div>

    <div class="card">
        <h2>最近 14 天流量</h2>
        @if ($days->isEmpty())
            <p class="muted">暂无记录。流量每分钟汇总一次。</p>
        @else
            <table>
                <tr><th>日期</th><th>上行</th><th>下行</th><th>合计</th></tr>
                @foreach ($days as $row)
                    <tr>
                        <td>{{ $row->day->toDateString() }}</td>
                        <td>{{ round($row->tx / 1048576, 2) }} MiB</td>
                        <td>{{ round($row->rx / 1048576, 2) }} MiB</td>
                        <td>{{ round(($row->tx + $row->rx) / 1048576, 2) }} MiB</td>
                    </tr>
                @endforeach
            </table>
        @endif
    </div>

    <div class="card">
        <h2>修改密码</h2>
        <form method="post" action="{{ route('me.password') }}">
            @csrf
            @method('patch')
            <label>当前密码</label>
            <input type="password" name="current_password" required>
            <label>新密码</label>
            <input type="password" name="password" required>
            <label>确认新密码</label>
            <input type="password" name="password_confirmation" required>
            <button type="submit">保存</button>
        </form>
        <p class="muted">改完立即生效：新旧密码的判定缓存会被同时清掉。</p>
    </div>
@endsection
