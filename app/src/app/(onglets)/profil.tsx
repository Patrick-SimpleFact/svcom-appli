import { AVenir, Ecran } from '@/components/ecran';

/** Onglet « Profil » : écran à venir (voir docs/plan-fonctionnalites.md, bloc 7). */
export default function Profil() {
  return (
    <Ecran titre="Profil">
      <AVenir texte="Compte, goûts, réglages et pages légales arrivent aux étapes M10 et M11." />
    </Ecran>
  );
}
