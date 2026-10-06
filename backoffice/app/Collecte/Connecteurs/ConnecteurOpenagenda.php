<?php

namespace App\Collecte\Connecteurs;

use App\Collecte\AnnonceNormalisee;
use App\Collecte\ApiOpenagenda;
use App\Collecte\CollecteParIntervalle;
use App\Collecte\Connecteur;
use App\Collecte\LigneIllisible;
use App\Enums\FrequenceAgenda;
use App\Models\AgendaOpenagenda;
use App\Models\Source;
use Carbon\CarbonImmutable;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

/**
 * OpenAgenda (API, COLLECTE §3) : pas de recherche nationale, on interroge un par un les agendas suivis (écran
 * « Agendas OpenAgenda »), toutes les 4 h de 6 h à 22 h. Chaque passage prend tous les événements en cours et à venir
 * (et non les seuls modifiés : le moteur retire ce qu'il ne voit plus). Un agenda abandonné (rien à venir, dernier
 * événement il y a plus d'un an) n'est interrogé qu'une fois par semaine, et redevient normal dès qu'il publie.
 * Chaque horaire est une séance ; un événement relayé par plusieurs agendas n'est lu qu'une fois.
 */
class ConnecteurOpenagenda implements CollecteParIntervalle, Connecteur
{
    private const FUSEAU = 'Europe/Paris';

    /** Statuts OpenAgenda : 4 reporté, 5 complet, 6 annulé. */
    private const REPORTE = 4;

    private const COMPLET = 5;

    private const ANNULE = 6;

    public function __construct(private ApiOpenagenda $api) {}

    public function intervalleHeures(): int
    {
        return 4;
    }

    public function plageHoraire(): array
    {
        return [6, 22];
    }

    public function extensionBrut(): string
    {
        return 'json';
    }

    public function telecharger(Source $source): string
    {
        $agendas = AgendaOpenagenda::where('actif', true)
            ->where(fn ($q) => $q->where('frequence', FrequenceAgenda::Normale)
                ->orWhereNull('derniere_collecte_le')
                ->orWhere('derniere_collecte_le', '<', now()->subWeek()))
            ->orderBy('id')
            ->get();

        $brut = ['agendas' => []];

        foreach ($agendas as $agenda) {
            try {
                $evenements = $this->api->evenementsAVenir($agenda->uid);
            } catch (RuntimeException $erreur) {
                if (preg_match('/erreur (403|404)/', $erreur->getMessage())) {
                    $agenda->update(['actif' => false]); // supprimé ou devenu privé

                    continue;
                }

                throw $erreur; // panne : la collecte entière sera retentée (sinon ses événements seraient retirés à tort)
            }

            $this->suivre($agenda, $evenements);
            $brut['agendas'][] = ['uid' => $agenda->uid, 'slug' => $agenda->slug, 'events' => $evenements];
        }

        return json_encode($brut, JSON_UNESCAPED_UNICODE);
    }

    public function lire(string $contenuBrut, Source $source): iterable
    {
        $vus = [];

        foreach (json_decode($contenuBrut, true)['agendas'] ?? [] as $agenda) {
            foreach ($agenda['events'] ?? [] as $evenement) {
                $uid = (string) ($evenement['uid'] ?? '');

                if ($uid === '' || isset($vus[$uid])) {
                    continue; // relayé par plusieurs agendas : lu une fois
                }

                $vus[$uid] = true;

                try {
                    foreach ($this->annonces($evenement, $agenda['slug'] ?? $agenda['uid']) as $annonce) {
                        yield $annonce;
                    }
                } catch (InvalidArgumentException|Throwable $erreur) {
                    yield new LigneIllisible("Événement {$uid} : ".$erreur->getMessage(), $uid);
                }
            }
        }
    }

