<?php

namespace App\Models;

use App\Enums\FileATraiter;
use App\Enums\StatutPropositionIa;
use App\Models\Concerns\IdentifiantNumerique;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Ce que l'assistant IA propose pour un élément de la boîte de travail, et ce qu'il en est advenu. */
class PropositionIa extends Model
{
    use IdentifiantNumerique;

    protected $table = 'propositions_ia';

    protected $fillable = ['element_a_traiter_id', 'appel_ia_id', 'file', 'proposition', 'confiance', 'justification', 'modele', 'niveau', 'statut', 'decidee_le'];

    protected function casts(): array
    {
        return [
            'file' => FileATraiter::class,
            'statut' => StatutPropositionIa::class,
            'proposition' => 'array',
            'decidee_le' => 'datetime',
        ];
    }

    public function element(): BelongsTo
    {
        return $this->belongsTo(ElementATraiter::class, 'element_a_traiter_id');
    }

    public function appel(): BelongsTo
    {
        return $this->belongsTo(AppelIa::class, 'appel_ia_id');
    }
}
