<?php

namespace App\Console\Commands;

use App\Actions\SuperviserSources;
use App\Ia\ErreurIa;
use App\Ia\MesActions;
use App\Ia\Quota;
use App\Mail\RecapitulatifIa;
use App\Models\PropositionIa;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;

/**
 * Chaque matin à 8 h (plan-module-ia v2) : envoie à Patrick le nombre de « Mes actions du jour », avec le lien,
 * ce que l'assistant a fait depuis la veille et l'état du forfait.
 */
class RecapitulatifIaCommande extends Command
{
    protected $signature = 'ia:recapitulatif';

    protected $description = 'Envoie l’e-mail du matin : actions du jour, travail de l’assistant IA depuis hier, forfait Ollama';

    public function handle(MesActions $mesActions, Quota $quota): int
    {
        $activite = PropositionIa::where('modele', 'cascade')->where('created_at', '>=', now()->subDay())
            ->selectRaw("file, count(*) filter (where statut = 'appliquee') as appliquees, count(*) filter (where statut = 'proposee') as proposees, count(*) filter (where statut = 'doute') as doutes")
            ->groupBy('file')->get()
            ->map(fn (PropositionIa $p) => ['file' => $p->file->getLabel(),
                'appliquees' => (int) $p->appliquees, 'proposees' => (int) $p->proposees, 'doutes' => (int) $p->doutes])
            ->values()->all();

        try {
            $forfait = collect($quota->etat()->fenetres())
                ->map(fn ($f) => sprintf('%s : %s %% restant', $f['nom'], str_replace('.', ',', (string) round($f['restant']))))->all();
        } catch (ErreurIa) {
            $forfait = [];
        }

        $mail = new RecapitulatifIa($mesActions->nombre(), $mesActions->enExamen(), $activite, $forfait);
        Mail::to(SuperviserSources::destinataires())->send($mail);
        $this->info($mail->envelope()->subject);

        return self::SUCCESS;
    }
}
