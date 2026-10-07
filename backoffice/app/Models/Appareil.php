<?php

namespace App\Models;

use App\Enums\ChoixSuggestion;
use App\Models\Concerns\IdentifiantNumerique;
use Illuminate\Database\Eloquent\Model;

/**
 * Un téléphone qui utilise l'app (SCHEMA §5), avec ou sans compte.
 */
class Appareil extends Model
{
    use IdentifiantNumerique;

    protected $fillable = [
        'identifiant', 'utilisateur_id', 'plateforme', 'version_app', 'jeton_push', 'nb_ouvertures', 'premiere_ouverture', 'derniere_ouverture',
        'suggestion_choix', 'suggestion_question_le', 'suggestion_repondu_le', 'suggestion_ouvertures_a_la_reponse', 'suggestion_relances', 'derniere_suggestion_le',
    ];

    protected $attributes = ['nb_ouvertures' => 0, 'suggestion_choix' => 'non_demande', 'suggestion_relances' => 0];

    protected $hidden = ['jeton_push'];

    protected function casts(): array
    {
        return [
            'suggestion_choix' => ChoixSuggestion::class,
            'premiere_ouverture' => 'datetime',
            'derniere_ouverture' => 'datetime',
            'suggestion_question_le' => 'datetime',
            'suggestion_repondu_le' => 'datetime',
            'derniere_suggestion_le' => 'datetime',
        ];
    }

    /**
     * Faut-il poser la question des suggestions à cette ouverture (F6.2) ?
     * - 1re fois : à la n-ième ouverture (réglage, 5), si jamais posée ;
     * - relance après « Non merci » : au moins N jours et M ouvertures depuis la réponse, dans la limite des relances ;
     * - jamais si l'utilisateur a dit oui ou désactivé les suggestions, ni si l'interrupteur général est coupé (F6.4).
     */
    public function doitPoserQuestionSuggestion(): bool
    {
        if (! Parametre::valeur('suggestions_actives')) {
            return false;
        }

        return match ($this->suggestion_choix) {
            ChoixSuggestion::NonDemande => $this->suggestion_question_le === null
                && $this->nb_ouvertures >= (int) Parametre::valeur('suggestion_question_ouverture'),
            ChoixSuggestion::NonMerci => $this->suggestion_repondu_le !== null
                && $this->suggestion_relances < (int) Parametre::valeur('suggestion_relances_max')
                && $this->suggestion_repondu_le->lessThanOrEqualTo(now()->subDays((int) Parametre::valeur('suggestion_relance_jours')))
                && $this->nb_ouvertures - (int) $this->suggestion_ouvertures_a_la_reponse >= (int) Parametre::valeur('suggestion_relance_ouvertures'),
            default => false,
        };
    }
}
