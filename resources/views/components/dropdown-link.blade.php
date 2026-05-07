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

<a {{ $attributes->merge(['class' => 'block w-full px-4 py-2 text-sm leading-5 text-slate-200 hover:bg-slate-800 focus:outline-none transition duration-150 ease-in-out']) }}>
    {{ $slot }}
</a>
