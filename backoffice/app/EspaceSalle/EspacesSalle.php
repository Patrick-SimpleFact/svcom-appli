<?php

namespace App\EspaceSalle;

use App\Actions\SuperviserSources;
use App\Collecte\BaseAdresseNationale;
use App\Contributions\Contributions;
use App\Enums\PrecisionPosition;
use App\Enums\StatutDemandeSalle;
use App\Enums\TypeLieu;
use App\Mail\EspaceSalleMail;
use App\Models\DemandeEspaceSalle;
use App\Models\Lieu;
use App\Models\StatutUtilisateur;
use App\Models\Utilisateur;
use App\Models\Ville;
use App\Support\Texte;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;

/**
 * Espace salle (F9.1, F9.2) : demande publique, traitement par le super-admin, comptes « gestionnaire de lieu ».
 * Pas d'inscription libre : seul le super-admin ouvre un compte. Connexion sans mot de passe (code par e-mail, décision du 08/10/2026).
 */
class EspacesSalle
{
    /** Le lien d'invitation connecte directement le théâtre pendant ce délai. */
    public const JOURS_INVITATION = 7;

    /** F9.1 : enregistre la demande, la rapproche du référentiel, confirme au demandeur et prévient le super-admin. */
    public function deposer(array $d): DemandeEspaceSalle
    {
        $ville = $this->ville($d['code_postal'] ?? null, $d['ville']);

        $demande = DemandeEspaceSalle::create([
            ...collect($d)->only(['nom_lieu', 'adresse', 'code_postal', 'nom_demandeur', 'fonction', 'telephone', 'site_web', 'billetterie', 'message'])->all(),
            'email' => mb_strtolower(trim($d['email'])),
            'ville_saisie' => $d['ville'],
            'ville_id' => $ville?->id,
            'consentement_le' => now(),
            'lieu_propose_id' => app(Contributions::class)->lieuConnu($d['nom_lieu'], $ville?->id)?->id,
        ]);

        Mail::to($demande->email)->send(new EspaceSalleMail('Votre demande d’espace salle a bien été reçue', 'mail.espace-salle.recue', ['demande' => $demande]));

        if (($admins = SuperviserSources::destinataires()) !== []) {
            Mail::to($admins)->send(new EspaceSalleMail("Nouvelle demande d’espace salle : {$demande->nom_lieu}", 'mail.espace-salle.nouvelle', ['demande' => $demande]));
        }

        return $demande;
    }

    /** F9.2 « Valider » : compte créé (ou compte existant complété) et rattaché au lieu, invitation par e-mail. */
    public function valider(DemandeEspaceSalle $demande, int $lieuId, ?int $admin): Utilisateur
    {
        $utilisateur = DB::transaction(function () use ($demande, $lieuId, $admin) {
            $utilisateur = $this->ouvrirCompte($demande->email, $lieuId, $admin);
            $demande->update(['statut' => StatutDemandeSalle::Validee, 'lieu_id' => $lieuId, 'utilisateur_cree_id' => $utilisateur->id, 'traite_par' => $admin, 'traite_le' => now()]);

            return $utilisateur;
        });

        $this->inviter($utilisateur, Lieu::find($lieuId), $demande->nom_demandeur);

        return $utilisateur;
    }

    /**
     * Le théâtre n'est pas encore dans Spettacoli : le lieu est créé avec les informations de la demande,
     * placé sur la carte par la Base Adresse Nationale (à défaut, au centre de la commune).
     */
    public function creerLieu(DemandeEspaceSalle $demande, string $nom, ?string $adresse, ?string $codePostal, TypeLieu|string $type): Lieu
    {
        $ville = $demande->ville;
        $geocodage = filled($adresse) ? app(BaseAdresseNationale::class)->geocoder($adresse, $codePostal, $ville?->nom, $ville?->code_insee) : null;

        return Lieu::create([
            'nom' => trim($nom), 'type' => $type, 'adresse' => $adresse, 'code_postal' => $codePostal, 'ville_id' => $ville?->id,
            'position' => $geocodage['position'] ?? $ville?->position,
            'precision_position' => $geocodage ? PrecisionPosition::Adresse : PrecisionPosition::Commune,
            'fuseau_horaire' => $ville?->fuseau_horaire ?? 'Europe/Paris',
            'site_web' => $demande->site_web, 'telephone' => $demande->telephone,
        ]);
    }

