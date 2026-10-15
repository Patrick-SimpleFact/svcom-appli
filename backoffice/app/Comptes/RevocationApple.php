<?php

namespace App\Comptes;

use App\Models\ConnexionExterne;
use Firebase\JWT\JWT;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * « Continuer avec Apple » (M10b) : Apple exige que la suppression d'un compte révoque l'accès donné à l'app
 * (règle 5.1.1(v) de l'App Store). À la connexion, l'app transmet le code d'autorisation d'Apple : il est échangé
 * contre un jeton de rafraîchissement, gardé chiffré, puis révoqué à la suppression du compte.
 * Une panne chez Apple ne bloque ni la connexion ni la suppression : elle est seulement journalisée.
 */
class RevocationApple
{
    public const SERVEUR = 'https://appleid.apple.com';

    public function configure(): bool
    {
        $c = config('services.apple');

        return filled($c['cle']) && is_readable((string) $c['cle']) && filled($c['cle_id']) && filled($c['equipe_id']) && $this->clientId() !== null;
    }

    /** Échange le code d'autorisation (valable 5 min) contre un jeton de rafraîchissement, gardé pour la révocation. */
    public function enregistrer(ConnexionExterne $connexion, string $code): void
    {
        if (! $this->configure()) {
            return;
        }

        try {
            $reponse = Http::asForm()->timeout(10)->post(self::SERVEUR.'/auth/token', [
                'client_id' => $this->clientId(), 'client_secret' => $this->secret(), 'code' => $code, 'grant_type' => 'authorization_code',
            ]);
            if ($reponse->successful() && filled($reponse->json('refresh_token'))) {
                $connexion->update(['jeton_apple' => $reponse->json('refresh_token')]);
            } else {
                Log::warning('Apple : échange du code d’autorisation refusé', ['statut' => $reponse->status(), 'erreur' => $reponse->json('error')]);
            }
        } catch (Throwable $e) {
            Log::warning('Apple : échange du code d’autorisation impossible', ['erreur' => $e->getMessage()]);
        }
    }

    /** Révoque l'accès donné à l'app (suppression du compte). */
    public function revoquer(ConnexionExterne $connexion): void
    {
        if (blank($connexion->jeton_apple) || ! $this->configure()) {
            return;
        }

        try {
            $reponse = Http::asForm()->timeout(10)->post(self::SERVEUR.'/auth/revoke', [
                'client_id' => $this->clientId(), 'client_secret' => $this->secret(),
                'token' => $connexion->jeton_apple, 'token_type_hint' => 'refresh_token',
            ]);
            if (! $reponse->successful()) {
                Log::warning('Apple : révocation refusée', ['statut' => $reponse->status(), 'erreur' => $reponse->json('error')]);
            }
        } catch (Throwable $e) {
            Log::warning('Apple : révocation impossible', ['erreur' => $e->getMessage()]);
        }
    }

    /** Identifiant de l'app chez Apple : le premier de APPLE_CLIENT_IDS (fr.spettacoli.app). */
    private function clientId(): ?string
    {
        $ids = array_filter(array_map('trim', explode(',', (string) config('services.apple.client_ids'))));

        return $ids[0] ?? null;
    }

    /** « client_secret » d'Apple : un jeton ES256 signé avec la clé .p8, valable 5 minutes. */
    private function secret(): string
    {
        $c = config('services.apple');

        return JWT::encode([
            'iss' => $c['equipe_id'], 'iat' => time(), 'exp' => time() + 300, 'aud' => self::SERVEUR, 'sub' => $this->clientId(),
        ], (string) file_get_contents((string) $c['cle']), 'ES256', $c['cle_id']);
    }
}
