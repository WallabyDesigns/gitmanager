<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class ProjectDirectoryPath implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if ($value === null) {
            return;
        }

        $normalized = trim(str_replace('\\', '/', (string) $value));
        if ($normalized === '') {
            return;
        }

        $normalized = trim($normalized, '/');
        $segments = explode('/', $normalized);

        foreach ($segments as $segment) {
            $segment = trim($segment);

            if ($segment === '') {
                $fail('Directory paths cannot include empty folder names.');
                return;
            }

            if ($segment === '.' || $segment === '..') {
                $fail('Directory paths cannot use "." or "..".');
                return;
            }

            if (! preg_match('/^[A-Za-z0-9 _.\-]+$/', $segment)) {
                $fail('Directory paths may only contain letters, numbers, spaces, dashes, underscores, periods, and slashes.');
                return;
            }
        }
    }
}
