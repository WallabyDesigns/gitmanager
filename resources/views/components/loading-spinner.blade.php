@props([
    'target' => null,
    'size' => 'w-4 h-4',
    'class' => '',
])

<span
    class="gwm-spinner-wrap {{ $class }}"
    @if ($target)
        wire:loading.class="gwm-spinner-visible"
        wire:target="{{ $target }}"
    @else
        wire:loading.class="gwm-spinner-visible"
    @endif
>
    <img src="{{ asset('images/loading.svg') }}" alt="Loading" class="gwm-spinner {{ $size }}" />
</span>
