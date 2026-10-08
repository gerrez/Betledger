<div class="flex items-start max-md:flex-col">
    <div class="mr-10 w-full pb-4 md:w-[220px]">
        <flux:navlist>
            <flux:navlist.item href="{{ route('settings.profile') }}" wire:navigate>Profile</flux:navlist.item>
            <flux:navlist.item href="{{ route('settings.currency') }}" wire:navigate>Currency</flux:navlist.item>
            <flux:navlist.item href="{{ route('settings.password') }}" wire:navigate>Password</flux:navlist.item>
            <flux:navlist.item href="{{ route('settings.appearance') }}" wire:navigate>Appearance</flux:navlist.item>
        </flux:navlist>

        {{-- On desktop these live in the sidebar; phones reach them from Settings. --}}
        <flux:navlist class="mt-4 lg:hidden">
            <flux:navlist.item href="{{ route('bookmakers.index') }}" wire:navigate>Bookmakers</flux:navlist.item>
            <flux:navlist.item href="{{ route('lists.index') }}" wire:navigate>Lists</flux:navlist.item>

            <form method="POST" action="{{ route('logout') }}" class="w-full">
                @csrf
                <flux:navlist.item as="button" type="submit">{{ __('Log Out') }}</flux:navlist.item>
            </form>
        </flux:navlist>
    </div>

    <flux:separator class="md:hidden" />

    <div class="flex-1 self-stretch max-md:pt-6">
        <flux:heading>{{ $heading ?? '' }}</flux:heading>
        <flux:subheading>{{ $subheading ?? '' }}</flux:subheading>

        <div class="mt-5 w-full max-w-lg">
            {{ $slot }}
        </div>
    </div>
</div>
