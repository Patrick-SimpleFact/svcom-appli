<?php

namespace App\Models;

use App\Models\Concerns\IdentifiantNumerique;
use App\Models\Concerns\Journalise;
use Illuminate\Database\Eloquent\Model;

/** Motif pour écarter une piste (F8.6), avec le message envoyé à l'utilisateur ; modifiable dans le back-office. */
class MotifRefus extends Model
{
    use IdentifiantNumerique;
    use Journalise;

    protected $table = 'motifs_refus';

    protected $fillable = ['code', 'libelle', 'message_public', 'ordre'];
}
