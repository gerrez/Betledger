<?php

use App\Domain\Currency;
use App\Domain\Decimal;
use App\Domain\ExchangeRate;
use App\Models\Bookmaker;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;

new #[Title('Bookmakers')] class extends Component {
    public bool $showForm = false;

    public ?int $editingId = null;

    public string $name = '';

    public string $currency = '';

    public string $exchange_rate = '';

    /**
     * The user's bookmakers, active ones first.
     *
     * @return Collection<int, Bookmaker>
     */
    #[Computed]
    public function bookmakers(): Collection
    {
        return $this->user()->bookmakers()
            ->orderByDesc('is_active')
            ->orderBy('name')
            ->get();
    }

    #[Computed]
    public function baseCurrency(): Currency
    {
        return $this->user()->base_currency;
    }

    /**
     * Open an empty form for a new bookmaker.
     */
    public function create(): void
    {
        $this->resetValidation();
        $this->editingId = null;
        $this->name = '';
        $this->currency = $this->baseCurrency->value;
        $this->exchange_rate = ExchangeRate::SAME_CURRENCY;
        $this->showForm = true;
    }

    /**
     * Open the form for one of the user's bookmakers.
     */
    public function edit(int $id): void
    {
        $bookmaker = $this->findBookmaker($id);

        $this->resetValidation();
        $this->editingId = $bookmaker->id;
        $this->name = $bookmaker->name;
        $this->currency = $bookmaker->currency->value;
        $this->exchange_rate = Decimal::normalize($bookmaker->exchange_rate);
        $this->showForm = true;
    }

    /**
     * A bookmaker in the base currency always has a rate of 1; switching currency
     * clears a rate that was meant for another currency.
     */
    public function updatedCurrency(): void
    {
        $this->exchange_rate = $this->isBaseCurrency() ? ExchangeRate::SAME_CURRENCY : '';
    }

    /**
     * Create the bookmaker, or update the one being edited.
     */
    public function save(): void
    {
        $this->name = trim($this->name);
        $this->exchange_rate = trim($this->exchange_rate);

        if ($this->isBaseCurrency()) {
            $this->exchange_rate = ExchangeRate::SAME_CURRENCY;
        }

        $validated = $this->validate([
            'name' => [
                'required',
                'string',
                'max:255',
                Rule::unique('bookmakers', 'name')
                    ->where('user_id', $this->user()->id)
                    ->ignore($this->editingId),
            ],
            'currency' => ['required', Rule::enum(Currency::class)],
            'exchange_rate' => [
                'required',
                'string',
                function (string $attribute, mixed $value, \Closure $fail): void {
                    if (! is_string($value) || ! ExchangeRate::isValid($value)) {
                        $fail('Enter the rate as a number above 0, like 7.46 (up to 8 decimals).');
                    }
                },
            ],
        ], [
            'name.unique' => 'You already have a bookmaker with this name.',
        ]);

        if ($this->editingId === null) {
            $this->user()->bookmakers()->create($validated);
        } else {
            $this->findBookmaker($this->editingId)->update($validated);
        }

        $this->showForm = false;
        $this->editingId = null;
        unset($this->bookmakers);
    }

    /**
     * Hide a bookmaker from the bet form; its bets and history stay.
     */
    public function deactivate(int $id): void
    {
        $this->findBookmaker($id)->update(['is_active' => false]);
        unset($this->bookmakers);
    }

    public function reactivate(int $id): void
    {
        $this->findBookmaker($id)->update(['is_active' => true]);
        unset($this->bookmakers);
    }

    /**
     * One of the current user's bookmakers; another user's is "not found".
     */
    private function findBookmaker(int $id): Bookmaker
    {
        $bookmaker = $this->user()->bookmakers()->findOrFail($id);

        $this->authorize('update', $bookmaker);

        return $bookmaker;
    }

    private function isBaseCurrency(): bool
    {
        return $this->currency === $this->baseCurrency->value;
    }

    private function user(): User
    {
        $user = Auth::user();

        abort_unless($user instanceof User, 403);

        return $user;
    }
}; ?>

