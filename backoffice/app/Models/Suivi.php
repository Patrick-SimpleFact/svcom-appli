<?php

namespace App\Models;

use App\Models\Concerns\IdentifiantNumerique;
use Illuminate\Database\Eloquent\Model;

/** Un lieu ou un artiste suivi (F3.1) : source des alertes (F3.4). */
class Suivi extends Model
{
    use IdentifiantNumerique;

    public const TYPES = ['lieu', 'artiste'];

    protected $fillable = ['utilisateur_id', 'type', 'cible_id'];
}
