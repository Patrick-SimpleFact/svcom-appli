<?php

namespace App\Console\Commands;

use App\Actions\FusionnerSpectacles;
use App\Actions\RattacherSpectacle;
use App\Collecte\ComparaisonSeances;
use App\Enums\FileATraiter;
use App\Models\ElementATraiter;
use App\Models\Offre;
use App\Models\Source;
use App\Models\Spectacle;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Rattrapage du 17/10/2026 : les agendas publics mettent la catégorie en tête du titre (« Humour “Les c*ns, c'est les
 * autres” ») et certains titres ont une lettre masquée ; le même spectacle avait donc deux fiches (487 et 24493 en
 * production). Le titre comparable de ces offres est recalculé sans la catégorie, puis chaque spectacle concerné rejoint
 * celui que la collecte lui choisirait aujourd'hui (règles de RattacherSpectacle, lui-même écarté) :
 * artiste commun, même lieu ou même billetterie → fusionné ; titre seul → fusionné et « à contrôler » ;
 * plusieurs spectacles au même titre → rien, « à contrôler ». Jamais deux spectacles séparés à la main, ni un spectacle
 * corrigé à la main. Sans effet une fois passé (les collectes suivantes calculent déjà ces titres).
 */
class RapprocherTitresCommande extends Command
{
    protected $signature = 'spectacles:rapprocher-titres {--simulation : lister sans rien modifier}';

    protected $description = 'Réunit les spectacles en double à cause d\'une catégorie en tête du titre ou d\'une lettre masquée';

    public function handle(ComparaisonSeances $comparaison, RattacherSpectacle $rattacher, FusionnerSpectacles $fusionner): int
    {
        $simulation = (bool) $this->option('simulation');
        $sources = Source::whereIn('code', ComparaisonSeances::SOURCES_CATEGORIE_EN_TETE)->pluck('nom', 'id');

        // 1. Titres comparables recalculés. En simulation, dans une transaction annulée à la fin.
        DB::beginTransaction();
        $concernees = $this->recalculerTitres($comparaison, $sources->keys()->all());
        $concernees += Offre::whereNotNull('spectacle_id')->where('titre_comparable', 'like', '%*%')->pluck('spectacle_id', 'id')->all();

        if (! $simulation) {
            DB::commit();
        }

        $this->info(($simulation ? 'Simulation : ' : '').count($concernees).' offre(s) avec une catégorie en tête du titre ou une lettre masquée.');

        // 2. Chaque spectacle concerné, avec l'une de ses offres concernées, cherche le spectacle qui lui correspond.
        $parSpectacle = collect($concernees)->unique()->sortKeys(); // la première offre concernée de chaque spectacle
        $issues = ['fusion' => [], 'controle' => [], 'ambigu' => []];
        $absorbes = []; // spectacle réuni → spectacle gardé (en simulation, rien n'est vidé : la paire ne compte qu'une fois)

        foreach ($parSpectacle as $offreId => $spectacleId) {
            $offre = Offre::with('source')->find($offreId);
            $spectacle = Spectacle::find($spectacleId);

            if ($offre === null || $spectacle === null || $offre->spectacle_id !== $spectacle->id || isset($absorbes[$spectacle->id])
                || ! empty($spectacle->champs_verrouilles)) {
                continue; // déjà réuni à un autre pendant ce rattrapage, ou corrigé à la main
            }

            [$cible, $niveau] = $rattacher->memeTitre($offre, sauf: $spectacle->id);

            if ($niveau === RattacherSpectacle::AMBIGU) {
                $issues['ambigu'][] = [$offre, $spectacle, null];
                $simulation || $rattacher->aControler($spectacle, RattacherSpectacle::MOTIF_AMBIGU);

                continue;
            }

            while ($cible !== null && isset($absorbes[$cible->id])) {
                $cible = Spectacle::find($absorbes[$cible->id]);
            }

            if ($cible === null || $cible->is($spectacle) || $this->separesALaMain($spectacle, $cible)) {
                continue;
            }

            $absorbes[$spectacle->id] = $cible->id;

            $issues[$niveau === RattacherSpectacle::TITRE_SEUL ? 'controle' : 'fusion'][] = [$offre, $spectacle, $cible];

            if (! $simulation) {
                $fusionner->handle($spectacle, $cible);
                $niveau === RattacherSpectacle::TITRE_SEUL && $rattacher->aControler($cible);
            }
        }

        if ($simulation) {
            DB::rollBack();
        }

        $this->rapport($issues, $simulation);

        return self::SUCCESS;
    }

    /**
     * @param  list<int>  $sources
     * @return array<int, int> offre → spectacle, pour les offres dont le titre commence par une catégorie
     */
    private function recalculerTitres(ComparaisonSeances $comparaison, array $sources): array
    {
        $concernees = [];

        Offre::with('lieu:id,nom')->whereIn('source_id', $sources)->whereNotNull('spectacle_id')
            ->select(['id', 'spectacle_id', 'lieu_id', 'titre_comparable', 'donnees_normalisees'])
            ->chunkById(2000, function ($offres) use ($comparaison, &$concernees) {
                foreach ($offres as $offre) {
                    $donnees = $offre->donnees_normalisees;

                    if ($comparaison->categorieEnTete((string) ($donnees['titre'] ?? '')) === null) {
                        continue;
                    }

                    $concernees[$offre->id] = $offre->spectacle_id;
                    $titre = mb_substr($comparaison->titreComparable($donnees['titre'], $donnees['lieu_nom'] ?? $offre->lieu?->nom, $donnees['lieu_ville'] ?? null, true), 0, 255);

                    if ($titre !== $offre->titre_comparable) {
                        Offre::whereKey($offre->id)->update(['titre_comparable' => $titre]);
                    }
                }
            });

        return $concernees;
    }

    /** Deux spectacles séparés depuis « Spectacles à contrôler » ne sont jamais réunis. */
    private function separesALaMain(Spectacle $a, Spectacle $b): bool
    {
        return ElementATraiter::where('file', FileATraiter::SpectacleAControler)
            ->whereIn('cible_id', [$a->id, $b->id])
            ->whereRaw("(decision->>'nouveau_spectacle_id')::bigint in (?, ?)", [$a->id, $b->id])
            ->exists();
    }

    /** @param  array<string, list<array{0: Offre, 1: Spectacle, 2: ?Spectacle}>>  $issues */
    private function rapport(array $issues, bool $simulation): void
    {
        $titres = [
            'fusion' => $simulation ? 'Seraient réunis (artiste commun, même lieu ou même billetterie)' : 'Réunis (artiste commun, même lieu ou même billetterie)',
            'controle' => $simulation ? 'Seraient réunis sur le titre seul, puis à contrôler' : 'Réunis sur le titre seul, à contrôler',
            'ambigu' => 'Plusieurs spectacles portent ce titre : rien de réuni, à contrôler',
        ];

        foreach ($titres as $issue => $titre) {
            $this->newLine();
            $this->line('<options=bold>'.$titre.' : '.count($issues[$issue]).'</>');

            foreach ($issues[$issue] as [$offre, $spectacle, $cible]) {
                $this->line('['.$offre->source?->nom.'] #'.$spectacle->id.' « '.trim($spectacle->titre).' »'
                    .($cible ? '  →  #'.$cible->id.' « '.trim($cible->titre).' »' : ''));
            }
        }
    }
}
