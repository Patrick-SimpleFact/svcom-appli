import { appelApi } from '@/api/client';

/** Ce que l'utilisateur signale (F8.2) : une salle, un spectacle, ou une billetterie / un site d'agenda. */
export type TypePiste = 'salle' | 'spectacle' | 'billetterie_ou_agenda';

export const TYPES_PISTE: { type: TypePiste; libelle: string }[] = [
  { type: 'salle', libelle: 'Une salle ou un lieu' },
  { type: 'spectacle', libelle: 'Un spectacle' },
  { type: 'billetterie_ou_agenda', libelle: 'Une billetterie ou un site d’agenda' },
];

export type Piste = {
  type: TypePiste;
  ville_id?: number;
  nom: string;
  lien?: string;
  commentaire?: string;
  email?: string;
  travaille_pour_le_lieu?: boolean;
};

export type ReponsePiste = {
  id: number;
  statut: string;
  /** La salle est déjà dans le catalogue (F8.2) : on le dit, la piste est quand même gardée. */
  deja_connu: { id: number; nom: string; ville: string | null } | null;
  /** Une réponse pourra être envoyée (e-mail donné ou compte). */
  reponse_possible: boolean;
};

/** `POST /v1/pistes` (API §10) ; au-delà du maximum par jour : `limite_atteinte` (F8.4). */
export function envoyerPiste(piste: Piste): Promise<ReponsePiste> {
  return appelApi<ReponsePiste>('/v1/pistes', { methode: 'POST', corps: piste });
}

/** « Le Théâtre des Halles est déjà dans Spettacoli » pendant la frappe (F8.2). */
export async function lieuConnu(nom: string, villeId: number): Promise<{ id: number; nom: string; ville: string | null } | null> {
  const r = await appelApi<{ lieu: { id: number; nom: string; ville: string | null } | null }>('/v1/pistes/lieu-connu', { parametres: { nom, ville_id: villeId } });

  return r.lieu;
}

/** Une adresse web saisie sans « https:// » est complétée ; sinon elle est refusée (F8.2 : doit être une adresse web). */
export function normaliserLien(saisie: string): string | null {
  const texte = saisie.trim();
  if (!texte || texte === 'https://') {
    return null;
  }
  const adresse = /^https?:\/\//i.test(texte) ? texte : `https://${texte}`;

  return /^https?:\/\/[^\s/]+\.[^\s]{2,}/i.test(adresse) ? adresse : null;
}

// ─── Signaler une erreur (F5.6) ────────────────────────────────────────────────

export type Motif = 'horaire_faux' | 'annule' | 'mauvais_lieu' | 'doublon' | 'autre';

export const MOTIFS: { motif: Motif; libelle: string }[] = [
  { motif: 'horaire_faux', libelle: 'Horaire faux' },
  { motif: 'annule', libelle: 'Spectacle annulé' },
  { motif: 'mauvais_lieu', libelle: 'Mauvais lieu' },
  { motif: 'doublon', libelle: 'Doublon' },
  { motif: 'autre', libelle: 'Autre' },
];

export function signaler(representationId: number, motif: Motif, commentaire?: string): Promise<{ id: number; statut: string }> {
  return appelApi('/v1/signalements', { methode: 'POST', corps: { representation_id: representationId, motif, commentaire: commentaire?.trim() || undefined } });
}

// ─── Mes propositions (F8.6) ───────────────────────────────────────────────────

export type Proposition = {
  id: number;
  type: TypePiste;
  nom: string;
  ville: { id: number; nom: string } | null;
  statut: 'nouvelle' | 'etudiee' | 'integree' | 'ecartee';
  envoyee_le: string;
  traitee_le: string | null;
  reponse: string | null;
};

export function mesPropositions(): Promise<Proposition[]> {
  return appelApi<{ propositions: Proposition[] }>('/v1/moi/propositions').then((r) => r.propositions);
}

export const LIBELLES_STATUTS: Record<Proposition['statut'], string> = {
  nouvelle: 'Envoyée',
  etudiee: 'En cours d’étude',
  integree: 'Intégrée',
  ecartee: 'Écartée',
};
