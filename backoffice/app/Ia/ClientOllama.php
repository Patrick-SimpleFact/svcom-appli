<?php

namespace App\Ia;

use App\Models\AppelIa;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Demandes à l'Ollama du serveur, qui transmet aux modèles « :cloud » du forfait de Patrick (plan-module-ia).
 * - seuls les modèles « cloud » sont acceptés : un modèle local tournerait sur le VPS et l'écraserait ;
 * - la réponse doit être un objet JSON conforme au schéma (clés obligatoires, valeurs permises) : les modèles cloud
 *   ne respectent pas toujours `format`, on vérifie donc nous-mêmes, et toute réponse hors cadre est rejetée ;
 * - chaque demande est journalisée dans `appels_ia` (jetons, durée, erreur).
 */
class ClientOllama
{
    /** Modèles hors forfait (facturés en supplément) : jamais utilisés, même s'ils sont proposés par ollama.com. */
    public const INTERDITS = ['kimi-k3', 'deepseek-v4-pro'];

    public const DELAI_S = 180;

    public const TENTATIVES = 3;

    /** Liste des modèles cloud gardée en cache (elle change rarement). */
    public const CACHE_MODELES_S = 6 * 3600;

    public function url(): string
    {
        return rtrim((string) config('services.ollama.url'), '/');
    }

    /**
     * Modèles cloud proposés par ollama.com, sous le nom à donner à l'Ollama local
     * (« gpt-oss:120b » → « gpt-oss:120b-cloud », « kimi-k3 » → « kimi-k3:cloud »). Liste vide si ollama.com ne répond pas.
     *
     * @return list<string>
     */
    public function modelesCloud(): array
    {
        $modeles = Cache::get('ia:modeles');

        if ($modeles === null) {
            try {
                $requete = Http::timeout(15)->acceptJson();
                if (filled($cle = config('services.ollama.cle_api'))) {
                    $requete = $requete->withToken($cle);
                }
                $modeles = collect($requete->get(rtrim((string) config('services.ollama.url_compte'), '/').'/api/tags')->throw()->json('models', []))
                    ->pluck('name')->filter(fn ($nom) => is_string($nom) && $nom !== '')
                    ->map(fn (string $nom) => str_contains($nom, ':') ? "{$nom}-cloud" : "{$nom}:cloud")
                    ->unique()->sort()->values()->all();
            } catch (Throwable) {
                return [];
            }

            Cache::put('ia:modeles', $modeles, self::CACHE_MODELES_S);
        }

        return array_values(array_filter($modeles, fn (string $m) => self::refus($m) === null));
    }

    /** Version de l'Ollama joint (vérifie que le serveur répond). */
    public function version(): string
    {
        try {
            return (string) Http::timeout(10)->get($this->url().'/api/version')->throw()->json('version');
        } catch (Throwable $e) {
            throw new ErreurIa("Ollama injoignable à {$this->url()} : {$e->getMessage()}", previous: $e);
        }
    }

    public static function estCloud(string $modele): bool
    {
        return (bool) preg_match('/[:-]cloud$/', $modele);
    }

    /** Pourquoi ce modèle est refusé d'office (pas cloud, ou hors forfait), ou null. */
    public static function refus(string $modele): ?string
    {
        if (! self::estCloud($modele)) {
            return "« {$modele} » n’est pas un modèle cloud (son nom doit finir par :cloud ou -cloud) : il tournerait sur le serveur.";
        }

        foreach (self::INTERDITS as $interdit) {
            if (str_starts_with($modele, $interdit.':') || str_starts_with($modele, $interdit.'-')) {
                return "« {$modele} » est facturé en plus du forfait Ollama : il n’est jamais utilisé.";
            }
        }

        return null;
    }

    /** Le modèle est-il utilisable ? null si oui, sinon la raison. Ne consomme pas de forfait (`/api/show` : description du modèle). */
    public function verifierModele(string $modele): ?string
    {
        if ($refus = self::refus($modele)) {
            return $refus;
        }

        try {
            $reponse = Http::timeout(15)->post($this->url().'/api/show', ['model' => $modele]);
        } catch (Throwable) {
            return "Ollama injoignable à {$this->url()} : impossible de vérifier le modèle.";
        }

        return match (true) {
            $reponse->successful() => null,
            $reponse->status() === 404 => "Ollama ne connaît pas « {$modele} » (".mb_strimwidth((string) $reponse->json('error'), 0, 150, '…').').',
            default => "Ollama a répondu {$reponse->status()} : impossible de vérifier le modèle.",
        };
    }

