{{ $brand['name'] }}

{{ $heading }}
{{ $intro }}

@foreach ($items as $item)
{{ $loop->iteration }}. {{ $item['title'] }}
@foreach (($item['fields'] ?? []) as $label => $value)
@if ($value !== null && $value !== '')
   {{ \Illuminate\Support\Str::headline((string) $label) }}: {{ is_scalar($value) ? (string) $value : json_encode($value) }}
@endif
@endforeach
@if (! empty($item['error_log']))
   Error log:
{{ $item['error_log'] }}
@endif

@endforeach
@if ($actionUrl && $actionLabel)
{{ $actionLabel }}: {{ $actionUrl }}
@endif
@if ($showEnterpriseSuggestion)

{{ __('Enterprise adds automated vulnerability remediation, support, and advanced infrastructure controls: https://gitwebmanager.com') }}
@endif
