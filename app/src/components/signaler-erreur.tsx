import { useState } from 'react';
import { ActivityIndicator, KeyboardAvoidingView, Modal, Platform, Pressable, StyleSheet, Text, TextInput, View } from 'react-native';
import { useSafeAreaInsets } from 'react-native-safe-area-context';
import Svg, { Path } from 'react-native-svg';

import { type Motif, MOTIFS, signaler } from '@/contributions/contributions';
import { Couleurs, Espaces, Polices } from '@/constants/theme';

/**
 * « Signaler une erreur » en bas de la fiche (F5.6) : choix rapides et commentaire facultatif, sans compte.
 * Le signalement arrive dans une file du BO ; un spectacle n'est jamais masqué par un seul signalement.
 */
export function SignalerErreur({ representationId }: { representationId: number | undefined }) {
  const marges = useSafeAreaInsets();
  const [ouvert, setOuvert] = useState(false);
  const [motif, setMotif] = useState<Motif | null>(null);
  const [commentaire, setCommentaire] = useState('');
  const [etat, setEtat] = useState<'saisie' | 'envoi' | 'envoye' | 'erreur'>('saisie');

  if (!representationId) {
    return null;
  }

  const fermer = () => {
    setOuvert(false);
    setMotif(null);
    setCommentaire('');
    setEtat('saisie');
  };

  const envoyer = async () => {
    if (!motif) {
      return;
    }
    setEtat('envoi');
    try {
      await signaler(representationId, motif, commentaire);
      setEtat('envoye');
    } catch {
      setEtat('erreur');
    }
  };

  return (
    <>
      <Pressable style={styles.lien} onPress={() => setOuvert(true)} accessibilityRole="button">
        <Svg width={15} height={15} viewBox="0 0 24 24" fill="none" stroke="#4A4A55" strokeWidth={2} strokeLinecap="round" strokeLinejoin="round">
          <Path d="M5 21V4M5 4h11l-2 4 2 4H5" />
        </Svg>
        <Text style={styles.lienTexte}>Signaler une erreur</Text>
      </Pressable>

      <Modal visible={ouvert} transparent animationType="slide" onRequestClose={fermer}>
        <KeyboardAvoidingView style={styles.voile} behavior={Platform.OS === 'ios' ? 'padding' : undefined}>
          <Pressable style={styles.fondTouchable} onPress={fermer} accessibilityLabel="Fermer" />
          <View style={[styles.feuille, { paddingBottom: 22 + marges.bottom }]}>
            {etat === 'envoye' ? (
              <>
                <Text style={styles.titre}>Merci !</Text>
                <Text style={styles.texte}>Votre signalement est transmis à l’équipe, qui va vérifier cette séance.</Text>
                <Pressable style={styles.bouton} onPress={fermer} accessibilityRole="button">
                  <Text style={styles.boutonTexte}>Fermer</Text>
                </Pressable>
              </>
            ) : (
              <>
                <Text style={styles.titre}>Signaler une erreur</Text>
                <Text style={styles.texte}>Qu’est-ce qui ne va pas sur cette séance ?</Text>
                <View style={styles.puces}>
                  {MOTIFS.map((m) => (
                    <Pressable key={m.motif} style={[styles.puce, motif === m.motif && styles.puceActive]} onPress={() => setMotif(m.motif)} accessibilityRole="button" accessibilityState={{ selected: motif === m.motif }}>
                      <Text style={[styles.puceTexte, motif === m.motif && styles.puceTexteActive]}>{m.libelle}</Text>
                    </Pressable>
                  ))}
                </View>
                <TextInput
                  style={styles.saisie}
                  value={commentaire}
                  onChangeText={(t) => setCommentaire(t.slice(0, 500))}
                  placeholder="Un commentaire (facultatif)"
                  placeholderTextColor={Couleurs.gris}
                  multiline
                />
                {etat === 'erreur' ? <Text style={styles.erreur}>L’envoi n’a pas abouti. Réessayez dans un instant.</Text> : null}
                <Pressable style={[styles.bouton, !motif && styles.inactif]} onPress={envoyer} disabled={!motif || etat === 'envoi'} accessibilityRole="button">
                  {etat === 'envoi' ? <ActivityIndicator color="#FFFFFF" /> : <Text style={styles.boutonTexte}>Envoyer</Text>}
                </Pressable>
              </>
            )}
          </View>
        </KeyboardAvoidingView>
      </Modal>
    </>
  );
}

const styles = StyleSheet.create({
  lien: { alignSelf: 'flex-start', flexDirection: 'row', alignItems: 'center', gap: 6, minHeight: 44 },
  lienTexte: { fontFamily: Polices.texteGras, fontSize: 13, color: '#4A4A55', textDecorationLine: 'underline' },
  voile: { flex: 1, justifyContent: 'flex-end', backgroundColor: 'rgba(22,22,29,0.45)' },
  fondTouchable: { flex: 1 },
  feuille: { gap: 14, paddingTop: 22, paddingHorizontal: Espaces.bord, borderTopLeftRadius: 22, borderTopRightRadius: 22, backgroundColor: Couleurs.carte },
  titre: { fontFamily: Polices.titre, fontSize: 22, color: Couleurs.encre },
  texte: { fontFamily: Polices.texte, fontSize: 15, lineHeight: 22, color: '#4A4A55' },
  puces: { flexDirection: 'row', flexWrap: 'wrap', gap: 8 },
  puce: { height: 38, paddingHorizontal: 14, borderRadius: 19, borderWidth: 1, borderColor: Couleurs.trait, backgroundColor: Couleurs.carte, justifyContent: 'center' },
  puceActive: { borderColor: Couleurs.encre, backgroundColor: Couleurs.encre },
  puceTexte: { fontFamily: Polices.texteMoyen, fontSize: 14, color: Couleurs.encre },
  puceTexteActive: { fontFamily: Polices.texteGras, color: '#FFFFFF' },
  saisie: { minHeight: 70, paddingHorizontal: 14, paddingTop: 12, borderRadius: Espaces.rayonCarte, borderWidth: 1, borderColor: Couleurs.trait, fontFamily: Polices.texte, fontSize: 15, color: Couleurs.encre, textAlignVertical: 'top' },
  erreur: { fontFamily: Polices.texteGras, fontSize: 13, color: Couleurs.rouge },
  bouton: { height: 52, borderRadius: 26, alignItems: 'center', justifyContent: 'center', backgroundColor: Couleurs.encre },
  inactif: { opacity: 0.35 },
  boutonTexte: { fontFamily: Polices.texteTresGras, fontSize: 16, color: '#FFFFFF' },
});
