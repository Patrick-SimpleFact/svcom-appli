<?php

namespace App\Models\Concerns;

/**
 * Les identifiants sont des nombres. Une adresse avec autre chose (ex. /admin/…/create)
 * doit donner « page introuvable », et non une erreur PostgreSQL.
 * À ajouter sur tous les modèles affichés dans le back-office ou l'API.
 */
trait IdentifiantNumerique
{
    public function resolveRouteBindingQuery($query, $value, $field = null)
    {
        if (($field === null || $field === $this->getKeyName()) && ! ctype_digit((string) $value)) {
            return $query->whereRaw('false');
        }

        return parent::resolveRouteBindingQuery($query, $value, $field);
    }
}
