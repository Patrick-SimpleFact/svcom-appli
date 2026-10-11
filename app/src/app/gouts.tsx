import { router } from 'expo-router';
import { useState } from 'react';
import { ActivityIndicator, Pressable, ScrollView, StyleSheet, Text, View } from 'react-native';
import { useSafeAreaInsets } from 'react-native-safe-area-context';

import { useCompte } from '@/compte/compte';
import { modifierPreferences, versPreferences } from '@/compte/profil';
import { ChoixGouts } from '@/components/premier-lancement';
import { Couleurs, Espaces, Polices } from '@/constants/theme';
import { type RayonM, useGouts } from '@/gouts/gouts';

/** Profil › Mes goûts (F1.6) : genres et rayon par défaut, gardés sur le téléphone et, avec un compte, retrouvés partout (F1.2). */
export default function MesGouts() {
  const marges = useSafeAreaInsets();
  const { gouts, modifierGouts } = useGouts();
  const { utilisateur } = useCompte();
  const [genres, setGenres] = useState(gouts.genres);
  const [rayon, setRayon] = useState<RayonM>(gouts.rayon_m);
  const [envoi, setEnvoi] = useState(false);
  const [erreur, setErreur] = useState(false);

  const enregistrer = async () => {
    const choix = { genres, rayon_m: rayon };
    setEnvoi(true);
    setErreur(false);
    try {
      if (utilisateur) {
        await modifierPreferences(versPreferences(choix));
      }
      modifierGouts(choix);
      router.back();
    } catch {
      setErreur(true);
    } finally {
      setEnvoi(false);
    }
  };

  return (
    <View style={styles.fond}>
      <View style={styles.entete}>
        <Text style={styles.titre} accessibilityRole="header">Mes goûts</Text>
        <Pressable onPress={() => router.back()} hitSlop={12} accessibilityRole="button">
          <Text style={styles.fermer}>Fermer</Text>
        </Pressable>
      </View>
      <ScrollView contentContainerStyle={styles.contenu}>
        <Text style={styles.texte}>
          {utilisateur ? 'Retrouvés sur tous vos appareils.' : 'Gardés sur ce téléphone. Créez un compte pour les retrouver sur vos autres appareils.'}
        </Text>
        <ChoixGouts
          genres={genres}
          basculer={(id) => setGenres((avant) => (avant.includes(id) ? avant.filter((g) => g !== id) : [...avant, id]))}
          rayon={rayon}
          choisirRayon={setRayon}
        />
        {erreur ? <Text style={styles.erreur}>Impossible d’enregistrer pour le moment. Réessayez dans un instant.</Text> : null}
      </ScrollView>
      <View style={[styles.pied, { paddingBottom: 12 + marges.bottom }]}>
        <Pressable style={styles.bouton} onPress={enregistrer} disabled={envoi} accessibilityRole="button">
          {envoi ? <ActivityIndicator color="#FFFFFF" /> : <Text style={styles.boutonTexte}>Enregistrer</Text>}
        </Pressable>
      </View>
    </View>
  );
}

const styles = StyleSheet.create({
  fond: { flex: 1, backgroundColor: Couleurs.fond },
  entete: { flexDirection: 'row', alignItems: 'center', justifyContent: 'space-between', padding: Espaces.bord },
  titre: { fontFamily: Polices.titre, fontSize: 24, color: Couleurs.encre },
  fermer: { fontFamily: Polices.texteGras, fontSize: 16, color: Couleurs.gris },
  contenu: { gap: 18, paddingHorizontal: Espaces.bord, paddingBottom: 24 },
  texte: { fontFamily: Polices.texte, fontSize: 15, lineHeight: 22, color: Couleurs.gris },
  erreur: { fontFamily: Polices.texteGras, fontSize: 14, color: Couleurs.rouge },
  pied: { paddingTop: 12, paddingHorizontal: Espaces.bord, borderTopWidth: 1, borderTopColor: Couleurs.traitCarte, backgroundColor: Couleurs.carte },
  bouton: { height: 54, borderRadius: 27, alignItems: 'center', justifyContent: 'center', backgroundColor: Couleurs.encre },
  boutonTexte: { fontFamily: Polices.texteTresGras, fontSize: 16, color: '#FFFFFF' },
});
