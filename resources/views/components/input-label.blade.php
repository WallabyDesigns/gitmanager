@props(['value'])

<label {{ $attributes->merge(['class' => 'gwm-label']) }}>
    {{ $value ?? $slot }}
</label>
