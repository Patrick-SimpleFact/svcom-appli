import { useEffect, useState } from 'react';
import { ActivityIndicator, Pressable, ScrollView, StyleSheet, Text, View } from 'react-native';

import { jourLong } from '@/fiche/fiche';
import { LIBELLES_STATUTS, mesPropositions, type Proposition, TYPES_PISTE } from '@/contributions/contributions';
import { Couleurs, Espaces, Polices } from '@/constants/theme';
import { revenir } from '@/navigation';

/** Profil › Mes propositions (F8.6) : chaque piste avec son statut et la réponse de l'équipe. */
export default function MesPropositions() {
  const [liste, setListe] = useState<Proposition[] | null>(null);
  const [erreur, setErreur] = useState(false);

  useEffect(() => {
    mesPropositions()
      .then(setListe)
      .catch(() => setErreur(true));
  }, []);

  return (
    <View style={styles.fond}>
      <View style={styles.entete}>
        <Text style={styles.titre} accessibilityRole="header">Mes propositions</Text>
        <Pressable onPress={revenir} hitSlop={12} accessibilityRole="button">
          <Text style={styles.fermer}>Fermer</Text>
        </Pressable>
      </View>
      <ScrollView contentContainerStyle={styles.contenu}>
        {erreur ? (
          <Text style={styles.texte}>Impossible de charger vos propositions pour le moment.</Text>
        ) : !liste ? (
          <ActivityIndicator color={Couleurs.rouge} />
        ) : !liste.length ? (
          <Text style={styles.texte}>Vous n’avez encore rien proposé. Une salle ou un spectacle manque ? Dites-le-nous depuis Profil › Aidez-nous.</Text>
        ) : (
          liste.map((p) => (
            <View key={p.id} style={styles.carte}>
              <View style={styles.ligne}>
                <Text style={styles.nom}>{p.nom}</Text>
                <Text style={[styles.statut, p.statut === 'integree' && styles.integree, p.statut === 'ecartee' && styles.ecartee]}>{LIBELLES_STATUTS[p.statut]}</Text>
              </View>
              <Text style={styles.detail}>
                {TYPES_PISTE.find((t) => t.type === p.type)?.libelle}
                {p.ville ? ` · ${p.ville.nom}` : ''} · envoyée le {jourLong(p.envoyee_le.slice(0, 10)).toLowerCase()}
              </Text>
              {p.reponse ? <Text style={styles.reponse}>{p.reponse}</Text> : null}
            </View>
          ))
        )}
      </ScrollView>
    </View>
  );
}

const styles = StyleSheet.create({
  fond: { flex: 1, backgroundColor: Couleurs.fond },
  entete: { flexDirection: 'row', alignItems: 'center', justifyContent: 'space-between', padding: Espaces.bord },
  titre: { fontFamily: Polices.titre, fontSize: 24, color: Couleurs.encre },
  fermer: { fontFamily: Polices.texteGras, fontSize: 16, color: Couleurs.gris },
  contenu: { gap: 10, paddingHorizontal: Espaces.bord, paddingBottom: 40 },
  texte: { fontFamily: Polices.texte, fontSize: 15, lineHeight: 22, color: Couleurs.gris },
  carte: { gap: 6, padding: 14, borderRadius: Espaces.rayonCarte, borderWidth: 1, borderColor: Couleurs.traitCarte, backgroundColor: Couleurs.carte },
  ligne: { flexDirection: 'row', alignItems: 'flex-start', justifyContent: 'space-between', gap: 10 },
  nom: { flex: 1, fontFamily: Polices.texteTresGras, fontSize: 16, color: Couleurs.encre },
  statut: { overflow: 'hidden', paddingVertical: 3, paddingHorizontal: 8, borderRadius: 10, backgroundColor: '#ECE8E2', fontFamily: Polices.texteGras, fontSize: 11, color: Couleurs.encre },
  integree: { backgroundColor: '#DDF0E7', color: '#0F5A43' },
  ecartee: { backgroundColor: '#FBE1DC', color: '#8E2410' },
  detail: { fontFamily: Polices.texte, fontSize: 13, color: Couleurs.gris },
  reponse: { fontFamily: Polices.texte, fontSize: 14, lineHeight: 20, color: Couleurs.encre, paddingTop: 6, borderTopWidth: 1, borderTopColor: '#F0ECE6' },
});
