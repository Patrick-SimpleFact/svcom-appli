<?php

namespace App\Actions;

use App\Enums\TypeRepresentation;
use App\Models\Appareil;
use App\Models\Artiste;
use App\Models\NotificationEnvoyee;
use App\Models\Nouveaute;
use App\Models\Representation;
use App\Models\Suivi;
use App\Models\Utilisateur;
use App\Push\EnvoiPush;
use App\Push\MessagePush;
use App\Push\ResultatPush;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Notification du soir (F3.4) : à 18 h, une seule notification par personne et par jour, qui regroupe ses nouveautés
 * pas encore notifiées ni vues (« 3 nouveautés : Théâtre de l'Observance : 2 nouveaux spectacles · Laura Cox : … »).
 * Garde-fous au moment de l'envoi : rien de complet, passé ou masqué ; alertes et rappels coupés respectés.
 * Sans téléphone joignable, les nouveautés restent dans l'app (badge de l'onglet Favoris).
 */
class EnvoyerNotificationsDuSoir
{
    /** Une nouveauté plus ancienne n'est plus notifiée (elle reste dans l'app). */
    public const JOURS_MAX = 7;

    public const LONGUEUR_CORPS = 180;

    public function __construct(private EnvoiPush $envoi) {}

    /** @return array{personnes: int, notifications: int} */
    public function handle(): array
    {
        $debutJour = CarbonImmutable::now('Europe/Paris')->startOfDay();
        $personnes = 0;
        $envoyees = 0;

        $aNotifier = Nouveaute::whereNull('notifiee_le')->whereNull('vue_le')->where('cree_le', '>=', now()->subDays(self::JOURS_MAX))
            ->whereIn('representation_id', $this->toujoursValables())
            ->whereNotIn('utilisateur_id', NotificationEnvoyee::where('test', false)->where('envoyee_le', '>=', $debutJour)->whereNotNull('utilisateur_id')->select('utilisateur_id'))
            ->distinct()->pluck('utilisateur_id');

        foreach (Utilisateur::with('preferences')->whereIn('id', $aNotifier)->whereNull('supprime_le')->lazyById(200) as $u) {
            $nouveautes = $this->nouveautes($u);
            $appareils = Appareil::where('utilisateur_id', $u->id)->whereNotNull('jeton_push')->get();

            if ($nouveautes->isEmpty() || $appareils->isEmpty()) {
                continue;
            }

            $message = $this->message($nouveautes, $u->nouveautes()->whereNull('vue_le')->count());
            $joint = false;

            foreach ($appareils as $appareil) {
                $note = $this->envoi->envoyer($appareil, fn (NotificationEnvoyee $n) => new MessagePush(
                    $message->titre, $message->corps, $message->pastille, ['ecran' => 'nouveautes', 'notification_id' => (string) $n->id],
                ), ['nb_nouveautes' => $nouveautes->count()]);
                $joint = $joint || $note->resultat === ResultatPush::Envoyee->value;
                $envoyees++;
            }

            if ($joint) {
                Nouveaute::whereKey($nouveautes->pluck('id'))->update(['notifiee_le' => now()]);
                $personnes++;
            }
        }

        return ['personnes' => $personnes, 'notifications' => $envoyees];
    }

    /** Séances encore visibles, pas complètes, pas passées. */
    private function toujoursValables()
    {
        return Representation::query()->visibles()->where('representations.complet', false)
            ->where(fn ($q) => $q
                ->where(fn ($s) => $s->where('representations.type', TypeRepresentation::Seance->value)->where('representations.debut', '>=', now()))
                ->orWhere(fn ($j) => $j->whereNull('representations.debut')->whereRaw('coalesce(representations.date_fin, representations.date_locale) >= ?', [today()->toDateString()])))
            ->select('representations.id');
    }

    /** Les nouveautés à annoncer, selon les réglages actuels de la personne (Profil › Réglages). */
    private function nouveautes(Utilisateur $u): Collection
    {
        $types = array_values(array_filter([
            ($u->preferences?->alertes_actives ?? true) ? Nouveaute::NOUVEAU_SPECTACLE_LIEU : null,
            ($u->preferences?->alertes_actives ?? true) ? Nouveaute::NOUVELLE_DATE_ARTISTE : null,
            ($u->preferences?->rappel_jour_j ?? true) ? Nouveaute::RAPPEL_JOUR_J : null,
        ]));

        return $u->nouveautes()->with(['spectacle', 'representation.lieu.ville'])
            ->whereNull('notifiee_le')->whereNull('vue_le')->where('cree_le', '>=', now()->subDays(self::JOURS_MAX))
            ->whereIn('type', $types)->whereIn('representation_id', $this->toujoursValables())
            ->orderBy('cree_le')->get();
    }

    /** « 3 nouveautés » et le détail par lieu, artiste et rappel ; une seule nouveauté : son texte précis. */
    public function message(Collection $nouveautes, int $pastille): MessagePush
    {
        $artistes = Artiste::whereIn('id', Suivi::whereIn('id', $nouveautes->pluck('suivi_id')->filter())->pluck('cible_id'))->pluck('nom', 'id');
        $suivis = Suivi::whereIn('id', $nouveautes->pluck('suivi_id')->filter())->pluck('cible_id', 'id');
        $quand = fn (Nouveaute $n) => $n->representation?->date_locale?->format('d/m');

        $parties = collect()
            ->merge($nouveautes->where('type', Nouveaute::RAPPEL_JOUR_J)
                ->map(fn (Nouveaute $n) => "Ce soir : {$n->spectacle?->titre} ({$n->representation?->lieu?->nom})"))
            ->merge($nouveautes->where('type', Nouveaute::NOUVEAU_SPECTACLE_LIEU)->groupBy(fn (Nouveaute $n) => $n->representation?->lieu_id)
                ->map(fn (Collection $g) => $g->count() === 1
                    ? "{$g->first()->representation?->lieu?->nom} : {$g->first()->spectacle?->titre}"
                    : "{$g->first()->representation?->lieu?->nom} : {$g->count()} nouveaux spectacles"))
            ->merge($nouveautes->where('type', Nouveaute::NOUVELLE_DATE_ARTISTE)->groupBy('suivi_id')
                ->map(function (Collection $g) use ($artistes, $suivis, $quand) {
                    $nom = $artistes[$suivis[$g->first()->suivi_id] ?? 0] ?? $g->first()->spectacle?->titre;

                    return $g->count() === 1
                        ? "{$nom} : le {$quand($g->first())} à {$g->first()->representation?->lieu?->ville?->nom}"
                        : "{$nom} : {$g->count()} nouvelles dates";
                }))
            ->values();

        $n = $nouveautes->count();
        $titre = match (true) {
            $n === 1 && $nouveautes->first()->type === Nouveaute::RAPPEL_JOUR_J => 'C’est ce soir',
            $n === 1 => 'Une nouveauté pour vous',
            default => "{$n} nouveautés pour vous",
        };
        $corps = $parties->implode(' · ');

        return new MessagePush($titre, mb_strlen($corps) > self::LONGUEUR_CORPS ? mb_substr($corps, 0, self::LONGUEUR_CORPS - 1).'…' : $corps, $pastille);
    }
}
