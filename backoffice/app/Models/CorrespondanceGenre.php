<?php

namespace App\Models;

use App\Models\Concerns\IdentifiantNumerique;
use App\Models\Concerns\Journalise;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CorrespondanceGenre extends Model
{
    use IdentifiantNumerique;
    use Journalise;

    protected $table = 'correspondances_genres';

    protected $fillable = ['source_id', 'categorie_source', 'genre_id', 'jeune_public'];

    protected function casts(): array
    {
        return ['jeune_public' => 'boolean'];
    }

    public function source(): BelongsTo
    {
        return $this->belongsTo(Source::class);
    }

    public function genre(): BelongsTo
    {
        return $this->belongsTo(Genre::class);
    }
}
