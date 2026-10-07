<?php

namespace App\Actions;

use App\Enums\FileATraiter;
use App\Enums\StatutElement;
use App\Enums\StatutRepresentation;
use App\Enums\TypeRepresentation;
use App\Models\ElementATraiter;
use App\Support\Texte;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Non-régression de la collecte (COLLECTE §9) : compare le catalogue publié à une journée étudiée par le POC.
 *
 * Chaque ligne du POC (heure, titre, lieu) est cherchée parmi les représentations du même jour,
 * dans le même rayon autour du centre de la ville : titre proche et, si les deux ont une heure, moins de 30 min d'écart.
 * Une ligne non retrouvée est expliquée si possible (offre disparue du flux, annonce en attente dans « À trier »).
 */
class VerifierNonRegression
{
    public const ECART_HEURE_MINUTES = 30;

    public const DOSSIER = 'non-regression';

    /**
     * @return array{date: string, villes: list<array{code: string, nom: string, poc: int, retrouvees: int, retirees: int, absentes: int, catalogue: int, en_plus: int, lignes: list<array>}>}
     */
    public function handle(string $journee, ?string $ville = null): array
    {
        // Nom d'une journée de database/non-regression, ou chemin d'un dossier (tests).
        $dossier = is_dir($journee) ? $journee : database_path(self::DOSSIER.'/'.$journee);
        $description = json_decode((string) @file_get_contents($dossier.'/journee.json'), true);

        if (! is_array($description)) {
            throw new RuntimeException("Journée de référence introuvable : {$dossier}/journee.json");
        }

        $date = $description['date'];
        $resultat = ['date' => $date, 'villes' => []];

        foreach ($description['villes'] as $zone) {
            if ($ville !== null && $zone['code'] !== $ville) {
                continue;
            }

            $resultat['villes'][] = $this->comparerVille($zone, $date, $this->lireCsv("{$dossier}/{$zone['code']}.csv"));
        }

        return $resultat;
    }

    /** @return list<array{heure: ?string, titre: string, lieu: string, sources: string}> */
    private function lireCsv(string $chemin): array
    {
        $lignes = [];
        $fichier = fopen($chemin, 'r');
        $entete = null;

        while (($valeurs = fgetcsv($fichier, separator: ';', escape: '')) !== false) {
            if ($entete === null) {
                $entete = array_map(fn ($c) => trim($c, "\u{FEFF} "), $valeurs);

                continue;
            }

            $ligne = array_combine($entete, array_pad($valeurs, count($entete), ''));
            $lignes[] = [
                'heure' => $ligne['heure'] !== '' ? $ligne['heure'] : null,
                'titre' => $ligne['titre'],
                'lieu' => $ligne['lieu'],
                'sources' => $ligne['sources'],
            ];
        }

        fclose($fichier);

        return $lignes;
    }

    private function comparerVille(array $zone, string $date, array $lignesPoc): array
    {
        $centre = sprintf('SRID=4326;POINT(%.7F %.7F)', $zone['longitude'], $zone['latitude']);
        $rayon = $zone['rayon_km'] * 1000;

        // Représentations du jour (séances du jour ou périodes qui le couvrent), tous statuts,
        // avec le titre du spectacle et ceux de toutes ses offres (chaque billetterie a le sien).
        $catalogue = collect(DB::select(<<<'SQL'
            select r.id, r.statut, r.type, s.titre, l.nom as lieu,
                   to_char(r.debut at time zone coalesce(l.fuseau_horaire, 'Europe/Paris'), 'HH24:MI') as heure,
                   (select string_agg(distinct so.code, ', ') from offres o join sources so on so.id = o.source_id where o.representation_id = r.id) as sources,
                   (select string_agg(distinct o.donnees_normalisees->>'titre', '|') from offres o where o.representation_id = r.id) as titres_offres
            from representations r
            join spectacles s on s.id = r.spectacle_id
            join lieux l on l.id = r.lieu_id
            where r.date_locale <= ? and coalesce(r.date_fin, r.date_locale) >= ?
              and ST_DWithin(r.position, ?::geography, ?)
            SQL, [$date, $date, $centre, $rayon]))
            ->map(fn ($r) => (array) $r + ['titres_normalises' => self::normaliserTitres([$r->titre, ...explode('|', (string) $r->titres_offres)])]);

        // Offres du jour (publiées ou non, disparues comprises), pour expliquer les absences.
        $offres = collect(DB::select(<<<'SQL'
            select o.donnees_normalisees->>'titre' as titre, o.disparue_le,
                   case when o.heure_connue then 'seance' else 'jour' end as type,
                   to_char(o.debut at time zone coalesce(l.fuseau_horaire, 'Europe/Paris'), 'HH24:MI') as heure
            from offres o
            join lieux l on l.id = o.lieu_id
            where o.date_locale <= ? and coalesce(o.date_fin, o.date_locale) >= ?
              and ST_DWithin(l.position, ?::geography, ?)
            SQL, [$date, $date, $centre, $rayon]))
            ->map(fn ($o) => (array) $o + ['titres_normalises' => [Texte::normaliser($o->titre)]]);

        $trouvees = [];
        $lignes = [];

        foreach ($lignesPoc as $ligne) {
            $correspond = fn (array $r) => self::correspond($ligne, $r['titres_normalises'], $r['heure'], $r['type']);

            // Une représentation publiée passe avant une représentation retirée.
            $meilleure = $catalogue->filter($correspond)
                ->sortBy(fn ($r) => $r['statut'] === StatutRepresentation::Programmee->value ? 0 : 1)
                ->first();

            if ($meilleure === null) {
                $etat = 'absente';
                $offre = $offres->first($correspond);
                $explication = match (true) {
                    $offre !== null && $offre['disparue_le'] !== null => 'offre disparue du flux de la source',
                    $offre !== null => 'offre collectée mais non publiée',
                    $this->enAttenteDeTri($ligne['titre']) => 'annonce en attente dans « À trier »',
                    default => 'absente du catalogue (exclue par le filtre, annulée ou plus vendue)',
                };
            } elseif ($meilleure['statut'] === StatutRepresentation::Programmee->value) {
                $etat = 'retrouvee';
                $explication = null;
                $trouvees[$meilleure['id']] = true;
            } else {
                $etat = 'retiree';
                $explication = 'représentation « '.StatutRepresentation::from($meilleure['statut'])->getLabel().' »';
                $trouvees[$meilleure['id']] = true;
            }

            $lignes[] = $ligne + [
                'etat' => $etat,
                'explication' => $explication,
                'titre_catalogue' => $meilleure['titre'] ?? null,
                'lieu_catalogue' => $meilleure['lieu'] ?? null,
                'sources_catalogue' => $meilleure['sources'] ?? null,
            ];
        }

        $publiees = $catalogue->where('statut', StatutRepresentation::Programmee->value);
        $compter = fn (string $etat) => count(array_filter($lignes, fn ($l) => $l['etat'] === $etat));

        return [
            'code' => $zone['code'],
            'nom' => $zone['nom'],
            'poc' => count($lignes),
            'retrouvees' => $compter('retrouvee'),
            'retirees' => $compter('retiree'),
            'absentes' => $compter('absente'),
            'catalogue' => $publiees->count(),
            'en_plus' => $publiees->reject(fn ($r) => isset($trouvees[$r['id']]))->count(),
            'lignes' => $lignes,
        ];
    }