    /** F9.2 : compte créé directement par le super-admin (théâtre démarché par téléphone). */
    public function creerCompte(string $email, int $lieuId, ?int $admin): Utilisateur
    {
        $utilisateur = $this->ouvrirCompte($email, $lieuId, $admin);
        $this->inviter($utilisateur, Lieu::find($lieuId), null);

        return $utilisateur;
    }

    public function refuser(DemandeEspaceSalle $demande, string $motif, ?int $admin): void
    {
        $demande->update(['statut' => StatutDemandeSalle::Refusee, 'motif_refus' => $motif, 'traite_par' => $admin, 'traite_le' => now()]);
        Mail::to($demande->email)->send(new EspaceSalleMail('Votre demande d’espace salle', 'mail.espace-salle.refusee', ['demande' => $demande]));
    }

    /** La demande attend la réponse du théâtre ; il répond à l'e-mail (réponse adressée au super-admin). */
    public function demanderPrecisions(DemandeEspaceSalle $demande, string $question, ?int $admin, ?string $repondreA): void
    {
        $demande->update(['statut' => StatutDemandeSalle::PrecisionsDemandees, 'precisions_demandees' => $question, 'traite_par' => $admin]);
        Mail::to($demande->email)->send((new EspaceSalleMail('Votre demande d’espace salle : une précision', 'mail.espace-salle.precisions', ['demande' => $demande]))
            ->when($repondreA, fn (EspaceSalleMail $m) => $m->replyTo($repondreA)));
    }

    /** F9.2 : le super-admin retire l'accès à un lieu à tout moment ; le compte de l'app, lui, reste. */
    public function desactiver(StatutUtilisateur $statut): void
    {
        $statut->delete();
    }

    /** Lien qui connecte directement à l'espace (valable 7 jours) ; ensuite, connexion par code. */
    public function lienInvitation(Utilisateur $u): string
    {
        return URL::temporarySignedRoute('espace-salle.invitation', now()->addDays(self::JOURS_INVITATION), ['utilisateur' => $u->id]);
    }

    /** @return list<Lieu> les lieux que ce compte gère */
    public function lieux(Utilisateur $u): array
    {
        return Lieu::with('ville')->whereIn('id', $u->statuts()->where('statut', StatutUtilisateur::GESTIONNAIRE_LIEU)->pluck('lieu_id'))->orderBy('nom')->get()->all();
    }

    private function ouvrirCompte(string $email, int $lieuId, ?int $admin): Utilisateur
    {
        // Compte unique : la personne a peut-être déjà un compte dans l'app, on lui ajoute le statut.
        $utilisateur = Utilisateur::firstOrCreate(['email' => mb_strtolower(trim($email))]);
        StatutUtilisateur::firstOrCreate(
            ['utilisateur_id' => $utilisateur->id, 'statut' => StatutUtilisateur::GESTIONNAIRE_LIEU, 'lieu_id' => $lieuId],
            ['accorde_par' => $admin, 'accorde_le' => now()],
        );

        return $utilisateur;
    }

    private function inviter(Utilisateur $u, ?Lieu $lieu, ?string $nom): void
    {
        Mail::to($u->email)->send(new EspaceSalleMail('Votre espace salle Spettacoli est ouvert', 'mail.espace-salle.invitation', [
            'lieu' => $lieu, 'nom' => $nom, 'lien' => $this->lienInvitation($u), 'jours' => self::JOURS_INVITATION, 'connexion' => route('espace-salle.connexion'),
        ]));
    }

    /** La commune : par code postal et nom, sinon par nom seul (la plus peuplée). */
    private function ville(?string $codePostal, string $nom): ?Ville
    {
        $normalise = Texte::normaliser($nom);
        $parCode = $codePostal ? Ville::whereJsonContains('codes_postaux', $codePostal)->get() : collect();

        return $parCode->first(fn (Ville $v) => $v->nom_normalise === $normalise)
            ?? Ville::where('nom_normalise', $normalise)->orderByDesc('population')->first()
            ?? ($parCode->count() === 1 ? $parCode->first() : null);
    }
}
