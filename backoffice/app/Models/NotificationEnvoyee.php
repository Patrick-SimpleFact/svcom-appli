<?php

namespace App\Models;

use App\Models\Concerns\IdentifiantNumerique;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Une notification envoyée à un téléphone (F3.7) : ouverture et erreur éventuelle. */
class NotificationEnvoyee extends Model
{
    use IdentifiantNumerique;

    public $timestamps = false;

    protected $table = 'notifications_envoyees';

    protected $fillable = ['appareil_id', 'utilisateur_id', 'titre', 'corps', 'nb_nouveautes', 'test', 'envoyee_le', 'ouverte_le', 'resultat', 'erreur'];

    protected $attributes = ['test' => false, 'nb_nouveautes' => 0, 'resultat' => 'envoyee'];

    protected function casts(): array
    {
        return ['envoyee_le' => 'datetime', 'ouverte_le' => 'datetime', 'test' => 'boolean'];
    }

    public function appareil(): BelongsTo
    {
        return $this->belongsTo(Appareil::class);
    }
}
