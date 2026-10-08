<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="dark">
    <head>
        @include('partials.head')
    </head>
    <body class="min-h-dvh bg-canvas text-ink antialiased">
        <div class="lg:flex">
            <aside class="hidden lg:sticky lg:top-0 lg:flex lg:h-dvh lg:w-60 lg:shrink-0 lg:flex-col lg:gap-5 lg:overflow-y-auto lg:px-4 lg:py-6">
                <a href="{{ route('dashboard') }}" class="flex items-center gap-2.5 px-2" wire:navigate>
                    <x-app-logo />
                </a>

                <nav aria-label="Main" class="flex flex-col gap-1">
                    <x-nav.sidebar-item :href="route('dashboard')" :current="request()->routeIs('dashboard')">Overview</x-nav.sidebar-item>
                    <x-nav.sidebar-item :href="route('bets.index')" :current="request()->routeIs('bets.index')">Bets</x-nav.sidebar-item>
                    <x-nav.sidebar-item :href="route('statistics')" :current="request()->routeIs('statistics')">Statistics</x-nav.sidebar-item>
                    <x-nav.sidebar-item :href="route('bookmakers.index')" :current="request()->routeIs('bookmakers.*')">Bookmakers</x-nav.sidebar-item>
                    <x-nav.sidebar-item :href="route('lists.index')" :current="request()->routeIs('lists.*')">Lists</x-nav.sidebar-item>
                    <x-nav.sidebar-item :href="route('settings.profile')" :current="request()->routeIs('settings.*')">Settings</x-nav.sidebar-item>
                </nav>

                <a
                    href="{{ route('bets.create') }}"
                    wire:navigate
                    @if (request()->routeIs('bets.create')) aria-current="page" @endif
                    class="flex h-11 items-center justify-center gap-1.5 rounded-xl bg-accent text-[15px] font-semibold text-accent-foreground hover:bg-accent/90 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent"
                >
                    <flux:icon.plus variant="micro" />
                    New bet
                </a>

                <div class="flex-1"></div>

                <flux:dropdown position="top" align="start">
                    <flux:profile
                        :name="auth()->user()->name"
                        :initials="auth()->user()->initials()"
                        icon-trailing="chevrons-up-down"
                    />

                    <flux:menu class="w-[220px]">
                        <flux:menu.radio.group>
                            <div class="p-0 text-sm font-normal">
                                <div class="flex items-center gap-2 px-1 py-1.5 text-left text-sm">
                                    <span class="relative flex h-8 w-8 shrink-0 overflow-hidden rounded-lg">
                                        <span class="flex h-full w-full items-center justify-center rounded-lg bg-accent-soft text-accent-soft-content">
                                            {{ auth()->user()->initials() }}
                                        </span>
                                    </span>

                                    <div class="grid flex-1 text-left text-sm leading-tight">
                                        <span class="truncate font-semibold">{{ auth()->user()->name }}</span>
                                        <span class="truncate text-xs">{{ auth()->user()->email }}</span>
                                    </div>
                                </div>
                            </div>
                        </flux:menu.radio.group>

                        <flux:menu.separator />

                        <flux:menu.radio.group>
                            <flux:menu.item :href="route('settings.profile')" icon="cog" wire:navigate>Settings</flux:menu.item>
                        </flux:menu.radio.group>

                        <flux:menu.separator />

                        <form method="POST" action="{{ route('logout') }}" class="w-full">
                            @csrf
                            <flux:menu.item as="button" type="submit" icon="arrow-right-start-on-rectangle" class="w-full">
                                {{ __('Log Out') }}
                            </flux:menu.item>
                        </form>
                    </flux:menu>
                </flux:dropdown>
            </aside>

            <main class="min-w-0 flex-1 px-5 pt-5 pb-32 lg:px-10 lg:pt-6 lg:pb-12">
                <div class="max-w-[1120px]">
                    {{ $slot }}
                </div>
            </main>
        </div>

        <nav
            aria-label="Main"
            class="fixed inset-x-0 bottom-0 z-10 flex items-center justify-between border-t border-line bg-surface px-4 pt-2 pb-[max(20px,env(safe-area-inset-bottom))] lg:hidden"
        >
            <x-nav.tab-item :href="route('dashboard')" icon="home" :current="request()->routeIs('dashboard')">Home</x-nav.tab-item>
            <x-nav.tab-item :href="route('bets.index')" icon="list-bullet" :current="request()->routeIs('bets.index')">Bets</x-nav.tab-item>

            <a
                href="{{ route('bets.create') }}"
                wire:navigate
                aria-label="New bet"
                @if (request()->routeIs('bets.create')) aria-current="page" @endif
                class="flex size-14 items-center justify-center rounded-[18px] bg-accent text-accent-foreground shadow-lg shadow-accent/35 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent"
            >
                <flux:icon.plus class="size-[26px]" />
            </a>

            <x-nav.tab-item :href="route('statistics')" icon="chart-bar" :current="request()->routeIs('statistics')">Stats</x-nav.tab-item>
            <x-nav.tab-item :href="route('settings.profile')" icon="cog-6-tooth" :current="request()->routeIs('settings.*', 'bookmakers.*', 'lists.*')">Settings</x-nav.tab-item>
        </nav>

        @fluxScripts
    </body>
</html>
