<?php

namespace App\Actions;

use App\Collecte\CollecteParIntervalle;
use App\Collecte\DetecteVersion;
use App\Collecte\RegistreConnecteurs;
use App\Enums\StatutCollecte;
use App\Jobs\CollecterSource;
use App\Models\Collecte;
use App\Models\Source;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Toutes les 30 min : pour chaque source active, faut-il lancer une collecte ? (COLLECTE §1, F7.2)
 * - source avec version publiée : seulement si la version a changé depuis la dernière collecte réussie ;
 * - source sans version : à intervalle régulier, dans sa plage horaire.
 */
class DetecterMisesAJour
{
    public function __construct(private RegistreConnecteurs $registre) {}

    /** @return list<string> codes des sources dont la collecte a été lancée */
    public function handle(): array
    {
        $lancees = [];

        foreach (Source::where('actif', true)->orderBy('id')->get() as $source) {
            if (! $this->registre->existe($source)) {
                continue;
            }

            try {
                if ($this->verifier($source)) {
                    $lancees[] = $source->code;
                }
            } catch (Throwable $erreur) {
                // Une source qui ne répond pas n'empêche pas de vérifier les autres.
                $source->update(['derniere_verification_le' => now(), 'erreur_detection' => mb_substr($erreur->getMessage(), 0, 1000)]);
                Log::warning("Détection impossible pour {$source->code} : {$erreur->getMessage()}");
            }
        }

        return $lancees;
    }

    private function verifier(Source $source): bool
    {
        $connecteur = $this->registre->pour($source);

        if ($connecteur instanceof DetecteVersion) {
            $version = $connecteur->versionDisponible($source);
            $source->update(['derniere_verification_le' => now(), 'dernier_contact_le' => now(), 'derniere_version_vue' => $version, 'erreur_detection' => null]);

            $derniereCollectee = Collecte::where('source_id', $source->id)
                ->where('statut', StatutCollecte::Reussie)
                ->latest('id')
                ->value('version_detectee');

            if ($version !== null && $version === $derniereCollectee) {
                return false;
            }

            CollecterSource::dispatch($source, $version);

            return true;
        }

        if ($connecteur instanceof CollecteParIntervalle) {
            $source->update(['derniere_verification_le' => now(), 'dernier_contact_le' => now(), 'erreur_detection' => null]);

            [$debut, $fin] = $connecteur->plageHoraire();
            $heure = now('Europe/Paris')->hour;

            if ($heure < $debut || $heure >= $fin) {
                return false;
            }

            $derniere = Collecte::where('source_id', $source->id)->latest('id')->value('debut');

            if ($derniere !== null && now()->diffInMinutes($derniere, absolute: true) < $connecteur->intervalleHeures() * 60) {
                return false;
            }

            CollecterSource::dispatch($source);

            return true;
        }

        return false;
    }
}
