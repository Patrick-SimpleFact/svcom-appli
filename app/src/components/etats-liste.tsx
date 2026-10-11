import { useEffect, useState } from 'react';
import { Animated, Pressable, StyleSheet, Text, View } from 'react-native';
import Svg, { Path } from 'react-native-svg';

import { Couleurs, Espaces, Polices } from '@/constants/theme';

/** Chargement normal (F2.8) : cartes grises animées, sans texte. */
export function Squelettes({ nombre = 4 }: { nombre?: number }) {
  const [opacite] = useState(() => new Animated.Value(0.45));

  useEffect(() => {
    const boucle = Animated.loop(
      Animated.sequence([
        Animated.timing(opacite, { toValue: 1, duration: 700, useNativeDriver: true }),
        Animated.timing(opacite, { toValue: 0.45, duration: 700, useNativeDriver: true }),
      ]),
    );
    boucle.start();

    return () => boucle.stop();
  }, [opacite]);

  return (
    <View style={styles.squelettes} accessibilityLabel="Chargement des spectacles" accessibilityRole="progressbar">
      {Array.from({ length: nombre }, (_, i) => (
        <Animated.View key={i} style={[styles.squelette, { opacity: opacite }]}>
          <View style={styles.talon} />
          <View style={styles.lignes}>
            <View style={[styles.ligne, { width: '30%' }]} />
            <View style={[styles.ligne, styles.ligneTitre, { width: '75%' }]} />
            <View style={[styles.ligne, { width: '55%' }]} />
          </View>
        </Animated.View>
      ))}
    </View>
  );
}

/** Bandeau d'information en haut de la liste (F2.8), avec une action quand il y en a une. */
export function Bandeau({ texte, action, onPress }: { texte: string; action?: string; onPress?: () => void }) {
  return (
    <View style={styles.bandeau} accessibilityRole="alert">
      <Text style={styles.bandeauTexte}>
        {texte}
        {action && onPress ? (
          <Text style={styles.bandeauAction} onPress={onPress} accessibilityRole="button">
            {' '}
            · {action}
          </Text>
        ) : null}
      </Text>
    </View>
  );
}

/** Couverture faible (F2.7, D3 bis ; maquette « Accueil couverture faible ») : on le dit, et on invite à proposer une salle (F8). */
export function BandeauCouverture({ proposer }: { proposer: () => void }) {
  return (
    <View style={styles.couverture}>
      <View style={styles.couvertureLigne}>
        <Svg width={22} height={22} viewBox="0 0 24 24" fill="none" stroke="#FFFFFF" strokeWidth={2} strokeLinecap="round" strokeLinejoin="round">
          <Path d="M12 3v3M12 18v3M3 12h3M18 12h3M5.6 5.6l2.1 2.1M16.3 16.3l2.1 2.1M5.6 18.4l2.1-2.1M16.3 7.7l2.1-2.1" />
        </Svg>
        <View style={styles.couvertureTextes}>
          <Text style={styles.couvertureTitre}>Couverture en cours d’enrichissement dans votre région</Text>
          <Text style={styles.couvertureTexte}>Nous ajoutons de nouvelles salles chaque semaine. Vous en connaissez une près d’ici ?</Text>
        </View>
      </View>
      <Pressable style={styles.couvertureBouton} onPress={proposer} accessibilityRole="button">
        <Svg width={16} height={16} viewBox="0 0 24 24" fill="none" stroke="#FFFFFF" strokeWidth={2.4} strokeLinecap="round" strokeLinejoin="round">
          <Path d="M12 5v14M5 12h14" />
        </Svg>
        <Text style={styles.couvertureBoutonTexte}>Proposer une salle ou un spectacle</Text>
      </Pressable>
    </View>
  );
}

/** Message de liste vide (F2.7) : toujours une explication, et une action quand il y en a une. */
export function MessageVide({ texte, action, onPress }: { texte: string; action?: string; onPress?: () => void }) {
  return (
    <View style={styles.vide}>
      <Text style={styles.videTexte}>{texte}</Text>
      {action && onPress ? (
        <Pressable onPress={onPress} accessibilityRole="button">
          <Text style={styles.videAction}>{action}</Text>
        </Pressable>
      ) : null}
    </View>
  );
}

/** Lien F8 en bas de toute liste, même bien remplie (F2.7). */
export function IlManqueUnSpectacle({ onPress }: { onPress: () => void }) {
  return (
    <Pressable style={styles.manque} onPress={onPress} accessibilityRole="button">
      <Text style={styles.manqueTexte}>Il manque un spectacle ?</Text>
    </Pressable>
  );
}

// Couleurs et rayons de la maquette (bandeau sombre, bouton rouge).
const styles = StyleSheet.create({
  squelettes: { gap: 10 },
  squelette: { flexDirection: 'row', height: 82, borderRadius: Espaces.rayonCarte, backgroundColor: Couleurs.carte, borderWidth: 1, borderColor: Couleurs.traitCarte },
  talon: { width: 76, borderRightWidth: 1, borderRightColor: Couleurs.traitCarte },
  lignes: { flex: 1, justifyContent: 'center', gap: 8, paddingHorizontal: 14 },
  ligne: { height: 10, borderRadius: 5, backgroundColor: Couleurs.trait },
  ligneTitre: { height: 14 },
  bandeau: { paddingVertical: 12, paddingHorizontal: 14, borderRadius: Espaces.rayonCarte, backgroundColor: Couleurs.carte, borderWidth: 1, borderColor: Couleurs.traitCarte },
  bandeauTexte: { fontFamily: Polices.texteMoyen, fontSize: 14, lineHeight: 20, color: Couleurs.encre },
  bandeauAction: { fontFamily: Polices.texteTresGras, color: Couleurs.rouge },
  couverture: { gap: 10, padding: 16, borderRadius: 16, backgroundColor: Couleurs.encre },
  couvertureLigne: { flexDirection: 'row', alignItems: 'flex-start', gap: 10 },
  couvertureTextes: { flex: 1, gap: 4 },
  couvertureTitre: { fontFamily: Polices.texteTresGras, fontSize: 15, color: '#FFFFFF' },
  couvertureTexte: { fontFamily: Polices.texte, fontSize: 13, lineHeight: 18, color: 'rgba(255,255,255,0.75)' },
  couvertureBouton: { height: 44, borderRadius: 22, flexDirection: 'row', alignItems: 'center', justifyContent: 'center', gap: 6, backgroundColor: Couleurs.rouge },
  couvertureBoutonTexte: { fontFamily: Polices.texteTresGras, fontSize: 14, color: '#FFFFFF' },
  vide: { gap: 10, paddingVertical: 8 },
  videTexte: { fontFamily: Polices.titre, fontSize: 20, lineHeight: 24, color: Couleurs.encre },
  videAction: { fontFamily: Polices.texteTresGras, fontSize: 15, color: Couleurs.rouge },
  manque: { alignItems: 'center', paddingVertical: 20 },
  manqueTexte: { fontFamily: Polices.texteGras, fontSize: 14, color: Couleurs.gris, textDecorationLine: 'underline' },
});
