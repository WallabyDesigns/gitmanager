@props(['disabled' => false])

<input @disabled($disabled) {{ $attributes->merge(['class' => 'gwm-field']) }}>
