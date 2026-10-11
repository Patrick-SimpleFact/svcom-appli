import { useEffect, useState } from 'react';
import { ActivityIndicator, Pressable, StyleSheet, Text, TextInput, View } from 'react-native';
import Svg, { Circle, Path } from 'react-native-svg';

import { chercherVilles, type Ville } from '@/autour/ville';
import { Couleurs, Espaces, Polices } from '@/constants/theme';

/** Délai après la dernière lettre tapée avant d'interroger l'API (propositions < 300 ms côté serveur, F4.1). */
const ATTENTE_SAISIE_MS = 250;

/** Choix d'une ville française avec autocomplétion (F2.1) ; « Ma position » en tête quand la localisation est possible. */
export function ChoixVille({ choisir, maPosition }: { choisir: (ville: Ville) => void; maPosition?: () => void }) {
  const [texte, setTexte] = useState('');
  const [villes, setVilles] = useState<Ville[]>([]);
  const [recherche, setRecherche] = useState(false);

  useEffect(() => {
    const saisie = texte.trim();
    if (saisie.length < 2) {
      return;
    }
    let actif = true;
    const minuteur = setTimeout(() => {
      setRecherche(true);
      chercherVilles(saisie)
        .then((resultats) => actif && setVilles(resultats))
        .catch(() => actif && setVilles([]))
        .finally(() => actif && setRecherche(false));
    }, ATTENTE_SAISIE_MS);

    return () => {
      actif = false;
      clearTimeout(minuteur);
    };
  }, [texte]);

  const saisieCourte = texte.trim().length < 2;

  return (
    <View style={styles.bloc}>
      <TextInput
        style={styles.champ}
        value={texte}
        onChangeText={setTexte}
        placeholder="Une ville (ex. Bordeaux)"
        placeholderTextColor={Couleurs.gris}
        autoCorrect={false}
        autoFocus
        returnKeyType="search"
        accessibilityLabel="Ville"
      />
      {maPosition ? (
        <Pressable style={styles.ligne} onPress={maPosition} accessibilityRole="button">
          <Svg width={18} height={18} viewBox="0 0 24 24" fill="none" stroke={Couleurs.rouge} strokeWidth={2} strokeLinecap="round" strokeLinejoin="round">
            <Circle cx={12} cy={12} r={3} />
            <Path d="M12 2v3M12 19v3M2 12h3M19 12h3" />
          </Svg>
          <Text style={[styles.nom, styles.maPosition]}>Ma position</Text>
        </Pressable>
      ) : null}
      {recherche ? <ActivityIndicator color={Couleurs.rouge} style={styles.attente} /> : null}
      {saisieCourte
        ? null
        : villes.map((ville) => (
            <Pressable key={ville.id} style={styles.ligne} onPress={() => choisir(ville)} accessibilityRole="button">
              <Text style={styles.nom}>{ville.nom}</Text>
              {ville.sous_titre ? <Text style={styles.sousTitre}>{ville.sous_titre}</Text> : null}
            </Pressable>
          ))}
      {!saisieCourte && !recherche && villes.length === 0 ? <Text style={styles.sousTitre}>Aucune ville trouvée.</Text> : null}
    </View>
  );
}

const styles = StyleSheet.create({
  bloc: { gap: 4 },
  champ: {
    height: 48, paddingHorizontal: 16, marginBottom: 8, borderRadius: 24, borderWidth: 1, borderColor: Couleurs.trait,
    backgroundColor: Couleurs.carte, fontFamily: Polices.texte, fontSize: 16, color: Couleurs.encre,
  },
  ligne: {
    flexDirection: 'row', alignItems: 'center', gap: 8, paddingVertical: 12, paddingHorizontal: 4,
    borderBottomWidth: StyleSheet.hairlineWidth, borderBottomColor: Couleurs.trait,
  },
  nom: { fontFamily: Polices.texteGras, fontSize: 16, color: Couleurs.encre },
  maPosition: { color: Couleurs.rouge },
  sousTitre: { fontFamily: Polices.texte, fontSize: 14, color: Couleurs.gris, paddingHorizontal: 4 },
  attente: { alignSelf: 'flex-start', marginVertical: 8, marginLeft: Espaces.bord / 4 },
});
