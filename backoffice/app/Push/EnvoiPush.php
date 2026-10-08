<?php

namespace App\Push;

use App\Models\Appareil;
use App\Models\NotificationEnvoyee;

/**
 * Envoie une notification à un téléphone, par Apple ou Google selon sa plateforme, et la note dans notifications_envoyees.
 * Un jeton refusé (app désinstallée, autorisation retirée) est effacé : on n'y écrira plus.
 */
class EnvoiPush
{
    public function __construct(private Apns $apns, private Fcm $fcm) {}

    /** @param  callable(NotificationEnvoyee): MessagePush  $message  le message, qui peut citer l'identifiant de la notification */
    public function envoyer(Appareil $appareil, callable $message, array $attributs = []): NotificationEnvoyee
    {
        $note = NotificationEnvoyee::create(['appareil_id' => $appareil->id, 'utilisateur_id' => $appareil->utilisateur_id, 'titre' => '', 'corps' => '', 'envoyee_le' => now(), ...$attributs]);
        $contenu = $message($note);

        [$resultat, $erreur] = blank($appareil->jeton_push)
            ? [ResultatPush::JetonInvalide, 'Pas de jeton (notifications non autorisées)']
            : ($appareil->plateforme === 'android' ? $this->fcm : $this->apns)->envoyer($appareil->jeton_push, $contenu);

        if ($resultat === ResultatPush::JetonInvalide && filled($appareil->jeton_push)) {
            $appareil->update(['jeton_push' => null]);
        }

        $note->update(['titre' => mb_substr($contenu->titre, 0, 120), 'corps' => mb_substr($contenu->corps, 0, 300), 'resultat' => $resultat->value, 'erreur' => $erreur]);

        return $note;
    }
}
