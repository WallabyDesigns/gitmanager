@props(['active'])

@php
$href = (string) ($attributes->get('href') ?? '');
$parsedHref = $href !== '' ? parse_url($href) : false;
$scheme = is_array($parsedHref) ? strtolower((string) ($parsedHref['scheme'] ?? '')) : '';
$host = is_array($parsedHref) ? strtolower((string) ($parsedHref['host'] ?? '')) : '';
$currentHost = strtolower((string) (request()?->getHost() ?: parse_url((string) config('app.url'), PHP_URL_HOST)));
$hasExternalScheme = in_array($scheme, ['mailto', 'tel'], true);
$isAbsoluteHttpLink = in_array($scheme, ['http', 'https'], true);
$isInternalAbsoluteLink = $isAbsoluteHttpLink && $host !== '' && $host === $currentHost;
$shouldNavigate = ! $attributes->has('target')
    && ! $attributes->has('download')
    && $href !== ''
    && ! \Illuminate\Support\Str::startsWith($href, ['#'])
    && ! $hasExternalScheme
    && (! $isAbsoluteHttpLink || $isInternalAbsoluteLink);
$classes = ($active ?? false)
            ? 'block w-full ps-3 pe-4 py-2 border-l-4 border-indigo-400 text-start text-base font-medium text-indigo-700 bg-indigo-50 dark:text-indigo-200 dark:bg-indigo-500/10 focus:outline-none transition duration-150 ease-in-out'
            : 'block w-full ps-3 pe-4 py-2 border-l-4 border-transparent text-start text-base font-medium text-slate-600 hover:text-slate-800 hover:bg-slate-50 hover:border-slate-300 dark:text-slate-300 dark:hover:text-slate-100 dark:hover:bg-slate-900 focus:outline-none transition duration-150 ease-in-out';
@endphp

<a @if ($shouldNavigate) wire:navigate.hover @endif data-rnavlink="true" {{ $attributes->merge(['class' => $classes]) }}>
    {{ $slot }}
</a>
