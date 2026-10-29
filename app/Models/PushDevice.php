<?php

namespace App\Models;

use Database\Factories\PushDeviceFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * An installation of the Android app that can receive push notifications for its user.
 *
 * @property int $id
 * @property int $user_id
 * @property string $token the Firebase Cloud Messaging registration token
 * @property string $platform
 * @property string|null $name e.g. "Google Pixel 8", as reported by the app
 * @property Carbon $last_seen_at when the app last registered the token
 */
class PushDevice extends Model
{
    /** @use HasFactory<PushDeviceFactory> */
    use HasFactory;

    public const PLATFORM_ANDROID = 'android';

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'token',
        'platform',
        'name',
        'last_seen_at',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'last_seen_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
