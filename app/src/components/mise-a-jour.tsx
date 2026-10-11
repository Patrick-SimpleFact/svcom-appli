import Constants from 'expo-constants';
import { Linking, Platform, Pressable, StyleSheet, Text, View } from 'react-native';
import { SafeAreaView } from 'react-native-safe-area-context';

import { Couleurs, Espaces, Polices } from '@/constants/theme';

/** Adresses de l'app dans les boutiques (app.json › extra.boutiques), à compléter quand l'app y sera publiée. */
const boutiques = (Constants.expoConfig?.extra?.boutiques ?? {}) as { ios?: string; android?: string };

/** Écran « Une nouvelle version de Spettacoli est disponible » (F2.8) : remplace toute l'app quand le BO l'exige. */
export function EcranMiseAJour() {
  const adresse = Platform.OS === 'android' ? boutiques.android : boutiques.ios;

  return (
    <SafeAreaView style={styles.fond}>
      <View style={styles.contenu}>
        <Text style={styles.titre} accessibilityRole="header">Une nouvelle version de Spettacoli est disponible</Text>
        <Text style={styles.texte}>Mettez l’application à jour pour continuer à trouver les spectacles autour de vous.</Text>
        {adresse ? (
          <Pressable style={styles.bouton} onPress={() => Linking.openURL(adresse)} accessibilityRole="button">
            <Text style={styles.boutonTexte}>Mettre à jour</Text>
          </Pressable>
        ) : null}
      </View>
    </SafeAreaView>
  );
}

const styles = StyleSheet.create({
  fond: { flex: 1, backgroundColor: Couleurs.fond },
  contenu: { flex: 1, justifyContent: 'center', paddingHorizontal: Espaces.bord, gap: 16 },
  titre: { fontFamily: Polices.titre, fontSize: 30, lineHeight: 34, letterSpacing: -0.6, color: Couleurs.encre },
  texte: { fontFamily: Polices.texte, fontSize: 16, lineHeight: 23, color: Couleurs.gris },
  bouton: { marginTop: 8, alignItems: 'center', paddingVertical: 15, borderRadius: 999, backgroundColor: Couleurs.rouge },
  boutonTexte: { fontFamily: Polices.texteTresGras, fontSize: 16, color: '#FFFFFF' },
});
