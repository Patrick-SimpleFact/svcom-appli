import { useEffect, useRef, useState } from 'react';
import { ActivityIndicator, AppState, FlatList, Linking, Modal, Pressable, RefreshControl, ScrollView, StyleSheet, Text, View } from 'react-native';
import { SafeAreaView } from 'react-native-safe-area-context';
import Svg, { Circle, Path } from 'react-native-svg';

import { type Carte, chargerAutour, compteur, ecartMetres, enFrance, type Onglet, type Point, type Quand, type Recherche, type ReponseAutour, titreAccueil } from '@/autour/autour';
import { coordonnees, usePosition } from '@/autour/position';
import { type Ville, useVilleChoisie } from '@/autour/ville';
import { CarteSansHoraire, CarteSpectacle } from '@/components/carte-spectacle';
import { ChoixVille } from '@/components/choix-ville';
import { OngletsGenres, PucesQuand } from '@/components/filtres-autour';
import { MessagesService } from '@/components/messages-service';
import { Couleurs, Espaces, Polices } from '@/constants/theme';
import { useDemarrage } from '@/demarrage/demarrage';
import { useGouts } from '@/gouts/gouts';

type Liste = { etat: 'chargement' } | { etat: 'erreur' } | { etat: 'prete'; reponse: ReponseAutour; cartes: Carte[] };

/** Au-delà de cette distance depuis le chargement de la liste, on propose d'actualiser (F2.1). */
const DEPLACEMENT_M = 1000;

/** Onglet « Autour de moi » (F2, maquette « Accueil autour de moi »). */
export default function AutourDeMoi() {
  const position = usePosition();
  const { ville, lue, choisir } = useVilleChoisie();
  const [selecteur, setSelecteur] = useState(false);

  const choisirVille = (v: Ville | null) => {
    choisir(v);
    setSelecteur(false);
  };
  const ouvrirSelecteur = () => setSelecteur(true);

  let contenu;
  if (!lue) {
    contenu = null;
  } else if (ville) {
    contenu = <ListeAutour point={{ ville_id: ville.id }} libelle={ville.nom} changerLieu={ouvrirSelecteur} />;
  } else if (position.etat === 'trouvee' && enFrance(position.position.pays)) {
    const { lat, lon, libelle } = position.position;
    contenu = <ListeAutour point={{ lat, lon }} libelle={libelle ?? 'Ma position'} changerLieu={ouvrirSelecteur} actualiser={position.actualiser} />;
  } else if (position.etat === 'trouvee') {
    contenu = <ChoisirUneVille titre={'Choisissez\nune ville'} texte="Spettacoli couvre la France pour l’instant. Choisissez une ville française :" choisir={choisirVille} />;
  } else if (position.etat === 'refusee') {
    contenu = (
      <ChoisirUneVille
        titre={'Où êtes-\nvous ?'}
        texte="Choisissez une ville pour voir les spectacles autour."
        choisir={choisirVille}
        lien={{ texte: 'Activer la localisation', onPress: () => Linking.openSettings() }}
      />
    );
  } else {
    contenu = (
      <View style={[styles.entete, styles.marges]}>
        <Text style={styles.titre} accessibilityRole="header">{titreAccueil(new Date())}</Text>
        <MessagesService />
        {position.etat === 'a_expliquer' ? (
          <Explication texte="Pour vous montrer les spectacles autour de vous, Spettacoli a besoin de votre position. Elle n’est jamais enregistrée." action="Autoriser la localisation" onPress={position.autoriser} />
        ) : (
          <Chargement texte="Recherche de votre position…" />
        )}
      </View>
    );
  }

  return (
    <SafeAreaView style={styles.fond} edges={['top']}>
      {contenu}
      <Modal visible={selecteur} animationType="slide" presentationStyle="pageSheet" onRequestClose={() => setSelecteur(false)}>
        <View style={styles.feuille}>
          <View style={styles.feuilleEntete}>
            <Text style={styles.feuilleTitre}>Où cherchez-vous ?</Text>
            <Pressable onPress={() => setSelecteur(false)} hitSlop={12} accessibilityRole="button">
              <Text style={styles.fermer}>Fermer</Text>
            </Pressable>
          </View>
          <ChoixVille choisir={choisirVille} maPosition={() => choisirVille(null)} />
        </View>
      </Modal>
    </SafeAreaView>
  );
}

