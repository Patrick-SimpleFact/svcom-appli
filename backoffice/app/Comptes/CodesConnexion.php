<?php

namespace App\Comptes;

use App\Exceptions\ErreurApi;
use App\Mail\CodeConnexionMail;
use App\Models\CodeConnexion;
use Illuminate\Support\Facades\Mail;

/**
 * Connexion par code envoyé par e-mail (F1.4, F1.5) : pas de mot de passe. Code à 6 chiffres, valable 10 min, une seule fois,
 * jamais en clair en base. 5 essais faux → 15 min d'attente. Au plus un code par minute et 5 par heure pour une même adresse.
 * La réponse ne dit jamais si un compte existe pour cette adresse.
 */
class CodesConnexion
{
    public const VALIDITE_MINUTES = 10;

    public const ESSAIS_MAX = 5;

    public const ATTENTE_MINUTES = 15;

    public const CODES_PAR_HEURE = 5;

    public function envoyer(string $email): void
    {
        $email = mb_strtolower(trim($email));
        $recents = CodeConnexion::where('email', $email)->where('created_at', '>=', now()->subHour());

        if ((clone $recents)->where('created_at', '>=', now()->subMinute())->exists()) {
            throw new ErreurApi('patientez', 'Un code vient d’être envoyé : patientez une minute avant d’en demander un autre.', 429);
        }

        if ((clone $recents)->count() >= self::CODES_PAR_HEURE) {
            throw new ErreurApi('trop_de_codes', 'Trop de codes demandés pour cette adresse : réessayez dans une heure.', 429);
        }

        $this->bloquerSiTropDEssais($email);

        // Un seul code valable à la fois : les précédents ne servent plus.
        CodeConnexion::where('email', $email)->whereNull('utilise_le')->update(['expire_le' => now()]);

        $code = str_pad((string) random_int(0, 999_999), 6, '0', STR_PAD_LEFT);
        CodeConnexion::create(['email' => $email, 'code_hash' => CodeConnexion::empreinte($email, $code), 'expire_le' => now()->addMinutes(self::VALIDITE_MINUTES)]);

        Mail::to($email)->send(new CodeConnexionMail($code));
    }

    /** Vérifie le code ; renvoie l'adresse e-mail (normalisée) à connecter. */
    public function verifier(string $email, string $code): string
    {
        $email = mb_strtolower(trim($email));
        $this->bloquerSiTropDEssais($email);

        $valable = CodeConnexion::where('email', $email)->whereNull('utilise_le')->where('expire_le', '>', now())->latest('id')->first();

        if ($valable === null) {
            throw new ErreurApi('code_expire', 'Ce code a expiré : demandez-en un nouveau.', 422);
        }

        if (! hash_equals($valable->code_hash, CodeConnexion::empreinte($email, $code))) {
            $valable->increment('essais');

            throw new ErreurApi('code_invalide', 'Code incorrect.', 422);
        }

        $valable->update(['utilise_le' => now()]);

        return $email;
    }

    private function bloquerSiTropDEssais(string $email): void
    {
        $essais = CodeConnexion::where('email', $email)->where('updated_at', '>=', now()->subMinutes(self::ATTENTE_MINUTES))->sum('essais');

        if ($essais >= self::ESSAIS_MAX) {
            throw new ErreurApi('trop_d_essais', 'Trop d’essais : réessayez dans '.self::ATTENTE_MINUTES.' minutes.', 429);
        }
    }
}
