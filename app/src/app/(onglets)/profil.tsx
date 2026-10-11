import { router } from 'expo-router';
import { Pressable, StyleSheet, Text, View } from 'react-native';

import { useCompte } from '@/compte/compte';
import { AVenir, Ecran } from '@/components/ecran';
import { Couleurs, Espaces, Polices } from '@/constants/theme';

/** Onglet « Profil » : connexion et déconnexion (F1.5) ; le profil complet et les réglages arrivent en M11. */
export default function Profil() {
  const { utilisateur, deconnecter } = useCompte();

  return (
    <Ecran titre="Profil">
      {utilisateur ? (
        <View style={styles.carte}>
          <Text style={styles.nom}>Bonjour {utilisateur.prenom ?? ''}</Text>
          {utilisateur.email ? <Text style={styles.email}>{utilisateur.email}</Text> : null}
          <Pressable onPress={deconnecter} accessibilityRole="button">
            <Text style={styles.lien}>Se déconnecter</Text>
          </Pressable>
        </View>
      ) : (
        <View style={styles.carte}>
          <Text style={styles.texte}>Retrouvez vos goûts et vos favoris sur tous vos appareils. Sans mot de passe.</Text>
          <Pressable style={styles.bouton} onPress={() => router.push({ pathname: '/connexion', params: { raison: 'profil' } })} accessibilityRole="button">
            <Text style={styles.boutonTexte}>Créer un compte ou se connecter</Text>
          </Pressable>
        </View>
      )}
      <AVenir texte="Vos goûts, réglages et la suppression du compte arrivent à l’étape M11." />
    </Ecran>
  );
}

const styles = StyleSheet.create({
  carte: { gap: 12, padding: 16, borderRadius: Espaces.rayonCarte, borderWidth: 1, borderColor: Couleurs.traitCarte, backgroundColor: Couleurs.carte },
  nom: { fontFamily: Polices.titre, fontSize: 22, color: Couleurs.encre },
  email: { fontFamily: Polices.texte, fontSize: 14, color: Couleurs.gris },
  texte: { fontFamily: Polices.texte, fontSize: 15, lineHeight: 22, color: Couleurs.encre },
  lien: { fontFamily: Polices.texteTresGras, fontSize: 14, color: Couleurs.encre, textDecorationLine: 'underline', paddingVertical: 4 },
  bouton: { height: 50, borderRadius: 25, alignItems: 'center', justifyContent: 'center', backgroundColor: Couleurs.encre },
  boutonTexte: { fontFamily: Polices.texteTresGras, fontSize: 15, color: '#FFFFFF' },
});