    /** @return iterable<AnnonceNormalisee> une par horaire à venir */
    private function annonces(array $evenement, string $agendaSlug): iterable
    {
        $statut = (int) ($evenement['status'] ?? 1);

        if (in_array($statut, [self::ANNULE, self::REPORTE], true)) {
            return;
        }

        $lieu = $evenement['location'] ?? [];
        $fuseau = $lieu['timezone'] ?? $evenement['timezone'] ?? self::FUSEAU;
        $conditions = mb_strtolower((string) ($evenement['conditions'] ?? ''));
        $image = $evenement['image'] ?? null;
        $aujourdhui = CarbonImmutable::today($fuseau);
        $motsCles = $evenement['keywords'] ?? [];
        $motsCles = is_array($motsCles) && array_is_list($motsCles) ? $motsCles : ($motsCles['fr'] ?? []);

        foreach ($evenement['timings'] ?? [] as $horaire) {
            $debut = CarbonImmutable::parse($horaire['begin'])->setTimezone($fuseau);

            if ($debut->lt($aujourdhui)) {
                continue;
            }

            $fin = isset($horaire['end']) ? CarbonImmutable::parse($horaire['end'])->setTimezone($fuseau) : null;

            yield new AnnonceNormalisee(
                identifiantExterne: $evenement['uid'].'@'.$debut->utc()->format('Y-m-d\TH:i'),
                titre: trim((string) ($evenement['title'] ?? '')),
                debut: $debut,
                heureConnue: $debut->format('H:i') !== '00:00',
                lien: "https://openagenda.com/fr/{$agendaSlug}/events/".($evenement['slug'] ?? $evenement['uid']),
                lieuNom: trim((string) ($lieu['name'] ?? '')) ?: null,
                lieuAdresse: trim((string) ($lieu['address'] ?? '')) ?: null,
                lieuCodePostal: trim((string) ($lieu['postalCode'] ?? '')) ?: null,
                lieuVille: trim((string) ($lieu['city'] ?? '')) ?: null,
                lieuLatitude: is_numeric($lieu['latitude'] ?? null) ? (float) $lieu['latitude'] : null,
                lieuLongitude: is_numeric($lieu['longitude'] ?? null) ? (float) $lieu['longitude'] : null,
                fin: $fin,
                categoriesSource: array_values(array_filter(array_map(fn ($m) => is_string($m) ? trim($m) : '', $motsCles))),
                description: trim(strip_tags((string) ($evenement['description'] ?? ''))) ?: null,
                imageUrl: is_array($image) && isset($image['base'], $image['filename']) ? $image['base'].$image['filename'] : null,
                gratuit: str_contains($conditions, 'gratuit') || str_contains($conditions, 'entrée libre'),
                complet: $statut === self::COMPLET,
                misAJourSource: isset($evenement['updatedAt']) ? CarbonImmutable::parse($evenement['updatedAt']) : null,
                identifiantSpectacle: (string) $evenement['uid'],
            );
        }
    }

    /** Met à jour le suivi de l'agenda : dernier événement, nombre à venir, fréquence d'interrogation. */
    private function suivre(AgendaOpenagenda $agenda, array $evenements): void
    {
        $dernier = collect($evenements)->map(fn ($e) => $e['lastTiming']['begin'] ?? null)->filter()->max()
            ?? ($agenda->dernier_evenement_le === null ? $this->api->dernierHorairePasse($agenda->uid) : null);

        $valeurs = [
            'derniere_collecte_le' => now(),
            'nb_evenements_a_venir' => count($evenements),
        ];

        if ($dernier !== null) {
            $valeurs['dernier_evenement_le'] = CarbonImmutable::parse($dernier);
        }

        $dernierConnu = $valeurs['dernier_evenement_le'] ?? $agenda->dernier_evenement_le;
        $valeurs['frequence'] = $evenements === [] && ($dernierConnu === null || $dernierConnu->lt(now()->subYear()))
            ? FrequenceAgenda::Hebdomadaire
            : FrequenceAgenda::Normale;

        $agenda->update($valeurs);
    }
}
