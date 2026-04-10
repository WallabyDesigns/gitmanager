@props([
    'forceVisible' => false,
    'overlay' => true,
    'status' => null,
    'output' => null,
])

@php
    $hiddenClass = $forceVisible ? '' : 'hidden';
    $wrapperStyle = $overlay
        ? 'position: fixed; inset: 0; z-index: 9999; padding: 2rem; background: rgba(15, 23, 42, 0.95); overflow: auto;'
        : 'position: relative; z-index: 20; padding: 2rem;';
    $panelStyle = 'max-width: 720px; width: 100%; margin: '.($overlay ? '10vh auto 0' : '0 auto').'; background: #0f172a; border: 1px solid #334155; border-radius: 16px; padding: 1.5rem; color: #e2e8f0; font-family: system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif; box-sizing: border-box;';
    $buttonStyle = 'display: inline-flex; align-items: center; justify-content: center; padding: 0.6rem 1rem; border-radius: 10px; border: 1px solid #475569; background: #1f2937; color: #e2e8f0; text-decoration: none; font-weight: 600; cursor: pointer;';
    $primaryStyle = 'display: inline-flex; align-items: center; justify-content: center; padding: 0.6rem 1rem; border-radius: 10px; border: 1px solid #38bdf8; background: #0ea5e9; color: #0f172a; text-decoration: none; font-weight: 700; cursor: pointer;';
@endphp

<div id="gwm-recovery-panel" class="{{ $hiddenClass }}" style="{{ $wrapperStyle }}">
    <div style="{{ $panelStyle }}">
        <div style="display: flex; align-items: center; gap: 0.75rem; margin-bottom: 0.5rem;">
            <strong style="font-size: 1.2rem;">Recovery Mode</strong>
            <span style="font-size: 0.8rem; color: #94a3b8;">Assets missing or styling broken</span>
        </div>
        <p style="margin: 0 0 1rem 0; line-height: 1.5;">
            Use the actions below to rebuild front-end assets. This panel is hidden when Tailwind loads, so if you can see it the UI assets likely failed.
        </p>

        @if ($status)
            <div style="margin-bottom: 0.75rem; padding: 0.5rem 0.75rem; border-radius: 10px; background: #1e293b; border: 1px solid #334155;">
                {{ $status }}
            </div>
        @endif

        <div style="display: flex; flex-wrap: wrap; gap: 0.75rem; margin-bottom: 1rem;">
            <form method="POST" action="{{ route('recovery.rebuild') }}">
                @csrf
                <button type="submit" style="{{ $primaryStyle }}">Rebuild Front-End Assets</button>
            </form>
            @if (!request()->routeIs('recovery.index'))
                <a href="{{ route('recovery.index') }}" style="{{ $buttonStyle }}">Open Recovery Page</a>
            @endif
            @if (auth()->user()?->isAdmin())
                <a href="{{ route('system.updates') }}" style="{{ $buttonStyle }}">System Updates</a>
            @endif
        </div>

        @if ($output)
            <div style="margin-top: 1rem;">
                <div style="font-size: 0.8rem; text-transform: uppercase; letter-spacing: 0.08em; color: #94a3b8;">Recent rebuild log</div>
                <pre style="margin-top: 0.5rem; max-height: 240px; overflow: auto; background: #020617; padding: 0.75rem; border-radius: 10px; border: 1px solid #1e293b; font-size: 0.75rem; color: #e2e8f0;">{{ $output }}</pre>
            </div>
        @endif
    </div>
</div>

<script>
    (function () {
        const panel = document.getElementById('gwm-recovery-panel');
        if (!panel) {
            return;
        }

        const sentinel = document.createElement('div');
        sentinel.className = 'hidden';
        sentinel.style.position = 'absolute';
        sentinel.style.left = '-9999px';
        sentinel.style.top = '-9999px';
        document.body.appendChild(sentinel);

        const tailwindReady = () => window.getComputedStyle(sentinel).display === 'none';

        if (!tailwindReady()) {
            panel.style.display = 'flex';
            panel.style.alignItems = 'center';
            panel.style.justifyContent = 'center';
            panel.style.minHeight = '100vh';

            let attempts = 0;
            const timer = setInterval(() => {
                attempts += 1;
                if (tailwindReady()) {
                    panel.style.display = '';
                    panel.style.alignItems = '';
                    panel.style.justifyContent = '';
                    panel.style.minHeight = '';
                    clearInterval(timer);
                    sentinel.remove();
                    return;
                }

                if (attempts >= 20) {
                    clearInterval(timer);
                    sentinel.remove();
                }
            }, 250);
        } else {
            sentinel.remove();
        }
    })();
</script>
