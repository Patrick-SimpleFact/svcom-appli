import Slider from '@react-native-community/slider';
import { useState } from 'react';
import { ActivityIndicator, Pressable, ScrollView, StyleSheet, Text, View } from 'react-native';
import { SafeAreaView } from 'react-native-safe-area-context';
import Svg, { Path } from 'react-native-svg';

import { Couleurs, Espaces, Polices } from '@/constants/theme';
import { useDemarrage } from '@/demarrage/demarrage';
import { GOUTS_PAR_DEFAUT, libelleRayon, RAYONS_M, type RayonM, useGouts } from '@/gouts/gouts';

/**
 * Premier lancement (F1.1, M03) : bienvenue (étape 1) → vos goûts (étape 2, maquette « Onboarding préférences ») → accueil.
 * Pas de compte ni de notifications demandés ici ; la localisation est demandée par l'accueil (M04).
 */
export function PremierLancement() {
  const [etape, setEtape] = useState<1 | 2>(1);

  return (
    <SafeAreaView style={styles.fond}>
      <View style={styles.entete}>
        <Text style={styles.logo}>
          Spettacoli<Text style={styles.point}>.</Text>
        </Text>
        <Text style={styles.etape}>Étape {etape} sur 2</Text>
      </View>
      <View style={styles.progression}>
        <View style={[styles.barre, styles.barreFaite]} />
        <View style={[styles.barre, etape === 2 && styles.barreFaite]} />
      </View>
      {etape === 1 ? <Bienvenue commencer={() => setEtape(2)} /> : <VosGouts />}
    </SafeAreaView>
  );
}

function Bienvenue({ commencer }: { commencer: () => void }) {
  return (
    <>
      <View style={styles.bienvenue}>
        <Text style={styles.titre} accessibilityRole="header">Les spectacles autour de vous, ce soir.</Text>
      </View>
      <View style={styles.pied}>
        <Bouton texte="Commencer" onPress={commencer} />
      </View>
    </>
  );
}

/** Genres de F2.3 à cocher, rayon par défaut ; rien n'est obligatoire. */
function VosGouts() {
  const { terminerPremierLancement } = useGouts();
  const [genres, setGenres] = useState<number[]>(GOUTS_PAR_DEFAUT.genres);
  const [rayon, setRayon] = useState<RayonM>(GOUTS_PAR_DEFAUT.rayon_m);

  const basculer = (id: number) => setGenres((avant) => (avant.includes(id) ? avant.filter((g) => g !== id) : [...avant, id]));

  return (
    <>
      <ScrollView contentContainerStyle={styles.contenu}>
        <View style={styles.intro}>
          <Text style={styles.titre} accessibilityRole="header">Qu’aimez-vous voir sur scène ?</Text>
          <Text style={styles.sousTitre}>On s’en sert pour trier les spectacles autour de vous. Modifiable à tout moment.</Text>
        </View>

        <ChoixGouts genres={genres} basculer={basculer} rayon={rayon} choisirRayon={setRayon} />
      </ScrollView>
      <View style={styles.pied}>
        <Bouton texte="Continuer" onPress={() => terminerPremierLancement({ genres, rayon_m: rayon })} />
        <Pressable style={styles.passer} onPress={() => terminerPremierLancement(null)} accessibilityRole="button">
          <Text style={styles.passerTexte}>Passer cette étape</Text>
        </Pressable>
      </View>
    </>
  );
}

/** Genres à cocher et distance par paliers (F1.1, F2.4) : premier lancement et Profil › Mes goûts. */
export function ChoixGouts(props: { genres: number[]; basculer: (id: number) => void; rayon: RayonM; choisirRayon: (r: RayonM) => void }) {
  const { genres, basculer, rayon } = props;
  const setRayon = props.choisirRayon;
  const { configuration, configurationTerminee } = useDemarrage();

  return (
    <>
        {configuration ? (
          <View style={styles.grille}>
            {configuration.genres.map((genre) => (
              <Genre key={genre.id} texte={genre.libelle} choisi={genres.includes(genre.id)} onPress={() => basculer(genre.id)} />
            ))}
          </View>
        ) : configurationTerminee ? (
          <Text style={styles.sousTitre}>Les genres n’ont pas pu être chargés. Vous pourrez les choisir plus tard.</Text>
        ) : (
          <ActivityIndicator color={Couleurs.rouge} style={styles.chargement} />
        )}

        <View style={styles.carte}>
          <View style={styles.carteLigne}>
            <Text style={styles.carteLibelle}>Distance maximale</Text>
            <Text style={styles.carteValeur}>{libelleRayon(rayon)}</Text>
          </View>
          {/* Paliers de F2.4 : Automatique, puis 1, 2, 5, 10, 25 et 50 km. */}
          <Slider
            minimumValue={0}
            maximumValue={RAYONS_M.length - 1}
            step={1}
            value={RAYONS_M.indexOf(rayon)}
            onValueChange={(i) => setRayon(RAYONS_M[Math.round(i)])}
            minimumTrackTintColor={Couleurs.rouge}
            maximumTrackTintColor={Couleurs.trait}
            thumbTintColor={Couleurs.rouge}
            tapToSeek
            accessibilityLabel="Distance maximale"
          />
        </View>
    </>
  );
}

