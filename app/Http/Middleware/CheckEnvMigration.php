<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Services\EnvManagerService;
use Closure;
use Illuminate\Http\Request;

class CheckEnvMigration
{
    public function __construct(private readonly EnvManagerService $envManager) {}

    public function handle(Request $request, Closure $next): mixed
    {
        // Only redirect admin users on full-page GET requests
        if (
            ! $request->user()?->isAdmin()
            || ! $request->isMethod('GET')
            || $request->ajax()
            || $request->wantsJson()
        ) {
            return $next($request);
        }

        // Never redirect on the wizard or recovery screens
        if ($request->routeIs('env.migrate', 'env.migrate.*', 'recovery.*', 'logout')) {
            return $next($request);
        }

        // Allow one-session dismissal
        if ($request->session()->get('env_migration_dismissed')) {
            return $next($request);
        }

        $missing = $this->envManager->getMissingKeys();

        if (! empty($missing)) {
            return redirect()->route('env.migrate');
        }

        return $next($request);
    }
}
