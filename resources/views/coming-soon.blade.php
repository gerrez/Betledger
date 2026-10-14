<x-layouts.app :title="$title">
    <x-page-heading :title="$title" />

    @if (session('status'))
        <flux:callout variant="success" icon="check-circle" class="mb-4" :heading="session('status')" />
    @endif

    <section class="rounded-[20px] border border-line bg-surface p-5">
        <p class="text-[15px] font-medium">Coming soon</p>
        <p class="mt-1 text-sm text-muted">{{ $description }}</p>
    </section>
</x-layouts.app>
