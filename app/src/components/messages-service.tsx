import { Pressable, StyleSheet, Text, View } from 'react-native';

import { adresseBoutique, ouvrirBoutique } from '@/components/mise-a-jour';
import { Couleurs, Espaces, Polices } from '@/constants/theme';
import { useDemarrage } from '@/demarrage/demarrage';

/**
 * Messages de service du BO (F2.8) : bandeau en haut de l'écran, fermé par l'utilisateur (il ne revient pas).
 * En tête, « Nouvelle version disponible » quand le BO annonce un build plus récent (fermé jusqu'à la prochaine ouverture).
 */
export function MessagesService() {
  const { messagesAffiches, fermerMessage, miseAJourDisponible, fermerMiseAJour } = useDemarrage();
  const miseAJour = miseAJourDisponible && Boolean(adresseBoutique);

  if (messagesAffiches.length === 0 && !miseAJour) {
    return null;
  }

  return (
    <View style={styles.pile}>
      {miseAJour ? (
        <View style={styles.bandeau} accessibilityRole="alert">
          <Text style={styles.texte}>Une nouvelle version de Spettacoli est disponible.</Text>
          <Pressable onPress={ouvrirBoutique} hitSlop={10} accessibilityRole="button">
            <Text style={styles.fermer}>Mettre à jour</Text>
          </Pressable>
          <Pressable onPress={fermerMiseAJour} hitSlop={10} accessibilityRole="button" accessibilityLabel="Plus tard">
            <Text style={styles.croix}>✕</Text>
          </Pressable>
        </View>
      ) : null}
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
  croix: { fontFamily: Polices.texteMoyen, fontSize: 14, color: Couleurs.gris },
});
