<?php

namespace App\Livewire\Settings;

use App\Domain\Currency;
use App\Domain\LocalTime;
use Closure;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Settings → Currency and time zone: two forms, each with its own Save button. The base
 * currency is locked once the user has bets; the time zone can always change.
 */
#[Title('Currency and time zone')]
class BaseCurrency extends Component
{
    public string $base_currency = '';

    public string $timezone = '';

    /**
     * Mount the component.
     */
    public function mount(): void
    {
        $user = Auth::user();

        $this->base_currency = $user->base_currency->value;
        $this->timezone = $user->timezone;
    }

    /**
     * Change the currency that statistics are shown in.
     */
    public function updateBaseCurrency(): void
    {
        $user = Auth::user();

        $validated = $this->validate([
            'base_currency' => [
                'required',
                Rule::enum(Currency::class),
                function (string $attribute, string $value, Closure $fail) use ($user): void {
                    if ($value !== $user->base_currency->value && ! $user->canChangeBaseCurrency()) {
                        $fail('The base currency can’t be changed once you have recorded bets.');
                    }
                },
            ],
        ]);

        $user->changeBaseCurrency(Currency::from($validated['base_currency']));

        $this->dispatch('base-currency-updated');
    }

    /**
     * Change the time zone that times are entered, shown and grouped into days in.
     * Stored times are instants and don't move.
     */
    public function updateTimezone(): void
    {
        $validated = $this->validate([
            'timezone' => ['required', 'string', 'timezone:all'],
        ], [
            'timezone.required' => 'Choose a time zone.',
            'timezone.timezone' => 'Choose a time zone from the list.',
        ]);

        $user = Auth::user();
        $user->timezone = $validated['timezone'];
        $user->save();

        $this->dispatch('timezone-updated');
    }

    public function render(): View
    {
        return view('livewire.settings.base-currency', [
            'currencies' => Currency::cases(),
            'canChange' => Auth::user()->canChangeBaseCurrency(),
            'timezones' => array_map(fn (string $timezone): array => ['value' => $timezone, 'label' => LocalTime::label($timezone)], LocalTime::identifiers()),
        ]);
    }
}
