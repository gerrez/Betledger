<?php

namespace App\Livewire\Bookmakers;

use App\Domain\Currency;
use App\Domain\ExchangeRate;
use App\Models\Bookmaker;
use App\Models\User;
use Closure;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Title('Bookmakers')]
class Index extends Component
{
    public bool $showForm = false;

    public ?int $editingId = null;

    public string $name = '';

    public string $currency = '';

    public string $exchange_rate = '';

    /**
     * Open an empty form for a new bookmaker.
     */
    public function create(): void
    {
        $this->resetValidation();
        $this->editingId = null;
        $this->name = '';
        $this->currency = $this->user()->base_currency->value;
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
        $this->exchange_rate = $bookmaker->formattedExchangeRate();
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
                function (string $attribute, mixed $value, Closure $fail): void {
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
    }

    /**
     * Hide a bookmaker from the bet form; its bets and history stay.
     */
    public function deactivate(int $id): void
    {
        $this->findBookmaker($id)->update(['is_active' => false]);
    }

    public function reactivate(int $id): void
    {
        $this->findBookmaker($id)->update(['is_active' => true]);
    }

    public function render(): View
    {
        $user = $this->user();

        return view('livewire.bookmakers.index', [
            'bookmakers' => $user->bookmakers()->orderByDesc('is_active')->orderBy('name')->get(),
            'baseCurrency' => $user->base_currency,
            'currencies' => Currency::cases(),
        ]);
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
        return $this->currency === $this->user()->base_currency->value;
    }

    private function user(): User
    {
        $user = Auth::user();

        abort_unless($user instanceof User, 403);

        return $user;
    }
}
