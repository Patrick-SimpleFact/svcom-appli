<?php

namespace App\Models;

use App\Models\Concerns\IdentifiantNumerique;
use App\Models\Concerns\Journalise;
use Illuminate\Database\Eloquent\Model;

/** Une action de communication de Patrick (post, article, affiche…), en repère sur les courbes de la page Audience. */
class ActionCommunication extends Model
{
    use IdentifiantNumerique;
    use Journalise;

    protected $table = 'actions_communication';

    /** Canaux proposés dans le formulaire (un autre peut être saisi). */
    public const CANAUX = [
        'Instagram', 'Facebook', 'TikTok', 'LinkedIn', 'Presse', 'Radio', 'Affiche / flyer', 'E-mail', 'Site web',
        'Salon / événement', 'Bouche-à-oreille', 'Autre',
    ];

    protected $fillable = ['date_heure', 'canal', 'libelle', 'lien', 'notes'];

    protected function casts(): array
    {
        return ['date_heure' => 'datetime'];
    }
}
