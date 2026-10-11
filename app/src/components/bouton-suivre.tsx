import { router } from 'expo-router';
import { useState } from 'react';
import { ActivityIndicator, Pressable, StyleSheet, Text, View } from 'react-native';
import Svg, { Path } from 'react-native-svg';

import { useCompte } from '@/compte/compte';
import { Couleurs, Polices } from '@/constants/theme';
import { useFavoris } from '@/favoris/favoris';

/**
 * Suivre un lieu ou un artiste (F3.2, maquettes « Page lieu » et « Page artiste ») : compte requis ;
 * en mode invité, le compte est proposé, puis le suivi est créé sans revenir ici (F1.3).
 */
export function BoutonSuivre(props: { type: 'lieu' | 'artiste'; id: number; nom?: string; petit?: boolean; avecAide?: boolean }) {
  const { utilisateur } = useCompte();
  const { suivis, suivre, nePlusSuivre } = useFavoris();
  const [envoi, setEnvoi] = useState(false);
  const suivi = suivis.find((s) => s.type === props.type && s.cible.id === props.id);

  const libelle = suivi
    ? props.type === 'lieu' ? 'Lieu suivi' : 'Artiste suivi'
    : props.type === 'lieu' ? 'Suivre ce lieu' : `Suivre ${props.nom ?? 'cet artiste'}`;
  const aide = props.type === 'lieu'
    ? suivi ? 'Vous recevrez une alerte pour ses nouveaux spectacles (1 notification par jour au plus).' : 'Recevez une alerte quand ce lieu annonce un spectacle.'
    // F3.5 : les sources ne donnent pas toujours les artistes, on le dit honnêtement.
    : 'Nous vous prévenons dès qu’une date apparaît dans nos sources. Certaines dates peuvent nous échapper.';

  const toucher = async () => {
    if (envoi) {
      return;
    }
    if (!utilisateur) {
      router.push({ pathname: '/connexion', params: { raison: 'suivre', [props.type]: String(props.id) } });
      return;
    }
    setEnvoi(true);
    await (suivi ? nePlusSuivre(suivi.id) : suivre(props.type, props.id)).catch(() => {});
    setEnvoi(false);
  };

  const bouton = (
    <Pressable
      style={[styles.bouton, props.petit && styles.petit, suivi ? styles.suivi : styles.aSuivre]}
      onPress={toucher}
      accessibilityRole="button"
      accessibilityState={{ selected: !!suivi }}
      accessibilityHint={suivi ? 'Touchez pour ne plus suivre' : undefined}>
      {envoi ? (
        <ActivityIndicator color={suivi ? Couleurs.encre : '#FFFFFF'} />
      ) : (
        <>
          <Svg width={16} height={16} viewBox="0 0 24 24" fill="none" stroke={suivi ? Couleurs.encre : '#FFFFFF'} strokeWidth={2.2} strokeLinecap="round" strokeLinejoin="round">
            <Path d={suivi ? 'M5 12l5 5 9-10' : 'M12 5v14M5 12h14'} />
          </Svg>
          <Text style={[styles.texte, props.petit && styles.textePetit, suivi && styles.texteSuivi]}>{libelle}</Text>
        </>
      )}
    </Pressable>
  );

  return props.avecAide ? (
    <View style={styles.bloc}>
      {bouton}
      <Text style={styles.aide}>{aide}</Text>
    </View>
  ) : (
    bouton
  );
}

// Valeurs des maquettes : bouton noir « Suivre », contour noir une fois suivi.
const styles = StyleSheet.create({
  bloc: { gap: 8 },
  bouton: { alignSelf: 'flex-start', height: 44, paddingHorizontal: 18, borderRadius: 22, borderWidth: 2, borderColor: Couleurs.encre, flexDirection: 'row', alignItems: 'center', gap: 8 },
  petit: { height: 34, paddingHorizontal: 12, borderRadius: 17, gap: 6 },
  aSuivre: { backgroundColor: Couleurs.encre },
  suivi: { backgroundColor: Couleurs.carte },
  texte: { fontFamily: Polices.texteTresGras, fontSize: 15, color: '#FFFFFF' },
  textePetit: { fontSize: 13 },
  texteSuivi: { color: Couleurs.encre },
  aide: { fontFamily: Polices.texte, fontSize: 12, lineHeight: 17, color: '#4A4A55' },
});
