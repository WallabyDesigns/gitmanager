@props([
    'backups' => [],
    'status' => null,
])

@php
    $panelStyle = 'max-width: 720px; width: 100%; margin: 1.5rem auto 0; background: #0f172a; border: 1px solid #334155; border-radius: 16px; padding: 1.5rem; color: #e2e8f0; font-family: system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif; box-sizing: border-box;';
    $buttonStyle = 'display: inline-flex; align-items: center; justify-content: center; padding: 0.4rem 0.75rem; border-radius: 8px; border: 1px solid #475569; background: #1f2937; color: #e2e8f0; text-decoration: none; font-weight: 600; font-size: 0.75rem; cursor: pointer;';
    $primaryStyle = 'display: inline-flex; align-items: center; justify-content: center; padding: 0.4rem 0.75rem; border-radius: 8px; border: 1px solid #38bdf8; background: #0ea5e9; color: #0f172a; text-decoration: none; font-weight: 700; font-size: 0.75rem; cursor: pointer;';
    $inputStyle = 'background: #1e293b; border: 1px solid #334155; border-radius: 8px; color: #e2e8f0; padding: 0.4rem 0.75rem; font-size: 0.75rem; width: 200px; box-sizing: border-box;';
@endphp

<div id="env-backups-panel" style="{{ $panelStyle }}">
    <div style="display: flex; align-items: center; gap: 0.75rem; margin-bottom: 0.75rem;">
        <strong style="font-size: 1rem;">Environment Backups</strong>
        <span style="font-size: 0.75rem; color: #94a3b8;">Restore a previous .env configuration</span>
    </div>

    <p style="margin: 0 0 1rem 0; line-height: 1.5; font-size: 0.85rem; color: #94a3b8;">
        Restoring a backup overwrites your current <code style="background:#1e293b;padding:1px 4px;border-radius:4px;">.env</code> file.
        A safety backup of the current file is created automatically before each restore.
    </p>

    @if ($status)
        <div style="margin-bottom: 0.75rem; padding: 0.5rem 0.75rem; border-radius: 10px; background: #1e293b; border: 1px solid #334155; font-size: 0.8rem;">
            {{ $status }}
        </div>
    @endif

    {{-- Create backup --}}
    <form method="POST" action="{{ route('recovery.env-backup.create') }}" style="display:flex;gap:0.5rem;align-items:center;margin-bottom:1rem;">
        @csrf
        <input type="text" name="label" placeholder="Label (optional)" style="{{ $inputStyle }}">
        <button type="submit" style="{{ $primaryStyle }}">Create Backup Now</button>
    </form>

    {{-- Backup list --}}
    @if (count($backups) === 0)
        <p style="font-size: 0.8rem; color: #64748b;">No backups yet.</p>
    @else
        <div style="overflow: auto; max-height: 320px;">
            <table style="width: 100%; border-collapse: collapse; font-size: 0.78rem;">
                <thead>
                    <tr style="color: #64748b; text-transform: uppercase; letter-spacing: 0.06em; font-size: 0.68rem;">
                        <th style="text-align:left;padding:0.4rem 0.5rem;border-bottom:1px solid #1e293b;">Filename</th>
                        <th style="text-align:left;padding:0.4rem 0.5rem;border-bottom:1px solid #1e293b;">Created</th>
                        <th style="text-align:left;padding:0.4rem 0.5rem;border-bottom:1px solid #1e293b;">Size</th>
                        <th style="text-align:left;padding:0.4rem 0.5rem;border-bottom:1px solid #1e293b;">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($backups as $backup)
                        <tr style="border-bottom:1px solid #1e293b;">
                            <td style="padding:0.5rem;font-family:monospace;color:#cbd5e1;word-break:break-all;">{{ $backup['filename'] }}</td>
                            <td style="padding:0.5rem;color:#94a3b8;white-space:nowrap;">{{ $backup['created_at'] }}</td>
                            <td style="padding:0.5rem;color:#94a3b8;white-space:nowrap;">{{ number_format($backup['size'] / 1024, 1) }} KB</td>
                            <td style="padding:0.5rem;">
                                <form method="POST" action="{{ route('recovery.env-backup.restore', ['filename' => $backup['filename']]) }}"
                                      onsubmit="return confirm('Restore this backup? Your current .env will be saved first.');">
                                    @csrf
                                    <button type="submit" style="{{ $buttonStyle }}">Restore</button>
                                </form>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</div>
