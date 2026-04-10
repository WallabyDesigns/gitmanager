@php
    $exception = $exception ?? null;
    $debug = (bool) config('app.debug', false);
    $message = $exception?->getMessage();
    $exceptionClass = $exception ? class_basename($exception) : null;
    $reportUrl = 'https://github.com/wallabydesigns/gitmanager/issues/new';
    $rollbackUrl = route('system.update.rollback');
    $details = [];

    if ($exceptionClass) {
        $details[] = 'Exception: '.$exceptionClass;
    }

    if ($message) {
        $details[] = 'Message: '.$message;
    }

    if ($debug && $exception) {
        $details[] = 'Location: '.$exception->getFile().':'.$exception->getLine();
        $details[] = '';
        $details[] = 'Trace:';
        $details[] = $exception->getTraceAsString();
    }

    $detailText = trim(implode("\n", $details));
@endphp

<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>Something went wrong | {{ config('app.name', 'Git Web Manager') }}</title>
        <style>
            :root {
                color-scheme: dark;
            }
            * {
                box-sizing: border-box;
            }
            body {
                margin: 0;
                min-height: 100vh;
                font-family: system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
                background: radial-gradient(circle at top, rgba(30, 41, 59, 0.9), rgba(2, 6, 23, 0.95));
                color: #e2e8f0;
                display: flex;
                align-items: center;
                justify-content: center;
                padding: 2rem;
            }
            .card {
                width: min(720px, 100%);
                background: rgba(15, 23, 42, 0.95);
                border: 1px solid rgba(148, 163, 184, 0.2);
                border-radius: 18px;
                padding: 2rem;
                box-shadow: 0 20px 45px rgba(15, 23, 42, 0.5);
            }
            .eyebrow {
                text-transform: uppercase;
                letter-spacing: 0.12em;
                font-size: 0.7rem;
                color: #94a3b8;
            }
            h1 {
                margin: 0.4rem 0 0.6rem;
                font-size: 1.6rem;
                color: #f8fafc;
            }
            p {
                margin: 0 0 1rem;
                color: #cbd5f5;
                line-height: 1.6;
                font-size: 0.95rem;
            }
            .actions {
                display: flex;
                flex-wrap: wrap;
                gap: 0.75rem;
                margin-top: 1rem;
            }
            .btn {
                display: inline-flex;
                align-items: center;
                justify-content: center;
                padding: 0.6rem 1.1rem;
                border-radius: 10px;
                border: 1px solid rgba(148, 163, 184, 0.4);
                background: #1e293b;
                color: #e2e8f0;
                text-decoration: none;
                font-weight: 600;
                font-size: 0.9rem;
                transition: transform 0.15s ease, border-color 0.15s ease;
            }
            .btn:hover {
                transform: translateY(-1px);
                border-color: rgba(56, 189, 248, 0.6);
            }
            .btn.primary {
                background: linear-gradient(135deg, #22d3ee, #3b82f6);
                color: #0f172a;
                border-color: transparent;
            }
            .callout {
                margin-top: 1rem;
                padding: 0.75rem 1rem;
                border-radius: 12px;
                border: 1px solid rgba(148, 163, 184, 0.2);
                background: rgba(30, 41, 59, 0.6);
                font-size: 0.85rem;
                color: #cbd5f5;
            }
            details {
                margin-top: 1.25rem;
                border-top: 1px solid rgba(148, 163, 184, 0.2);
                padding-top: 1rem;
            }
            summary {
                cursor: pointer;
                font-weight: 600;
                color: #93c5fd;
            }
            pre {
                margin-top: 0.75rem;
                padding: 0.75rem;
                border-radius: 12px;
                background: #020617;
                border: 1px solid rgba(148, 163, 184, 0.2);
                color: #e2e8f0;
                font-size: 0.75rem;
                white-space: pre-wrap;
                word-break: break-word;
                max-height: 260px;
                overflow: auto;
            }
        </style>
    </head>
    <body>
        <div class="card">
            <div class="eyebrow">Git Web Manager</div>
            <h1>Something went wrong</h1>
            <p>
                The application hit an unexpected error. If this happened after an update, you can roll back to the previous
                version and get back online quickly.
            </p>

            <div class="actions">
                @if (auth()->check())
                    <a class="btn primary" href="{{ $rollbackUrl }}" onclick="return confirm('Roll back to the previous update? This keeps storage and configuration intact.');">Roll Back Update</a>
                @endif
                <a class="btn" href="{{ $reportUrl }}" target="_blank" rel="noreferrer">Report This Issue</a>
            </div>

            @if (auth()->check())
                <div class="callout">
                    Rolling back returns to the last successful update and keeps your data, configuration, and logs intact.
                </div>
            @endif

            @if ($detailText !== '')
                <details>
                    <summary>View error details</summary>
                    <div style="margin-top: 0.75rem; display: flex; justify-content: flex-end;">
                        <button
                            type="button"
                            class="btn"
                            style="padding: 0.45rem 0.9rem; font-size: 0.8rem;"
                            onclick="navigator.clipboard?.writeText(document.getElementById('gwm-error-details')?.innerText || '')"
                        >
                            Copy Details
                        </button>
                    </div>
                    <pre id="gwm-error-details">{{ $detailText }}</pre>
                </details>
            @endif
        </div>
    </body>
</html>
