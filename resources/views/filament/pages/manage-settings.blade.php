<x-filament-panels::page>
    <form wire:submit="save">
        {{ $this->form }}

        <div class="mt-4 flex gap-2">
            <x-filament::button type="submit">保存设置</x-filament::button>
        </div>
    </form>

    <x-filament::section heading="运维提示" class="mt-6">
        <ul class="list-disc space-y-1 pl-5 text-sm text-gray-400">
            <li>流量每 {{ 60 }} 秒由 <code>php artisan traffic:flush</code> 落库（schedule:run 必须常驻）。</li>
            <li>「清空鉴权缓存」会同时清掉在线集合与未落库计数，建议先 flush。</li>
            <li>禁用用户不需要清缓存：面板会主动失效那个用户的快照。</li>
        </ul>
    </x-filament::section>
</x-filament-panels::page>
