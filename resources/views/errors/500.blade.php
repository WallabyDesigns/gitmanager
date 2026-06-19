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

        <link rel="icon" type="image/x-icon" href="/favicons/favicon.ico" >
        <link rel="icon" type="image/png" href="/favicons/favicon-96x96.png" sizes="96x96" />
        <link rel="icon" type="image/svg+xml" href="/favicons/favicon.svg" />
        <link rel="shortcut icon" href="/favicons/favicon.ico" />
        <link rel="apple-touch-icon" sizes="180x180" href="/favicons/apple-touch-icon.png" />
        <meta name="apple-mobile-web-app-title" content="GWM" />
        <link rel="manifest" href="/favicons/site.webmanifest" />

        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>{{ __('Something went wrong') }} | {{ config('app.name', 'Git Web Manager') }}</title>
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
                background: #F15A29;
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
            <div class="logo" style="display: flex; flex-direction: row; gap: 10px; margin-bottom: 15px;">
                <svg viewBox="0 0 341.41 340.88" xmlns="http://www.w3.org/2000/svg" style="width:48px;height:48px;" aria-hidden="true">
                    <path d="M100.6,221.15l-18.54,37.88L5.73,182.7c-6-6-6-15.74,0-21.74l34.79-34.79,56.51,64.84c-2.69,3.68-4.28,8.22-4.28,13.14,0,6.81,3.05,12.91,7.85,17Z" style="fill:#f15a29;"/>
                    <path d="M334.83,182.7l-48.42,48.42-11.61-27.88,36.75-.64-82.46-82.46-.13,113.23,26.02-24.67,15.98,37.86-89.82,89.82c-6,6-15.73,6-21.73,0l-68.38-68.38,20.47-41.8c1.17.19,2.37.29,3.59.29,3.82,0,7.41-.96,10.55-2.65l25.98,29.81c-2.33,3.52-3.68,7.74-3.68,12.28,0,12.33,10,22.33,22.34,22.33s22.34-10,22.34-22.33-10.01-22.34-22.34-22.34c-3.44,0-6.71.78-9.62,2.17l-26.35-30.23c1.98-3.33,3.12-7.22,3.12-11.38,0-6.46-2.75-12.29-7.14-16.35l41.27-84.3c1.32.25,2.68.38,4.07.38,12.33,0,22.33-10,22.33-22.34s-10-22.34-22.33-22.34-22.34,10-22.34,22.34c0,6.64,2.89,12.6,7.49,16.69l-41.15,84.05c-1.46-.3-2.98-.46-4.54-.46-3.06,0-5.98.62-8.64,1.74l-57.43-65.89L159.41,7.28c6-6,15.73-6,21.73,0l153.69,153.68c6,6,6,15.74,0,21.74Z" style="fill:#f15a29;"/>
                </svg>
                <div>
                    <h1>{{ __('Something went wrong') }}</h1>
                </div>
            </div>
            <p>
                {{ __('The application hit an unexpected error. If this happened after an update, you can roll back to the previous version and get back online quickly.') }}
            </p>

            <div class="actions">
                @if (auth()->check())
                    <a class="btn primary" href="{{ $rollbackUrl }}" onclick="return confirm('{{ __('Roll back to the previous update? This keeps storage and configuration intact.') }}');">{{ __('Roll Back Update') }}</a>
                @endif
                <a class="btn" href="{{ $reportUrl }}" target="_blank" rel="noreferrer">{{ __('Report This Issue') }}</a>
            </div>

            @if (auth()->check())
                <div class="callout">
                    {{ __('Rolling back returns to the last successful update and keeps your data, configuration, and logs intact.') }}
                </div>
            @endif

            @if ($detailText !== '')
                <details>
                    <summary>{{ __('View error details') }}</summary>
                    <div style="margin-top: 0.75rem; display: flex; justify-content: flex-end;">
                        <button
                            type="button"
                            class="btn"
                            style="padding: 0.45rem 0.9rem; font-size: 0.8rem;"
                            onclick="navigator.clipboard?.writeText(document.getElementById('gwm-error-details')?.innerText || '')"
                        >
                            {{ __('Copy Details') }}
                        </button>
                    </div>
                    <pre id="gwm-error-details">{{ $detailText }}</pre>
                </details>
            @endif
        </div>
    </body>
</html>
