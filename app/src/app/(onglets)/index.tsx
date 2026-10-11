import * as Network from 'expo-network';
import { useCallback, useEffect, useRef, useState } from 'react';
import { ActivityIndicator, AppState, FlatList, Linking, Modal, Pressable, RefreshControl, ScrollView, StyleSheet, Text, View } from 'react-native';
import { SafeAreaView } from 'react-native-safe-area-context';
import Svg, { Circle, Path } from 'react-native-svg';

import {
  type Attente, type Carte, chargerAutour, compteur, ecartMetres, enFrance, etapeAttente, ilYA, messageVide, type Onglet, type Point, programmeAncien, type Quand, type Recherche, regionAutour, type ReponseAutour, titreAccueil,
} from '@/autour/autour';
import { coordonnees, usePosition } from '@/autour/position';
import { type Ville, useVilleChoisie } from '@/autour/ville';
import { CarteSansHoraire, CarteSpectacle, ouvrirFiche } from '@/components/carte-spectacle';
import { ChoixVille } from '@/components/choix-ville';
import { Bandeau, BandeauCouverture, IlManqueUnSpectacle, MessageVide, Squelettes } from '@/components/etats-liste';
import { OngletsGenres, PucesQuand } from '@/components/filtres-autour';
import { VueCarte } from '@/components/vue-carte';
import { MessagesService } from '@/components/messages-service';
import { Couleurs, Espaces, Polices } from '@/constants/theme';
import { useDemarrage } from '@/demarrage/demarrage';
import { ErreurApi } from '@/api/client';
import { useGouts } from '@/gouts/gouts';

type Liste = { etat: 'chargement' } | { etat: 'erreur' } | { etat: 'prete'; reponse: ReponseAutour; cartes: Carte[] };

/** Au-delà de cette distance depuis le chargement de la liste, on propose d'actualiser (F2.1). */
const DEPLACEMENT_M = 1000;