<div>
    <div class="mb-5 flex items-start justify-between gap-4">
        <x-page-heading title="Bookmakers" description="The bookmakers you bet with, their currency and exchange rate." class="mb-0" />

        <flux:button variant="primary" icon="plus" wire:click="create" class="shrink-0">Add</flux:button>
    </div>

    @if ($this->bookmakers->isEmpty())
        <section class="rounded-[20px] border border-line bg-surface p-5">
            <p class="text-[15px] font-medium">No bookmakers yet</p>
            <p class="mt-1 text-sm text-muted">Add the bookmakers you bet with. Every bet you record is placed at one of them.</p>
        </section>
    @else
        <ul class="divide-y divide-line overflow-hidden rounded-[20px] border border-line bg-surface">
            @foreach ($this->bookmakers as $bookmaker)
                <li wire:key="bookmaker-{{ $bookmaker->id }}" class="flex flex-wrap items-center gap-x-4 gap-y-2 px-5 py-4">
                    <div class="min-w-0 flex-1">
                        <div class="flex items-center gap-2 text-[15px] font-medium">
                            <span class="truncate @if (! $bookmaker->is_active) text-muted @endif">{{ $bookmaker->name }}</span>

                            @unless ($bookmaker->is_active)
                                <flux:badge size="sm">Inactive</flux:badge>
                            @endunless
                        </div>

                        <p class="mt-0.5 text-sm text-muted tabular-nums">
                            @if ($bookmaker->currency === $this->baseCurrency)
                                {{ $bookmaker->currency->value }} · base currency
                            @else
                                1 {{ $bookmaker->currency->value }} = {{ Decimal::normalize($bookmaker->exchange_rate) }} {{ $this->baseCurrency->value }}
                            @endif
                        </p>
                    </div>

                    <div class="flex items-center gap-2">
                        <flux:button size="sm" wire:click="edit({{ $bookmaker->id }})">Edit</flux:button>

                        @if ($bookmaker->is_active)
                            <flux:button size="sm" variant="ghost" wire:click="deactivate({{ $bookmaker->id }})">Deactivate</flux:button>
                        @else
                            <flux:button size="sm" variant="ghost" wire:click="reactivate({{ $bookmaker->id }})">Reactivate</flux:button>
                        @endif
                    </div>
                </li>
            @endforeach
        </ul>

        <p class="mt-3 text-sm text-muted">Inactive bookmakers are hidden when you record a bet, but their bets stay in your history and statistics.</p>
    @endif

    <flux:modal wire:model.self="showForm" class="w-full md:w-[28rem]">
        <form wire:submit="save" class="space-y-6">
            <flux:heading size="lg">{{ $editingId === null ? 'Add bookmaker' : 'Edit bookmaker' }}</flux:heading>

            <flux:input wire:model="name" label="Name" required autocomplete="off" />

            <flux:select wire:model.live="currency" label="Currency">
                @foreach (Currency::cases() as $option)
                    <flux:select.option :value="$option->value">{{ $option->label() }}</flux:select.option>
                @endforeach
            </flux:select>

            @if ($currency === $this->baseCurrency->value)
                <flux:input
                    label="Exchange rate"
                    value="1"
                    disabled
                    description="This is your base currency, so the rate is always 1."
                />
            @else
                <flux:input
                    wire:model="exchange_rate"
                    label="Exchange rate"
                    inputmode="decimal"
                    autocomplete="off"
                    required
                    :description="'How many '.$this->baseCurrency->value.' you get for 1 '.$currency.'. Bets copy the rate when you save them, so changing it later does not rewrite old bets.'"
                />
            @endif

            <div class="flex justify-end gap-2">
                <flux:modal.close>
                    <flux:button variant="ghost">Cancel</flux:button>
                </flux:modal.close>

                <flux:button type="submit" variant="primary">Save</flux:button>
            </div>
        </form>
    </flux:modal>
</div>
