<?php

namespace Tests\Feature;

use Tests\TestCase;

class SelectComponentTest extends TestCase
{
    public function test_it_renders_a_combobox_with_its_options_and_groups(): void
    {
        $view = $this->blade(<<<'BLADE'
            <x-select variant="pill" wire:model.live="team" label="Team">
                <x-select.option value="">All teams</x-select.option>
                <x-select.group heading="Football">
                    <x-select.option value="7">Arsenal</x-select.option>
                </x-select.group>
            </x-select>
            BLADE);

        $view->assertSeeInOrder([
            'x-modelable="value"',
            'wire:model.live="team"',
            'id="select-team"',
            'role="combobox"',
            'aria-controls="select-team-listbox"',
            'aria-label="Team"',
            'id="select-team-listbox"',
            'role="listbox"',
            'popover="manual"',
            'id="select-team-option-none"',
            'data-value=""',
            'All teams',
            'role="group" aria-label="Football"',
            'id="select-team-option-7"',
            'data-value="7"',
            'Arsenal',
        ], escape: false);
    }

    public function test_a_field_shows_its_label_and_validation_error(): void
    {
        $view = $this->withViewErrors(['currency' => 'Choose a supported currency.'])->blade(<<<'BLADE'
            <x-select wire:model="currency" label="Currency">
                <x-select.option value="EUR">Euro</x-select.option>
            </x-select>
            BLADE);

        $view->assertSeeInOrder(['id="select-currency-label"', 'Currency', 'aria-labelledby="select-currency-label"', 'aria-invalid="true"'], escape: false)
            ->assertSee('Choose a supported currency.');
    }

    public function test_it_can_be_disabled(): void
    {
        $this->withViewErrors([]);
        $disabledAttribute = '/role="combobox"[^>]*\sdisabled\s/';

        $this->assertMatchesRegularExpression(
            $disabledAttribute,
            (string) $this->blade('<x-select wire:model="currency" label="Currency" :disabled="true"></x-select>'),
        );
        $this->assertDoesNotMatchRegularExpression(
            $disabledAttribute,
            (string) $this->blade('<x-select wire:model="currency" label="Currency"></x-select>'),
        );
    }
}
