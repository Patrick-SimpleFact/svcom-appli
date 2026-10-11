import { Pressable, StyleSheet, Text, View } from 'react-native';

import { Couleurs, Espaces, Polices } from '@/constants/theme';
import { useDemarrage } from '@/demarrage/demarrage';

/** Messages de service du BO (F2.8) : bandeau en haut de l'écran, fermé par l'utilisateur (il ne revient pas). */
export function MessagesService() {
  const { messagesAffiches, fermerMessage } = useDemarrage();

  if (messagesAffiches.length === 0) {
    return null;
  }

  return (
    <View style={styles.pile}>
      {messagesAffiches.map((message) => {
        const alerte = message.type === 'alerte';

        return (
          <View key={message.id} style={[styles.bandeau, alerte && styles.bandeauAlerte]} accessibilityRole="alert">
            <Text style={[styles.texte, alerte && styles.texteAlerte]}>{message.texte}</Text>
            <Pressable onPress={() => fermerMessage(message.id)} hitSlop={10} accessibilityRole="button" accessibilityLabel="Fermer le message">
              <Text style={[styles.fermer, alerte && styles.texteAlerte]}>Fermer</Text>
            </Pressable>
          </View>
        );
      })}
    </View>
  );
}

const styles = StyleSheet.create({
  pile: { gap: 8 },
  bandeau: {
    flexDirection: 'row', alignItems: 'center', gap: 12, paddingVertical: 12, paddingHorizontal: 14,
    borderRadius: Espaces.rayonCarte, backgroundColor: Couleurs.carte, borderWidth: 1, borderColor: Couleurs.traitCarte,
  },
  bandeauAlerte: { backgroundColor: Couleurs.rouge, borderColor: Couleurs.rouge },
  texte: { flex: 1, fontFamily: Polices.texteMoyen, fontSize: 14, lineHeight: 20, color: Couleurs.encre },
  texteAlerte: { color: '#FFFFFF' },
  fermer: { fontFamily: Polices.texteTresGras, fontSize: 14, color: Couleurs.rouge },
});
