<?php

namespace App\Comptes;

use App\Contributions\Contributions;
use App\Exceptions\ErreurApi;
use App\Mail\ExportDonneesMail;
use App\Models\Appareil;
use App\Models\ConnexionExterne;
use App\Models\Genre;
use App\Models\Piste;
use App\Models\StatutUtilisateur;
use App\Models\Utilisateur;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

/**
 * Comptes de l'app (F1) : connexion (code, Apple, Google), profil, export des données, suppression.
 */
class Comptes
{
    /**
     * Retrouve ou crée le compte, rattache le téléphone, reprend ce que le mode invité avait gardé, et émet un jeton.
     *
     * @param  array{gouts?: list<int>, rayon_m?: ?int}  $invite  goûts et rayon gardés sur le téléphone (F1.4)
     * @return array{jeton: string, nouveau_compte: bool, utilisateur: array}
     */
    public function connecter(?string $email, ?string $fournisseur, ?string $identifiant, ?string $appareil, array $invite = []): array
    {
        return DB::transaction(function () use ($email, $fournisseur, $identifiant, $appareil, $invite) {
            $utilisateur = $fournisseur
                ? ConnexionExterne::where('fournisseur', $fournisseur)->where('identifiant_fournisseur', $identifiant)->first()?->utilisateur
                : null;
            $utilisateur ??= $email ? Utilisateur::where('email', $email)->whereNull('supprime_le')->first() : null;
            $nouveau = $utilisateur === null;

            if ($nouveau) {
                $utilisateur = Utilisateur::create(['email' => $email]);
                $utilisateur->statuts()->create(['statut' => StatutUtilisateur::SPECTATEUR, 'accorde_le' => now()]);
            }

            if ($fournisseur) {
                ConnexionExterne::firstOrCreate(['fournisseur' => $fournisseur, 'identifiant_fournisseur' => $identifiant], ['utilisateur_id' => $utilisateur->id]);
            }

            $utilisateur->update(['derniere_connexion' => now(), 'email' => $utilisateur->email ?? $email]);
            $this->reprendreInvite($utilisateur, $invite);

            if ($appareil) {
                Appareil::where('identifiant', $appareil)->update(['utilisateur_id' => $utilisateur->id]);
            }

            return [
                'jeton' => $utilisateur->createToken('app:'.($appareil ?? 'inconnu'))->plainTextToken,
                'nouveau_compte' => $nouveau,
                'utilisateur' => $this->moi($utilisateur->fresh()),
            ];
        });
    }

    /** F1.4 : les goûts et le rayon du téléphone deviennent ceux du compte, s'il n'en avait pas encore. */
    private function reprendreInvite(Utilisateur $utilisateur, array $invite): void
    {
        if ($utilisateur->preferences()->exists() || ($invite['gouts'] ?? []) === [] && ($invite['rayon_m'] ?? null) === null) {
            return;
        }

        $utilisateur->preferences()->create([
            'genres' => Genre::whereIn('id', $invite['gouts'] ?? [])->pluck('id')->all(),
            'rayon_m' => $invite['rayon_m'] ?? null,
        ]);
    }

    public function moi(Utilisateur $u): array
    {
        $u->loadMissing(['connexionsExternes', 'statuts', 'preferences']);

        return [
            'id' => $u->id,
            'prenom' => $u->prenom,
            'email' => $u->email,
            'naissance_mois' => $u->naissance_mois,
            'naissance_annee' => $u->naissance_annee,
            'lettre_info' => $u->lettre_info,
            'cgu_acceptees' => $u->cgu_acceptees_le !== null,
            'profil_complet' => $u->profilComplet(),
            'methodes' => $u->connexionsExternes->pluck('fournisseur')->unique()->values()->all(),
            'statuts' => $u->statuts->map(fn (StatutUtilisateur $s) => ['statut' => $s->statut, 'lieu_id' => $s->lieu_id])->all(),
            'preferences' => ['genres' => $u->preferences?->genres ?? [], 'rayon_m' => $u->preferences?->rayon_m],
        ];
    }

