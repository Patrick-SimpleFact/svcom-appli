import DateTimePicker from '@react-native-community/datetimepicker';
import { useRef, useState } from 'react';
import { Modal, Pressable, ScrollView, StyleSheet, Text } from 'react-native';
import Svg, { Path, Rect } from 'react-native-svg';

import { dateDuJour, jourDe, libelleJour, type Onglet, type Quand } from '@/autour/autour';
import { Couleurs, Espaces, Polices } from '@/constants/theme';

const PUCES: { quand: Quand; libelle: string }[] = [
  { quand: 'ce_soir', libelle: 'Ce soir' },
  { quand: 'demain', libelle: 'Demain' },
  { quand: 'week_end', libelle: 'Week-end' },
];

/** Jusqu'où le calendrier permet d'aller (F2.2). */
const MOIS_MAX = 3;

/** Quand ? (F2.2) : une seule puce active ; le calendrier choisit un jour précis. */
export function PucesQuand({ quand, choisir }: { quand: Quand; choisir: (q: Quand) => void }) {
  const [calendrier, setCalendrier] = useState(false);
  const jourChoisi = !PUCES.some((p) => p.quand === quand);
  const aujourdhui = new Date();
  const limite = new Date(aujourdhui.getFullYear(), aujourdhui.getMonth() + MOIS_MAX, aujourdhui.getDate());

  const defilement = useRef<ScrollView>(null);

  // La ligne défile : un jour choisi (« Sam. 17 oct. ») élargit la dernière puce au-delà de l'écran.
  return (
    <ScrollView
      ref={defilement}
      horizontal
      showsHorizontalScrollIndicator={false}
      style={styles.pucesCadre}
      contentContainerStyle={styles.puces}
      onContentSizeChange={() => jourChoisi && defilement.current?.scrollToEnd({ animated: true })}>
      {PUCES.map((p) => (
        <Puce key={p.quand} libelle={p.libelle} active={quand === p.quand} onPress={() => choisir(p.quand)} />
      ))}
      <Pressable
        style={[styles.puce, jourChoisi ? styles.puceActive : styles.puceCalendrier]}
        onPress={() => setCalendrier(true)}
        accessibilityRole="button"
        accessibilityLabel={jourChoisi ? `Date choisie : ${libelleJour(quand)}` : 'Choisir une date'}>
        {jourChoisi ? (
          <Text style={[styles.puceTexte, styles.puceTexteActive]}>{libelleJour(quand)}</Text>
        ) : (
          <Svg width={18} height={18} viewBox="0 0 24 24" fill="none" stroke={Couleurs.encre} strokeWidth={2} strokeLinecap="round" strokeLinejoin="round">
            <Rect x={4} y={5} width={16} height={15} rx={2} />
            <Path d="M4 10h16M9 3v4M15 3v4" />
          </Svg>
        )}
      </Pressable>

      <Modal visible={calendrier} transparent animationType="fade" onRequestClose={() => setCalendrier(false)}>
        <Pressable style={styles.voile} onPress={() => setCalendrier(false)} accessibilityLabel="Fermer le calendrier">
          <Pressable style={styles.feuille} onPress={() => {}}>
            <DateTimePicker
              mode="date"
              display="inline"
              locale="fr-FR"
              value={jourChoisi ? dateDuJour(quand) : aujourdhui}
              minimumDate={aujourdhui}
              maximumDate={limite}
              accentColor={Couleurs.rouge}
              themeVariant="light"
              onValueChange={(_evenement, date) => {
                setCalendrier(false);
                choisir(jourDe(date));
              }}
              onDismiss={() => setCalendrier(false)}
            />
          </Pressable>
        </Pressable>
      </Modal>
    </ScrollView>
  );
}

function Puce({ libelle, active, onPress }: { libelle: string; active: boolean; onPress: () => void }) {
  return (
    <Pressable style={[styles.puce, active && styles.puceActive]} onPress={onPress} accessibilityRole="button" accessibilityState={{ selected: active }}>
      <Text style={[styles.puceTexte, active && styles.puceTexteActive]}>{libelle}</Text>
    </Pressable>
  );
}

/** Quoi ? (F2.3) : onglets défilants, soulignés en rouge ; « Pour vous » en tête quand des goûts sont enregistrés, « Jeune public » à la fin. */
export function OngletsGenres({ onglets, actif, choisir }: { onglets: { onglet: Onglet; libelle: string }[]; actif: Onglet; choisir: (o: Onglet) => void }) {
  return (
    <ScrollView horizontal showsHorizontalScrollIndicator={false} style={styles.onglets} contentContainerStyle={styles.ongletsContenu}>
      {onglets.map(({ onglet, libelle }) => {
        const estActif = onglet === actif;

        return (
          <Pressable key={String(onglet)} onPress={() => choisir(onglet)} style={[styles.onglet, estActif && styles.ongletActif]} accessibilityRole="tab" accessibilityState={{ selected: estActif }}>
            <Text style={[styles.ongletTexte, estActif && styles.ongletTexteActif]}>{libelle}</Text>
          </Pressable>
        );
      })}
    </ScrollView>
  );
}

// Valeurs de la maquette « Accueil autour de moi ».
const styles = StyleSheet.create({
  // Déborde jusqu'aux bords de l'écran pour que le défilement ne coupe pas les puces à la marge.
  pucesCadre: { flexGrow: 0, marginHorizontal: -Espaces.bord },
  puces: { flexDirection: 'row', gap: 8, paddingHorizontal: Espaces.bord },
  puce: {
    height: 40, paddingHorizontal: 16, borderRadius: 20, borderWidth: 1, borderColor: Couleurs.trait,
    backgroundColor: Couleurs.carte, alignItems: 'center', justifyContent: 'center',
  },
  puceCalendrier: { width: 40, paddingHorizontal: 0 },
  puceActive: { backgroundColor: Couleurs.encre, borderColor: Couleurs.encre },
  puceTexte: { fontFamily: Polices.texteMoyen, fontSize: 14, color: Couleurs.encre },
  puceTexteActive: { fontFamily: Polices.texteGras, color: '#FFFFFF' },
  voile: { flex: 1, justifyContent: 'center', padding: Espaces.bord, backgroundColor: 'rgba(22, 22, 29, 0.35)' },
  feuille: { borderRadius: 20, padding: 8, backgroundColor: Couleurs.carte },
  onglets: { flexGrow: 0, borderBottomWidth: 1, borderBottomColor: Couleurs.trait },
  ongletsContenu: { gap: 18 },
  onglet: { paddingBottom: 10, borderBottomWidth: 2, borderBottomColor: 'transparent' },
  ongletActif: { borderBottomColor: Couleurs.rouge },
  ongletTexte: { fontFamily: Polices.texte, fontSize: 14, color: Couleurs.gris },
  ongletTexteActif: { fontFamily: Polices.texteTresGras, color: Couleurs.encre },
});
