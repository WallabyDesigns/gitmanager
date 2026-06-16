<button {{ $attributes->merge(['type' => 'submit', 'class' => 'gwm-btn gwm-btn-primary']) }}>
    {{ $slot }}
</button>
