<?php

namespace App\Console\Commands;

use App\Actions\VerifierNonRegression;
use Illuminate\Console\Command;

/**
 * Rejoue une journée étudiée par le POC et compare le catalogue publié (COLLECTE §9).
 */
class VerifierNonRegressionCommande extends Command
{
    protected $signature = 'collecte:non-regression
        {journee=2026-10-17 : dossier de database/non-regression}
        {--ville= : une seule ville (paris, bordeaux, avignon, figeac)}
        {--details : liste les lignes du POC non retrouvées}
        {--seuil=90 : taux minimal (%) de lignes retrouvées ou expliquées}';

    protected $description = 'Compare le catalogue à une journée de référence du POC (non-régression de la collecte)';

    public function handle(VerifierNonRegression $verification): int
    {
        $resultat = $verification->handle($this->argument('journee'), $this->option('ville'));
        $seuil = (float) $this->option('seuil');
        $echec = false;

        $this->info("Journée de référence : {$resultat['date']}");

        $this->table(
            ['Ville', 'POC', 'Retrouvées', 'Retirées', 'Absentes', 'Taux', 'Catalogue', 'En plus'],
            array_map(function (array $ville) use ($seuil, &$echec) {
                $taux = $ville['poc'] > 0 ? 100 * ($ville['retrouvees'] + $ville['retirees']) / $ville['poc'] : 100;
                $echec = $echec || $taux < $seuil;

                return [
                    $ville['nom'], $ville['poc'], $ville['retrouvees'], $ville['retirees'], $ville['absentes'],
                    sprintf('%.0f %% %s', $taux, $taux < $seuil ? '(sous le seuil)' : 'ok'), $ville['catalogue'], $ville['en_plus'],
                ];
            }, $resultat['villes']),
        );

        $this->line('Retirées : la source ne vend plus la séance (annulation…). En plus : représentations publiées absentes du POC.');

        if ($this->option('details')) {
            foreach ($resultat['villes'] as $ville) {
                $lignes = array_filter($ville['lignes'], fn ($l) => $l['etat'] !== 'retrouvee');

                if ($lignes === []) {
                    continue;
                }

                $this->newLine();
                $this->info($ville['nom']);
                $this->table(
                    ['Heure', 'Titre (POC)', 'Lieu (POC)', 'Sources (POC)', 'Explication'],
                    array_map(fn ($l) => [$l['heure'] ?? '—', mb_strimwidth($l['titre'], 0, 50, '…'), mb_strimwidth($l['lieu'], 0, 30, '…'), $l['sources'], $l['explication']], $lignes),
                );
            }
        }

        return $echec ? self::FAILURE : self::SUCCESS;
    }
}
