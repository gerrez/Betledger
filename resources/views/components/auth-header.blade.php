@props([
    'title',
    'description',
])

<div class="flex w-full flex-col gap-2 text-center">
    <h1 class="text-xl font-semibold">{{ $title }}</h1>
    <p class="text-center text-sm text-muted">{{ $description }}</p>
</div>
