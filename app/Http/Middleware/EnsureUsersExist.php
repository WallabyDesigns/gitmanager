<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureUsersExist
{
    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (! User::query()->exists()) {
            return redirect()
                ->route('register')
                ->with('status', 'Create the first account to continue.');
        }

        return $next($request);
    }
}
