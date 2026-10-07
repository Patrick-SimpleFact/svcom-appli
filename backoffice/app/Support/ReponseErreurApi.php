<?php

namespace App\Support;

use App\Exceptions\ErreurApi;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Throwable;

/**
 * Toute erreur de l'API au même format (API §1) : { "erreur": { "code", "message" } }, plus « champs » pour une saisie invalide.
 * Une erreur imprévue donne « erreur_interne » sans détail technique (le détail part dans les journaux).
 */
class ReponseErreurApi
{
    public static function depuis(Throwable $erreur): JsonResponse
    {
        [$statut, $code, $message, $extra, $entetes] = match (true) {
            $erreur instanceof ErreurApi => [$erreur->statut, $erreur->code_erreur, $erreur->getMessage(), [], []],
            $erreur instanceof ValidationException => [422, 'donnees_invalides', 'Certaines données sont invalides.', ['champs' => $erreur->errors()], []],
            $erreur instanceof AuthenticationException => [401, 'non_connecte', 'Connexion requise.', [], []],
            $erreur instanceof ThrottleRequestsException => [429, 'trop_de_requetes', 'Trop de requêtes, réessayez dans un instant.', [], $erreur->getHeaders()],
            $erreur instanceof ModelNotFoundException, $erreur instanceof NotFoundHttpException => [404, 'introuvable', 'Ressource introuvable.', [], []],
            $erreur instanceof MethodNotAllowedHttpException => [405, 'methode_non_permise', 'Méthode non permise.', [], $erreur->getHeaders()],
            $erreur instanceof HttpExceptionInterface && $erreur->getStatusCode() < 500 => [$erreur->getStatusCode(), 'requete_invalide', 'Requête invalide.', [], $erreur->getHeaders()],
            default => [500, 'erreur_interne', 'Une erreur est survenue.', [], []],
        };

        return response()->json(['erreur' => ['code' => $code, 'message' => $message, ...$extra]], $statut, $entetes, JSON_UNESCAPED_UNICODE);
    }
}
