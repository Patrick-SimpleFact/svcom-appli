<?php

namespace App\Console\Commands;

use App\Actions\SuperviserSources;
use App\Enums\TypeAlerte;
use App\Mail\AlertesSupervision;
use App\Models\Alerte;
use App\Models\Source;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;

/**
 * Contrôle des sources et alertes e-mail (F7.9).
 */
class SuperviserSourcesCommande extends Command
{
    protected $signature = 'supervision:verifier {--essai : envoie seulement un e-mail d’essai aux destinataires des alertes (vérifier la configuration Brevo)}';

    protected $description = 'Ouvre ou résout les alertes des sources (échec, publication manquante, chute de volume, données périmées) et envoie l’e-mail';

    public function handle(SuperviserSources $superviser): int
    {
        if ($this->option('essai')) {
            $exemple = new Alerte(['type' => TypeAlerte::Echec, 'message' => 'E-mail d’essai : aucune source n’est en panne.', 'ouverte_le' => now()]);
            $exemple->setRelation('source', new Source(['nom' => 'Source d’essai']));
            Mail::to($destinataires = SuperviserSources::destinataires())->send(new AlertesSupervision(collect([$exemple]), collect()));
            $this->info('E-mail d’essai envoyé à '.implode(', ', $destinataires).' (mailer : '.config('mail.default').').');

            return self::SUCCESS;
        }

        $resultat = $superviser->handle();

        $this->info("{$resultat['ouvertes']->count()} alerte(s) ouverte(s), {$resultat['resolues']->count()} résolue(s).");

        return self::SUCCESS;
    }
}
