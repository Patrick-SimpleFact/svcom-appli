<?php

namespace App\Jobs;

use App\Actions\ExecuterCollecte;
use App\Actions\SuperviserSources;
use App\Enums\StatutCollecte;
use App\Models\Collecte;
use App\Models\Source;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Tâche de fond : collecte d'une source, avec 4 essais (15 min, 30 min, 1 h d'écart, F7.2)
 * et jamais deux collectes en même temps pour une même source.
 */
class CollecterSource implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 4;

    /**
     * Une collecte attend d'abord la fin de celle en cours (une seule à la fois, ExecuterCollecte::VERROU), puis s'exécute :
     * la 1re d'un gros flux peut prendre une heure ou plus.
     */
    public int $timeout = 14400;

    public int $uniqueFor = 4 * 3600;

    public function __construct(
        public Source $source,
        public ?string $version = null,
    ) {}

    /** @return list<int> */
    public function backoff(): array
    {
        return config('collecte.delais_essais_secondes');
    }

    public function uniqueId(): string
    {
        return 'collecte-source-'.$this->source->id;
    }

    public function handle(ExecuterCollecte $executer): void
    {
        $executer->handle($this->source, $this->attempts(), version: $this->version);
    }

    /**
     * Après le dernier essai : la collecte est abandonnée et l'alerte part tout de suite (F7.9).
     * Aussi quand le worker est mort pendant le dernier essai (mémoire épuisée…) : Laravel appelle failed() au passage suivant,
     * et la collecte restée « en cours » est abandonnée à son tour.
     */
    public function failed(?Throwable $erreur): void
    {
        $derniere = Collecte::where('source_id', $this->source->id)->latest('id')->first();

        if (in_array($derniere?->statut, [StatutCollecte::Echouee, StatutCollecte::EnCours], true)) {
            $derniere->update([
                'statut' => StatutCollecte::Abandonnee,
                'fin' => $derniere->fin ?? now(),
                'erreur' => $derniere->erreur ?? 'Interrompue : le worker s’est arrêté pendant la collecte ('.($erreur?->getMessage() ?: 'cause inconnue').').',
            ]);
        }

        app(SuperviserSources::class)->handle($this->source);
    }
}
