<?php

namespace App\Models;

use App\Models\Concerns\IdentifiantNumerique;
use Illuminate\Database\Eloquent\Model;

/** Une personne qui veut tester Spettacoli avant sa sortie (W05b). */
class InscriptionBeta extends Model
{
    use IdentifiantNumerique;

    public const PLATEFORMES = ['ios' => 'iPhone', 'android' => 'Android'];

    /** Une inscription non confirmée est effacée après ce délai. */
    public const JOURS_SANS_CONFIRMATION = 30;

    protected $table = 'inscriptions_beta';

    protected $fillable = ['email', 'plateforme', 'ville', 'consentement_le', 'confirmee_le', 'invitee_le'];

    protected function casts(): array
    {
        return ['consentement_le' => 'datetime', 'confirmee_le' => 'datetime', 'invitee_le' => 'datetime'];
    }
}
