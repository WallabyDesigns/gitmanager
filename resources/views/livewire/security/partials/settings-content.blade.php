{{-- Audit Checks + Health Alerts settings. Rendered from App\Livewire\System\Settings
     (the shared settings component) whenever $settingsSection === 'audits', which is
     reached via the /security/settings route. Lives under security/ rather than
     system/ because this content belongs to the Security tab, not System Settings. --}}
<div class="bg-slate-900 shadow-sm sm:rounded-xl border border-slate-800 p-6 space-y-4">
    <div>
        <h3 class="text-lg font-semibold text-slate-100">{{ __('Audit Checks') }}</h3>
        <p class="text-sm text-slate-400">{{ __('Run scheduled project and container audits and track issues in System Security.') }}</p>
    </div>
    @if ($isEnterprise)
        <label class="flex items-start gap-3">
            <input type="checkbox" wire:model="auditEnabled" class="mt-1 rounded border-slate-300 text-indigo-600 shadow-sm focus:ring-indigo-500" />
            <span class="text-sm text-slate-300">
                {{ __('Enable automatic audits') }}
                <span class="block text-xs text-slate-500">{{ __('Runs npm/composer project audits and managed container runtime checks on the scheduler.') }}</span>
            </span>
        </label>
        <label class="flex items-start gap-3">
            <input type="checkbox" wire:model="auditEmailEnabled" class="mt-1 rounded border-slate-300 text-indigo-600 shadow-sm focus:ring-indigo-500" />
            <span class="text-sm text-slate-300">
                {{ __('Email audit summaries') }}
                <span class="block text-xs text-slate-500">{{ __('Sends a consolidated report when issues are found or resolved.') }}</span>
            </span>
        </label>
        <label class="flex items-start gap-3">
            <input type="checkbox" wire:model="auditAutoCommit" class="mt-1 rounded border-slate-300 text-indigo-600 shadow-sm focus:ring-indigo-500" />
            <span class="text-sm text-slate-300">
                {{ __('Auto-commit resolved audit fixes') }}
                <span class="block text-xs text-slate-500">{{ __('Pushes dependency lockfile changes when audits resolve all vulnerabilities.') }}</span>
            </span>
        </label>
        @if ($auditEmailEnabled)
            <div>
                <label for="audit-notification-cooldown" class="block text-sm text-slate-300 mb-1">{{ __('Notification cooldown (hours)') }}</label>
                <input id="audit-notification-cooldown" type="number" wire:model="auditNotificationCooldown" min="0" max="168" step="1"
                    class="w-28 rounded-md border border-slate-700 bg-slate-800 px-3 py-1.5 text-sm text-slate-100 focus:border-indigo-500 focus:ring-indigo-500" />
                <p class="mt-1 text-xs text-slate-500">{{ __('Minimum hours before re-emailing about the same vulnerability. Set to 0 to always email.') }}</p>
            </div>
        @endif
        @if (! $mailConfigured)
            <div class="text-xs text-rose-400">{{ __('Email is not configured. Set SMTP details in System → Email Settings to enable audit emails.') }}</div>
        @endif
        @if (! $auditEnabled)
            <div class="text-xs text-slate-500">{{ __('Scheduled audits are disabled.') }}</div>
        @endif
    @else
        <div class="rounded-lg border border-amber-500/30 bg-amber-500/10 p-4">
            <div class="text-sm font-semibold text-amber-200">{{ __('Enterprise Feature') }}</div>
            <p class="mt-1 text-xs text-amber-200">
                {{ __('Automatic project and container audits are available on Enterprise. Upgrade to unlock hourly audit automation and alerting.') }}
            </p>
            <button
                type="button"
                onclick="window.dispatchEvent(new CustomEvent('gwm-open-enterprise-modal', { detail: { feature: 'Automatic Project & Container Audits' } }));"
                class="mt-3 inline-flex items-center gap-2 rounded-md border px-3 py-1.5 text-xs font-semibold border-amber-500/60 text-amber-300 hover:text-amber-200"
            >
                Get Enterprise
            </button>
        </div>
    @endif
</div>

<div class="bg-slate-900 shadow-sm sm:rounded-xl border border-slate-800 p-6 space-y-4">
    <div>
        <h3 class="text-lg font-semibold text-slate-100">{{ __('Health Alerts') }}</h3>
        <p class="text-sm text-slate-400">{{ __('Email notifications when projects go offline during automatic checks.') }}</p>
    </div>
    <label class="flex items-start gap-3">
        <input type="checkbox" wire:model="healthEmailEnabled" class="mt-1 rounded border-slate-300 text-indigo-600 shadow-sm focus:ring-indigo-500" />
        <span class="text-sm text-slate-300">
            {{ __('Send email alerts when health checks fail') }}
            <span class="block text-xs text-slate-500">{{ __('Only automatic checks trigger emails. Manual checks never send alerts.') }}</span>
        </span>
    </label>
    @if (! $mailConfigured)
        <div class="text-xs text-rose-400">{{ __('Email is not configured. Set SMTP details in System → Email Settings to enable health alerts.') }}</div>
    @endif
    @if (! $healthEmailEnabled)
        <div class="text-xs text-slate-500">{{ __('Health alert emails are disabled.') }}</div>
    @endif
</div>
