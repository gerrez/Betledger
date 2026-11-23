<?php

namespace App\Livewire\Settings;

use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Laravel\Sanctum\PersonalAccessToken;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Personal access tokens for the API: create one (its value is shown once) and revoke
 * the ones no longer needed.
 */
#[Title('API tokens')]
class ApiTokens extends Component
{
    public string $name = '';

    /** The value of the token just created, shown until the user leaves the page. */
    public ?string $plainTextToken = null;

    public function createToken(): void
    {
        $validated = $this->validate(
            ['name' => ['required', 'string', 'max:255']],
            ['name.required' => 'Name the token, e.g. after the script or app that uses it.'],
        );

        $this->plainTextToken = $this->user()->createToken($validated['name'])->plainTextToken;
        $this->name = '';
    }

    /**
     * Revoke one of the user's own tokens: requests sending it are refused from now on.
     */
    public function revokeToken(int $tokenId): void
    {
        $this->user()->tokens()->whereKey($tokenId)->firstOrFail()->delete();
    }

    public function render(): View
    {
        $localTime = $this->user()->localTime();

        return view('livewire.settings.api-tokens', [
            'tokens' => $this->user()->tokens()->latest()->latest('id')->get()->map(fn (PersonalAccessToken $token): array => [
                'id' => $token->id,
                'name' => $token->name,
                'meta' => 'Created '.($token->created_at === null ? '—' : $localTime->toLocal($token->created_at)->format('j M Y'))
                    .' · '.($token->last_used_at === null ? 'Never used' : 'Last used '.$token->last_used_at->diffForHumans()),
            ]),
        ]);
    }

    private function user(): User
    {
        $user = Auth::user();

        abort_unless($user instanceof User, 403);

        return $user;
    }
}
