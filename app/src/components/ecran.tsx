import type { ReactNode } from 'react';
import { ScrollView, StyleSheet, Text, View } from 'react-native';
import { SafeAreaView } from 'react-native-safe-area-context';

import { MessagesService } from '@/components/messages-service';
import { Couleurs, Espaces, Polices } from '@/constants/theme';

/** Cadre commun d'un onglet : fond crème, grand titre façon maquettes, messages de service (F2.8), contenu qui défile. */
export function Ecran({ titre, children }: { titre: string; children?: ReactNode }) {
  return (
    <SafeAreaView style={styles.fond} edges={['top']}>
      <ScrollView contentContainerStyle={styles.contenu} contentInsetAdjustmentBehavior="automatic">
        <Text style={styles.titre} accessibilityRole="header">{titre}</Text>
        <View style={styles.corps}>
          <MessagesService />
          {children}
        </View>
      </ScrollView>
    </SafeAreaView>
  );
}

/** Texte de remplacement d'un écran pas encore construit (étape du plan indiquée). */
export function AVenir({ texte }: { texte: string }) {
  return <Text style={styles.aVenir}>{texte}</Text>;
}

const styles = StyleSheet.create({
  fond: { flex: 1, backgroundColor: Couleurs.fond },
  contenu: { paddingHorizontal: Espaces.bord, paddingTop: 16, paddingBottom: 120 },
  titre: { fontFamily: Polices.titre, fontSize: 34, lineHeight: 36, letterSpacing: -0.7, color: Couleurs.encre },
  corps: { marginTop: 16, gap: 12 },
  aVenir: { fontFamily: Polices.texte, fontSize: 15, lineHeight: 22, color: Couleurs.gris },
});
