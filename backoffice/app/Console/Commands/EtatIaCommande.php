<?php

namespace App\Console\Commands;

use App\Enums\ModeIa;
use App\Ia\Assistant;
use App\Ia\ClientOllama;
use App\Ia\ErreurIa;
use App\Ia\Quota;
use App\Models\Parametre;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/**
 * État de l'assistant IA (plan-module-ia) : Ollama joignable, modèle choisi, modes des files, forfait restant.
 * Avec --essai : une toute petite demande au modèle, pour vérifier la chaîne de bout en bout (consomme très peu).
 */
class EtatIaCommande extends Command
{
    protected $signature = 'ia:etat {--essai : envoie une très courte demande au modèle}';

    protected $description = 'Affiche l’état de l’assistant IA : connexion à Ollama, modèle, modes des files et forfait restant';

    private const MODES = [
        'ia_mode_a_trier' => 'À trier',
        'ia_mode_a_classer' => 'À classer',
        'ia_mode_doublons' => 'Doublons probables',
        'ia_mode_fusions' => 'Fusions à contrôler',
        'ia_mode_spectacles_a_controler' => 'Spectacles à contrôler',
    ];

    public function handle(ClientOllama $client, Quota $quota): int
    {
        $succes = true;

        try {
            $this->info("Ollama : joignable à {$client->url()} (version {$client->version()}).");
        } catch (ErreurIa $e) {
            $this->error($e->getMessage());
            $succes = false;
        }

        foreach ([1 => Assistant::modelesNiveau1(), 2 => array_filter([Assistant::modeleNiveau2()])] as $niveau => $modeles) {
            foreach ($modeles as $modele) {
                ($erreur = $client->verifierModele($modele)) === null
                    ? $this->info("Modèle du niveau {$niveau} : {$modele}.")
                    : $this->error("Modèle du niveau {$niveau} : {$erreur}");
            }
        }

        $this->table(['File', 'Mode'], collect(self::MODES)
            ->map(fn (string $file, string $cle) => [$file, ModeIa::tryFrom((string) Parametre::valeur($cle))?->getLabel() ?? '?'])
            ->values()->all());

        $decision = $quota->verifier();
        foreach ($decision->etat?->fenetres() ?? [] as $fenetre) {
            $this->line(sprintf('Forfait, fenêtre %s : %s %% restant%s.', $fenetre['nom'], str_replace('.', ',', (string) round($fenetre['restant'], 1)),
                $fenetre['remise'] ? ', remise à zéro le '.$this->date($fenetre['remise']) : ''));
        }
        $decision->autorise ? $this->info($decision->motif) : $this->warn($decision->motif.($decision->repriseLe ? ' Reprise prévue le '.$this->date($decision->repriseLe).'.' : ''));

        if ($this->option('essai')) {
            try {
                $reponse = $client->demander('essai', [
                    ['role' => 'system', 'content' => 'Tu réponds uniquement par un objet JSON {"reponse": "ok"}.'],
                    ['role' => 'user', 'content' => 'Test de connexion.'],
                ], ['type' => 'object', 'properties' => ['reponse' => ['type' => 'string', 'enum' => ['ok']]], 'required' => ['reponse']]);
                $this->info("Essai réussi en {$reponse->appel->duree_ms} ms ({$reponse->appel->jetons_entree} jetons envoyés, {$reponse->appel->jetons_sortie} reçus).");
            } catch (ErreurIa $e) {
                $this->error("Essai échoué : {$e->getMessage()}");
                $succes = false;
            }
        }

        return $succes ? self::SUCCESS : self::FAILURE;
    }

    private function date(CarbonImmutable $date): string
    {
        return $date->setTimezone('Europe/Paris')->format('d/m à H:i');
    }
}