    /**
     * Profil (F1.4) : prénom, mois et année de naissance, conditions acceptées, lettre d'information (consentement daté).
     * Moins de 15 ans : le compte n'est pas gardé (F1.4) ; l'app reste utilisable sans compte.
     */
    public function modifier(Utilisateur $u, array $donnees): array
    {
        if (isset($donnees['naissance_mois'], $donnees['naissance_annee'])
            && Utilisateur::age((int) $donnees['naissance_mois'], (int) $donnees['naissance_annee']) < Utilisateur::AGE_MINIMUM) {
            $this->effacer($u);

            throw new ErreurApi('age_minimum', 'Il faut avoir au moins '.Utilisateur::AGE_MINIMUM.' ans pour créer un compte. L’app reste utilisable sans compte.', 422);
        }

        $u->fill(collect($donnees)->only(['prenom', 'naissance_mois', 'naissance_annee'])->all());

        if (($donnees['cgu_acceptees'] ?? false) && $u->cgu_acceptees_le === null) {
            $u->cgu_acceptees_le = now();
        }

        if (array_key_exists('lettre_info', $donnees) && (bool) $donnees['lettre_info'] !== $u->lettre_info) {
            $u->lettre_info = (bool) $donnees['lettre_info'];
            $u->lettre_info_consentie_le = $u->lettre_info ? now() : null;
        }

        $u->save();

        return $this->moi($u->fresh());
    }

    /** F1.7 : « Recevoir mes données », envoyé par e-mail. */
    public function exporter(Utilisateur $u): void
    {
        if (blank($u->email)) {
            throw new ErreurApi('email_absent', 'Aucune adresse e-mail n’est liée à ce compte.', 422);
        }

        Mail::to($u->email)->send(new ExportDonneesMail($this->donnees($u)));
    }

    /** Tout ce qui est stocké sur la personne (F1.7) : jamais de position (F2.11). */
    public function donnees(Utilisateur $u): array
    {
        return [
            'compte' => collect($this->moi($u))->except(['profil_complet'])->all(),
            'consentements' => [
                'conditions_acceptees_le' => $u->cgu_acceptees_le?->toIso8601String(),
                'lettre_info_consentie_le' => $u->lettre_info_consentie_le?->toIso8601String(),
            ],
            'cree_le' => $u->created_at?->toIso8601String(),
            'derniere_connexion' => $u->derniere_connexion?->toIso8601String(),
            'appareils' => $u->appareils()->get(['plateforme', 'version_app', 'premiere_ouverture', 'derniere_ouverture'])->toArray(),
            'pistes' => app(Contributions::class)->propositions($u),
        ];
    }

    /**
     * F1.7 : suppression demandée depuis l'app. L'accès est coupé tout de suite et les données personnelles effacées ;
     * la ligne vide disparaît 30 jours plus tard (comptes:purger), le temps de purger les sauvegardes.
     */
    public function supprimer(Utilisateur $u): void
    {
        DB::transaction(function () use ($u) {
            $u->tokens()->delete();
            $u->connexionsExternes()->delete();
            $u->preferences()->delete();
            $u->statuts()->delete();
            $u->appareils()->update(['utilisateur_id' => null]);
            // Pistes gardées pour le back-office, sans l'e-mail ni le lien vers le compte.
            Piste::where('utilisateur_id', $u->id)->update(['email' => null, 'utilisateur_id' => null]);
            $u->update([
                'prenom' => null, 'email' => null, 'naissance_mois' => null, 'naissance_annee' => null,
                'lettre_info' => false, 'lettre_info_consentie_le' => null, 'supprime_le' => now(),
            ]);
        });
    }

    /** Moins de 15 ans : le compte n'est pas créé du tout (rien n'est gardé). */
    private function effacer(Utilisateur $u): void
    {
        DB::transaction(function () use ($u) {
            $u->tokens()->delete();
            $u->appareils()->update(['utilisateur_id' => null]);
            $u->delete();
        });
    }
}
