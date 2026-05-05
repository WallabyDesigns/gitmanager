<?php

namespace App\Http\Controllers;

use App\Support\LanguageOptions;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class LanguageController extends Controller
{
    public function update(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'locale' => ['required', 'string', Rule::in(LanguageOptions::codes())],
        ]);

        $locale = LanguageOptions::normalize($validated['locale']);

        $request->session()->put('locale', $locale);
        app()->setLocale($locale);

        if ($request->user()) {
            $request->user()->forceFill(['locale' => $locale])->save();
        }

        return back()->with('gwm_flash', [
            'type' => 'success',
            'message' => 'Language preference saved.',
        ]);
    }
}
