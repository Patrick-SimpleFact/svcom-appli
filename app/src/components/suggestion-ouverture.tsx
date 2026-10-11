import * as Location from 'expo-location';
import { useEffect, useRef, useState } from 'react';
import { Modal, Platform, Pressable, StyleSheet, Text, View } from 'react-native';
import { useSafeAreaInsets } from 'react-native-safe-area-context';
import Svg, { Circle, Path } from 'react-native-svg';

import { heure, distance } from '@/autour/autour';
import { ouvrirFiche } from '@/components/carte-spectacle';
import { Couleurs, Polices } from '@/constants/theme';
import { useDemarrage } from '@/demarrage/demarrage';
import { useGouts } from '@/gouts/gouts';
import { chargerSuggestion, choisir, libelleTarifs, lireChoix, signalerAction, type Suggestion } from '@/suggestion/suggestion';

/**
 * Suggestion à l'ouverture (F6, maquette « Suggestion à l'ouverture ») : seulement si l'utilisateur l'a acceptée,
 * une fois par jour au plus (le BO répond 204 sinon), et jamais si elle met plus d'une seconde à venir.
 */
export function SuggestionOuverture() {
  const { configurationTerminee } = useDemarrage();
  const { gouts } = useGouts();
  const marges = useSafeAreaInsets();
  const [suggestion, setSuggestion] = useState<Suggestion | null>(null);
  const [visible, setVisible] = useState(false);
  const [confirmation, setConfirmation] = useState(false);
  const deja = useRef(false);
  // Fiche à ouvrir une fois la carte refermée (iOS ne présente pas un écran pendant qu'une feuille se ferme).
  const ficheEnAttente = useRef<Suggestion | null>(null);

  useEffect(() => {
    // Après le démarrage (l'appareil doit être connu du BO), une seule fois par lancement.
    if (!configurationTerminee || deja.current) {
      return;
    }
    deja.current = true;
    let actif = true;
    (async () => {
      if ((await lireChoix()) !== 'oui') {
        return;
      }
      // Dernière position connue : immédiate, pour tenir dans la seconde (F6.3).
      const permission = await Location.getForegroundPermissionsAsync().catch(() => null);
      const coords = permission?.granted ? (await Location.getLastKnownPositionAsync().catch(() => null))?.coords : null;
      if (!coords) {
        return;
      }
      const s = await chargerSuggestion({ lat: coords.latitude, lon: coords.longitude }, gouts.genres);
      if (actif && s) {
        setSuggestion(s);
        setVisible(true);
      }
    })();

    return () => {
      actif = false;
    };
  }, [configurationTerminee, gouts.genres]);

  if (!suggestion) {
    return null;
  }

  const fermer = () => {
    setConfirmation(false);
    setVisible(false);
  };
  const passer = () => {
    signalerAction(suggestion.affichage_id, 'passee');
    fermer();
  };
  const voir = () => {
    signalerAction(suggestion.affichage_id, 'fiche');
    if (Platform.OS === 'ios') {
      ficheEnAttente.current = suggestion;
      fermer();
    } else {
      fermer();
      ouvrir(suggestion);
    }
  };
  const ouvrir = (s: Suggestion) => ouvrirFiche(s.spectacle.id, s.seance.representation_id, null, s.type === 'sponsorise' ? 'suggestion_sponsorisee' : 'suggestion_auto');
  const arreter = () => {
    choisir('desactive');
    fermer();
  };

  const surtitre = [suggestion.spectacle.genre?.libelle, suggestion.lieu.nom].filter(Boolean).join(' · ');
  const tarifs = libelleTarifs(suggestion);
  const ceSoir = suggestion.raison.includes('ce soir');

  return (
    <Modal
      visible={visible}
      animationType="slide"
      presentationStyle="pageSheet"
      onRequestClose={passer}
      onDismiss={() => {
        const s = ficheEnAttente.current;
        ficheEnAttente.current = null;
        if (s) {
          ouvrir(s);
        }
      }}>
      <View style={[styles.fond, { paddingBottom: 22 + marges.bottom }]}>
        <View style={styles.poignee} />
        <View style={styles.entete}>
          {suggestion.type === 'sponsorise' ? (
            // Mention légale : une publicité doit être identifiable comme telle (F6.1).
            <Text style={styles.sponsorise}>Sponsorisé{suggestion.annonceur ? ` · ${suggestion.annonceur}` : ''}</Text>
          ) : (
            <View style={styles.etiquette}>
              <Svg width={14} height={14} viewBox="0 0 24 24" fill="none" stroke="#FFFFFF" strokeWidth={2.2} strokeLinecap="round" strokeLinejoin="round">
                <Path d="M12 3l2.7 5.6 6.1.9-4.4 4.3 1 6.1L12 17l-5.4 2.9 1-6.1-4.4-4.3 6.1-.9z" />
              </Svg>
              <Text style={styles.etiquetteTexte}>Suggestion pour vous</Text>
            </View>
          )}
          <Pressable style={styles.passer} onPress={passer} accessibilityRole="button">
            <Text style={styles.passerTexte}>Passer</Text>
            <Svg width={14} height={14} viewBox="0 0 24 24" fill="none" stroke="#FFFFFF" strokeWidth={2.4} strokeLinecap="round">
              <Path d="M6 6l12 12M18 6L6 18" />
            </Svg>
          </Pressable>
        </View>

        <View style={styles.centre}>
          <View style={styles.disque} />
          <View style={styles.titres}>
            {surtitre ? <Text style={styles.surtitre}>{surtitre}</Text> : null}
            <Text style={[styles.titre, tailleTitre(suggestion.spectacle.titre)]} numberOfLines={5} accessibilityRole="header">{suggestion.spectacle.titre}</Text>
          </View>
          <View style={styles.raison}>
            <Svg width={14} height={14} viewBox="0 0 24 24" fill="none" stroke="rgba(255,255,255,0.85)" strokeWidth={2} strokeLinecap="round">
              <Circle cx={12} cy={12} r={9} />
              <Path d="M12 11v5M12 8h.01" />
            </Svg>
            <Text style={styles.raisonTexte}>{suggestion.raison}</Text>
          </View>
        </View>

        <View style={styles.bas}>
          <View style={styles.infos}>
            <Info libelle={ceSoir ? 'Ce soir' : 'Demain'} valeur={heure(suggestion.seance.debut)} />
            <Info libelle="Distance" valeur={distance(suggestion.distance_m)} />
            {tarifs ? <Info libelle="Tarifs" valeur={tarifs} /> : null}
          </View>
          <Pressable style={styles.voir} onPress={voir} accessibilityRole="button">
            <Text style={styles.voirTexte}>Voir le spectacle</Text>
          </Pressable>
          {/* Lien discret, éloigné de « Voir le spectacle » pour éviter un appui par erreur (F6.2). */}
          <Pressable style={styles.arreter} onPress={() => setConfirmation(true)} accessibilityRole="button">
            <Text style={styles.arreterTexte}>Ne plus me proposer de suggestions</Text>
          </Pressable>
        </View>

        {confirmation ? (
          <View style={styles.voile}>
            <View style={styles.dialogue} accessibilityRole="alert">
              <Text style={styles.dialogueTitre}>Arrêter les suggestions ?</Text>
              <Text style={styles.dialogueTexte}>
                Vous ne verrez plus de spectacle proposé à l’ouverture de l’app. Vous pourrez les réactiver à tout moment dans Profil › Réglages.
              </Text>
              <Pressable style={styles.garder} onPress={() => setConfirmation(false)} accessibilityRole="button">
                <Text style={styles.garderTexte}>Garder les suggestions</Text>
              </Pressable>
              <Pressable style={styles.ouiArreter} onPress={arreter} accessibilityRole="button">
                <Text style={styles.ouiArreterTexte}>Oui, arrêter</Text>
              </Pressable>
            </View>
          </View>
        ) : null}
      </View>
    </Modal>
  );
}

