@extends('layouts.app')

@section('title', '我的 · '.$brand)
@section('content')
    <div class="card">
        <h1>{{ $user->username }} <span class="badge {{ $user->isUsable() ? 'ok' : 'bad' }}">{{ $user->statusLabel() }}</span></h1>
        @php($mode = app(\App\Services\SettingStore::class)->string('auth_mode'))
        <p class="muted">
            客户端 <code>auth</code> 填：
            @if ($mode === 'password')
                <code>{{ $user->username }}:你的密码</code>
            @else
                <code class="break-all">{{ $user->uuid }}</code>
                <br><span class="muted">（这串就是你的凭据；泄露了或被踢了，让管理员在后台点「重置 UUID」换一把，旧的立刻失效）</span>
            @endif
        </p>

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
