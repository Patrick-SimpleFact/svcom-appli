import { useState } from 'react';
import { Pressable, StyleSheet, Text, View } from 'react-native';
import Svg, { Path } from 'react-native-svg';

import { Couleurs, Polices } from '@/constants/theme';
import { useDemarrage } from '@/demarrage/demarrage';
import { choisir } from '@/suggestion/suggestion';

/**
 * Question de la 5ᵉ ouverture (F6.2, maquette « Accueil, question suggestions ») : le BO dit quand la poser
 * (`poser_question_suggestion`, puis une seule relance après un « Non merci »). Rien n'est activé sans un « Oui ».
 */
export function QuestionSuggestion() {
  const { configuration } = useDemarrage();
  const [repondu, setRepondu] = useState(false);

  if (!configuration?.poser_question_suggestion || repondu) {
    return null;
  }

  const repondre = (choix: 'oui' | 'non_merci') => {
    setRepondu(true);
    choisir(choix);
  };

  return (
    <View style={styles.carte}>
      <View style={styles.ligne}>
        <View style={styles.icone}>
          <Svg width={16} height={16} viewBox="0 0 24 24" fill="none" stroke="#FFFFFF" strokeWidth={2.2} strokeLinecap="round" strokeLinejoin="round">
            <Path d="M12 3l2.7 5.6 6.1.9-4.4 4.3 1 6.1L12 17l-5.4 2.9 1-6.1-4.4-4.3 6.1-.9z" />
          </Svg>
        </View>
        <View style={styles.textes}>
          <Text style={styles.titre}>Une suggestion à chaque ouverture ?</Text>
          <Text style={styles.texte}>Un spectacle de ce soir, près de vous, selon vos goûts. Une fois par jour au plus. Certaines suggestions peuvent être sponsorisées.</Text>
        </View>
      </View>
      <View style={styles.boutons}>
        <Pressable style={[styles.bouton, styles.non]} onPress={() => repondre('non_merci')} accessibilityRole="button">
          <Text style={styles.nonTexte}>Non merci</Text>
        </Pressable>
        <Pressable style={[styles.bouton, styles.oui]} onPress={() => repondre('oui')} accessibilityRole="button">
          <Text style={styles.ouiTexte}>Oui, volontiers</Text>
        </Pressable>
      </View>
    </View>
  );
}

// Valeurs de la maquette (encadré rouge, pastille étoile, deux boutons).
const styles = StyleSheet.create({
  carte: { gap: 14, padding: 16, borderRadius: 16, borderWidth: 1.5, borderColor: Couleurs.rouge, backgroundColor: Couleurs.carte },
  ligne: { flexDirection: 'row', alignItems: 'flex-start', gap: 12 },
  icone: { width: 36, height: 36, borderRadius: 18, alignItems: 'center', justifyContent: 'center', backgroundColor: Couleurs.rouge },
  textes: { flex: 1, gap: 4 },
  titre: { fontFamily: Polices.texteTresGras, fontSize: 16, lineHeight: 21, color: Couleurs.encre },
  texte: { fontFamily: Polices.texte, fontSize: 13, lineHeight: 19, color: '#4A4A55' },
  boutons: { flexDirection: 'row', gap: 8 },
  bouton: { flex: 1, height: 44, borderRadius: 22, alignItems: 'center', justifyContent: 'center' },
  non: { borderWidth: 1, borderColor: Couleurs.trait, backgroundColor: Couleurs.carte },
  oui: { backgroundColor: Couleurs.encre },
  nonTexte: { fontFamily: Polices.texteTresGras, fontSize: 14, color: Couleurs.encre },
  ouiTexte: { fontFamily: Polices.texteTresGras, fontSize: 14, color: '#FFFFFF' },
});

