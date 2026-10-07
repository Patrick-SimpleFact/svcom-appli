<?php

namespace App\Models;

use App\Models\Concerns\IdentifiantNumerique;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Un clic vers une billetterie ou un organisateur (F7.13 bis), sans donnée personnelle.
 */
class ClicSortant extends Model
{
    use IdentifiantNumerique;

    public $timestamps = false;

    protected $table = 'clics_sortants';

    /** Origines possibles d'un clic (SCHEMA §9). */
    public const ORIGINES = ['liste_ce_soir', 'recherche', 'suggestion_auto', 'suggestion_sponsorisee', 'favori', 'lien_partage', 'page_lieu', 'page_artiste', 'fiche'];

    protected $fillable = [
        'horodatage', 'appareil_hash', 'offre_id', 'source_id', 'representation_id', 'spectacle_id', 'lieu_id', 'ville_id', 'genre_id',
        'origine', 'bouton', 'prix_affiche', 'delai_avant_seance_min', 'distance_km', 'compte',
    ];

    protected function casts(): array
    {
        return ['horodatage' => 'datetime', 'prix_affiche' => 'decimal:2', 'compte' => 'boolean'];
    }

    public function source(): BelongsTo
    {
        return $this->belongsTo(Source::class);
    }

    public function spectacle(): BelongsTo
    {
        return $this->belongsTo(Spectacle::class);
    }

    public function lieu(): BelongsTo
    {
        return $this->belongsTo(Lieu::class);
    }

    public function ville(): BelongsTo
    {
        return $this->belongsTo(Ville::class);
    }

    /** Empreinte d'un appareil (ou d'une adresse IP) : clé de l'application, jamais réversible ni transmise. */
    public static function empreinte(string $identifiant): string
    {
        return hash_hmac('sha256', $identifiant, (string) config('app.key'));
    }
}
