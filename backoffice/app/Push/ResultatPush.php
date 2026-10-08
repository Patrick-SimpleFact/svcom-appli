<?php

namespace App\Push;

/** Issue d'un envoi vers Apple ou Google. Un jeton invalide (app désinstallée) est effacé de l'appareil. */
enum ResultatPush: string
{
    case Envoyee = 'envoyee';
    case JetonInvalide = 'jeton_invalide';
    case Erreur = 'erreur';
    case NonConfigure = 'non_configure';
}
