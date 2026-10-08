<?php

namespace App\Models;

use App\Models\Concerns\IdentifiantNumerique;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Une suggestion montrée à l'ouverture (F6.5) : ce qu'en a fait l'utilisateur, pour les statistiques et le rapport annonceur. */
class AffichageSuggestion extends Model
{
    use IdentifiantNumerique;

    public $timestamps = false;

    protected $table = 'affichages_suggestion';

    protected $fillable = ['appareil', 'representation_id', 'spectacle_id', 'campagne_id', 'affiche_le', 'clic_fiche', 'clic_billetterie', 'passee'];

    protected function casts(): array
    {
        return ['affiche_le' => 'datetime', 'clic_fiche' => 'boolean', 'clic_billetterie' => 'boolean', 'passee' => 'boolean'];
    }

    public function campagne(): BelongsTo
    {
        return $this->belongsTo(Campagne::class);
    }
}
