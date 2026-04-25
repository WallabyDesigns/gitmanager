@php
    $href = (string) ($attributes->get('href') ?? '');
    $parsedHref = $href !== '' ? parse_url($href) : false;
    $scheme = is_array($parsedHref) ? strtolower((string) ($parsedHref['scheme'] ?? '')) : '';
    $host = is_array($parsedHref) ? strtolower((string) ($parsedHref['host'] ?? '')) : '';
    $currentHost = strtolower((string) (request()?->getHost() ?: parse_url((string) config('app.url'), PHP_URL_HOST)));
    $hasExternalScheme = in_array($scheme, ['mailto', 'tel'], true);
    $isAbsoluteHttpLink = in_array($scheme, ['http', 'https'], true);
    $isInternalAbsoluteLink = $isAbsoluteHttpLink && $host !== '' && $host === $currentHost;
    // $shouldNavigate = ! $attributes->has('target')
    //     && ! $attributes->has('download')
    //     && $href !== ''
    //     && ! \Illuminate\Support\Str::startsWith($href, ['#'])
    //     && ! $hasExternalScheme
    //     && (! $isAbsoluteHttpLink || $isInternalAbsoluteLink);
@endphp

<a 
{{-- @if ($shouldNavigate) wire:navigate.hover @endif  --}}
{{ $attributes->merge(['class' => 'block w-full px-4 py-2 text-start text-sm leading-5 text-slate-700 hover:bg-slate-100 dark:text-slate-200 dark:hover:bg-slate-800 focus:outline-none transition duration-150 ease-in-out']) }}>{{ $slot }}</a>
