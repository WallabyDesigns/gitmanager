<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $subjectLine }}</title>
</head>
<body style="margin:0;padding:0;background:#020617;color:#e2e8f0;font-family:Arial,Helvetica,sans-serif;">
    <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="background:#020617;padding:32px 16px;">
        <tr>
            <td align="center">
                <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="max-width:680px;background:#0f172a;border:1px solid #334155;border-radius:8px;overflow:hidden;">
                    <tr>
                        <td style="padding:22px 28px;background:#0b1120;border-bottom:3px solid #f97316;">
                            <table role="presentation" cellspacing="0" cellpadding="0" border="0">
                                <tr>
                                    <td style="padding-right:10px;vertical-align:middle;">
                                        <img src="{{ $brand['logo_url'] }}" alt="{{ $brand['name'] }}" width="34" height="34" style="display:block;width:34px;height:34px;max-width:34px;border:0;outline:none;text-decoration:none;object-fit:contain;">
                                    </td>
                                    <td style="vertical-align:middle;font-size:18px;font-weight:700;color:#f8fafc;">{{ $brand['name'] }}</td>
                                </tr>
                            </table>
                            <div style="margin-top:4px;font-size:11px;letter-spacing:1.5px;text-transform:uppercase;color:#94a3b8;">{{ __('System notification') }}</div>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:28px;">
                            <h1 style="margin:0 0 10px;font-size:22px;line-height:1.3;color:#f8fafc;">{{ $heading }}</h1>
                            <p style="margin:0 0 22px;font-size:14px;line-height:1.6;color:#cbd5e1;">{{ $intro }}</p>

                            @foreach ($items as $item)
                                <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="margin:0 0 14px;background:#111c33;border:1px solid #334155;border-radius:6px;">
                                    <tr>
                                        <td style="padding:16px;">
                                            <div style="font-size:15px;font-weight:700;color:#f8fafc;">{{ $item['title'] }}</div>
                                            @if (! empty($item['fields']))
                                                <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="margin-top:10px;font-size:13px;line-height:1.5;">
                                                    @foreach ($item['fields'] as $label => $value)
                                                        @continue($value === null || $value === '')
                                                        <tr>
                                                            <td style="padding:3px 12px 3px 0;color:#94a3b8;vertical-align:top;width:34%;">{{ \Illuminate\Support\Str::headline((string) $label) }}</td>
                                                            <td style="padding:3px 0;color:#e2e8f0;vertical-align:top;">{{ is_scalar($value) ? (string) $value : json_encode($value) }}</td>
                                                        </tr>
                                                    @endforeach
                                                </table>
                                            @endif
                                            @if (! empty($item['error_log']))
                                                <details style="margin-top:14px;">
                                                    <summary style="cursor:pointer;color:#fbbf24;font-size:13px;font-weight:700;">{{ __('View error log') }}</summary>
                                                    <div style="max-height:240px;overflow:auto;-webkit-overflow-scrolling:touch;margin-top:10px;border:1px solid #334155;border-radius:4px;background:#020617;">
                                                        <pre style="margin:0;padding:12px;white-space:pre-wrap;word-break:break-word;color:#cbd5e1;font:12px/1.5 Consolas,Monaco,monospace;">{{ $item['error_log'] }}</pre>
                                                    </div>
                                                </details>
                                            @endif
                                        </td>
                                    </tr>
                                </table>
                            @endforeach

                            @if ($actionUrl && $actionLabel)
                                <table role="presentation" cellspacing="0" cellpadding="0" border="0" style="margin-top:22px;">
                                    <tr>
                                        <td style="border-radius:5px;background:#ea580c;">
                                            <a href="{{ $actionUrl }}" style="display:inline-block;padding:11px 16px;color:#ffffff;text-decoration:none;font-size:14px;font-weight:700;">{{ $actionLabel }}</a>
                                        </td>
                                    </tr>
                                </table>
                            @endif

                            @if ($showEnterpriseSuggestion)
                                <p style="margin:24px 0 0;padding-top:16px;border-top:1px solid #334155;font-size:12px;line-height:1.5;color:#94a3b8;">{{ __('Enterprise adds automated vulnerability remediation, support, and advanced infrastructure controls.') }} <a href="https://gitwebmanager.com" style="color:#fdba74;">{{ __('Explore Enterprise') }}</a>.</p>
                            @endif
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:16px 28px;background:#0b1120;border-top:1px solid #334155;font-size:11px;color:#64748b;">
                            {{ __('Sent by :app.', ['app' => $brand['name']]) }}
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
