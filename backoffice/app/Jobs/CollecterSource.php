<?php

namespace App\Jobs;

use App\Actions\ExecuterCollecte;
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

    /** Après le dernier essai : la collecte est abandonnée (l'alerte e-mail viendra à l'étape A03). */
    public function failed(?Throwable $erreur): void
    {
        Collecte::where('source_id', $this->source->id)
            ->where('statut', StatutCollecte::Echouee)
            ->latest('id')
            ->first()
            ?->update(['statut' => StatutCollecte::Abandonnee]);
    }
}
