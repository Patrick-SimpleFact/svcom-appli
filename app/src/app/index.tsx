import { useCallback, useEffect, useRef, useState } from 'react';
import { ActivityIndicator, FlatList, Linking, Pressable, RefreshControl, StyleSheet, Text, View } from 'react-native';
import { SafeAreaView } from 'react-native-safe-area-context';
import Svg, { Circle, Path } from 'react-native-svg';

import { type Carte, chargerAutour, compteur, type ReponseAutour, titreAccueil } from '@/autour/autour';
import { type Position, usePosition } from '@/autour/position';
import { CarteSansHoraire, CarteSpectacle } from '@/components/carte-spectacle';
import { MessagesService } from '@/components/messages-service';
import { Couleurs, Espaces, Polices } from '@/constants/theme';
import { useDemarrage } from '@/demarrage/demarrage';
import { useGouts } from '@/gouts/gouts';

type Liste = { etat: 'chargement' } | { etat: 'erreur' } | { etat: 'prete'; reponse: ReponseAutour; cartes: Carte[] };

/** Onglet « Autour de moi » (F2, maquette « Accueil autour de moi ») : les spectacles de ce soir autour de la position. */
export default function AutourDeMoi() {
  const position = usePosition();

  return (
    <SafeAreaView style={styles.fond} edges={['top']}>
      {position.etat === 'trouvee' ? (
        <ListeAutour position={position.position} />
      ) : (
        <View style={[styles.entete, styles.marges]}>
          <Titre />
          <MessagesService />
          {position.etat === 'a_expliquer' ? (
            <Explication texte="Pour vous montrer les spectacles autour de vous, Spettacoli a besoin de votre position. Elle n’est jamais enregistrée." action="Autoriser la localisation" onPress={position.autoriser} />
          ) : position.etat === 'refusee' ? (
            <Explication texte="Activez la localisation pour voir les spectacles autour de vous." action="Ouvrir les réglages" onPress={() => Linking.openSettings()} />
          ) : (
            <Chargement texte="Recherche de votre position…" />
          )}
        </View>
      )}
    </SafeAreaView>
  );
}

function ListeAutour({ position }: { position: Position }) {
  const { configuration } = useDemarrage();
  const { gouts } = useGouts();
  const [liste, setListe] = useState<Liste>({ etat: 'chargement' });
  const [rafraichissement, setRafraichissement] = useState(false);
  const pageEnCours = useRef(false);

  const charger = useCallback(async () => {
    try {
      const reponse = await chargerAutour({ lat: position.lat, lon: position.lon, rayon_m: gouts.rayon_m });
      setListe({ etat: 'prete', reponse, cartes: reponse.cartes });
    } catch {
      setListe({ etat: 'erreur' });
    }
  }, [position.lat, position.lon, gouts.rayon_m]);

  // Au changement de position ou de rayon, l'ancienne liste reste affichée jusqu'à l'arrivée de la nouvelle.
  useEffect(() => {
    let actif = true;
    chargerAutour({ lat: position.lat, lon: position.lon, rayon_m: gouts.rayon_m })
      .then((reponse) => actif && setListe({ etat: 'prete', reponse, cartes: reponse.cartes }))
      .catch(() => actif && setListe({ etat: 'erreur' }));

    return () => {
      actif = false;
    };
  }, [position.lat, position.lon, gouts.rayon_m]);

  const rafraichir = async () => {
    setRafraichissement(true);
    await charger();
    setRafraichissement(false);
  };

  // Chargement continu : 30 cartes par page (F2.5).
  const pageSuivante = async () => {
    if (liste.etat !== 'prete' || !liste.reponse.suivant || pageEnCours.current) {
      return;
    }
    pageEnCours.current = true;
    try {
      const suite = await chargerAutour({ lat: position.lat, lon: position.lon, rayon_m: gouts.rayon_m, suivant: liste.reponse.suivant });
      setListe({ etat: 'prete', reponse: { ...liste.reponse, suivant: suite.suivant }, cartes: [...liste.cartes, ...suite.cartes] });
    } catch {
      // Nouvel essai au prochain défilement.
    } finally {
      pageEnCours.current = false;
    }
  };

  const libelleGenre = (id: number) => configuration?.genres.find((g) => g.id === id)?.libelle ?? '';
  const reponse = liste.etat === 'prete' ? liste.reponse : null;

  return (
    <FlatList
      data={liste.etat === 'prete' ? liste.cartes : []}
      keyExtractor={(c) => `${c.spectacle.id}-${c.lieu.id}-${c.date_locale}`}
      renderItem={({ item }) => <CarteSpectacle carte={item} genre={libelleGenre(item.spectacle.genre_id)} />}
      contentContainerStyle={styles.contenu}
      ItemSeparatorComponent={Espace}
      onEndReached={pageSuivante}
      onEndReachedThreshold={0.5}
      refreshControl={<RefreshControl refreshing={rafraichissement} onRefresh={rafraichir} tintColor={Couleurs.rouge} />}
      ListHeaderComponent={
        <View style={styles.entete}>
          <EnTeteLieu libelle={position.libelle} />
          <Titre />
          <MessagesService />
          {reponse ? <Text style={styles.compteur}>{compteur(reponse.total, reponse.rayon_retenu_m)}</Text> : null}
        </View>
      }
      ListEmptyComponent={
        liste.etat === 'chargement' ? (
          <Chargement texte="Chargement des spectacles…" />
        ) : liste.etat === 'erreur' ? (
          <Explication texte="Impossible de charger les spectacles pour le moment." action="Réessayer" onPress={rafraichir} />
        ) : reponse?.toute_la_journee.length ? null : (
          <Text style={styles.vide}>Aucun spectacle ce soir autour de vous.</Text>
        )
      }
      ListFooterComponent={
        reponse?.suivant ? (
          <ActivityIndicator color={Couleurs.rouge} style={styles.pied} />
        ) : reponse?.toute_la_journee.length ? (
          <View style={styles.journee}>
            <Text style={styles.rubrique}>Toute la journée</Text>
            {reponse.toute_la_journee.map((c) => (
              <CarteSansHoraire key={c.representation_id} carte={c} genre={libelleGenre(c.spectacle.genre_id)} />
            ))}
          </View>
        ) : null
      }
    />
  );
}

