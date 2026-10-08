<?php

namespace App\Push;

/** Une notification : titre, texte, pastille (nombre de nouveautés non vues) et données pour l'app (écran à ouvrir). */
final class MessagePush
{
    /** @param  array<string, string>  $donnees */
    public function __construct(
        public readonly string $titre,
        public readonly string $corps,
        public readonly ?int $pastille = null,
        public readonly array $donnees = [],
    ) {}
}
