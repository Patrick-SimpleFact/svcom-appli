<?php

namespace App\Models\Concerns;

use DateTimeInterface;
use Illuminate\Support\Carbon;

/**
 * Laravel envoie les dates à PostgreSQL sans leur fuseau : une heure de La Réunion
 * serait lue comme une heure universelle. On convertit donc toujours en UTC avant l'envoi.
 * À utiliser sur tout modèle qui reçoit des dates dans un autre fuseau que l'UTC.
 */
trait DatesEnUtc
{
    public function fromDateTime($value)
    {
        if ($value instanceof DateTimeInterface) {
            $value = Carbon::instance($value)->utc();
        }

        return parent::fromDateTime($value);
    }
}
