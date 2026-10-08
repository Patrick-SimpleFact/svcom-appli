<?php

namespace App\Contributions;

use App\Actions\MasquerRepresentation;
use App\Api\TexteCherche;
use App\Enums\ActionSignalement;
use App\Enums\StatutPiste;
use App\Enums\StatutSignalement;
use App\Enums\TypePiste;
use App\Exceptions\ErreurApi;
use App\Mail\ReponsePisteMail;
use App\Models\Lieu;
use App\Models\MotifRefus;
use App\Models\Parametre;
use App\Models\Piste;
use App\Models\Signalement;
use App\Models\Utilisateur;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

/**
 * Contributions des utilisateurs (F5.6, F8, API §10) : signalements d'erreur, pistes de sources et réponses motivées.
 */
class Contributions
{
    /** Un lieu connu à moins de cette distance du centre de la ville choisie compte aussi (arrondissements, communes limitrophes). */
    public const RAYON_LIEU_CONNU_M = 10_000;

    /**
     * F5.6 : un signalement par appareil et par séance tant qu'il n'est pas traité (un 2e envoi met à jour le premier).
     * Jamais de masquage automatique : c'est le super-admin qui décide.
     */
    public function signaler(string $appareil, ?Utilisateur $utilisateur, array $d): Signalement
    {
        $signalement = Signalement::firstOrNew([
            'appareil' => $appareil, 'representation_id' => $d['representation_id'], 'statut' => StatutSignalement::Nouveau,
        ]);
        $signalement->fill(['motif' => $d['motif'], 'commentaire' => $d['commentaire'] ?? null, 'utilisateur_id' => $utilisateur?->id ?? $signalement->utilisateur_id]);
        $signalement->save();

        return $signalement;
    }

    /** F8.2 : « Le Théâtre des Halles est déjà dans Spettacoli » pendant la frappe. */
    public function lieuConnu(string $nom, ?int $villeId): ?Lieu
    {
        $texte = TexteCherche::preparer($nom);

        if ($texte === null || $villeId === null) {
            return null;
        }

        return Lieu::with('ville:id,nom')
            ->where('masque', false)->whereNull('fusionne_dans_id')
            ->where(fn ($q) => $q->where('ville_id', $villeId)
                ->orWhereRaw('ST_DWithin(lieux.position, (select position from villes where id = ?), ?)', [$villeId, self::RAYON_LIEU_CONNU_M]))
            ->where(fn ($q) => TexteCherche::correspond($q, 'nom_normalise', $texte))
            ->orderByRaw('similarity(?, nom_normalise) desc', [$texte])
            ->first(['id', 'nom', 'ville_id']);
    }

    /** F8.2, F8.4 : enregistre la piste, la rapproche du catalogue et la range dans son groupe. */
    public function proposer(string $appareil, ?Utilisateur $utilisateur, array $d): Piste
    {
        $max = (int) Parametre::valeur('pistes_max_par_jour');
        $debutJour = CarbonImmutable::now('Europe/Paris')->startOfDay();

        if (Piste::where('appareil', $appareil)->where('created_at', '>=', $debutJour)->count() >= $max) {
            throw new ErreurApi('limite_atteinte', "{$max} propositions par jour au plus depuis un même téléphone.", 429);
        }

        $type = TypePiste::from($d['type']);
        $villeId = $d['ville_id'] ?? null;
        $lieu = $type === TypePiste::Salle ? $this->lieuConnu($d['nom'], $villeId) : null;

        return DB::transaction(function () use ($appareil, $utilisateur, $d, $type, $villeId, $lieu) {
            $cle = $this->cleGroupe($type, $d['nom'], $villeId, $d['lien'] ?? null, $lieu);

            // Le groupe ouvert qui parle de la même chose ; verrou pour que deux envois simultanés ne créent pas deux groupes.
            DB::select('select pg_advisory_xact_lock(hashtext(?))', [$cle]);
            $groupe = Piste::where('cle_groupe', $cle)->whereIn('statut', StatutPiste::OUVERTS)->min('groupe_id');

            $piste = Piste::create([
                'type' => $type, 'ville_id' => $villeId, 'nom' => trim($d['nom']), 'lien' => $d['lien'] ?? null,
                'commentaire' => $d['commentaire'] ?? null, 'email' => $d['email'] ?? null,
                'travaille_pour_le_lieu' => (bool) ($d['travaille_pour_le_lieu'] ?? false),
                'appareil' => $appareil, 'utilisateur_id' => $utilisateur?->id, 'cle_groupe' => $cle, 'groupe_id' => $groupe, 'lieu_id' => $lieu?->id,
            ]);

            if ($groupe === null) {
                $piste->update(['groupe_id' => $piste->id]);
            }

            return $piste;
        });
    }

    /**
     * F8.5 : même lieu connu, même site (billetteries et agendas), sinon même nom (sans articles ni accents) dans la même ville.
     */
    public function cleGroupe(TypePiste $type, string $nom, ?int $villeId, ?string $lien, ?Lieu $lieu): string
    {
        if ($lieu !== null) {
            return "lieu:{$lieu->id}";
        }

        $site = $lien ? preg_replace('/^www\./', '', mb_strtolower((string) parse_url($lien, PHP_URL_HOST))) : '';

        if ($type === TypePiste::BilletterieOuAgenda && $site !== '') {
            return "site:{$site}";
        }

        return mb_substr("{$type->value}:".($villeId ?? '-').':'.(TexteCherche::preparer($nom) ?? $nom), 0, 300);
    }

