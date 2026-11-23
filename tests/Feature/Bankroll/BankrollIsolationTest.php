<?php

namespace Tests\Feature\Bankroll;

use App\Domain\TransactionType;
use App\Livewire\Bankroll\Index;
use App\Models\BankrollTransaction;
use App\Models\Bet;
use App\Models\Bookmaker;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class BankrollIsolationTest extends TestCase
{
    use RefreshDatabase;

    private User $alice;

    private Bookmaker $alicesBookmaker;

    private BankrollTransaction $alicesTransaction;

    private User $bob;

    protected function setUp(): void
    {
        parent::setUp();

        $this->alice = User::factory()->create();
        $this->alicesBookmaker = Bookmaker::factory()->for($this->alice)->create(['name' => 'Alices Book']);
        $this->alicesTransaction = BankrollTransaction::factory()->forBookmaker($this->alicesBookmaker)->deposit(123456)->create(['note' => 'Alices note']);
        Bet::factory()->forBookmaker($this->alicesBookmaker)->single()->create(['stake' => 4321]);

        $this->bob = User::factory()->create();
        Bookmaker::factory()->for($this->bob)->create(['name' => 'Bobs Book']);
        $this->actingAs($this->bob);
    }

    public function test_another_users_bookmakers_transactions_and_figures_are_not_shown(): void
    {
        $this->get(route('bankroll.index', ['bookmaker' => $this->alicesBookmaker->id]))
            ->assertOk()
            ->assertSee('Bobs Book')
            ->assertDontSee('Alices Book')
            ->assertDontSee('Alices note')
            ->assertDontSee('1,234.56')
            ->assertDontSee('43.21')
            ->assertSeeInOrder(['Total balance · EUR', '€0.00']);
    }

    public function test_another_users_transaction_cannot_be_edited_by_id(): void
    {
        Livewire::test(Index::class)
            ->call('editTransaction', $this->alicesTransaction->id)
            ->assertNotFound();
    }

    public function test_another_users_transaction_cannot_be_saved_by_id(): void
    {
        Livewire::test(Index::class)
            ->set('editingId', $this->alicesTransaction->id)
            ->set('type', 'withdrawal')
            ->set('amount', '1')
            ->set('occurred_on', now()->toDateString())
            ->call('save')
            ->assertNotFound();

        $this->assertSame(TransactionType::Deposit, $this->alicesTransaction->refresh()->type);
    }

    public function test_another_users_transaction_cannot_be_deleted_by_id(): void
    {
        Livewire::test(Index::class)
            ->call('deleteTransaction', $this->alicesTransaction->id)
            ->assertNotFound();

        $this->assertModelExists($this->alicesTransaction);
    }

    public function test_a_transaction_cannot_be_added_to_another_users_bookmaker_by_id(): void
    {
        Livewire::test(Index::class)
            ->call('addTransaction', $this->alicesBookmaker->id, 'deposit')
            ->set('amount', '10')
            ->call('save')
            ->assertHasErrors('bookmaker_id');

        $this->assertSame(1, BankrollTransaction::query()->count());
    }

    public function test_the_database_refuses_a_transaction_at_another_users_bookmaker(): void
    {
        $this->expectConstraintViolation();

        $transaction = new BankrollTransaction([
            'bookmaker_id' => $this->alicesBookmaker->id,
            'type' => TransactionType::Deposit,
            'amount' => 1000,
            'currency' => $this->alicesBookmaker->currency,
            'occurred_on' => '2026-11-01',
        ]);
        $transaction->user()->associate($this->bob);
        $transaction->save();
    }
}
