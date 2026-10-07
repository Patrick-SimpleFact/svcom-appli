<?php

namespace App\Models\Concerns;

use App\Models\Admin;
use App\Models\JournalAction;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * Un champ corrigé à la main par un admin est « verrouillé » :
 * les imports et la collecte ne l'écrasent plus (F7.8).
 */
trait VerrouilleCorrections
{
    /** Champs qui ne se verrouillent pas (techniques ou d'administration). */
    protected function champsNonVerrouillables(): array
    {
        return ['champs_verrouilles', 'masque', 'fusionne_dans_id', 'nom_normalise', 'titre_normalise', 'created_at', 'updated_at'];
    }

    public static function bootVerrouilleCorrections(): void
    {
        static::updating(function (Model $modele) {
            if (! auth()->user() instanceof Admin) {
                return;
            }

            $corriges = array_diff(array_keys($modele->getDirty()), $modele->champsNonVerrouillables());

            if ($corriges !== []) {
                $modele->champs_verrouilles = array_values(array_unique([...($modele->champs_verrouilles ?? []), ...$corriges]));
            }
        });
    }

    public function estVerrouille(string $champ): bool
    {
        return in_array($champ, $this->champs_verrouilles ?? [], true);
    }

    /**
     * Les champs corrigés à la main, avec la date et l'auteur de la dernière correction (journal des actions).
     *
     * @return Collection<string, array{le: ?CarbonInterface, par: ?string}>
     */
    public function correctionsDatees(): Collection
    {
        $champs = $this->champs_verrouilles ?? [];

        if ($champs === []) {
            return collect();
        }

        $journal = JournalAction::with('admin')
            ->where('cible_type', $this->getMorphClass())->where('cible_id', $this->getKey())
            ->whereNotNull('admin_id')
            ->latest('id')
            ->get();

        return collect($champs)->mapWithKeys(function (string $champ) use ($journal) {
            $action = $journal->first(fn (JournalAction $a) => array_key_exists($champ, $a->apres ?? []));

            return [$champ => ['le' => $action?->created_at, 'par' => $action?->admin?->nom]];
        });
    }

    /** « titre (07/10/2026, Patrick), horaire (…) » : affiché sur les fiches du back-office. */
    public function resumeCorrections(array $libelles = []): ?string
    {
        $corrections = $this->correctionsDatees();

        return $corrections->isEmpty() ? null : $corrections
            ->map(fn (array $c, string $champ) => ($libelles[$champ] ?? $champ)
                .' ('.collect([$c['le']?->setTimezone('Europe/Paris')->format('d/m/Y H:i'), $c['par']])->filter()->implode(', ').')')
            ->implode(' ; ');
    }
}
