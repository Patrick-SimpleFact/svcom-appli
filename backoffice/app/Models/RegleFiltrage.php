<?php

namespace App\Models;

use App\Enums\TypeRegleFiltrage;
use App\Models\Concerns\IdentifiantNumerique;
use App\Models\Concerns\Journalise;
use Illuminate\Database\Eloquent\Model;

class RegleFiltrage extends Model
{
    use IdentifiantNumerique;
    use Journalise;

    protected $table = 'regles_filtrage';

    protected $fillable = ['type', 'mot', 'actif'];

    protected function casts(): array
    {
        return ['type' => TypeRegleFiltrage::class, 'actif' => 'boolean'];
    }
}
