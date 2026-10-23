<?php

namespace App\Filament\Widgets;

use App\Ia\MesActions;
use App\Models\ElementATraiter;
use Filament\Notifications\Notification;
use Filament\Widgets\Widget;
use Livewire\Attributes\Session;
use Throwable;

/**
 * « Mes actions du jour » en tête de la page d'accueil : une carte à la fois, les plus récentes d'abord puis la plus urgente (plan-module-ia v2).
 * Un clic décide et passe à la suivante ; « Plus tard » la remet en fin de journée (pour cet admin, dans cette session).
 */
class MesActionsDuJour extends Widget
{
    protected static bool $isDiscovered = false;

    protected string $view = 'filament.widgets.mes-actions-du-jour';

    protected int|string|array $columnSpan = 'full';

    /** @var list<int> cartes remises à plus tard aujourd'hui */
    #[Session(key: 'mes-actions-passees')]
    public array $passes = [];

    #[Session(key: 'mes-actions-jour')]
    public ?string $jour = null;

    /** @var list<int> lieux cochés pour « Détacher » */
    public array $lieux = [];

    /** Lieu à vérifier : le vrai nom saisi, la recherche d'un lieu connu et le lieu choisi pour le rattachement. */
    public string $nouveauNom = '';

    public string $rechercheLieu = '';

    public ?int $lieuChoisi = null;

    public function mount(): void
    {
        if ($this->jour !== today()->toDateString()) {
            [$this->passes, $this->jour] = [[], today()->toDateString()];
        }
    }

    public function decider(int $id, string $choix): void
    {
        $element = ElementATraiter::with(['cible', 'propositionIa'])->findOrFail($id);

        try {
            $message = app(MesActions::class)->decider($element, $choix, array_map('intval', $this->lieux), $this->nouveauNom, $this->lieuChoisi);
            Notification::make()->success()->title($message)->send();
        } catch (Throwable $e) {
            report($e);
            Notification::make()->danger()->title('Décision impossible')->body($e->getMessage())->send();
        }

        $this->reinitialiserCarte();
        $this->dispatch('boite-reclassee');
    }

    public function plusTard(int $id): void
    {
        $this->passes[] = $id;
        $this->reinitialiserCarte();
    }

    private function reinitialiserCarte(): void
    {
        [$this->lieux, $this->nouveauNom, $this->rechercheLieu, $this->lieuChoisi] = [[], '', '', null];
    }

    public function toutReprendre(): void
    {
        $this->passes = [];
    }

    protected function getViewData(): array
    {
        $mesActions = app(MesActions::class);
        $element = $mesActions->suivante($this->passes);

        return [
            'total' => $total = $mesActions->nombre(),
            'nouvelles' => $mesActions->nouvelles(),
            'rang' => $total - $mesActions->requete()->whereKeyNot($this->passes)->count() + 1,
            'remises' => count($this->passes),
            'enExamen' => $mesActions->enExamen(),
            'element' => $element,
            'carte' => $element ? $mesActions->carte($element) : null,
            'lieuxTrouves' => $element ? $mesActions->chercherLieux($element, $this->rechercheLieu) : [],
        ];
    }
}
