<?php

namespace App\Comptes;

use App\Exceptions\ErreurApi;
use Firebase\JWT\JWK;
use Firebase\JWT\JWT;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * « Continuer avec Apple » et « avec Google » (F1.4) : l'app reçoit un jeton signé (JWT) et nous l'envoie.
 * On vérifie la signature avec les clés publiques du fournisseur (gardées 1 h), l'émetteur, le destinataire (notre app) et l'expiration.
 * Identifiants de l'app dans .env : APPLE_CLIENT_IDS (identifiant de l'app iOS), GOOGLE_CLIENT_IDS (identifiants clients OAuth), séparés par des virgules.
 */
class JetonsExternes
{
    public const FOURNISSEURS = [
        'apple' => ['cles' => 'https://appleid.apple.com/auth/keys', 'emetteurs' => ['https://appleid.apple.com']],
        'google' => ['cles' => 'https://www.googleapis.com/oauth2/v3/certs', 'emetteurs' => ['https://accounts.google.com', 'accounts.google.com']],
    ];

    /** @return array{identifiant: string, email: ?string} */
    public function verifier(string $fournisseur, string $jeton): array
    {
        $config = self::FOURNISSEURS[$fournisseur];
        $destinataires = array_filter(array_map('trim', explode(',', (string) config("services.{$fournisseur}.client_ids"))));

        if ($destinataires === []) {
            throw new ErreurApi('connexion_indisponible', "Connexion {$fournisseur} pas encore configurée.", 503);
        }

        try {
            JWT::$leeway = 60;
            $contenu = (array) JWT::decode($jeton, $this->cles($fournisseur, $config['cles']));
        } catch (Throwable) {
            throw new ErreurApi('jeton_invalide', 'Jeton de connexion invalide ou expiré.', 401);
        }

        $aud = (array) ($contenu['aud'] ?? []);

        if (! in_array($contenu['iss'] ?? null, $config['emetteurs'], true) || array_intersect($aud, $destinataires) === [] || blank($contenu['sub'] ?? null)) {
            throw new ErreurApi('jeton_invalide', 'Jeton de connexion invalide ou expiré.', 401);
        }

        // Une adresse non vérifiée par le fournisseur ne sert pas à retrouver un compte existant.
        $verifiee = in_array($contenu['email_verified'] ?? false, [true, 'true'], true);

        return [
            'identifiant' => (string) $contenu['sub'],
            'email' => $verifiee && filled($contenu['email'] ?? null) ? mb_strtolower((string) $contenu['email']) : null,
        ];
    }

    /** Clés publiques du fournisseur, au format attendu par php-jwt. */
    private function cles(string $fournisseur, string $adresse): array
    {
        $jwks = Cache::remember("jwks.{$fournisseur}", now()->addHour(), fn () => Http::timeout(5)->get($adresse)->throw()->json());

        return JWK::parseKeySet($jwks, 'RS256');
    }
}
