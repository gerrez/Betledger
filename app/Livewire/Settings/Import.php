<?php

namespace App\Livewire\Settings;

use App\Domain\Currency;
use App\Domain\Import\Sportmarket\ImportedBet;
use App\Domain\Import\Sportmarket\ImportedOrder;
use App\Domain\Import\Sportmarket\InvalidOrdersFile;
use App\Domain\Import\Sportmarket\OrderOutcome;
use App\Domain\Import\Sportmarket\RecordOrders;
use App\Domain\Import\Sportmarket\SportmarketImport;
use App\Domain\Money;
use App\Domain\Odds;
use App\Models\Bookmaker;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;

/**
 * Import a Sportmarket orders export: choose the bookmaker, upload the CSV, preview what
 * happens to every order, then confirm. Nothing is saved before confirming.
 */
#[Title('Import')]
class Import extends Component
{
    use WithFileUploads;

    /**
     * Uploads larger than this many kilobytes are refused (the owner's export of 588
     * orders is well under 1 MB).
     */
    private const MAX_KILOBYTES = 10240;

    public string $bookmaker = '';

    public ?TemporaryUploadedFile $file = null;

    /**
     * Whether the preview of the file's orders is shown.
     */
    public bool $previewing = false;

    /**
     * Start at the bookmaker named "Sportmarket", else the first active one.
     */
    public function mount(): void
    {
        $bookmakers = $this->bookmakers();
        $bookmaker = $bookmakers->first(fn (Bookmaker $bookmaker): bool => mb_strtolower($bookmaker->name) === 'sportmarket')
            ?? $bookmakers->first();

        $this->bookmaker = $bookmaker === null ? '' : (string) $bookmaker->id;
    }

    /**
     * Another bookmaker or file needs a new preview.
     */
    public function updated(string $property): void
    {
        if (in_array($property, ['bookmaker', 'file'], true)) {
            $this->previewing = false;
        }
    }

    /**
     * Read the file and show what would happen to each order.
     */
    public function preview(SportmarketImport $import): void
    {
        $this->validateForm();

        $this->previewing = $this->readOrders($import) !== null;
    }

    /**
     * Save the bets of the previewed file. The file is read again, so an order imported
     * in the meantime is skipped rather than created twice.
     */
    public function import(SportmarketImport $import, RecordOrders $recordOrders): void
    {
        $this->validateForm();

        $orders = $this->readOrders($import);

        if ($orders === null) {
            $this->previewing = false;

            return;
        }

        $created = $recordOrders->handle($this->user(), $this->selectedBookmaker(), $orders);

        if ($created === 0) {
            $this->addError('file', 'There is nothing to import in this file.');

            return;
        }

        session()->flash('status', $created === 1 ? 'Imported 1 bet.' : "Imported {$created} bets.");

        $this->redirectRoute('bets.index', ['status' => 'settled'], navigate: true);
    }

    /**
     * Leave the preview without saving anything.
     */
    public function cancel(): void
    {
        $this->reset('file', 'previewing');
        $this->resetValidation();
    }

    public function render(): View
    {
        $bookmakers = $this->bookmakers();
        $orders = $this->previewing ? ($this->readOrders(app(SportmarketImport::class)) ?? []) : [];
        $currency = $bookmakers->firstWhere('id', (int) $this->bookmaker)?->currency;

        $bets = array_values(array_filter(array_map(fn (ImportedOrder $order): ?ImportedBet => $order->bet, $orders)));
        $errorOrders = $this->withOutcome($orders, OrderOutcome::Error);
        $skippedOrders = $this->withOutcome($orders, OrderOutcome::Skip);

        return view('livewire.settings.import', [
            'bookmakers' => $bookmakers,
            'summary' => $this->summary(count($orders), count($bets), count($skippedOrders), count($errorOrders)),
            'bets' => $currency === null ? [] : array_map(fn (ImportedBet $bet): array => $this->previewRow($bet, $currency), $bets),
            'errorOrders' => $errorOrders,
            'skippedOrders' => $skippedOrders,
        ]);
    }

    private function validateForm(): void
    {
        $this->validate([
            'bookmaker' => [
                'required',
                Rule::exists('bookmakers', 'id')->where('user_id', $this->user()->id)->where('is_active', true),
            ],
            'file' => ['required', 'file', 'extensions:csv', 'max:'.self::MAX_KILOBYTES],
        ], [
            'bookmaker.required' => 'Choose the bookmaker to import the bets to.',
            'bookmaker.exists' => 'Choose one of your bookmakers.',
            'file.required' => 'Choose the CSV file exported from Sportmarket.',
            'file.extensions' => 'Choose a CSV file.',
        ]);
    }

    /**
     * Read the validated file for the chosen bookmaker; null, with an error on the file,
     * when it isn't an orders export.
     *
     * @return list<ImportedOrder>|null
     */
    private function readOrders(SportmarketImport $import): ?array
    {
        $user = $this->user();

        if ($this->file === null) {
            return null;
        }

        $importedRefs = $user->bets()->whereNotNull('external_ref')->pluck('external_ref')->all();

        try {
            return $import->read($this->file->get(), $this->selectedBookmaker()->currency, $importedRefs);
        } catch (InvalidOrdersFile $exception) {
            $this->addError('file', $exception->getMessage());

            return null;
        }
    }

    /**
     * "17 orders: 16 to import, 1 skipped, 0 errors."
     */
    private function summary(int $orders, int $bets, int $skipped, int $errors): string
    {
        return $orders.' '.($orders === 1 ? 'order' : 'orders').": {$bets} to import, {$skipped} skipped, {$errors} ".($errors === 1 ? 'error' : 'errors').'.';
    }

    /**
     * @return array{event: string, pick: string, placed: string, stake: string, result: string, profit: string, tone: string, isManual: bool, warning: string|null}
     */
    private function previewRow(ImportedBet $bet, Currency $currency): array
    {
        $profit = $bet->payout - $bet->stake;

        return [
            'event' => $bet->eventName,
            'pick' => $bet->pick.' @ '.Odds::format($bet->odds),
            'placed' => $bet->placedAt->format('j M Y H:i'),
            'stake' => Money::format($bet->stake, $currency),
            'result' => $bet->result->label(),
            'profit' => Money::format($profit, $currency, signed: true),
            'tone' => match (true) {
                $profit > 0 => 'profit',
                $profit < 0 => 'loss',
                default => 'none',
            },
            'isManual' => $bet->manualPayout !== null,
            'warning' => $bet->warning,
        ];
    }

    /**
     * @param  list<ImportedOrder>  $orders
     * @return list<ImportedOrder>
     */
    private function withOutcome(array $orders, OrderOutcome $outcome): array
    {
        return array_values(array_filter($orders, fn (ImportedOrder $order): bool => $order->outcome === $outcome));
    }

    /**
     * The user's active bookmakers, which the import can save bets to.
     *
     * @return Collection<int, Bookmaker>
     */
    private function bookmakers(): Collection
    {
        return $this->user()->bookmakers()->where('is_active', true)->orderBy('name')->get();
    }

    private function selectedBookmaker(): Bookmaker
    {
        return $this->user()->bookmakers()->where('is_active', true)->findOrFail((int) $this->bookmaker);
    }

    private function user(): User
    {
        $user = Auth::user();

        abort_unless($user instanceof User, 403);

        return $user;
    }
}
