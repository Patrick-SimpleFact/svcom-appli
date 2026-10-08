<?php

namespace App\Push;

use Firebase\JWT\JWT;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Apple Push Notification service, par jeton d'authentification (clé .p8, HTTP/2).
 * https://developer.apple.com/documentation/usernotifications/sending-notification-requests-to-apns
 */
class Apns
{
    public function configure(): bool
    {
        $c = config('services.apns');

        return filled($c['cle']) && is_readable($c['cle']) && filled($c['cle_id']) && filled($c['equipe_id']) && filled($c['bundle_id']);
    }

    /** @return array{ResultatPush, ?string} */
    public function envoyer(string $jeton, MessagePush $message): array
    {
        if (! $this->configure()) {
            return [ResultatPush::NonConfigure, 'Clé APNs absente (.env : APNS_KEY_PATH, APNS_KEY_ID, APNS_TEAM_ID, APNS_BUNDLE_ID).'];
        }

        $c = config('services.apns');
        $hote = $c['production'] ? 'https://api.push.apple.com' : 'https://api.sandbox.push.apple.com';
        $corps = ['aps' => array_filter(['alert' => ['title' => $message->titre, 'body' => $message->corps], 'sound' => 'default', 'badge' => $message->pastille], fn ($v) => $v !== null)] + $message->donnees;

        try {
            $reponse = Http::withOptions(['version' => 2.0])->timeout(10)
                ->withHeaders(['authorization' => 'bearer '.$this->jwt(), 'apns-topic' => $c['bundle_id'], 'apns-push-type' => 'alert', 'apns-priority' => '10'])
                ->post("{$hote}/3/device/{$jeton}", $corps);
        } catch (Throwable $e) {
            return [ResultatPush::Erreur, mb_substr($e->getMessage(), 0, 300)];
        }

        $raison = (string) $reponse->json('reason');

        return match (true) {
            $reponse->successful() => [ResultatPush::Envoyee, null],
            $reponse->status() === 410 || in_array($raison, ['BadDeviceToken', 'Unregistered', 'DeviceTokenNotForTopic'], true) => [ResultatPush::JetonInvalide, $raison ?: 'Unregistered'],
            default => [ResultatPush::Erreur, "APNs {$reponse->status()} {$raison}"],
        };
    }

    /** Jeton signé ES256, valable 1 h chez Apple : gardé 50 min. */
    private function jwt(): string
    {
        $c = config('services.apns');

        return Cache::remember('apns_jwt_'.$c['cle_id'], 50 * 60, fn () => JWT::encode(
            ['iss' => $c['equipe_id'], 'iat' => time()], (string) file_get_contents($c['cle']), 'ES256', $c['cle_id'],
        ));
    }
}