    /**
     * @param  list<array{role: string, content: string}>  $messages
     * @param  array<string, mixed>  $schema  schéma JSON d'un objet (properties, required, enum au premier niveau)
     */
    public function demander(string $tache, array $messages, array $schema, int $nbElements = 1, ?string $modele = null): ReponseIa
    {
        $modele ??= Assistant::modelesNiveau1()[0] ?? '';

        if ($refus = self::refus($modele)) {
            throw new ErreurIa($refus);
        }

        $appel = AppelIa::create(['tache' => $tache, 'modele' => $modele, 'nb_elements' => $nbElements]);
        $debut = hrtime(true);

        try {
            $reponse = Http::timeout(self::DELAI_S)
                ->retry(self::TENTATIVES, 2000, fn (Throwable $e) => $e instanceof ConnectionException
                    || ($e instanceof RequestException && $e->response->serverError()), throw: false)
                ->post($this->url().'/api/chat', [
                    'model' => $modele,
                    'messages' => $messages,
                    'stream' => false,
                    'format' => $schema,
                    'options' => ['temperature' => 0],
                ]);

            $appel->fill([
                'duree_ms' => (int) ((hrtime(true) - $debut) / 1_000_000),
                'jetons_entree' => $reponse->json('prompt_eval_count'),
                'jetons_sortie' => $reponse->json('eval_count'),
            ]);

            if ($reponse->failed()) {
                throw new ErreurIa("Ollama a répondu {$reponse->status()} : ".mb_strimwidth((string) ($reponse->json('error') ?? $reponse->body()), 0, 300, '…'));
            }

            $contenu = $this->verifier($this->decoder((string) $reponse->json('message.content')), $schema);
            $appel->save();

            return new ReponseIa($contenu, $modele, $appel);
        } catch (Throwable $e) {
            $erreur = $e instanceof ErreurIa ? $e : new ErreurIa("Appel à Ollama impossible : {$e->getMessage()}", previous: $e);
            $appel->fill(['erreur' => mb_strimwidth($erreur->getMessage(), 0, 500, '…')]);
            $appel->duree_ms ??= (int) ((hrtime(true) - $debut) / 1_000_000);
            $appel->save();

            throw $erreur;
        }
    }

    /** @return array<string, mixed> */
    private function decoder(string $texte): array
    {
        $donnees = json_decode($texte, true);

        // Certains modèles entourent l'objet de texte ou de ```json : on prend du premier « { » au dernier « } ».
        if (! is_array($donnees) && ($debut = strpos($texte, '{')) !== false && ($fin = strrpos($texte, '}')) > $debut) {
            $donnees = json_decode(substr($texte, $debut, $fin - $debut + 1), true);
        }

        if (! is_array($donnees) || array_is_list($donnees)) {
            throw new ErreurReponseIa('Réponse du modèle illisible (objet JSON attendu) : '.mb_strimwidth($texte, 0, 200, '…'));
        }

        return $donnees;
    }

    /**
     * @param  array<string, mixed>  $donnees
     * @param  array<string, mixed>  $schema
     * @return array<string, mixed>
     */
    private function verifier(array $donnees, array $schema): array
    {
        foreach ($schema['required'] ?? [] as $cle) {
            if (! array_key_exists($cle, $donnees)) {
                throw new ErreurReponseIa("Réponse du modèle incomplète : « {$cle} » manquant.");
            }
        }

        foreach ($schema['properties'] ?? [] as $cle => $propriete) {
            if (isset($propriete['enum']) && array_key_exists($cle, $donnees) && ! in_array($donnees[$cle], $propriete['enum'], true)) {
                throw new ErreurReponseIa("Réponse du modèle hors des choix permis pour « {$cle} » : ".json_encode($donnees[$cle], JSON_UNESCAPED_UNICODE));
            }
        }

        return $donnees;
    }
}
