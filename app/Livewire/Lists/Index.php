<?php

namespace App\Livewire\Lists;

use App\Domain\ListName;
use App\Models\Competition;
use App\Models\Market;
use App\Models\Sport;
use App\Models\Tag;
use App\Models\Team;
use App\Models\Tipster;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

#[Title('Lists')]
class Index extends Component
{
    /**
     * The open tab, a ListType value.
     */
    #[Url]
    public string $list = 'sports';

    public bool $showRename = false;

    public ?int $renamingId = null;

    public string $name = '';

    /**
     * Switching tabs abandons a rename in progress.
     */
    public function updatedList(): void
    {
        $this->resetValidation();
        $this->showRename = false;
        $this->renamingId = null;
    }

    /**
     * Open the rename form for one of the user's entries in the open list.
     */
    public function rename(int $id): void
    {
        $entry = $this->findEntry($id, 'update');

        $this->resetValidation();
        $this->renamingId = $entry->id;
        $this->name = $entry->name;
        $this->showRename = true;
    }

    /**
     * Save the new name of the entry being renamed.
     */
    public function save(): void
    {
        $entry = $this->findEntry($this->renamingId ?? 0, 'update');
        $type = $this->listType();

        $this->name = trim($this->name);

        $unique = Rule::unique($type->value, 'name')
            ->where('user_id', $entry->user_id)
            ->ignore($entry->id);
        $duplicateMessage = "You already have a {$type->singular()} with this name.";

        if ($entry instanceof Competition || $entry instanceof Team) {
            $unique->where('sport_id', $entry->sport_id);
            $duplicateMessage = "You already have a {$type->singular()} with this name in {$entry->sport->name}.";
        }

        $validated = $this->validate([
            'name' => ['required', 'string', 'max:255', ListName::rule(), $unique],
        ], [
            'name.unique' => $duplicateMessage,
        ]);

        $entry->update($validated);

        $this->showRename = false;
        $this->renamingId = null;
    }

    /**
     * Delete one of the user's entries, as long as nothing uses it. Deleted bets that
     * use it are deleted for good with it (the confirmation says how many).
     */
    public function delete(int $id): void
    {
        $entry = $this->findEntry($id, 'delete');
        $user = $this->user();

        $this->resetValidation();

        if ($entry->isInUse()) {
            $this->addError('delete', "“{$entry->name}” is in use, so it can’t be deleted.");

            return;
        }

        $deletedBetIds = $this->listType()->deletedBetIds($user)[$entry->id] ?? [];

        DB::transaction(function () use ($user, $deletedBetIds, $entry): void {
            $user->bets()->onlyTrashed()->whereKey($deletedBetIds)->forceDelete();
            $entry->delete();
        });
    }

    public function render(): View
    {
        $type = $this->listType();
        $query = $type->entries($this->user());
        $deletedBetIds = $type->deletedBetIds($this->user());

        $entries = $query
            ->withExists($query->getRelated()::usageRelations())
            ->when($type->isPerSport(), fn ($query) => $query->with('sport'))
            ->orderBy('name')
            ->get();

        return view('livewire.lists.index', [
            'types' => ListType::cases(),
            'type' => $type,
            'groups' => $this->groupBySport($type, $entries),
            'deleteWarnings' => array_map(fn (array $betIds): string => count($betIds) === 1
                ? 'A recently deleted bet uses it and is deleted for good too.'
                : count($betIds).' recently deleted bets use it and are deleted for good too.', $deletedBetIds),
        ]);
    }

    /**
     * Competitions and teams under a heading per sport (sorted by sport); other lists
     * as one group without a heading.
     *
     * @template TEntry of Model
     *
     * @param  Collection<int, TEntry>  $entries
     * @return Collection<string, Collection<int, TEntry>>
     */
    private function groupBySport(ListType $type, Collection $entries): Collection
    {
        if (! $type->isPerSport()) {
            return collect(['' => $entries]);
        }

        return $entries
            ->groupBy(fn (Model $entry): string => $entry instanceof Competition || $entry instanceof Team ? $entry->sport->name : '')
            ->sortKeys(SORT_NATURAL | SORT_FLAG_CASE);
    }

    /**
     * One of the current user's entries in the open list; another user's is "not found".
     */
    private function findEntry(int $id, string $ability): Sport|Competition|Team|Market|Tipster|Tag
    {
        $entry = $this->listType()->entries($this->user())->findOrFail($id);

        $this->authorize($ability, $entry);

        return $entry;
    }

    /**
     * The open list; an unknown value in the URL falls back to sports.
     */
    private function listType(): ListType
    {
        return ListType::tryFrom($this->list) ?? ListType::Sports;
    }

    private function user(): User
    {
        $user = Auth::user();

        abort_unless($user instanceof User, 403);

        return $user;
    }
}
