<?php

namespace Tests\Feature\Bankroll;

use App\Domain\Bankroll\Bankroll;
use App\Domain\Currency;
use App\Domain\TransactionType;
use App\Livewire\Bankroll\Index;
use App\Models\BankrollTransaction;
use App\Models\Bookmaker;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ManageTransactionsTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Bookmaker $bookmaker;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-11-23 12:00');
        $this->user = User::factory()->create();
        $this->bookmaker = Bookmaker::factory()->for($this->user)->inCurrency(Currency::Gbp, '1.17')->create(['name' => 'Bet365']);
        $this->actingAs($this->user);
    }

    public function test_a_deposit_and_a_withdrawal_can_be_added_and_the_balance_follows(): void
    {
        $component = Livewire::test(Index::class)
            ->call('addTransaction', $this->bookmaker->id, 'deposit')
            ->assertSet('bookmaker_id', (string) $this->bookmaker->id)
            ->assertSet('type', 'deposit')
            ->assertSet('occurred_on', '2026-11-23')
            ->set('amount', '150')
            ->set('note', '  Welcome bonus  ')
            ->call('save')
            ->assertHasNoErrors()
            ->assertSet('showForm', false)
            ->assertSet('status', 'Deposit added at Bet365.')
            ->assertSet('bookmaker', (string) $this->bookmaker->id);

        $this->assertSame(15000, $this->balance($component));

        $component->call('addTransaction', $this->bookmaker->id, 'withdrawal')
            ->set('amount', '40,25')
            ->set('occurred_on', '2026-11-20')
            ->call('save')
            ->assertHasNoErrors()
            ->assertSet('status', 'Withdrawal added at Bet365.');

        $this->assertSame(10975, $this->balance($component));

        $deposit = $this->user->bankrollTransactions()->where('type', TransactionType::Deposit)->sole();
        $this->assertSame(15000, $deposit->amount);
        $this->assertSame(Currency::Gbp, $deposit->currency);
        $this->assertSame('2026-11-23', $deposit->occurred_on->toDateString());
        $this->assertSame('Welcome bonus', $deposit->note);

        $withdrawal = $this->user->bankrollTransactions()->where('type', TransactionType::Withdrawal)->sole();
        $this->assertSame(4025, $withdrawal->amount);
        $this->assertSame('2026-11-20', $withdrawal->occurred_on->toDateString());
        $this->assertNull($withdrawal->note);
    }

    public function test_a_transaction_can_be_edited_and_the_balance_follows(): void
    {
        $transaction = BankrollTransaction::factory()->forBookmaker($this->bookmaker)->deposit(10000)->create(['occurred_on' => '2026-11-01', 'note' => 'Card']);

        $component = Livewire::test(Index::class)
            ->call('editTransaction', $transaction->id)
            ->assertSet('editingId', $transaction->id)
            ->assertSet('type', 'deposit')
            ->assertSet('amount', '100.00')
            ->assertSet('occurred_on', '2026-11-01')
            ->assertSet('note', 'Card')
            ->set('type', 'withdrawal')
            ->set('amount', '25.5')
            ->set('note', '')
            ->call('save')
            ->assertHasNoErrors()
            ->assertSet('status', 'Withdrawal saved.');

        $this->assertSame(-2550, $this->balance($component));

        $transaction->refresh();
        $this->assertSame(TransactionType::Withdrawal, $transaction->type);
        $this->assertSame(2550, $transaction->amount);
        $this->assertNull($transaction->note);
        $this->assertSame($this->bookmaker->id, $transaction->bookmaker_id);
    }

    public function test_a_transaction_can_be_deleted_and_the_balance_follows(): void
    {
        BankrollTransaction::factory()->forBookmaker($this->bookmaker)->deposit(10000)->create();
        $withdrawal = BankrollTransaction::factory()->forBookmaker($this->bookmaker)->withdrawal(3000)->create();

        $component = Livewire::test(Index::class);
        $this->assertSame(7000, $this->balance($component));

        $component->call('deleteTransaction', $withdrawal->id)->assertSet('status', 'Withdrawal deleted.');

        $this->assertModelMissing($withdrawal);
        $this->assertSame(10000, $this->balance($component));
    }

    public function test_delete_asks_for_confirmation_with_the_shared_dialog(): void
    {
        BankrollTransaction::factory()->forBookmaker($this->bookmaker)->deposit()->create();

        $this->get(route('bankroll.index', ['bookmaker' => $this->bookmaker->id]))
            ->assertOk()
            ->assertSee('data-confirm-title="Delete this deposit?"', escape: false)
            ->assertDontSee('wire:confirm', escape: false);
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function invalidAmounts(): array
    {
        return [
            'missing' => ['', 'required'],
            'zero' => ['0', 'Enter the amount as a number above 0'],
            'negative' => ['-5', 'Enter the amount as a number above 0'],
            'too large' => ['1000000000', 'Enter the amount as a number above 0'],
            'three decimals' => ['10.555', 'Enter the amount as a number above 0'],
            'not a number' => ['ten', 'Enter the amount as a number above 0'],
        ];
    }

    #[DataProvider('invalidAmounts')]
    public function test_the_amount_must_be_valid(string $amount, string $message): void
    {
        Livewire::test(Index::class)
            ->call('addTransaction', $this->bookmaker->id, 'deposit')
            ->set('amount', $amount)
            ->call('save')
            ->assertHasErrors('amount')
            ->assertSee($message);

        $this->assertDatabaseEmpty('bankroll_transactions');
    }

    public function test_the_largest_amount_is_accepted(): void
    {
        Livewire::test(Index::class)
            ->call('addTransaction', $this->bookmaker->id, 'deposit')
            ->set('amount', '999999999,99')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame(99999999999, $this->user->bankrollTransactions()->sole()->amount);
    }

    public function test_the_date_cannot_be_in_the_future_or_missing(): void
    {
        Livewire::test(Index::class)
            ->call('addTransaction', $this->bookmaker->id, 'deposit')
            ->set('amount', '10')
            ->set('occurred_on', '2026-11-24')
            ->call('save')
            ->assertHasErrors(['occurred_on' => 'before_or_equal'])
            ->assertSee('The date can’t be in the future.')
            ->set('occurred_on', '')
            ->call('save')
            ->assertHasErrors(['occurred_on' => 'required'])
            ->set('occurred_on', '23/11/2026')
            ->call('save')
            ->assertHasErrors(['occurred_on' => 'date_format']);

        $this->assertDatabaseEmpty('bankroll_transactions');
    }

    public function test_the_type_is_required_and_must_be_known(): void
    {
        Livewire::test(Index::class)
            ->call('addTransaction', $this->bookmaker->id, 'bonus')
            ->assertSet('type', '')
            ->set('amount', '10')
            ->call('save')
            ->assertHasErrors(['type' => 'required'])
            ->set('type', 'bonus')
            ->call('save')
            ->assertHasErrors('type');

        $this->assertDatabaseEmpty('bankroll_transactions');
    }

    public function test_the_note_is_limited_to_255_characters(): void
    {
        Livewire::test(Index::class)
            ->call('addTransaction', $this->bookmaker->id, 'deposit')
            ->set('amount', '10')
            ->set('note', str_repeat('a', 256))
            ->call('save')
            ->assertHasErrors(['note' => 'max']);

        $this->assertDatabaseEmpty('bankroll_transactions');
    }

    public function test_new_transactions_are_refused_at_an_inactive_bookmaker(): void
    {
        $inactive = Bookmaker::factory()->for($this->user)->inactive()->create();

        Livewire::test(Index::class)
            ->call('addTransaction', $inactive->id, 'deposit')
            ->set('amount', '10')
            ->call('save')
            ->assertHasErrors('bookmaker_id')
            ->assertSee('Choose one of your active bookmakers.');

        $this->assertDatabaseEmpty('bankroll_transactions');
    }

    public function test_an_existing_transaction_stays_editable_after_its_bookmaker_is_deactivated(): void
    {
        $transaction = BankrollTransaction::factory()->forBookmaker($this->bookmaker)->deposit(10000)->create();
        $this->bookmaker->update(['is_active' => false]);

        Livewire::test(Index::class)
            ->assertDontSee('Add deposit')
            ->call('editTransaction', $transaction->id)
            ->set('amount', '120')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame(12000, $transaction->refresh()->amount);
    }

    public function test_deleting_the_account_removes_its_transactions(): void
    {
        $transaction = BankrollTransaction::factory()->forBookmaker($this->bookmaker)->deposit()->create();
        $someoneElses = BankrollTransaction::factory()->deposit()->create();

        $this->user->delete();

        $this->assertModelMissing($transaction);
        $this->assertModelExists($someoneElses);
    }

    /**
     * The balance of the only bookmaker, in minor units of its currency.
     */
    private function balance(Testable $component): int
    {
        $bankroll = $component->viewData('bankroll');
        $this->assertInstanceOf(Bankroll::class, $bankroll);

        return $bankroll->rows[0]->figures->balance;
    }
}
