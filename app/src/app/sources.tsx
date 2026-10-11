import { router } from 'expo-router';
import { Pressable, ScrollView, StyleSheet, Text, View } from 'react-native';

import { Couleurs, Espaces, Polices } from '@/constants/theme';

/** Sources du catalogue et leurs licences (référentiel des sources du BO), avec les mentions obligatoires. */
const SOURCES = [
  { nom: 'BilletRéduc', role: 'Billetterie (lien affilié)', licence: 'Contrat d’affiliation Awin' },
  { nom: 'Fnac Spectacles', role: 'Billetterie (lien affilié)', licence: 'Contrat d’affiliation Awin' },
  { nom: 'Ticketmaster', role: 'Billetterie', licence: 'Conditions de l’API Discovery de Ticketmaster' },
  { nom: 'OpenAgenda', role: 'Agendas publics des villes et des associations', licence: 'Licence ouverte des agendas publics OpenAgenda', mention: 'Source : OpenAgenda' },
  { nom: 'DATAtourisme', role: 'Données touristiques publiques', licence: 'Licence Ouverte 2.0 (Etalab)', mention: 'Source : DATAtourisme (date de mise à jour indiquée sur chaque fiche)' },
  { nom: 'Que faire à Paris', role: 'Agenda de la Ville de Paris', licence: 'ODbL (Ville de Paris)', mention: 'Source : Que faire à Paris – Ville de Paris (ODbL)' },
];

/** Profil › À propos › Sources des données (F1.6). */
export default function Sources() {
  return (
    <View style={styles.fond}>
      <View style={styles.entete}>
        <Text style={styles.titre} accessibilityRole="header">Sources des données</Text>
        <Pressable onPress={() => router.back()} hitSlop={12} accessibilityRole="button">
          <Text style={styles.fermer}>Fermer</Text>
        </Pressable>
      </View>
      <ScrollView contentContainerStyle={styles.contenu}>
        <Text style={styles.texte}>
          Spettacoli rassemble les programmes publiés par ces sources. Le billet est toujours vendu par la billetterie d’origine. Chaque fiche indique sa source et
          la date de sa dernière mise à jour.
        </Text>
        {SOURCES.map((s) => (
          <View key={s.nom} style={styles.source}>
            <Text style={styles.nom}>{s.nom}</Text>
            <Text style={styles.detail}>{s.role}</Text>
            <Text style={styles.detail}>Licence : {s.licence}</Text>
            {s.mention ? <Text style={styles.mention}>{s.mention}</Text> : null}
          </View>
        ))}
      </ScrollView>
    </View>
  );
}

const styles = StyleSheet.create({
  fond: { flex: 1, backgroundColor: Couleurs.fond },
  entete: { flexDirection: 'row', alignItems: 'center', justifyContent: 'space-between', padding: Espaces.bord },
  titre: { fontFamily: Polices.titre, fontSize: 24, color: Couleurs.encre },
  fermer: { fontFamily: Polices.texteGras, fontSize: 16, color: Couleurs.gris },
  contenu: { gap: 12, paddingHorizontal: Espaces.bord, paddingBottom: 40 },
  texte: { fontFamily: Polices.texte, fontSize: 15, lineHeight: 22, color: Couleurs.gris },
  source: { gap: 3, padding: 14, borderRadius: Espaces.rayonCarte, borderWidth: 1, borderColor: Couleurs.traitCarte, backgroundColor: Couleurs.carte },
  nom: { fontFamily: Polices.texteTresGras, fontSize: 16, color: Couleurs.encre },
  detail: { fontFamily: Polices.texte, fontSize: 13, color: Couleurs.gris },
  mention: { fontFamily: Polices.texteMoyen, fontSize: 13, color: Couleurs.encre },
});
