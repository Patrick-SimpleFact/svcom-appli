import { AVenir, Ecran } from '@/components/ecran';

/** Onglet « Rechercher » : écran à venir (voir docs/plan-fonctionnalites.md, bloc 7). */
export default function Recherche() {
  return (
    <Ecran titre="Rechercher">
      <AVenir texte="La recherche (spectacles, lieux, artistes, villes) arrive à l’étape M09." />
    </Ecran>
  );
}
