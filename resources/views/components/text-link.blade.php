<a
    {{ $attributes->merge(['class' => 'underline text-sm decoration-accent-content/40 underline-offset-2 duration-300 ease-out hover:decoration-accent-content text-accent-content']) }}
    wire:navigate
>
    {{ $slot }}
</a>
