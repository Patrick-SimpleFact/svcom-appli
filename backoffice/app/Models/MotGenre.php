<?php

namespace App\Models;

use App\Models\Concerns\IdentifiantNumerique;
use App\Models\Concerns\Journalise;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Un mot du titre ou de la description qui donne un genre, ou le marqueur « Jeune public » (COLLECTE §6, F7.6).
 */
class MotGenre extends Model
{
    use IdentifiantNumerique;
    use Journalise;

    protected $table = 'mots_genres';

    protected $fillable = ['mot', 'genre_id', 'jeune_public', 'actif'];

    protected function casts(): array
    {
        return ['jeune_public' => 'boolean', 'actif' => 'boolean'];
    }

    public function genre(): BelongsTo
    {
        return $this->belongsTo(Genre::class);
    }
}