/** « Où êtes-vous ? » (localisation refusée) ou hors de France : jamais de blocage, on choisit une ville (F2.1). */
function ChoisirUneVille({ titre, texte, choisir, lien }: { titre: string; texte: string; choisir: (v: Ville) => void; lien?: { texte: string; onPress: () => void } }) {
  return (
    <ScrollView contentContainerStyle={[styles.entete, styles.marges]} keyboardShouldPersistTaps="handled">
      <Text style={styles.titre} accessibilityRole="header">{titre}</Text>
      <MessagesService />
      <Text style={styles.texte}>{texte}</Text>
      <ChoixVille choisir={choisir} />
      {lien ? (
        <Pressable onPress={lien.onPress} accessibilityRole="link">
          <Text style={styles.lien}>{lien.texte}</Text>
        </Pressable>
      ) : null}
    </ScrollView>
  );
}

function ListeAutour({ point, libelle, changerLieu, actualiser }: { point: Point; libelle: string; changerLieu: () => void; actualiser?: () => void }) {
  const { configuration } = useDemarrage();
  const { gouts } = useGouts();
  const [quand, setQuand] = useState<Quand>('ce_soir');
  // « Pour vous » en tête et par défaut quand des goûts sont enregistrés (F2.3, F1.10 point 3).
  const [onglet, setOnglet] = useState<Onglet>(gouts.genres.length ? 'pour_vous' : 'tout');
  const [liste, setListe] = useState<Liste>({ etat: 'chargement' });
  const [rafraichissement, setRafraichissement] = useState(false);
  const [bouge, setBouge] = useState(false);
  const pageEnCours = useRef(false);

  const recherche: Recherche = { point, quand, onglet, rayon_m: gouts.rayon_m, gouts: gouts.genres };
  const cle = JSON.stringify(recherche);

  // Nouvelle recherche à chaque changement de lieu, de date, d'onglet ou de goûts.
  useEffect(() => {
    let actif = true;
    chargerAutour(JSON.parse(cle) as Recherche)
      .then((reponse) => actif && setListe({ etat: 'prete', reponse, cartes: reponse.cartes }))
      .catch(() => actif && setListe({ etat: 'erreur' }));

    return () => {
      actif = false;
    };
  }, [cle]);

  // Retour dans l'app après un déplacement de plus de 1 km : on propose d'actualiser, sans recharger tout seul (F2.1).
  const depart = 'lat' in point ? `${point.lat},${point.lon}` : null;
  useEffect(() => {
    if (!depart || !actualiser) {
      return;
    }
    const [lat, lon] = depart.split(',').map(Number);
    const abonnement = AppState.addEventListener('change', (etat) => {
      if (etat === 'active') {
        coordonnees().then((ici) => ici && ecartMetres({ lat, lon }, ici) > DEPLACEMENT_M && setBouge(true));
      }
    });

    return () => abonnement.remove();
  }, [depart, actualiser]);

  const rafraichir = async () => {
    setRafraichissement(true);
    try {
      const reponse = await chargerAutour(recherche);
      setListe({ etat: 'prete', reponse, cartes: reponse.cartes });
    } catch {
      setListe({ etat: 'erreur' });
    }
    setRafraichissement(false);
  };

  /** Changer de puce ou d'onglet vide la liste le temps du chargement. */
  const changer = <T,>(maj: (v: T) => void) => (v: T) => {
    setListe({ etat: 'chargement' });
    maj(v);
  };

  // Chargement continu : 30 cartes par page (F2.5).
  const pageSuivante = async () => {
    if (liste.etat !== 'prete' || !liste.reponse.suivant || pageEnCours.current) {
      return;
    }
    pageEnCours.current = true;
    try {
      const suite = await chargerAutour({ ...recherche, suivant: liste.reponse.suivant });
      setListe({ etat: 'prete', reponse: { ...liste.reponse, suivant: suite.suivant }, cartes: [...liste.cartes, ...suite.cartes] });
    } catch {
      // Nouvel essai au prochain défilement.
    } finally {
      pageEnCours.current = false;
    }
  };

  const genres = configuration?.genres ?? [];
  const onglets: { onglet: Onglet; libelle: string }[] = [
    ...(gouts.genres.length ? [{ onglet: 'pour_vous' as const, libelle: 'Pour vous' }] : []),
    { onglet: 'tout', libelle: 'Tout' },
    ...genres.map((g) => ({ onglet: g.id, libelle: g.libelle })),
    { onglet: 'jeune_public', libelle: 'Jeune public' },
  ];
  const libelleGenre = (id: number) => genres.find((g) => g.id === id)?.libelle ?? '';
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
          <EnTeteLieu libelle={libelle} onPress={changerLieu} />
          <Text style={styles.titre} accessibilityRole="header">{titreAccueil(new Date(), quand)}</Text>
          <MessagesService />
          {bouge && actualiser ? (
            <Pressable
              style={styles.bouge}
              onPress={() => {
                setBouge(false);
                actualiser();
              }}
              accessibilityRole="button">
              <Text style={styles.bougeTexte}>
                Vous avez bougé · <Text style={styles.bougeAction}>Actualiser</Text>
              </Text>
            </Pressable>
          ) : null}
          <PucesQuand quand={quand} choisir={changer(setQuand)} />
          <OngletsGenres onglets={onglets} actif={onglet} choisir={changer(setOnglet)} />
          {reponse ? <Text style={styles.compteur}>{compteur(reponse.total, reponse.rayon_retenu_m)}</Text> : null}
        </View>
      }
      ListEmptyComponent={
        liste.etat === 'chargement' ? (
          <Chargement texte="Chargement des spectacles…" />
        ) : liste.etat === 'erreur' ? (
          <Explication texte="Impossible de charger les spectacles pour le moment." action="Réessayer" onPress={rafraichir} />
        ) : reponse?.toute_la_journee.length ? null : (
          <Text style={styles.texte}>Aucun spectacle trouvé autour de vous.</Text>
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

/** « Avignon · Centre ⌄ » : toujours cliquable, pour changer de ville ou revenir à « Ma position » (F2.1). */
function EnTeteLieu({ libelle, onPress }: { libelle: string; onPress: () => void }) {
  return (
    <Pressable style={styles.lieu} onPress={onPress} accessibilityRole="button" accessibilityLabel={`${libelle}, changer de lieu`}>
      <Svg width={18} height={18} viewBox="0 0 24 24" fill="none" stroke={Couleurs.encre} strokeWidth={2} strokeLinecap="round" strokeLinejoin="round">
        <Path d="M12 21s-7-6.2-7-11a7 7 0 0 1 14 0c0 4.8-7 11-7 11z" />
        <Circle cx={12} cy={10} r={2.5} />
      </Svg>
      <Text style={styles.lieuTexte}>{libelle}</Text>
      <Svg width={14} height={14} viewBox="0 0 24 24" fill="none" stroke={Couleurs.encre} strokeWidth={2.2} strokeLinecap="round" strokeLinejoin="round">
        <Path d="M6 9l6 6 6-6" />
      </Svg>
    </Pressable>
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
  lieu: { flexDirection: 'row', alignItems: 'center', alignSelf: 'flex-start', gap: 6, paddingVertical: 10 },
  lieuTexte: { fontFamily: Polices.texteGras, fontSize: 14, color: Couleurs.encre },
  titre: { fontFamily: Polices.titreFort, fontSize: 34, lineHeight: 35, letterSpacing: -0.7, color: Couleurs.encre },
  compteur: { fontFamily: Polices.texte, fontSize: 13, color: Couleurs.gris },
  texte: { fontFamily: Polices.texte, fontSize: 15, lineHeight: 22, color: Couleurs.gris },
  lien: { fontFamily: Polices.texteGras, fontSize: 14, color: Couleurs.gris, textDecorationLine: 'underline', paddingVertical: 8 },
  espace: { height: 10 },
  pied: { marginVertical: 20 },
  journee: { marginTop: 24, gap: 10 },
  rubrique: { fontFamily: Polices.titre, fontSize: 20, color: Couleurs.encre },
  chargement: { flexDirection: 'row', alignItems: 'center', gap: 10, paddingVertical: 12 },
  bouge: { paddingVertical: 12, paddingHorizontal: 14, borderRadius: Espaces.rayonCarte, backgroundColor: Couleurs.encre },
  bougeTexte: { fontFamily: Polices.texteMoyen, fontSize: 14, color: '#FFFFFF' },
  bougeAction: { fontFamily: Polices.texteTresGras, textDecorationLine: 'underline' },
  explication: { gap: 14, padding: 16, borderRadius: Espaces.rayonCarte, backgroundColor: Couleurs.carte, borderWidth: 1, borderColor: Couleurs.traitCarte },
  explicationTexte: { fontFamily: Polices.texte, fontSize: 15, lineHeight: 22, color: Couleurs.encre },
  bouton: { height: 48, borderRadius: 24, alignItems: 'center', justifyContent: 'center', backgroundColor: Couleurs.encre },
  boutonTexte: { fontFamily: Polices.texteTresGras, fontSize: 15, color: '#FFFFFF' },
  feuille: { flex: 1, padding: Espaces.bord, gap: 16, backgroundColor: Couleurs.fond },
  feuilleEntete: { flexDirection: 'row', alignItems: 'center', justifyContent: 'space-between' },
  feuilleTitre: { fontFamily: Polices.titre, fontSize: 24, color: Couleurs.encre },
  fermer: { fontFamily: Polices.texteGras, fontSize: 16, color: Couleurs.gris },
});
