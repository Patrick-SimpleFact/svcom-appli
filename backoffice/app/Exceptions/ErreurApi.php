<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Erreur renvoyée à l'app (API §1) : { "erreur": { "code": "…", "message": "…" } }.
 * L'app traduit le code en message lisible ; elle n'affiche jamais le message technique (F2.8).
 */
class ErreurApi extends RuntimeException
{
    public function __construct(
        public readonly string $code_erreur,
        string $message,
        public readonly int $statut = 400,
    ) {
        parent::__construct($message);
    }
}
