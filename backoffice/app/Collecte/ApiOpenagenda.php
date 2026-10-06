<?php

namespace App\Collecte;

use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Accès à l'API OpenAgenda v2 (clé dans OPENAGENDA_API_KEY) : recherche d'agendas, événements d'un agenda.
 */
class ApiOpenagenda
{
    /** Taille de page maximale acceptée par l'API pour les événements. */
    private const PAGE = 300;

    /**
     * Agendas dont le nom ou la description contient le texte (officiels d'abord).
     *
     * @return list<array{uid: string, nom: string, slug: ?string, officiel: bool}>
     */
    public function chercherAgendas(string $texte, int $maximum = 100): array
    {
        $agendas = [];
        $after = null;

        do {
            $reponse = $this->get('/agendas', array_filter(['search' => $texte, 'size' => 100, 'after[]' => $after]));
            foreach ($reponse['agendas'] ?? [] as $agenda) {
                $agendas[(string) $agenda['uid']] = ['uid' => (string) $agenda['uid'], 'nom' => (string) ($agenda['title'] ?? $agenda['uid']), 'slug' => $agenda['slug'] ?? null, 'officiel' => (bool) ($agenda['official'] ?? false)];
            }
            $after = $reponse['after'] ?? null;
        } while ($after && count($agendas) < $maximum && ($reponse['agendas'] ?? []) !== []);

        $agendas = array_values($agendas); // un même agenda peut revenir sur deux pages
        usort($agendas, fn ($a, $b) => $b['officiel'] <=> $a['officiel']);

        return array_slice($agendas, 0, $maximum);
    }

    /**
     * L'agenda désigné par son adresse (https://openagenda.com/fr/avignon), son slug ou son identifiant.
     *
     * @return array{uid: string, nom: string, slug: ?string, officiel: bool}|null
     */
    public function agenda(string $adresseOuIdentifiant): ?array
    {
        $texte = trim($adresseOuIdentifiant);
        $parametres = ctype_digit($texte)
            ? ['uid' => [$texte]]
            : ['slug' => [preg_match('#openagenda\.com/(?:[a-z]{2}/)?([^/?\#]+)#', $texte, $m) ? $m[1] : $texte]];
        $agenda = $this->get('/agendas', [...$parametres, 'size' => 1])['agendas'][0] ?? null;

        return $agenda === null ? null
            : ['uid' => (string) $agenda['uid'], 'nom' => (string) ($agenda['title'] ?? $agenda['uid']), 'slug' => $agenda['slug'] ?? null, 'officiel' => (bool) ($agenda['official'] ?? false)];
    }

    /**
     * Événements en cours et à venir d'un agenda (toutes les pages).
     *
     * @return list<array<string, mixed>>
     */
    public function evenementsAVenir(string $uid): array
    {
        $evenements = [];
        $after = null;

        do {
            $parametres = ['size' => self::PAGE, 'detailed' => 1, 'monolingual' => 'fr', 'relative' => ['current', 'upcoming']];
            if ($after !== null) {
                $parametres['after'] = $after;
            }
            $reponse = $this->get("/agendas/{$uid}/events", $parametres);
            $page = $reponse['events'] ?? [];
            array_push($evenements, ...$page);
            $after = $reponse['after'] ?? null;
        } while ($after && $page !== []);

        return $evenements;
    }

    /** Date du dernier horaire passé de l'agenda (pour repérer les agendas abandonnés). */
    public function dernierHorairePasse(string $uid): ?string
    {
        $reponse = $this->get("/agendas/{$uid}/events", ['size' => 1, 'relative' => ['passed'], 'sort' => 'lastTiming.desc', 'monolingual' => 'fr']);

        return $reponse['events'][0]['lastTiming']['begin'] ?? null;
    }

    private function get(string $chemin, array $parametres): array
    {
        $cle = config('services.openagenda.cle');

        if (blank($cle)) {
            throw new RuntimeException('Clé d’API OpenAgenda absente (OPENAGENDA_API_KEY).');
        }

        // Les tableaux (relative[], after[]) sont écrits « clé[]=valeur » comme l'attend l'API.
        $requete = http_build_query([...$parametres, 'key' => $cle]);
        $requete = preg_replace('/%5B\d+%5D=/', '%5B%5D=', $requete);
        $reponse = Http::timeout(60)->retry(3, 2000, throw: false)->get(config('services.openagenda.adresse').$chemin.'?'.$requete);

        if (! $reponse->successful()) {
            throw new RuntimeException("API OpenAgenda {$chemin} : erreur {$reponse->status()}.");
        }

        return $reponse->json() ?? [];
    }
}
