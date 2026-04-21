<div>
    <h2 class="text-base font-semibold text-slate-900 dark:text-slate-100">Database Containers</h2>
    <p class="text-sm text-slate-500 dark:text-slate-400 mt-0.5">Detected running MySQL, PostgreSQL, MongoDB, Redis, and other database containers. Launch Adminer to browse them in-browser.</p>
</div>

@if (count($containers) === 0)
    <div class="rounded-xl border border-dashed border-slate-300 dark:border-slate-700 p-12 text-center space-y-3">
        <svg class="mx-auto h-10 w-10 text-slate-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.25"><path stroke-linecap="round" stroke-linejoin="round" d="M20.25 6.375c0 2.278-3.694 4.125-8.25 4.125S3.75 8.653 3.75 6.375m16.5 0c0-2.278-3.694-4.125-8.25-4.125S3.75 4.097 3.75 6.375m16.5 0v11.25c0 2.278-3.694 4.125-8.25 4.125s-8.25-1.847-8.25-4.125V6.375m16.5 0v3.75m-16.5-3.75v3.75m16.5 0v3.75C20.25 16.153 16.556 18 12 18s-8.25-1.847-8.25-4.125v-3.75m16.5 0c0 2.278-3.694 4.125-8.25 4.125s-8.25-1.847-8.25-4.125"/></svg>
        <p class="text-sm font-medium text-slate-600 dark:text-slate-400">No database containers detected.</p>
        <a href="{{ route('infra.containers.section', 'templates') }}"
           class="inline-flex items-center text-sm text-indigo-600 dark:text-indigo-400 hover:underline">
            Deploy a database from Templates →
        </a>
    </div>
@else
    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
        @foreach ($containers as $c)
            @php
                $name  = $c['Names'] ?? $c['ID'] ?? '';
                $image = strtolower($c['Image'] ?? '');
                $state = $c['State'] ?? '';
                $ports = $c['Ports'] ?? '';
                $dbType = match(true) {
                    str_contains($image, 'mysql')    => 'MySQL',
                    str_contains($image, 'mariadb')  => 'MariaDB',
                    str_contains($image, 'postgres') => 'PostgreSQL',
                    str_contains($image, 'mongo')    => 'MongoDB',
                    str_contains($image, 'redis')    => 'Redis',
                    default                          => 'Database',
                };
                $canAdminer = in_array($dbType, ['MySQL', 'MariaDB', 'PostgreSQL'], true);
            @endphp
            <div class="rounded-xl border border-slate-200 dark:border-slate-800 bg-white dark:bg-slate-900 p-5">
                <div class="flex items-start justify-between gap-3">
                    <div class="min-w-0">
                        <div class="flex items-center gap-2 flex-wrap">
                            <h3 class="font-semibold text-slate-900 dark:text-slate-100 truncate">{{ $name }}</h3>
                            <span class="inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-xs font-medium
                                {{ $state === 'running' ? 'bg-emerald-100 text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-400' : 'bg-slate-100 text-slate-600 dark:bg-slate-800 dark:text-slate-400' }}">
                                <span class="h-1.5 w-1.5 rounded-full {{ $state === 'running' ? 'bg-emerald-500' : 'bg-slate-400' }}"></span>
                                {{ ucfirst($state) }}
                            </span>
                        </div>
                        <p class="text-xs text-slate-500 dark:text-slate-400 mt-1 font-mono">{{ $c['Image'] ?? '' }}</p>
                        @if ($ports)
                            <p class="text-xs text-slate-500 dark:text-slate-400 mt-0.5">Ports: {{ $ports }}</p>
                        @endif
                    </div>
                    <span class="shrink-0 inline-flex rounded-md bg-blue-100 dark:bg-blue-500/10 px-2 py-1 text-xs font-semibold text-blue-700 dark:text-blue-300">{{ $dbType }}</span>
                </div>

                <div class="mt-4 flex flex-wrap gap-2">
                    @if ($canAdminer && $state === 'running')
                        <button wire:click="launchAdminer('{{ $name }}', '{{ $dbType }}')"
                                class="inline-flex items-center gap-1.5 rounded-lg bg-amber-500 hover:bg-amber-400 px-3 py-1.5 text-xs font-semibold text-white shadow-sm transition">
                            <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M5.25 14.25h13.5m-13.5 0a3 3 0 01-3-3m3 3a3 3 0 100 6h13.5a3 3 0 100-6m-16.5-3a3 3 0 013-3h13.5a3 3 0 013 3m-19.5 0a4.5 4.5 0 01.9-2.7L5.737 5.1a3.375 3.375 0 012.7-1.35h7.126c1.062 0 2.062.5 2.7 1.35l2.587 3.45a4.5 4.5 0 01.9 2.7m0 0a3 3 0 01-3 3m0 3h.008v.008h-.008v-.008zm0-6h.008v.008h-.008v-.008zm-3 6h.008v.008h-.008v-.008zm0-6h.008v.008h-.008v-.008z"/></svg>
                            Launch Adminer
                        </button>
                    @endif
                    <button wire:click="viewLogs('{{ $c['ID'] ?? '' }}')"
                            class="inline-flex items-center gap-1.5 rounded-lg border border-slate-200 dark:border-slate-700 px-3 py-1.5 text-xs font-semibold text-slate-600 dark:text-slate-400 hover:border-indigo-300 hover:text-indigo-600 transition">
                        Logs
                    </button>
                    <button wire:click="inspectContainer('{{ $c['ID'] ?? '' }}')"
                            class="inline-flex items-center gap-1.5 rounded-lg border border-slate-200 dark:border-slate-700 px-3 py-1.5 text-xs font-semibold text-slate-600 dark:text-slate-400 hover:border-slate-400 transition">
                        Inspect
                    </button>
                </div>

                @if ($canAdminer && $state === 'running')
                    <p class="mt-3 text-xs text-slate-400">Adminer will launch on port <strong>8080</strong>. If a previous Adminer container exists it will conflict — stop it first.</p>
                @endif
            </div>
        @endforeach
    </div>

    {{-- Adminer tip --}}
    <div class="rounded-xl border border-amber-100 dark:border-amber-800/40 bg-amber-50 dark:bg-amber-950/20 p-4 flex items-start gap-3">
        <svg class="h-5 w-5 text-amber-500 shrink-0 mt-0.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75"><path stroke-linecap="round" stroke-linejoin="round" d="M11.25 11.25l.041-.02a.75.75 0 011.063.852l-.708 2.836a.75.75 0 001.063.853l.041-.021M21 12a9 9 0 11-18 0 9 9 0 0118 0zm-9-3.75h.008v.008H12V8.25z"/></svg>
        <div class="text-sm text-amber-800 dark:text-amber-300">
            <strong>Tip:</strong> For Redis, use the <strong>RedisInsight</strong> or <strong>Redis Commander</strong> template in the Templates tab for a dedicated browser UI. For MongoDB, use <strong>mongo-express</strong>.
        </div>
    </div>
@endif
