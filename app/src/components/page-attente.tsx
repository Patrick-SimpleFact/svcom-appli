import { ActivityIndicator, Pressable, StyleSheet, Text, View } from 'react-native';
import { SafeAreaView } from 'react-native-safe-area-context';
import Svg, { Path } from 'react-native-svg';

import { Couleurs, Espaces, Polices } from '@/constants/theme';
import { revenir } from '@/navigation';

/** Bouton rond « Retour » des écrans ouverts par-dessus les onglets ; `clair` sur fond clair, sinon sur un visuel sombre. */
export function BoutonRetour({ clair = false }: { clair?: boolean }) {
  return (
    <Pressable onPress={revenir} style={[styles.retour, clair ? styles.retourClair : styles.retourSombre]} accessibilityRole="button" accessibilityLabel="Retour" hitSlop={6}>
      <Svg width={20} height={20} viewBox="0 0 24 24" fill="none" stroke={clair ? Couleurs.encre : '#FFFFFF'} strokeWidth={2.2} strokeLinecap="round" strokeLinejoin="round">
        <Path d="M15 5l-7 7 7 7" />
      </Svg>
    </Pressable>
  );
}

/** Pendant le chargement d'une page lieu ou artiste, ou si elle n'a pas pu être chargée. */
export function Attente({ erreur, reessayer }: { erreur: boolean; reessayer: () => void }) {
  return (
    <SafeAreaView style={styles.fond}>
      <View style={styles.retourSeul}>
        <BoutonRetour clair />
      </View>
      {erreur ? (
        <View style={styles.erreur}>
          <Text style={styles.erreurTexte}>Impossible d’ouvrir cette page pour le moment.</Text>
          <Pressable onPress={reessayer} accessibilityRole="button">
            <Text style={styles.lien}>Réessayer</Text>
          </Pressable>
        </View>
      ) : (
        <ActivityIndicator color={Couleurs.rouge} />
      )}
    </SafeAreaView>
  );
}

const styles = StyleSheet.create({
  fond: { flex: 1, alignItems: 'center', justifyContent: 'center', backgroundColor: Couleurs.fond },
  retourSeul: { position: 'absolute', top: 60, left: Espaces.bord },
  retour: { width: 44, height: 44, borderRadius: 22, alignItems: 'center', justifyContent: 'center' },
  retourClair: { backgroundColor: 'rgba(255,255,255,0.7)' },
  retourSombre: { backgroundColor: 'rgba(0,0,0,0.35)' },
  erreur: { alignItems: 'center', gap: 10, padding: Espaces.bord },
  erreurTexte: { fontFamily: Polices.texte, fontSize: 16, color: Couleurs.encre, textAlign: 'center' },
  lien: { fontFamily: Polices.texteTresGras, fontSize: 14, color: Couleurs.encre, textDecorationLine: 'underline', paddingVertical: 6 },
});
