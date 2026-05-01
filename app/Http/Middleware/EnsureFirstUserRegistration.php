<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureFirstUserRegistration
{
    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (User::query()->exists()) {
            return redirect()
                ->route('login')
                ->with('status', 'Registration is closed.');
        }

        return $next($request);
    }
}
