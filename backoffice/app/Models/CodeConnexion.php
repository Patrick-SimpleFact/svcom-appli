<?php

namespace App\Models;

use App\Models\Concerns\IdentifiantNumerique;
use Illuminate\Database\Eloquent\Model;

/** Code à 6 chiffres envoyé par e-mail (F1.4) : jamais en clair en base, valable 10 min, une seule fois. */
class CodeConnexion extends Model
{
    use IdentifiantNumerique;

    protected $table = 'codes_connexion';

    protected $fillable = ['email', 'code_hash', 'expire_le', 'essais', 'utilise_le'];

    protected function casts(): array
    {
        return ['expire_le' => 'datetime', 'utilise_le' => 'datetime', 'essais' => 'integer'];
    }

    public static function empreinte(string $email, string $code): string
    {
        return hash_hmac('sha256', mb_strtolower($email).'|'.$code, (string) config('app.key'));
    }
}
