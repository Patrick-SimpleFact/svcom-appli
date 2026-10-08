<?php

namespace App\Web;

use App\Mail\InscriptionBetaMail;
use App\Models\InscriptionBeta;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;

/**
 * Liste d'attente de la bêta (W05b). Double opt-in : un e-mail de confirmation, valable 7 jours ; la réponse au formulaire
 * est toujours la même (on ne révèle pas si une adresse est déjà inscrite). Désinscription en un clic = effacement.
 */
class ListeAttenteBeta
{
    public const JOURS_LIEN = 7;

    public function inscrire(string $email, string $plateforme, ?string $ville): void
    {
        $inscription = InscriptionBeta::firstOrNew(['email' => mb_strtolower(trim($email))]);

        // Déjà confirmée, ou e-mail envoyé il y a moins de 10 minutes : rien de plus (évite d'inonder une adresse saisie par un tiers).
        if ($inscription->confirmee_le !== null || ($inscription->exists && $inscription->updated_at->greaterThan(now()->subMinutes(10)))) {
            return;
        }

        $inscription->fill(['plateforme' => $plateforme, 'ville' => filled($ville) ? trim($ville) : null, 'consentement_le' => now()])->save();

        Mail::to($inscription->email)->send(new InscriptionBetaMail(
            URL::temporarySignedRoute('beta.confirmer', now()->addDays(self::JOURS_LIEN), ['inscription' => $inscription->id]),
            URL::signedRoute('beta.desinscrire', ['inscription' => $inscription->id]),
        ));
    }

    public function confirmer(InscriptionBeta $inscription): void
    {
        $inscription->confirmee_le ??= now();
        $inscription->save();
    }

    public function desinscrire(InscriptionBeta $inscription): void
    {
        $inscription->delete();
    }

    /** Inscriptions jamais confirmées : effacées après 30 jours. */
    public function purger(): int
    {
        return InscriptionBeta::whereNull('confirmee_le')->where('created_at', '<', now()->subDays(InscriptionBeta::JOURS_SANS_CONFIRMATION))->delete();
    }
}