/** Hors ligne : on retente la liste à ce rythme ; la première réponse du BO prouve que le réseau est revenu (F2.8). */
const ESSAI_HORS_LIGNE_MS = 5000;

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
    const centre = ville.lat !== undefined && ville.lon !== undefined ? { lat: ville.lat, lon: ville.lon } : null;
    contenu = <ListeAutour point={{ ville_id: ville.id }} centre={centre} libelle={ville.nom} changerLieu={ouvrirSelecteur} />;
  } else if (position.etat === 'trouvee' && enFrance(position.position.pays)) {
    const { lat, lon, libelle } = position.position;
    contenu = (
      <ListeAutour
        point={{ lat, lon }}
        centre={{ lat, lon }}
        libelle={libelle ?? 'Ma position'}
        changerLieu={ouvrirSelecteur}
        actualiser={position.actualiser}
        approximative={position.position.approximative}
      />
    );
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

/** Les pistes (F8) arrivent en M14 : les boutons « Proposer une salle » et « Il manque un spectacle ? » y mèneront. */
const proposerUnePiste = () => {};

function ListeAutour(props: {
  point: Point;
  /** Centre de la carte (F2.6) ; inconnu pour une ville choisie avant M06 : pas de vue carte. */
  centre: { lat: number; lon: number } | null;
  libelle: string;
  changerLieu: () => void;
  actualiser?: () => void;
  approximative?: boolean;
}) {
  const { point, centre, libelle, changerLieu, actualiser, approximative } = props;
  const [vue, setVue] = useState<'liste' | 'carte'>('liste');
  const { configuration } = useDemarrage();
  const { gouts } = useGouts();
  const [quand, setQuand] = useState<Quand>('ce_soir');
  // « Pour vous » en tête et par défaut quand des goûts sont enregistrés (F2.3, F1.10 point 3).
  const [onglet, setOnglet] = useState<Onglet>(gouts.genres.length ? 'pour_vous' : 'tout');
  const [liste, setListe] = useState<Liste>({ etat: 'chargement' });
  const [attente, setAttente] = useState<Attente>('squelettes');
  const [rafraichissement, setRafraichissement] = useState(false);
  const [bouge, setBouge] = useState(false);
  const [horsLigne, setHorsLigne] = useState(false);
  // Texte du bandeau hors ligne, calculé au moment où le réseau tombe (« liste mise à jour il y a 2 h »).
  const [miseAJour, setMiseAJour] = useState<string | null>(null);
  const pageEnCours = useRef(false);
  // Chaque chargement a un numéro : une réponse arrivée trop tard (après l'échec à 20 s, ou d'une ancienne recherche) est ignorée.
  const demande = useRef(0);
  const debut = useRef(0);
  const chargeeLe = useRef<number | null>(null);
  const delais = configuration?.parametres.delais_messages_s;

  const recherche: Recherche = { point, quand, onglet, rayon_m: gouts.rayon_m, gouts: gouts.genres };
  const cle = JSON.stringify(recherche);

  /**
   * L'appel n'a pas atteint le BO (pas de réseau, serveur injoignable) : c'est ce qui compte pour l'utilisateur,
   * que le téléphone ait signalé la coupure ou non. Une erreur renvoyée par le BO n'est pas une coupure.
   */
  const signalerSiCoupure = useCallback((erreur: unknown) => {
    if (!(erreur instanceof ErreurApi)) {
      setHorsLigne(true);
      setMiseAJour(chargeeLe.current ? ilYA(chargeeLe.current, Date.now()) : null);
    }
  }, []);

  const recevoir = useCallback((numero: number, envoi: Promise<ReponseAutour>) => {
    envoi
      .then((reponse) => {
        if (numero === demande.current) {
          chargeeLe.current = Date.now();
          setListe({ etat: 'prete', reponse, cartes: reponse.cartes });
          setHorsLigne(false);
        }
      })
      .catch((erreur) => {
        if (numero === demande.current) {
          signalerSiCoupure(erreur);
          setListe({ etat: 'erreur' });
        }
      });
  }, [signalerSiCoupure]);

  // Nouvelle recherche à chaque changement de lieu, de date, d'onglet ou de goûts.
  useEffect(() => {
    debut.current = Date.now();
    recevoir(++demande.current, chargerAutour(JSON.parse(cle) as Recherche));
  }, [cle, recevoir]);

  /** « Réessayer », retour du réseau : on repart des squelettes. */
  const recharger = useCallback(() => {
    debut.current = Date.now();
    setAttente('squelettes');
    setListe({ etat: 'chargement' });
    recevoir(++demande.current, chargerAutour(JSON.parse(cle) as Recherche));
  }, [cle, recevoir]);

  // Attente (F2.8) : squelettes, puis « Chargement en cours… » (3 s), « … plus de temps que d'habitude » (8 s), échec (20 s).
  useEffect(() => {
    if (liste.etat !== 'chargement') {
      return;
    }
    const minuteur = setInterval(() => {
      const etape = etapeAttente((Date.now() - debut.current) / 1000, delais);
      setAttente(etape);
      if (etape === 'echec') {
        demande.current++;
        setListe({ etat: 'erreur' });
      }
    }, 500);

    return () => clearInterval(minuteur);
  }, [liste.etat, delais]);

  // Réseau (F2.8) : hors ligne, la liste reste affichée avec un bandeau ; au retour du réseau, elle se met à jour toute seule.
  const rechargerRef = useRef(recharger);
  useEffect(() => {
    rechargerRef.current = recharger;
  }, [recharger]);
  useEffect(() => {
    let precedent: boolean | null = null;
    const appliquer = (etat: Network.NetworkState) => {
      const coupe = etat.isConnected === false || etat.isInternetReachable === false;
      setHorsLigne(coupe);
      setMiseAJour(coupe && chargeeLe.current ? ilYA(chargeeLe.current, Date.now()) : null);
      if (precedent === true && !coupe) {
        rechargerRef.current();
      }
      precedent = coupe;
    };
    Network.getNetworkStateAsync().then(appliquer).catch(() => {});
    const abonnement = Network.addNetworkStateListener(appliquer);

    return () => abonnement.remove();
  }, []);

  // Le téléphone (et surtout le simulateur) ne signale pas toujours le retour du réseau : tant qu'on se croit
  // hors ligne, on retente la liste ; dès que le BO répond, la liste est à jour et le bandeau disparaît.
  useEffect(() => {
    if (!horsLigne) {
      return;
    }
    const minuteur = setInterval(() => {
      const numero = ++demande.current;
      chargerAutour(JSON.parse(cle) as Recherche)
        .then((reponse) => {
          if (numero === demande.current) {
            chargeeLe.current = Date.now();
            setListe({ etat: 'prete', reponse, cartes: reponse.cartes });
            setHorsLigne(false);
          }
        })
        .catch(() => {});
    }, ESSAI_HORS_LIGNE_MS);

    return () => clearInterval(minuteur);
  }, [horsLigne, cle]);

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
    const numero = ++demande.current;
    try {
      const reponse = await chargerAutour(recherche);
      if (numero === demande.current) {
        chargeeLe.current = Date.now();
        setListe({ etat: 'prete', reponse, cartes: reponse.cartes });
        setHorsLigne(false);
      }
    } catch (erreur) {
      // La liste affichée reste en place ; le bandeau « Hors ligne » dit pourquoi.
      signalerSiCoupure(erreur);
    }
    setRafraichissement(false);
  };

  /** Changer de puce ou d'onglet repart des squelettes. */
  const changer = <T,>(maj: (v: T) => void) => (v: T) => {
    setAttente('squelettes');
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
  const ancien = reponse ? programmeAncien(reponse.programme_mis_a_jour_le, new Date()) : null;
  const vide = reponse ? messageVide(reponse, quand, typeof onglet === 'number' ? libelleGenre(onglet) : null) : null;

  let etatVide;
  if (liste.etat === 'chargement') {
    etatVide = (
      <View style={styles.attente}>
        <Squelettes />
        {attente === 'lent' ? <Text style={styles.compteur}>Chargement en cours…</Text> : null}
        {attente === 'tres_lent' ? (
          <Bandeau texte="Le chargement prend plus de temps que d’habitude. Votre connexion est peut-être faible." action="Réessayer" onPress={recharger} />
        ) : null}
      </View>
    );
  } else if (liste.etat === 'erreur') {
    etatVide = horsLigne ? (
      <MessageVide texte="Pas de connexion internet." action="Réessayer" onPress={recharger} />
    ) : (
      <MessageVide texte="Impossible de charger les spectacles pour le moment." action="Réessayer" onPress={recharger} />
    );
  } else if (vide && !reponse?.toute_la_journee.length) {
    etatVide =
      vide.cas === 'genre' ? (
        <MessageVide texte={vide.texte} action={vide.action} onPress={changer(setOnglet).bind(null, 'tout')} />
      ) : vide.cas === 'date' ? (
        <MessageVide texte={vide.texte} action={vide.action} onPress={changer(setQuand).bind(null, vide.quand)} />
      ) : (
        <MessageVide texte={vide.texte} action="Choisir une autre ville" onPress={changerLieu} />
      );
  }

  const entete = (
    <View style={styles.entete}>
      <EnTeteLieu libelle={libelle} onPress={changerLieu} />
      <Text style={styles.titre} accessibilityRole="header">{titreAccueil(new Date(), quand)}</Text>
      <MessagesService />
      {horsLigne && liste.etat === 'prete' && miseAJour ? <Bandeau texte={`Hors ligne · liste mise à jour ${miseAJour}`} /> : null}
      {approximative && actualiser ? <Bandeau texte="Position approximative" action="Actualiser" onPress={actualiser} /> : null}
      {bouge && actualiser ? (
        <Bandeau
          texte="Vous avez bougé"
          action="Actualiser"
          onPress={() => {
            setBouge(false);
            actualiser();
          }}
        />
      ) : null}
      {ancien ? <Bandeau texte={ancien} /> : null}
      <PucesQuand quand={quand} choisir={changer(setQuand)} />
      <OngletsGenres onglets={onglets} actif={onglet} choisir={changer(setOnglet)} />
      {reponse?.couverture_faible ? <BandeauCouverture proposer={proposerUnePiste} /> : null}
      <View style={styles.ligneCompteur}>
        <Text style={styles.compteur}>{reponse && reponse.total > 0 ? compteur(reponse.total, reponse.rayon_retenu_m) : ''}</Text>
        {centre ? (
          <Pressable style={styles.bascule} onPress={() => setVue(vue === 'liste' ? 'carte' : 'liste')} accessibilityRole="button" hitSlop={8}>
            <IconeVue vue={vue} />
            <Text style={styles.basculeTexte}>{vue === 'liste' ? 'Carte' : 'Liste'}</Text>
          </Pressable>
        ) : null}
      </View>
    </View>
  );

  if (vue === 'carte' && centre) {
    return (
      <View style={styles.carteVue}>
        {entete}
        <VueCarte
          region={regionAutour(centre, reponse?.rayon_retenu_m ?? 5000)}
          filtres={{ quand, onglet, rayon_m: gouts.rayon_m, gouts: gouts.genres }}
          libelleGenre={libelleGenre}
        />
      </View>
    );
  }

  return (
    <FlatList
      data={liste.etat === 'prete' ? liste.cartes : []}
      keyExtractor={(c) => `${c.spectacle.id}-${c.lieu.id}-${c.date_locale}`}
      renderItem={({ item }) => (
        <CarteSpectacle carte={item} genre={libelleGenre(item.spectacle.genre_id)} onPress={() => ouvrirFiche(item.spectacle.id, item.seances[0]?.representation_id, centre)} />
      )}
      contentContainerStyle={styles.contenu}
      ItemSeparatorComponent={Espace}
      onEndReached={pageSuivante}
      onEndReachedThreshold={0.5}
      refreshControl={<RefreshControl refreshing={rafraichissement} onRefresh={rafraichir} tintColor={Couleurs.rouge} />}
      ListHeaderComponent={entete}
      ListEmptyComponent={etatVide}
      ListFooterComponent={
        reponse?.suivant ? (
          <ActivityIndicator color={Couleurs.rouge} style={styles.pied} />
        ) : (
          <>
            {reponse?.toute_la_journee.length ? (
              <View style={styles.journee}>
                <Text style={styles.rubrique}>Toute la journée</Text>
                {reponse.toute_la_journee.map((c) => (
                  <CarteSansHoraire key={c.representation_id} carte={c} genre={libelleGenre(c.spectacle.genre_id)} onPress={() => ouvrirFiche(c.spectacle.id, c.representation_id, centre)} />
                ))}
              </View>
            ) : null}
            {reponse ? <IlManqueUnSpectacle onPress={proposerUnePiste} /> : null}
          </>
        )
      }
    />
  );
}

/** Icône du bouton de bascule (maquette : « Carte » avec une carte pliée). */
function IconeVue({ vue }: { vue: 'liste' | 'carte' }) {
  return (
    <Svg width={16} height={16} viewBox="0 0 24 24" fill="none" stroke={Couleurs.encre} strokeWidth={2} strokeLinecap="round" strokeLinejoin="round">
      <Path d={vue === 'liste' ? 'M9 4L3 6v14l6-2 6 2 6-2V4l-6 2zM9 4v14M15 6v14' : 'M8 6h13M8 12h13M8 18h13M3 6h.01M3 12h.01M3 18h.01'} />
    </Svg>
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
  ligneCompteur: { flexDirection: 'row', alignItems: 'center', justifyContent: 'space-between', minHeight: 20 },
  bascule: { flexDirection: 'row', alignItems: 'center', gap: 6 },
  basculeTexte: { fontFamily: Polices.texteGras, fontSize: 13, color: Couleurs.encre },
  carteVue: { flex: 1, paddingHorizontal: Espaces.bord },
  texte: { fontFamily: Polices.texte, fontSize: 15, lineHeight: 22, color: Couleurs.gris },
  lien: { fontFamily: Polices.texteGras, fontSize: 14, color: Couleurs.gris, textDecorationLine: 'underline', paddingVertical: 8 },
  espace: { height: 10 },
  pied: { marginVertical: 20 },
  journee: { marginTop: 24, gap: 10 },
  rubrique: { fontFamily: Polices.titre, fontSize: 20, color: Couleurs.encre },
  chargement: { flexDirection: 'row', alignItems: 'center', gap: 10, paddingVertical: 12 },
  attente: { gap: 12 },
  explication: { gap: 14, padding: 16, borderRadius: Espaces.rayonCarte, backgroundColor: Couleurs.carte, borderWidth: 1, borderColor: Couleurs.traitCarte },
  explicationTexte: { fontFamily: Polices.texte, fontSize: 15, lineHeight: 22, color: Couleurs.encre },
  bouton: { height: 48, borderRadius: 24, alignItems: 'center', justifyContent: 'center', backgroundColor: Couleurs.encre },
  boutonTexte: { fontFamily: Polices.texteTresGras, fontSize: 15, color: '#FFFFFF' },
  feuille: { flex: 1, padding: Espaces.bord, gap: 16, backgroundColor: Couleurs.fond },
  feuilleEntete: { flexDirection: 'row', alignItems: 'center', justifyContent: 'space-between' },
  feuilleTitre: { fontFamily: Polices.titre, fontSize: 24, color: Couleurs.encre },
  fermer: { fontFamily: Polices.texteGras, fontSize: 16, color: Couleurs.gris },
});
