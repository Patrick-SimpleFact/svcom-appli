<?php

namespace App\Collecte;

use App\Models\Source;
use RuntimeException;

class RegistreConnecteurs
{
    public function pour(Source $source): Connecteur
    {
        $classe = config("collecte.connecteurs.{$source->code}");

        if ($classe === null) {
            throw new RuntimeException("Aucun connecteur n'est encore écrit pour la source « {$source->nom} ».");
        }

        return app($classe);
    }

    public function existe(Source $source): bool
    {
        return config("collecte.connecteurs.{$source->code}") !== null;
    }
}
