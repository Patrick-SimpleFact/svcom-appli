import * as SecureStore from 'expo-secure-store';
import { createContext, type ReactNode, useCallback, useContext, useEffect, useMemo, useState } from 'react';

import { appelApi, definirJeton, ErreurApi, quandJetonRefuse } from '@/api/client';
import type { Gouts } from '@/gouts/gouts';

/** Compte connecté (`GET /v1/moi`, API §7). */
export type Utilisateur = {
  id: number;
  prenom: string | null;
  email: string | null;
  naissance_mois: number | null;
  naissance_annee: number | null;
  lettre_info: boolean;
  cgu_acceptees: boolean;
  /** Prénom, naissance et CGU renseignés : sinon on les demande juste après la première connexion (F1.4). */
  profil_complet: boolean;
  methodes: string[];
  statuts: string[];
  preferences: { genres: number[]; rayon_m: number | null } | null;
};

type Connexion = { jeton: string; nouveau_compte: boolean; utilisateur: Utilisateur };

/** Le jeton est un secret : il est gardé dans le trousseau du téléphone, jamais en clair. */
const CLE_JETON = 'spettacoli.jeton';

// ─── Échanges avec l'API ──────────────────────────────────────────────────────

export function demanderCode(email: string): Promise<{ envoye: boolean; valable_minutes: number }> {
  return appelApi('/v1/auth/code', { methode: 'POST', corps: { email } });
}

/** Le code reçu par e-mail ; les goûts du mode invité sont repris si le compte n'en a pas encore (F1.4). */
export function verifierCode(email: string, code: string, gouts: Gouts): Promise<Connexion> {
  return appelApi('/v1/auth/code/verification', { methode: 'POST', corps: { email, code, gouts: gouts.genres, rayon_m: gouts.rayon_m } });
}

export function modifierMoi(champs: Partial<Pick<Utilisateur, 'prenom' | 'naissance_mois' | 'naissance_annee' | 'lettre_info'>> & { cgu_acceptees?: true }): Promise<Utilisateur> {
  return appelApi('/v1/moi', { methode: 'PATCH', corps: champs });
}

/** Messages lisibles pour les codes d'erreur du compte (API §7) ; jamais le message technique (F2.8). */
export function messageErreur(erreur: unknown): string {
  const code = erreur instanceof ErreurApi ? erreur.code : 'reseau';
  const messages: Record<string, string> = {
    code_invalide: 'Ce code n’est pas le bon. Vérifiez l’e-mail reçu.',
    code_expire: 'Ce code a expiré. Demandez-en un nouveau.',
    trop_d_essais: 'Trop d’essais. Réessayez dans 15 minutes.',
    patientez: 'Un code vient d’être envoyé. Patientez une minute avant d’en demander un autre.',
    trop_de_codes: 'Trop de codes demandés. Réessayez dans une heure.',
    donnees_invalides: 'Vérifiez les informations saisies.',
    trop_de_requetes: 'Trop de tentatives. Réessayez dans un instant.',
    reseau: 'Impossible de joindre Spettacoli. Vérifiez votre connexion.',
  };

  return messages[code] ?? 'Une erreur est survenue. Réessayez dans un instant.';
}

/** Moins de 15 ans au mois de naissance (seuil du consentement numérique en France, F1.4). */
export function tropJeune(mois: number, annee: number, maintenant: Date): boolean {
  const ageEnMois = (maintenant.getFullYear() - annee) * 12 + (maintenant.getMonth() + 1 - mois);

  return ageEnMois < 15 * 12;
}

// ─── Session ──────────────────────────────────────────────────────────────────

type Etat = {
  /** `false` tant que le trousseau n'a pas été lu (au lancement). */
  pret: boolean;
  utilisateur: Utilisateur | null;
  connecter: (connexion: Connexion) => Promise<void>;
  mettreAJour: (u: Utilisateur) => void;
  deconnecter: () => Promise<void>;
};

const Contexte = createContext<Etat>({ pret: false, utilisateur: null, connecter: async () => {}, mettreAJour: () => {}, deconnecter: async () => {} });

export const useCompte = () => useContext(Contexte);

/** Compte du téléphone : session ouverte tant que l'utilisateur ne se déconnecte pas (F1.5). */
export function CompteProvider({ children }: { children: ReactNode }) {
  const [pret, setPret] = useState(false);
  const [utilisateur, setUtilisateur] = useState<Utilisateur | null>(null);

  const oublier = useCallback(async () => {
    definirJeton(null);
    setUtilisateur(null);
    await SecureStore.deleteItemAsync(CLE_JETON).catch(() => {});
  }, []);

  useEffect(() => {
    // Jeton refusé par l'API (compte supprimé ailleurs…) : retour au mode invité.
    quandJetonRefuse(() => {
      oublier();
    });
    SecureStore.getItemAsync(CLE_JETON)
      .then(async (jeton) => {
        if (!jeton) {
          return;
        }
        definirJeton(jeton);
        try {
          setUtilisateur(await appelApi<Utilisateur>('/v1/moi'));
        } catch (erreur) {
          // Hors ligne : la session reste ouverte, le profil sera relu au prochain lancement.
          if (erreur instanceof ErreurApi && erreur.statut === 401) {
            await oublier();
          }
        }
      })
      .catch(() => {})
      .finally(() => setPret(true));

    return () => quandJetonRefuse(null);
  }, [oublier]);

  const connecter = useCallback(async ({ jeton, utilisateur: u }: Connexion) => {
    definirJeton(jeton);
    setUtilisateur(u);
    await SecureStore.setItemAsync(CLE_JETON, jeton).catch(() => {});
  }, []);

  /** Déconnexion (F1.5) : retour au mode invité ; les goûts restent sur le téléphone, les favoris dans le compte. */
  const deconnecter = useCallback(async () => {
    await appelApi('/v1/auth/deconnexion', { methode: 'POST' }).catch(() => {});
    await oublier();
  }, [oublier]);

  const valeur = useMemo(() => ({ pret, utilisateur, connecter, mettreAJour: setUtilisateur, deconnecter }), [pret, utilisateur, connecter, deconnecter]);

  return <Contexte.Provider value={valeur}>{children}</Contexte.Provider>;
}
