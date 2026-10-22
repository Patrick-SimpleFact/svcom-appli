<?php

namespace App\Ia;

use App\Models\Parametre;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Garde-fou du forfait Ollama (plan-module-ia) : avant chaque lot, l'assistant demande s'il peut consommer.
 * Il s'arrête :
 * - s'il reste moins que la part réservée sur l'une des fenêtres (réglages « ia_quota_reserve_5h_pct » et « ia_quota_reserve_semaine_pct »),
 *   jusqu'à sa remise à zéro ;
 * - si des crédits achetés existent (une fois l'inclus épuisé, ils seraient facturés) ;
 * - s'il ne peut pas lire le solde (pas de clé, ollama.com indisponible) : sans certitude, on ne consomme pas.
 * Le solde est lu au plus une fois par minute (ollama.com limite à 10 lectures par minute, partagées avec les autres usages).
 */
class Quota
{
    public const CLE_CACHE = 'ia:quota';

    public const CACHE_S = 60;

    public function etat(): EtatQuota
    {
        $cle = config('services.ollama.cle_api');

        if (blank($cle)) {
            throw new ErreurIa('Clé API ollama.com absente (OLLAMA_API_KEY) : le quota ne peut pas être vérifié.');
        }

        // On garde en cache la réponse brute (un tableau) : le cache ne restaure pas nos objets (classes non autorisées).
        return EtatQuota::depuisReponse(Cache::remember(self::CLE_CACHE, self::CACHE_S, function () use ($cle) {
            try {
                $reponse = Http::timeout(10)->withToken($cle)->acceptJson()
                    ->get(rtrim((string) config('services.ollama.url_compte'), '/').'/api/balance');
            } catch (Throwable $e) {
                throw new ErreurIa("Solde Ollama illisible : {$e->getMessage()}", previous: $e);
            }

            if ($reponse->failed()) {
                $motif = match ($reponse->status()) {
                    401 => 'clé API refusée',
                    403 => 'compte suspendu',
                    429 => 'trop de lectures du solde',
                    503 => 'solde momentanément indisponible',
                    default => "erreur {$reponse->status()}",
                };
                throw new ErreurIa("Solde Ollama illisible : {$motif}.");
            }

            return (array) $reponse->json();
        }));
    }

    /** L'assistant peut-il envoyer une demande maintenant ? */
    public function verifier(): DecisionQuota
    {
        try {
            $etat = $this->etat();
        } catch (ErreurIa $e) {
            return new DecisionQuota(false, $e->getMessage(), CarbonImmutable::now()->addMinutes(15));
        }

        if ($etat->achatsUsd > 0) {
            return new DecisionQuota(false, sprintf('Crédits achetés présents (%.2f $) : ils seraient facturés une fois le forfait épuisé. Assistant arrêté par sécurité.', $etat->achatsUsd), null, $etat);
        }

        $fenetres = $etat->fenetres();
        if ($fenetres === []) {
            return new DecisionQuota(false, 'Solde Ollama sans aucune fenêtre connue : quota invérifiable.', CarbonImmutable::now()->addMinutes(15), $etat);
        }

        $reserve = fn (array $f): float => (float) Parametre::valeur($f['nom'] === '5 heures' ? 'ia_quota_reserve_5h_pct' : 'ia_quota_reserve_semaine_pct');
        $epuisees = array_filter($fenetres, fn ($f) => $f['restant'] <= $reserve($f));

        if ($epuisees !== []) {
            // Reprise quand toutes les fenêtres sous la réserve sont remises à zéro.
            $reprise = collect($epuisees)->pluck('remise')->filter()->max();
            $plusBasse = reset($epuisees);

            return new DecisionQuota(false, sprintf('Forfait sous la part réservée (%s %% sur la fenêtre %s, réserve %s %%).',
                self::nombre($plusBasse['restant']), $plusBasse['nom'], self::nombre($reserve($plusBasse))), $reprise, $etat);
        }

        return new DecisionQuota(true, 'Forfait disponible ('.collect($fenetres)
            ->map(fn ($f) => sprintf('%s : %s %% restant, réserve %s %%', $f['nom'], self::nombre($f['restant']), self::nombre($reserve($f))))
            ->implode(' ; ').').', null, $etat);
    }

    private static function nombre(float $valeur): string
    {
        return str_replace('.', ',', (string) round($valeur, 1));
    }
}
