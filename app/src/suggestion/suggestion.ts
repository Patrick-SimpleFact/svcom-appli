import AsyncStorage from '@react-native-async-storage/async-storage';
import { useEffect, useState } from 'react';

import { appelApi } from '@/api/client';

/** La suggestion du jour (`GET /v1/suggestion`, API §9) ; `type` dit si elle est sponsorisée (mention obligatoire, F6.1). */
export type Suggestion = {
  affichage_id: number;
  type: 'auto' | 'sponsorise';
  annonceur: string | null;
  raison: string;
  spectacle: { id: number; titre: string; genre: { id: number; libelle: string } | null; image_url: string | null };
  seance: { representation_id: number; debut: string };
  lieu: { id: number; nom: string; ville: string | null };
  distance_m: number;
  prix_min: number | null;
  prix_max: number | null;
  gratuit: boolean;
};

/** Le choix de l'utilisateur (F6.2) : `null` tant qu'il n'a pas répondu (rien ne s'affiche à l'ouverture). */
export type Choix = 'oui' | 'non_merci' | 'desactive';

const CLE = 'spettacoli.suggestion';

/** Au-delà, la suggestion n'est pas montrée : l'utilisateur arrive directement sur l'accueil (F6.3). */
export const DELAI_SUGGESTION_MS = 1000;

const abonnes = new Set<(c: Choix | null) => void>();

export async function lireChoix(): Promise<Choix | null> {
  const valeur = await AsyncStorage.getItem(CLE).catch(() => null);

  return valeur === 'oui' || valeur === 'non_merci' || valeur === 'desactive' ? valeur : null;
}

/** Réponse à la question, lien « Ne plus me proposer » ou réglage du Profil : gardé sur le téléphone et envoyé au BO. */
export async function choisir(choix: Choix): Promise<void> {
  await AsyncStorage.setItem(CLE, choix).catch(() => {});
  abonnes.forEach((f) => f(choix));
  await appelApi('/v1/appareils/suggestion', { methode: 'PUT', corps: { choix } }).catch(() => {});
}

/** Choix courant, tenu à jour quand un autre écran le change (question de l'accueil ↔ Profil). */
export function useChoixSuggestion(): Choix | null {
  const [choix, setChoix] = useState<Choix | null>(null);
  useEffect(() => {
    lireChoix().then(setChoix);
    abonnes.add(setChoix);

    return () => {
      abonnes.delete(setChoix);
    };
  }, []);

  return choix;
}

/** La suggestion du jour, ou `null` (déjà vue aujourd'hui, refusée, aucune correspondance… : 204) ; abandon après 1 s. */
export async function chargerSuggestion(position: { lat: number; lon: number }, gouts: number[], delaiMs = DELAI_SUGGESTION_MS): Promise<Suggestion | null> {
  const delai = new Promise<null>((fin) => setTimeout(() => fin(null), delaiMs));
  const demande = appelApi<Suggestion | undefined>('/v1/suggestion', { parametres: { lat: position.lat, lon: position.lon, gouts } })
    .then((s) => s ?? null)
    .catch(() => null);

  return Promise.race([demande, delai]);
}

/** Statistiques (F6.5) : la suggestion a été passée, ou a mené à la fiche. */
export function signalerAction(affichageId: number, action: 'passee' | 'fiche'): Promise<unknown> {
  return appelApi(`/v1/suggestion/${affichageId}`, { methode: 'POST', corps: { action } }).catch(() => {});
}

/** « 6–18 € », « 12 € », « Gratuit », ou rien si le prix est inconnu (jamais inventé). */
export function libelleTarifs(s: Pick<Suggestion, 'prix_min' | 'prix_max' | 'gratuit'>): string | null {
  if (s.gratuit) {
    return 'Gratuit';
  }
  if (s.prix_min === null) {
    return null;
  }
  const n = (v: number) => (Number.isInteger(v) ? String(v) : v.toFixed(2).replace('.', ','));

  return s.prix_max !== null && s.prix_max > s.prix_min ? `${n(s.prix_min)}–${n(s.prix_max)} €` : `${n(s.prix_min)} €`;
}
