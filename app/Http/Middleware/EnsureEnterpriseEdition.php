<?php

namespace App\Http\Middleware;

use App\Services\LicenseService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureEnterpriseEdition
{
    public function __construct(
        private readonly LicenseService $license,
    ) {}

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (! $this->license->hasValidEnterpriseLicense()) {
            abort(403);
        }

        return $next($request);
    }
}
