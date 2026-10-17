<x-filament-panels::page>
    <div class="grid gap-4 md:grid-cols-3">
        <x-filament::section heading="连接信息">
            <div class="space-y-1 text-sm">
                <div>账号：<code>{{ $user->username }}:密码</code></div>
                <div>状态：{{ $user->statusLabel() }}</div>
                <div>到期：{{ $user->expires_at?->toDateTimeString() ?? '不限' }}</div>
                <div>设备上限：{{ $user->max_devices > 0 ? $user->max_devices : '不限' }}</div>
            </div>
        </x-filament::section>

        <x-filament::section heading="凭据">
            <div class="space-y-2 text-sm">
                <div>UUID（客户端 <code>auth</code> 填这个）：</div>
                <div><code class="break-all">{{ $user->uuid ?? '（无，去列表点「重置 UUID」生成）' }}</code></div>
                <div class="text-gray-500">最近重置：{{ $user->uuid_rotated_at?->toDateTimeString() ?? '—' }}</div>
                <div class="text-gray-500">换钥匙：列表行操作「重置 UUID」——旧 UUID 立刻失效。</div>
            </div>
        </x-filament::section>

        <x-filament::section heading="流量">
            <div class="space-y-1 text-sm">
                <div>已落库：{{ $human((int) $user->used_bytes) }}</div>
                <div>未落库：{{ $human((int) $pending) }}</div>
                <div>配额：{{ $user->quota_bytes > 0 ? $human((int) $user->quota_bytes) : '不限' }}（{{ $user->quotaPercent() ?? 0 }}%）</div>
                <div>上行/下行：{{ $human((int) $user->tx_bytes) }} / {{ $human((int) $user->rx_bytes) }}</div>
            </div>
        </x-filament::section>

        <x-filament::section heading="在线设备（按 IP）">
            @if (empty($devices))
                <p class="text-sm text-gray-500">当前没有在线设备。</p>
            @else
                <ul class="space-y-1 text-sm">
                    @foreach ($devices as $ip => $ts)
                        <li>{{ $ip }} · {{ \Illuminate\Support\Carbon::createFromTimestamp($ts)->diffForHumans() }}</li>
                    @endforeach
                </ul>
            @endif
        </x-filament::section>
    </div>

    <x-filament::section heading="最近 30 天流量" class="mt-4">
        @if ($daily->isEmpty())
            <p class="text-sm text-gray-500">暂无日汇总（每分钟由 traffic:flush 生成）。</p>
        @else
            <table class="w-full text-sm">
                <thead>
                    <tr class="text-left text-gray-500">
                        <th class="py-1">日期</th><th>上行</th><th>下行</th><th>合计</th>
                    </tr>
                </thead>
                <tbody>
                @foreach ($daily as $row)
                    <tr class="border-t border-gray-800">
                        <td class="py-1">{{ $row->day->toDateString() }}</td>
                        <td>{{ $human($row->tx) }}</td>
                        <td>{{ $human($row->rx) }}</td>
                        <td>{{ $human($row->tx + $row->rx) }}</td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        @endif
    </x-filament::section>

    <x-filament::section heading="最近的原始上报" class="mt-4">
        @if ($reports->isEmpty())
            <p class="text-sm text-gray-500">暂无。</p>
        @else
            <table class="w-full text-sm">
                <thead>
                    <tr class="text-left text-gray-500">
                        <th class="py-1">时间</th><th>地址</th><th>上行增量</th><th>下行增量</th><th>来源</th>
                    </tr>
                </thead>
                <tbody>
                @foreach ($reports as $row)
                    <tr class="border-t border-gray-800">
                        <td class="py-1">{{ $row->reported_at?->toDateTimeString() }}</td>
                        <td>{{ $row->addr ?? '-' }}</td>
                        <td>{{ $human($row->tx_delta) }}</td>
                        <td>{{ $human($row->rx_delta) }}</td>
                        <td>{{ $row->source }}</td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        @endif
    </x-filament::section>
</x-filament-panels::page>
