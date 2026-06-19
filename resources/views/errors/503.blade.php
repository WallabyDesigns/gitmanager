<!DOCTYPE html>
<html lang="en">
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

        <title>Update in Progress | {{ config('app.name', 'Git Web Manager') }}</title>
        <style>
            :root { color-scheme: dark; }
            * { box-sizing: border-box; margin: 0; padding: 0; }
            body {
                min-height: 100vh;
                font-family: system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
                background: radial-gradient(circle at top, rgba(30,41,59,0.2), rgba(2,6,23,0.2));
                color: #e2e8f0;
                display: flex;
                align-items: center;
                justify-content: center;
                padding: 2rem;
            }
            .card {
                width: min(520px, 100%);
                background: rgba(15,23,42,0.95);
                border: 1px solid rgba(148,163,184,0.15);
                border-radius: 18px;
                padding: 2.5rem 2rem;
                box-shadow: 0 20px 45px rgba(15,23,42,0.5);
                text-align: center;
            }
            .logo { margin: 0 auto 1.5rem; display: flex; justify-content: center; }
            .spinner {
                margin-top: 15px;
                width: 44px;
                height: 44px;
                border: 3px solid rgba(255,255,255,0.15);
                border-top-color: #ffffff;
                border-radius: 50%;
                animation: gwm-spin 0.85s linear infinite;
                margin: 0 auto 1.25rem;
            }
            @keyframes gwm-spin { to { transform: rotate(360deg); } }
            h1 { font-size: 1.3rem; font-weight: 700; color: #f1f5f9; margin-bottom: 0.4rem; }
            .sub { color: #64748b; font-size: 0.875rem; line-height: 1.6; }
            .retry { margin-top: 1rem; font-size: 0.78rem; color: #475569; }
            #countdown { color: #94a3b8; font-weight: 600; }
            .recovery { display: none; margin-top: 1.25rem; }
            .recovery a {
                display: inline-block;
                padding: 0.5rem 1.1rem;
                border-radius: 8px;
                border: 1px solid rgba(148,163,184,0.3);
                background: #F15A29;
                color: rgba(15,23,42,0.95);
                text-decoration: none;
                font-size: 0.85rem;
                font-weight: 500;
                transition: border-color 0.15s, color 0.15s;
            }
            .recovery a:hover { border-color: rgba(148,163,184,0.6); color: #f1f5f9; }
            .log-wrap { margin-top: 1.5rem; text-align: left; }
            details { border-radius: 8px; border: 1px solid rgba(148,163,184,0.12); overflow: hidden; }
            summary {
                cursor: pointer;
                padding: 0.6rem 0.9rem;
                font-size: 0.8rem;
                color: #64748b;
                background: rgba(30,41,59,0.5);
                user-select: none;
                list-style: none;
                display: flex;
                align-items: center;
                gap: 0.4rem;
            }
            summary::-webkit-details-marker { display: none; }
            summary::before { content: '▶'; font-size: 0.6rem; transition: transform 0.2s; }
            details[open] summary::before { transform: rotate(90deg); }
            #log-body {
                padding: 0.75rem 0.9rem;
                font-size: 0.72rem;
                font-family: ui-monospace, "Cascadia Code", monospace;
                color: #94a3b8;
                background: rgba(2,6,23,0.6);
                max-height: 180px;
                overflow-y: auto;
                white-space: pre-wrap;
                word-break: break-word;
            }
        </style>
    </head>
    <body style="display: flex; flex-direction: column;">
        <div class="card">
            <div class="logo">
                <svg viewBox="0 0 341.41 340.88" xmlns="http://www.w3.org/2000/svg" style="width:48px;height:48px;" aria-hidden="true">
                    <path d="M100.6,221.15l-18.54,37.88L5.73,182.7c-6-6-6-15.74,0-21.74l34.79-34.79,56.51,64.84c-2.69,3.68-4.28,8.22-4.28,13.14,0,6.81,3.05,12.91,7.85,17Z" style="fill:#f15a29;"/>
                    <path d="M334.83,182.7l-48.42,48.42-11.61-27.88,36.75-.64-82.46-82.46-.13,113.23,26.02-24.67,15.98,37.86-89.82,89.82c-6,6-15.73,6-21.73,0l-68.38-68.38,20.47-41.8c1.17.19,2.37.29,3.59.29,3.82,0,7.41-.96,10.55-2.65l25.98,29.81c-2.33,3.52-3.68,7.74-3.68,12.28,0,12.33,10,22.33,22.34,22.33s22.34-10,22.34-22.33-10.01-22.34-22.34-22.34c-3.44,0-6.71.78-9.62,2.17l-26.35-30.23c1.98-3.33,3.12-7.22,3.12-11.38,0-6.46-2.75-12.29-7.14-16.35l41.27-84.3c1.32.25,2.68.38,4.07.38,12.33,0,22.33-10,22.33-22.34s-10-22.34-22.33-22.34-22.34,10-22.34,22.34c0,6.64,2.89,12.6,7.49,16.69l-41.15,84.05c-1.46-.3-2.98-.46-4.54-.46-3.06,0-5.98.62-8.64,1.74l-57.43-65.89L159.41,7.28c6-6,15.73-6,21.73,0l153.69,153.68c6,6,6,15.74,0,21.74Z" style="fill:#f15a29;"/>
                </svg>
            </div>
            <h1>Update in Progress</h1>
            <p class="sub">Git Web Manager is applying an update.<br>The app will be back online shortly.</p>

            <div class="retry">Retrying in <span id="countdown">10</span>s</div>

            @auth
                <div class="recovery" id="recovery-btn">
                    <a href="/recovery">Open Recovery Page</a>
                </div>
                <div class="log-wrap">
                    <details>
                        <summary>Update log</summary>
                        <div id="log-body">Waiting for app to respond…</div>
                    </details>
                </div>
            @endauth

            <div class="spinner"></div>
        </div>

        <script>
            (function () {
                var countEl  = document.getElementById('countdown');
                var logEl    = document.getElementById('log-body');
                var recovery = document.getElementById('recovery-btn');
                var elapsed  = 0;
                var retryIn  = 10;
                function setLog(text) {
                    if (logEl) {
                        logEl.textContent = text;
                        logEl.scrollTop   = logEl.scrollHeight;
                    }
                }

                function fetchLog() {
                    fetch('/update/status', { cache: 'no-store', credentials: 'same-origin' })
                        .then(function (r) {
                            if (r.ok) return r.text();
                            return Promise.reject(r.status);
                        })
                        .then(function (text) { setLog(text || 'No log content yet.'); })
                        .catch(function () { setLog('Update log unavailable while app is restarting…'); });
                }

                function checkOnline() {
                    fetch(window.location.href, { method: 'HEAD', cache: 'no-store' })
                        .then(function (r) { if (r.ok) window.location.reload(); })
                        .catch(function () { /* still down */ });
                }

                setInterval(function () {
                    elapsed++;
                    retryIn--;

                    if (elapsed === 60 && recovery) {
                        recovery.style.display = 'block';
                    }

                    if (retryIn <= 0) {
                        retryIn = 10;
                        checkOnline();
                        fetchLog();
                    }

                    if (countEl) countEl.textContent = retryIn;
                }, 1000);

                fetchLog();
            })();
        </script>
    </body>
</html>