    /** F8.6 : « Mes propositions » d'un compte, les plus récentes d'abord. */
    public function propositions(Utilisateur $u): array
    {
        return Piste::with('ville:id,nom')->where('utilisateur_id', $u->id)->latest('id')->get()
            ->map(fn (Piste $p) => [
                'id' => $p->id,
                'type' => $p->type->value,
                'nom' => $p->nom,
                'ville' => $p->ville ? ['id' => $p->ville->id, 'nom' => $p->ville->nom] : null,
                'statut' => $p->statut->value,
                'envoyee_le' => $p->created_at->toIso8601String(),
                'traitee_le' => $p->traite_le?->toIso8601String(),
                'reponse' => $p->reponse,
            ])->all();
    }

    /** Le super-admin regarde le sujet : le groupe passe en « étudiée » (pas de réponse à ce stade). */
    public function etudier(Piste $tete, ?int $admin): void
    {
        $this->ouverts($tete)->where('statut', StatutPiste::Nouvelle)->update(['statut' => StatutPiste::Etudiee, 'traite_par' => $admin, 'updated_at' => now()]);
    }

    /** F8.6 : « Bonne nouvelle » à chaque personne du groupe. */
    public function integrer(Piste $tete, ?int $lieuId, ?string $messagePersonnel, ?int $admin): int
    {
        $reponse = "Bonne nouvelle : « {$tete->nom} » est maintenant dans Spettacoli. Vous pouvez retrouver ses spectacles en le cherchant dans l’app.";

        return $this->repondre($tete, StatutPiste::Integree, $reponse, $messagePersonnel, $admin, ['lieu_id' => $lieuId ?? $tete->lieu_id]);
    }

    /** F8.6 : un motif (message rédigé à l'avance) et, au choix, une phrase personnelle ; « Autre » = message libre. */
    public function ecarter(Piste $tete, MotifRefus $motif, ?string $messagePersonnel, ?int $admin): int
    {
        if (blank($motif->message_public) && blank($messagePersonnel)) {
            throw new \InvalidArgumentException('Ce motif n’a pas de message : écrivez la réponse.');
        }

        return $this->repondre($tete, StatutPiste::Ecartee, (string) $motif->message_public, $messagePersonnel, $admin, ['motif_refus_id' => $motif->id]);
    }

    /** Clôt les pistes encore ouvertes du groupe, garde le texte envoyé et prévient par e-mail ceux qui en ont laissé un. Rend le nombre d'e-mails. */
    private function repondre(Piste $tete, StatutPiste $statut, string $reponse, ?string $messagePersonnel, ?int $admin, array $autres): int
    {
        $messagePersonnel = filled($messagePersonnel) ? trim($messagePersonnel) : null;
        $texte = trim($reponse."\n\n".($messagePersonnel ?? ''));

        $pistes = DB::transaction(function () use ($tete, $statut, $texte, $messagePersonnel, $admin, $autres) {
            $pistes = $this->ouverts($tete)->lockForUpdate()->get();
            $pistes->each->update([
                'statut' => $statut, 'reponse' => $texte, 'message_personnel' => $messagePersonnel, 'traite_par' => $admin, 'traite_le' => now(), ...$autres,
            ]);

            return $pistes;
        });

        $envoyes = 0;

        foreach ($pistes->filter(fn (Piste $p) => filled($p->email))->unique(fn (Piste $p) => mb_strtolower($p->email)) as $piste) {
            Mail::to($piste->email)->send(new ReponsePisteMail($piste));
            $pistes->where('email', $piste->email)->each->update(['reponse_envoyee_le' => now()]);
            $envoyes++;
        }

        return $envoyes;
    }

    private function ouverts(Piste $tete)
    {
        return Piste::where('groupe_id', $tete->groupe_id ?? $tete->id)->whereIn('statut', StatutPiste::OUVERTS);
    }

    /**
     * F5.6 : traite d'un geste tous les signalements en attente de la même séance.
     * « Masquer » cache la séance dans l'app (réversible depuis la fiche du spectacle).
     */
    public function traiterSignalements(Signalement $signalement, ActionSignalement $action, ?int $admin): int
    {
        return DB::transaction(function () use ($signalement, $action, $admin) {
            if ($action === ActionSignalement::Masque) {
                app(MasquerRepresentation::class)->masquer($signalement->representation);
            }

            return Signalement::where('representation_id', $signalement->representation_id)->where('statut', StatutSignalement::Nouveau)
                ->update([
                    'statut' => $action === ActionSignalement::Rien ? StatutSignalement::Rejete : StatutSignalement::Traite,
                    'action' => $action, 'traite_par' => $admin, 'traite_le' => now(), 'updated_at' => now(),
                ]);
        });
    }

    /** F7.15 : e-mails des pistes effacés 12 mois après le traitement. */
    public function effacerEmailsAnciens(): int
    {
        return Piste::whereNotNull('email')->where('traite_le', '<', now()->subMonths(Piste::MOIS_CONSERVATION_EMAIL))->update(['email' => null]);
    }
}
