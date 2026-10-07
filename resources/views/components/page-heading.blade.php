@props([
    'title',
    'description' => null,
])

<div {{ $attributes->class('mb-5 flex flex-col gap-1') }}>
    <h1 class="text-[26px] font-semibold tracking-tight lg:text-[30px]">{{ $title }}</h1>

    @if ($description)
        <p class="text-sm text-muted">{{ $description }}</p>
    @endif
</div>
