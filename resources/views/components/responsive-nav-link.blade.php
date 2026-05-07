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
$classes = ($active ?? false)
            ? 'block w-full ps-3 pe-4 py-2 border-l-4 border-indigo-400 text-base font-medium text-indigo-200 bg-indigo-500/10 focus:outline-none transition duration-150 ease-in-out'
            : 'block w-full ps-3 pe-4 py-2 border-l-4 border-transparent text-base font-medium hover:border-slate-600 text-slate-300 hover:text-slate-100 hover:bg-slate-900 focus:outline-none transition duration-150 ease-in-out';
@endphp

<a data-rnavlink="true" {{ $attributes->merge(['class' => $classes]) }}>
    {{ $slot }}
</a>
