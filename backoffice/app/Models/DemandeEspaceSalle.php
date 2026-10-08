<?php

namespace App\Models;

use App\Enums\StatutDemandeSalle;
use App\Models\Concerns\IdentifiantNumerique;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Demande d'ouverture d'un espace salle par un théâtre (F9.1). */
class DemandeEspaceSalle extends Model
{
    use IdentifiantNumerique;

    protected $table = 'demandes_espace_salle';

    protected $fillable = [
        'nom_lieu', 'adresse', 'code_postal', 'ville_saisie', 'ville_id', 'nom_demandeur', 'fonction', 'email', 'telephone', 'site_web', 'billetterie',
        'message', 'consentement_le', 'lieu_propose_id', 'lieu_id', 'statut', 'motif_refus', 'precisions_demandees', 'utilisateur_cree_id', 'traite_par', 'traite_le',
    ];

    protected $attributes = ['statut' => 'en_attente'];

    protected function casts(): array
    {
        return ['statut' => StatutDemandeSalle::class, 'consentement_le' => 'datetime', 'traite_le' => 'datetime'];
    }

    public function ville(): BelongsTo
    {
        return $this->belongsTo(Ville::class);
    }

    public function lieuPropose(): BelongsTo
    {
        return $this->belongsTo(Lieu::class, 'lieu_propose_id');
    }

    public function lieu(): BelongsTo
    {
        return $this->belongsTo(Lieu::class);
    }
}