function Titre() {
  return <Text style={styles.titre} accessibilityRole="header">{titreAccueil(new Date())}</Text>;
}

/** « Avignon · Centre » (le choix d'une autre ville arrive en M04b). */
function EnTeteLieu({ libelle }: { libelle: string | null }) {
  return (
    <View style={styles.lieu}>
      <Svg width={18} height={18} viewBox="0 0 24 24" fill="none" stroke={Couleurs.encre} strokeWidth={2} strokeLinecap="round" strokeLinejoin="round">
        <Path d="M12 21s-7-6.2-7-11a7 7 0 0 1 14 0c0 4.8-7 11-7 11z" />
        <Circle cx={12} cy={10} r={2.5} />
      </Svg>
      <Text style={styles.lieuTexte}>{libelle ?? 'Ma position'}</Text>
    </View>
  );
}

function Explication({ texte, action, onPress }: { texte: string; action: string; onPress: () => void }) {
  return (
    <View style={styles.explication}>
      <Text style={styles.explicationTexte}>{texte}</Text>
      <Pressable style={styles.bouton} onPress={onPress} accessibilityRole="button">
        <Text style={styles.boutonTexte}>{action}</Text>
      </Pressable>
    </View>
  );
}

function Chargement({ texte }: { texte: string }) {
  return (
    <View style={styles.chargement} accessibilityLiveRegion="polite">
      <ActivityIndicator color={Couleurs.rouge} />
      <Text style={styles.compteur}>{texte}</Text>
    </View>
  );
}

const Espace = () => <View style={styles.espace} />;

// Valeurs de la maquette « Accueil autour de moi ».
const styles = StyleSheet.create({
  fond: { flex: 1, backgroundColor: Couleurs.fond },
  contenu: { paddingHorizontal: Espaces.bord, paddingBottom: 120 },
  entete: { paddingTop: 8, paddingBottom: 12, gap: 16 },
  marges: { paddingHorizontal: Espaces.bord },
  lieu: { flexDirection: 'row', alignItems: 'center', gap: 6, paddingVertical: 10 },
  lieuTexte: { fontFamily: Polices.texteGras, fontSize: 14, color: Couleurs.encre },
  titre: { fontFamily: Polices.titreFort, fontSize: 34, lineHeight: 35, letterSpacing: -0.7, color: Couleurs.encre },
  compteur: { fontFamily: Polices.texte, fontSize: 13, color: Couleurs.gris },
  espace: { height: 10 },
  vide: { fontFamily: Polices.texte, fontSize: 15, lineHeight: 22, color: Couleurs.gris },
  pied: { marginVertical: 20 },
  journee: { marginTop: 24, gap: 10 },
  rubrique: { fontFamily: Polices.titre, fontSize: 20, color: Couleurs.encre },
  chargement: { flexDirection: 'row', alignItems: 'center', gap: 10, paddingVertical: 12 },
  explication: { gap: 14, padding: 16, borderRadius: Espaces.rayonCarte, backgroundColor: Couleurs.carte, borderWidth: 1, borderColor: Couleurs.traitCarte },
  explicationTexte: { fontFamily: Polices.texte, fontSize: 15, lineHeight: 22, color: Couleurs.encre },
  bouton: { height: 48, borderRadius: 24, alignItems: 'center', justifyContent: 'center', backgroundColor: Couleurs.encre },
  boutonTexte: { fontFamily: Polices.texteTresGras, fontSize: 15, color: '#FFFFFF' },
});
