<?php

namespace App\Models;

use App\Models\Concerns\IsListEntry;
use Database\Factories\TipsterFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * A tipster, model or strategy a bet followed.
 *
 * @property int $id
 * @property int $user_id
 * @property string $name
 */
class Tipster extends Model
{
    /** @use HasFactory<TipsterFactory> */
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
