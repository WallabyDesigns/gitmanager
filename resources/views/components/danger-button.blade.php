<button {{ $attributes->merge(['type' => 'submit', 'class' => 'gwm-btn gwm-btn-danger']) }}>
    {{ $slot }}
</button>
