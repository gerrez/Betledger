<?php

namespace App\Livewire\Bankroll;

use App\Domain\Bankroll\BankrollQuery;
use App\Domain\Bankroll\BookmakerBankroll;
use App\Domain\Decimal;
use App\Domain\Money;
use App\Domain\TransactionType;
use App\Models\BankrollTransaction;
use App\Models\Bookmaker;
use App\Models\User;
use Closure;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * The bankroll: per bookmaker and in total, the bets, open stake, turnover, ROI, profit
 * and balance, plus the deposits and withdrawals the balances start from.
 */
#[Title('Bankroll')]
class Index extends Component
{
    /**
     * The id of the bookmaker whose transactions are shown; empty for none.
     */
    #[Url]
    public string $bookmaker = '';

    public bool $showForm = false;

    public ?int $editingId = null;

    public string $bookmaker_id = '';

    public string $type = '';

    public string $amount = '';

    public string $occurred_on = '';

    public string $note = '';

    /** The confirmation of the last change, shown until the next one. */
    public ?string $status = null;

    /**
     * Open an empty form for a deposit or withdrawal at one of the user's bookmakers.
     */
    public function addTransaction(int $bookmakerId, string $type): void
    {
        $this->resetValidation();
        $this->editingId = null;
        $this->bookmaker_id = (string) $bookmakerId;
        $this->type = TransactionType::tryFrom($type)->value ?? '';
        $this->amount = '';
        $this->occurred_on = $this->user()->localTime()->today()->toDateString();
        $this->note = '';
        $this->showForm = true;
    }

    /**
     * Open the form for one of the user's transactions.
     */
    public function editTransaction(int $id): void
    {
        $transaction = $this->findTransaction($id);

        $this->resetValidation();
        $this->editingId = $transaction->id;
        $this->bookmaker_id = (string) $transaction->bookmaker_id;
        $this->type = $transaction->type->value;
        $this->amount = Money::fromMinorUnits($transaction->amount);
        $this->occurred_on = $transaction->occurred_on->toDateString();
        $this->note = $transaction->note ?? '';
        $this->showForm = true;
    }

    /**
     * Add the transaction, or update the one being edited. A new transaction must be at
     * one of the user's active bookmakers; an existing one keeps its bookmaker.
     */
    public function save(): void
    {
        $user = $this->user();
        $editing = $this->editingId === null ? null : $this->findTransaction($this->editingId);

        $this->amount = Decimal::fromInput($this->amount);
        $this->note = trim($this->note);

        $validated = $this->validate([
            'bookmaker_id' => $editing !== null ? [] : [
                'required',
                function (string $attribute, mixed $value, Closure $fail) use ($user): void {
                    if (! is_string($value) || ! ctype_digit($value) || $this->activeBookmaker($user, (int) $value) === null) {
                        $fail('Choose one of your active bookmakers.');
                    }
                },
            ],
            'type' => ['required', Rule::enum(TransactionType::class)],
            'amount' => [
                'required',
                'string',
                function (string $attribute, mixed $value, Closure $fail): void {
                    if (! is_string($value) || ! Money::isValidStake($value)) {
                        $fail('Enter the amount as a number above 0, like 100 or 100.50.');
                    }
                },
            ],
            'occurred_on' => ['required', 'date_format:Y-m-d', 'before_or_equal:'.$user->localTime()->today()->toDateString()],
            'note' => ['nullable', 'string', 'max:255'],
        ], [
            'type.required' => 'Choose deposit or withdrawal.',
            'occurred_on.required' => 'Enter the date.',
            'occurred_on.date_format' => 'Enter the date as a calendar date.',
            'occurred_on.before_or_equal' => 'The date can’t be in the future.',
            'note.max' => 'Keep the note to 255 characters.',
        ]);

        $type = TransactionType::from($validated['type']);
        $values = [
            'type' => $type,
            'amount' => Money::toMinorUnits($validated['amount']),
            'occurred_on' => $validated['occurred_on'],
            'note' => $this->note === '' ? null : $this->note,
        ];

        if ($editing === null) {
            $bookmaker = $user->bookmakers()->findOrFail((int) $validated['bookmaker_id']);
            $this->authorize('create', [BankrollTransaction::class, $bookmaker]);

            $user->bankrollTransactions()->create([...$values, 'bookmaker_id' => $bookmaker->id, 'currency' => $bookmaker->currency]);
            $this->bookmaker = (string) $bookmaker->id;
            $this->status = "{$type->label()} added at {$bookmaker->name}.";
        } else {
            $editing->update($values);
            $this->status = "{$type->label()} saved.";
        }

        $this->showForm = false;
        $this->editingId = null;
    }