function Genre({ texte, choisi, onPress }: { texte: string; choisi: boolean; onPress: () => void }) {
  return (
    <Pressable
      onPress={onPress}
      style={[styles.genre, choisi && styles.genreChoisi]}
      accessibilityRole="checkbox"
      accessibilityState={{ checked: choisi }}>
      <Text style={[styles.genreTexte, choisi && styles.genreTexteChoisi]}>{texte}</Text>
      {choisi ? (
        <Svg width={18} height={18} viewBox="0 0 24 24" fill="none" stroke="#FFFFFF" strokeWidth={2.4} strokeLinecap="round" strokeLinejoin="round">
          <Path d="M5 12l5 5 9-10" />
        </Svg>
      ) : null}
    </Pressable>
  );
}

function Bouton({ texte, onPress }: { texte: string; onPress: () => void }) {
  return (
    <Pressable style={({ pressed }) => [styles.bouton, pressed && styles.boutonAppuye]} onPress={onPress} accessibilityRole="button">
      <Text style={styles.boutonTexte}>{texte}</Text>
    </Pressable>
  );
}

// Valeurs de la maquette « Onboarding préférences » (écran de 390 × 844).
const styles = StyleSheet.create({
  fond: { flex: 1, backgroundColor: Couleurs.fond },
  entete: { flexDirection: 'row', alignItems: 'center', justifyContent: 'space-between', paddingHorizontal: Espaces.bord, paddingTop: 12 },
  logo: { fontFamily: Polices.titreFort, fontSize: 22, letterSpacing: -0.44, color: Couleurs.encre },
  point: { color: Couleurs.rouge },
  etape: { fontFamily: Polices.texte, fontSize: 13, color: Couleurs.gris },
  progression: { flexDirection: 'row', gap: 6, paddingHorizontal: Espaces.bord, marginTop: 18 },
  barre: { flex: 1, height: 4, borderRadius: 2, backgroundColor: Couleurs.trait },
  barreFaite: { backgroundColor: Couleurs.encre },
  bienvenue: { flex: 1, justifyContent: 'center', paddingHorizontal: Espaces.bord },
  contenu: { paddingHorizontal: Espaces.bord, paddingTop: 18, paddingBottom: 18, gap: 18 },
  intro: { gap: 8 },
  titre: { fontFamily: Polices.titreFort, fontSize: 32, lineHeight: 34, letterSpacing: -0.64, color: Couleurs.encre },
  sousTitre: { fontFamily: Polices.texte, fontSize: 15, lineHeight: 22, color: Couleurs.gris },
  grille: { flexDirection: 'row', flexWrap: 'wrap', gap: 10 },
  genre: {
    width: '48.5%', minHeight: 52, paddingVertical: 8, paddingHorizontal: 14, borderRadius: Espaces.rayonCarte,
    borderWidth: 1, borderColor: Couleurs.trait, backgroundColor: Couleurs.carte,
    flexDirection: 'row', alignItems: 'center', justifyContent: 'space-between', gap: 6,
  },
  genreChoisi: { backgroundColor: Couleurs.encre, borderColor: Couleurs.encre },
  genreTexte: { flexShrink: 1, fontFamily: Polices.texteGras, fontSize: 14, lineHeight: 17, color: Couleurs.encre },
  genreTexteChoisi: { color: '#FFFFFF' },
  chargement: { alignSelf: 'flex-start' },
  carte: { gap: 10, padding: 16, borderRadius: Espaces.rayonCarte, backgroundColor: Couleurs.carte, borderWidth: 1, borderColor: Couleurs.traitCarte },
  carteLigne: { flexDirection: 'row', justifyContent: 'space-between' },
  carteLibelle: { fontFamily: Polices.texteGras, fontSize: 15, color: Couleurs.encre },
  carteValeur: { fontFamily: Polices.texteTresGras, fontSize: 15, color: Couleurs.encre },
  pied: { paddingHorizontal: Espaces.bord, paddingTop: 8, paddingBottom: 12, gap: 6 },
  bouton: { height: 54, borderRadius: 27, alignItems: 'center', justifyContent: 'center', backgroundColor: Couleurs.encre },
  boutonAppuye: { opacity: 0.85 },
  boutonTexte: { fontFamily: Polices.texteTresGras, fontSize: 16, color: '#FFFFFF' },
  passer: { height: 44, alignItems: 'center', justifyContent: 'center' },
  passerTexte: { fontFamily: Polices.texteGras, fontSize: 14, color: Couleurs.encre, textDecorationLine: 'underline' },
});
