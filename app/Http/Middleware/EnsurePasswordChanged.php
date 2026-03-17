<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class EnsurePasswordChanged
{
    /**
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = Auth::user();
        if (! $user || ! $user->must_change_password) {
            return $next($request);
        }

        if ($request->routeIs('profile')) {
            return $next($request);
        }

        return redirect()
            ->route('profile')
            ->with('status', 'Please change your password to continue.');
    }
}