    public function deleteTransaction(int $id): void
    {
        $transaction = $this->findTransaction($id);
        $this->authorize('delete', $transaction);

        $transaction->delete();

        $this->status = "{$transaction->type->label()} deleted.";
    }

    /**
     * Show or hide a bookmaker's transactions.
     */
    public function toggleTransactions(int $bookmakerId): void
    {
        $this->bookmaker = $this->bookmaker === (string) $bookmakerId ? '' : (string) $bookmakerId;
    }

    public function render(BankrollQuery $query): View
    {
        $user = $this->user();
        $bankroll = $query->bankroll($user);
        $expanded = $this->expandedRow($bankroll->rows);

        return view('livewire.bankroll.index', [
            'bankroll' => $bankroll,
            'total' => FiguresDisplay::fromFigures($bankroll->total),
            'rows' => array_map(fn (BookmakerBankroll $row): array => [
                'row' => $row,
                'figures' => FiguresDisplay::fromFigures($row->figures),
                'deposited' => Money::format($row->deposits, $row->figures->currency),
                'withdrawn' => Money::format($row->withdrawals, $row->figures->currency),
                'isExpanded' => $row === $expanded,
            ], $bankroll->rows),
            'baseCurrency' => $user->base_currency,
            'transactions' => $expanded === null ? null : $this->transactionsAt($user, $expanded->bookmakerId),
            'activeBookmakers' => $user->bookmakers()->where('is_active', true)->orderBy('name')->get(),
            'editingBookmaker' => $this->editingId === null ? null : $user->bookmakers()->find((int) $this->bookmaker_id),
            'types' => TransactionType::cases(),
            'today' => $user->localTime()->today()->toDateString(),
        ]);
    }

    /**
     * The row whose transactions are shown, if the URL names a bookmaker that is listed.
     *
     * @param  list<BookmakerBankroll>  $rows
     */
    private function expandedRow(array $rows): ?BookmakerBankroll
    {
        foreach ($rows as $row) {
            if ((string) $row->bookmakerId === $this->bookmaker) {
                return $row;
            }
        }

        return null;
    }

    /**
     * A bookmaker's transactions as shown, newest first: deposits with "+", withdrawals
     * with "−".
     *
     * @return list<array{id: int, type: string, meta: string, amount: string}> `meta` is the date and the note, if any
     */
    private function transactionsAt(User $user, int $bookmakerId): array
    {
        return array_values($user->bankrollTransactions()
            ->where('bookmaker_id', $bookmakerId)
            ->orderByDesc('occurred_on')
            ->orderByDesc('id')
            ->get()
            ->map(fn (BankrollTransaction $transaction): array => [
                'id' => $transaction->id,
                'type' => $transaction->type->label(),
                'meta' => $transaction->occurred_on->format('j M Y').($transaction->note === null ? '' : ' · '.$transaction->note),
                'amount' => ($transaction->type === TransactionType::Deposit ? '+' : Money::MINUS).Money::format($transaction->amount, $transaction->currency),
            ])
            ->all());
    }

    private function activeBookmaker(User $user, int $id): ?Bookmaker
    {
        return $user->bookmakers()->whereKey($id)->where('is_active', true)->first();
    }

    /**
     * One of the current user's transactions; another user's is "not found".
     */
    private function findTransaction(int $id): BankrollTransaction
    {
        $transaction = $this->user()->bankrollTransactions()->findOrFail($id);

        $this->authorize('update', $transaction);

        return $transaction;
    }

    private function user(): User
    {
        $user = Auth::user();

        abort_unless($user instanceof User, 403);

        return $user;
    }
}
