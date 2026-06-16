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
    ? 'gwm-top-nav-link gwm-top-nav-link-active'
    : 'gwm-top-nav-link gwm-top-nav-link-idle';
@endphp

<a data-navlink="true" {{ $attributes->merge(['class' => $classes]) }}>
    {{ $slot }}
</a>
