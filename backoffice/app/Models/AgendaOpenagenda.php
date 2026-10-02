<?php

namespace App\Models;

use App\Enums\FrequenceAgenda;
use App\Enums\OrigineAgenda;
use App\Models\Concerns\IdentifiantNumerique;
use App\Models\Concerns\Journalise;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Agenda OpenAgenda suivi par la collecte (pas de recherche nationale chez OpenAgenda, COLLECTE §3).
 */
class AgendaOpenagenda extends Model
{
    use IdentifiantNumerique;
    use Journalise;

    protected $table = 'agendas_openagenda';

    protected $fillable = ['uid', 'nom', 'ville_id', 'officiel', 'dernier_evenement_le', 'frequence', 'actif', 'origine'];

    protected function casts(): array
    {
        return [
            'officiel' => 'boolean',
            'dernier_evenement_le' => 'datetime',
            'frequence' => FrequenceAgenda::class,
            'actif' => 'boolean',
            'origine' => OrigineAgenda::class,
        ];
    }

    public function ville(): BelongsTo
    {
        return $this->belongsTo(Ville::class);
    }
}
