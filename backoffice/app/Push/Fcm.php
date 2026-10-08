<?php

namespace App\Push;

use Firebase\JWT\JWT;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Firebase Cloud Messaging, API HTTP v1, avec le compte de service du projet Firebase (OAuth 2).
 * https://firebase.google.com/docs/cloud-messaging/send-message
 */
class Fcm
{
    public function configure(): bool
    {
        return $this->compte() !== null;
    }

    /** @return array{ResultatPush, ?string} */
    public function envoyer(string $jeton, MessagePush $message): array
    {
        $compte = $this->compte();

        if ($compte === null) {
            return [ResultatPush::NonConfigure, 'Compte de service Firebase absent (.env : FCM_CREDENTIALS_PATH).'];
        }

        $corps = ['message' => array_filter([
            'token' => $jeton,
            'notification' => ['title' => $message->titre, 'body' => $message->corps],
            'data' => $message->donnees === [] ? null : array_map('strval', $message->donnees),
            'android' => ['priority' => 'high', 'notification' => array_filter(['notification_count' => $message->pastille])],
        ], fn ($v) => $v !== null)];

        try {
            $reponse = Http::timeout(10)->withToken($this->jetonAcces($compte))
                ->post("https://fcm.googleapis.com/v1/projects/{$compte['project_id']}/messages:send", $corps);
        } catch (Throwable $e) {
            return [ResultatPush::Erreur, mb_substr($e->getMessage(), 0, 300)];
        }

        $statut = (string) $reponse->json('error.status');
        $code = collect($reponse->json('error.details') ?? [])->pluck('errorCode')->filter()->first();

        return match (true) {
            $reponse->successful() => [ResultatPush::Envoyee, null],
            $reponse->status() === 404 || $code === 'UNREGISTERED' || ($code === 'INVALID_ARGUMENT' && $statut === 'INVALID_ARGUMENT') => [ResultatPush::JetonInvalide, $code ?? $statut],
            default => [ResultatPush::Erreur, "FCM {$reponse->status()} {$statut}"],
        };
    }

    private function compte(): ?array
    {
        $chemin = config('services.fcm.compte_service');
        $compte = filled($chemin) && is_readable($chemin) ? json_decode((string) file_get_contents($chemin), true) : null;

        return is_array($compte) && isset($compte['project_id'], $compte['client_email'], $compte['private_key']) ? $compte : null;
    }

    /** Jeton OAuth du compte de service (1 h chez Google) : gardé 50 min. */
    private function jetonAcces(array $compte): string
    {
        return Cache::remember('fcm_jeton_'.$compte['client_email'], 50 * 60, function () use ($compte) {
            $assertion = JWT::encode([
                'iss' => $compte['client_email'], 'scope' => 'https://www.googleapis.com/auth/firebase.messaging',
                'aud' => $compte['token_uri'] ?? 'https://oauth2.googleapis.com/token', 'iat' => time(), 'exp' => time() + 3600,
            ], $compte['private_key'], 'RS256');

            return (string) Http::asForm()->timeout(10)->post($compte['token_uri'] ?? 'https://oauth2.googleapis.com/token', [
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer', 'assertion' => $assertion,
            ])->throw()->json('access_token');
        });
    }
}
