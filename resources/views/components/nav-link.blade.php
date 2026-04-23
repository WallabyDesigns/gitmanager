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
            ? 'inline-flex items-center px-1 pt-1 border-b-2 border-indigo-400 text-sm font-medium leading-5 text-slate-900 dark:text-slate-100 focus:outline-none focus:border-indigo-700 transition duration-150 ease-in-out'
            : 'inline-flex items-center px-1 pt-1 border-b-2 border-transparent text-sm font-medium leading-5 text-slate-500 hover:text-slate-700 hover:border-slate-300 dark:text-slate-300 dark:hover:text-slate-100 dark:hover:border-slate-600 focus:outline-none transition duration-150 ease-in-out';
@endphp

<a @if ($shouldNavigate) wire:navigate.hover @endif {{ $attributes->merge(['class' => $classes]) }}>
    {{ $slot }}
</a>
