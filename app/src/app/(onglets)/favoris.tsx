import { AVenir, Ecran } from '@/components/ecran';

/** Onglet « Favoris » : écran à venir (voir docs/plan-fonctionnalites.md, bloc 7). */
export default function Favoris() {
  return (
    <Ecran titre="Favoris">
      <AVenir texte="Vos favoris et les nouveautés des lieux et artistes suivis arrivent à l’étape M12." />
    </Ecran>
  );
}
