<?php

namespace App\Http\Middleware;

use App\Support\LanguageOptions;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Symfony\Component\HttpFoundation\Response;

class SetUserLocale
{
    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $locale = LanguageOptions::normalize(
            $request->user()?->locale
                ?? $request->session()->get('locale')
                ?? LanguageOptions::default()
        );

        App::setLocale($locale);
        $request->session()->put('locale', $locale);

        return $next($request);
    }
}