    /** Titres normalisés de la file « À trier » (un élément par annonce, daté de sa 1re séance : on compare le titre seul). */
    private ?array $titresATrier = null;

    private function enAttenteDeTri(string $titrePoc): bool
    {
        $this->titresATrier ??= ElementATraiter::where('file', FileATraiter::ATrier)
            ->where('statut', StatutElement::EnAttente)
            ->pluck('donnees')
            ->map(fn ($donnees) => Texte::normaliser($donnees['titre'] ?? ''))
            ->unique()
            ->all();

        $normalise = Texte::normaliser($titrePoc);

        // Égalité d'abord (rapide), puis rapprochement souple.
        return in_array($normalise, $this->titresATrier, true)
            || array_any($this->titresATrier, fn ($titre) => self::titresProches($titrePoc, $titre));
    }

    /** @return list<string> */
    private static function normaliserTitres(array $titres): array
    {
        return array_values(array_unique(array_filter(array_map(Texte::normaliser(...), $titres))));
    }

    /** Une ligne du POC correspond si l'un des titres est proche et que les heures sont compatibles. */
    private static function correspond(array $ligne, array $titresNormalises, ?string $heure, string $type): bool
    {
        if (! self::heuresCompatibles($ligne['heure'], $heure, $type)) {
            return false;
        }

        foreach ($titresNormalises as $titre) {
            if (self::titresProches($ligne['titre'], $titre)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Titres proches : identiques, l'un contient l'autre, ou au moins 60 % des mots du plus court en commun
     * (le POC et les sources ajoutent souvent le lieu ou un sous-titre : « Mamouchka, l'Apprentie Sorcière - Café Théâtre… »).
     */
    public static function titresProches(string $titrePoc, string $titreNormalise): bool
    {
        $a = Texte::normaliser($titrePoc);
        $b = $titreNormalise;

        if ($a === '' || $b === '') {
            return false;
        }

        if ($a === $b || (min(strlen($a), strlen($b)) >= 4 && (str_contains($a, $b) || str_contains($b, $a)))) {
            return true;
        }

        $mots = fn (string $t) => array_values(array_unique(array_filter(explode(' ', $t), fn ($m) => strlen($m) >= 3)));
        [$motsA, $motsB] = [$mots($a), $mots($b)];

        if ($motsA === [] || $motsB === []) {
            return false;
        }

        return count(array_intersect($motsA, $motsB)) / min(count($motsA), count($motsB)) >= 0.6;
    }

    /** Sans heure d'un côté (POC sans horaire, journée ou période), seul le jour compte. */
    public static function heuresCompatibles(?string $heurePoc, ?string $heureCatalogue, string $type): bool
    {
        if ($heurePoc === null || $heureCatalogue === null || $type !== TypeRepresentation::Seance->value) {
            return true;
        }

        $ecart = abs(CarbonImmutable::createFromFormat('H:i', $heurePoc)->diffInMinutes(CarbonImmutable::createFromFormat('H:i', $heureCatalogue)));

        return $ecart <= self::ECART_HEURE_MINUTES;
    }
}