/**
 * Taille du titre selon sa longueur : très grand comme la maquette pour un titre court, plus sage pour un long
 * (la réduction automatique d'iOS ne réduit pas l'interligne et laissait des trous entre les lignes).
 */
export function tailleTitre(titre: string): { fontSize: number; lineHeight: number; letterSpacing: number } {
  if (titre.length <= 18) {
    return { fontSize: 64, lineHeight: 60, letterSpacing: -2.2 };
  }
  if (titre.length <= 40) {
    return { fontSize: 48, lineHeight: 46, letterSpacing: -1.6 };
  }

  return { fontSize: 34, lineHeight: 35, letterSpacing: -0.9 };
}

function Info({ libelle, valeur }: { libelle: string; valeur: string }) {
  return (
    <View style={styles.info}>
      <Text style={styles.infoLibelle}>{libelle}</Text>
      <Text style={styles.infoValeur}>{valeur}</Text>
    </View>
  );
}

// Valeurs de la maquette « Suggestion à l'ouverture ».
const styles = StyleSheet.create({
  fond: { flex: 1, paddingTop: 14, paddingHorizontal: 24, backgroundColor: Couleurs.encre, overflow: 'hidden' },
  poignee: { alignSelf: 'center', width: 40, height: 4, borderRadius: 2, backgroundColor: 'rgba(255,255,255,0.3)' },
  entete: { flexDirection: 'row', alignItems: 'center', justifyContent: 'space-between', marginTop: 10 },
  etiquette: { flexDirection: 'row', alignItems: 'center', gap: 6, paddingVertical: 6, paddingHorizontal: 12, borderRadius: 14, backgroundColor: 'rgba(255,255,255,0.14)' },
  etiquetteTexte: { fontFamily: Polices.texteTresGras, fontSize: 12, letterSpacing: 0.7, textTransform: 'uppercase', color: '#FFFFFF' },
  sponsorise: {
    overflow: 'hidden', paddingVertical: 6, paddingHorizontal: 12, borderRadius: 14, backgroundColor: '#FFFFFF',
    fontFamily: Polices.texteTresGras, fontSize: 12, letterSpacing: 0.7, textTransform: 'uppercase', color: Couleurs.encre,
  },
  passer: { height: 44, paddingHorizontal: 18, borderRadius: 22, flexDirection: 'row', alignItems: 'center', gap: 6, backgroundColor: 'rgba(255,255,255,0.14)' },
  passerTexte: { fontFamily: Polices.texteTresGras, fontSize: 14, color: '#FFFFFF' },
  centre: { flex: 1, justifyContent: 'center', gap: 20 },
  disque: { position: 'absolute', right: -114, top: 40, width: 300, height: 300, borderRadius: 150, backgroundColor: Couleurs.rouge },
  titres: { gap: 6 },
  surtitre: { fontFamily: Polices.texteGras, fontSize: 13, letterSpacing: 1.3, textTransform: 'uppercase', color: 'rgba(255,255,255,0.75)' },
  titre: { fontFamily: Polices.titreFort, fontSize: 64, lineHeight: 60, letterSpacing: -2.2, color: '#FFFFFF' },
  raison: { alignSelf: 'flex-start', flexDirection: 'row', alignItems: 'center', gap: 8, paddingVertical: 8, paddingHorizontal: 12, borderRadius: 12, backgroundColor: 'rgba(255,255,255,0.1)' },
  raisonTexte: { flexShrink: 1, fontFamily: Polices.texte, fontSize: 13, color: 'rgba(255,255,255,0.85)' },
  bas: { gap: 12 },
  infos: { flexDirection: 'row', gap: 8 },
  info: { flex: 1, gap: 2, padding: 12, borderRadius: 14, backgroundColor: 'rgba(255,255,255,0.08)' },
  infoLibelle: { fontFamily: Polices.texte, fontSize: 12, color: 'rgba(255,255,255,0.7)' },
  infoValeur: { fontFamily: Polices.titre, fontSize: 20, color: '#FFFFFF' },
  voir: { height: 56, borderRadius: 28, alignItems: 'center', justifyContent: 'center', backgroundColor: '#FFFFFF' },
  voirTexte: { fontFamily: Polices.texteTresGras, fontSize: 16, color: Couleurs.encre },
  arreter: { alignSelf: 'center', marginTop: 14, minHeight: 36, justifyContent: 'center', paddingHorizontal: 8 },
  arreterTexte: { fontFamily: Polices.texteMoyen, fontSize: 12, color: 'rgba(255,255,255,0.6)', textDecorationLine: 'underline' },
  voile: { position: 'absolute', top: 0, right: 0, bottom: 0, left: 0, alignItems: 'center', justifyContent: 'center', padding: 24, backgroundColor: 'rgba(0,0,0,0.6)' },
  dialogue: { alignSelf: 'stretch', gap: 14, paddingTop: 22, paddingHorizontal: 20, paddingBottom: 18, borderRadius: 20, backgroundColor: '#FFFFFF' },
  dialogueTitre: { fontFamily: Polices.titre, fontSize: 22, lineHeight: 25, color: Couleurs.encre },
  dialogueTexte: { fontFamily: Polices.texte, fontSize: 14, lineHeight: 21, color: '#4A4A55' },
  garder: { height: 48, borderRadius: 24, alignItems: 'center', justifyContent: 'center', backgroundColor: Couleurs.encre },
  garderTexte: { fontFamily: Polices.texteTresGras, fontSize: 15, color: '#FFFFFF' },
  ouiArreter: { height: 48, borderRadius: 24, alignItems: 'center', justifyContent: 'center', borderWidth: 1, borderColor: '#D9D4CD' },
  ouiArreterTexte: { fontFamily: Polices.texteGras, fontSize: 15, color: Couleurs.encre },
});
