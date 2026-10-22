<?php

namespace App\Models;

use App\Models\Concerns\IdentifiantNumerique;
use Illuminate\Database\Eloquent\Model;

/** Une demande envoyée au modèle de l'assistant IA (jetons consommés, durée, erreur éventuelle). */
class AppelIa extends Model
{
    use IdentifiantNumerique;

    protected $table = 'appels_ia';

    protected $fillable = ['tache', 'modele', 'nb_elements', 'jetons_entree', 'jetons_sortie', 'duree_ms', 'erreur'];
}
