<?php

namespace App\Models;

use App\Models\Concerns\IsListEntry;
use Database\Factories\TagFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * A free label for bets.
 *
 * @property int $id
 * @property int $user_id
 * @property string $name
 */
class Tag extends Model
{
    /** @use HasFactory<TagFactory> */
    use HasFactory, IsListEntry;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
    ];
}
