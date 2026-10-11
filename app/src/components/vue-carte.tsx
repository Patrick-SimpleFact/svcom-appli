import { useEffect, useRef, useState } from 'react';
import { ActivityIndicator, Pressable, ScrollView, StyleSheet, Text, View } from 'react-native';
import MapView, { Marker } from 'react-native-maps';

import { chargerAutour, chargerReperes, type Carte, type CarteJournee, type Recherche, type Region, type Repere, zoneDeplacee } from '@/autour/autour';
import { CarteSansHoraire, CarteSpectacle, ouvrirFiche } from '@/components/carte-spectacle';
import { Couleurs, Espaces, Polices } from '@/constants/theme';

/** Cartes d'un lieu : recherche à 1 km autour de lui, puis seulement ses propres cartes. */
const RAYON_LIEU_M = 1000;

type Panneau =
  | { repere: Repere; etat: 'chargement' }
  | { repere: Repere; etat: 'erreur' }
  | { repere: Repere; etat: 'pret'; cartes: Carte[]; journee: CarteJournee[] };

/**
 * Vue carte (F2.6) : un repère par lieu avec le nombre de spectacles, mêmes filtres que la liste.
 * Toucher un repère ouvre un panneau avec les cartes du lieu ; déplacer la carte propose « Rechercher dans cette zone ».
 */
export function VueCarte({ region, filtres, libelleGenre }: { region: Region; filtres: Omit<Recherche, 'point' | 'suivant'>; libelleGenre: (id: number) => string }) {
  const [reperes, setReperes] = useState<Repere[]>([]);
  const [chargement, setChargement] = useState(true);
  const [erreur, setErreur] = useState(false);
  const [zoneChargee, setZoneChargee] = useState(region);
  const [deplacee, setDeplacee] = useState(false);
  const [panneau, setPanneau] = useState<Panneau | null>(null);
  const regionActuelle = useRef(region);
  const cle = JSON.stringify({ zoneChargee, quand: filtres.quand, onglet: filtres.onglet, gouts: filtres.gouts });

  // Pas de rechargement pendant le geste : seulement à l'ouverture, au changement de filtre, ou sur « Rechercher dans cette zone ».
  useEffect(() => {
    let actif = true;
    const { zoneChargee: zone, ...f } = JSON.parse(cle) as { zoneChargee: Region } & Pick<Recherche, 'quand' | 'onglet' | 'gouts'>;
    chargerReperes(zone, f)
      .then((r) => {
        if (actif) {
          setReperes(r);
          setErreur(false);
        }
      })
      .catch(() => actif && setErreur(true))
      .finally(() => actif && setChargement(false));

    return () => {
      actif = false;
    };
  }, [cle]);

  const rechercherIci = () => {
    setChargement(true);
    setDeplacee(false);
    setZoneChargee(regionActuelle.current);
  };

  const ouvrir = (repere: Repere) => {
    setPanneau({ repere, etat: 'chargement' });
    chargerAutour({ ...filtres, point: { lat: repere.lat, lon: repere.lon }, rayon_m: RAYON_LIEU_M })
      .then((r) =>
        setPanneau((avant) =>
          avant?.repere.lieu_id === repere.lieu_id
            ? { repere, etat: 'pret', cartes: r.cartes.filter((c) => c.lieu.id === repere.lieu_id), journee: r.toute_la_journee.filter((c) => c.lieu.id === repere.lieu_id) }
            : avant,
        ),
      )
      .catch(() => setPanneau((avant) => (avant?.repere.lieu_id === repere.lieu_id ? { repere, etat: 'erreur' } : avant)));
  };

  return (
    <View style={styles.cadre}>
      <MapView
        style={StyleSheet.absoluteFill}
        initialRegion={region}
        showsUserLocation
        showsPointsOfInterests={false}
        // Sur iPhone, react-native-maps n'envoie la fin d'un déplacement que si le déplacement lui-même est écouté,
        // et ne dit pas s'il vient du doigt : la carte n'est jamais déplacée par l'app, donc tout déplacement vient de l'utilisateur.
        onRegionChange={() => {}}
        onRegionChangeComplete={(r) => {
          regionActuelle.current = r;
          if (zoneDeplacee(zoneChargee, r)) {
            setDeplacee(true);
          }
        }}
        onPress={() => setPanneau(null)}>
        {reperes.map((r) => (
          <Marker
            key={r.lieu_id}
            coordinate={{ latitude: r.lat, longitude: r.lon }}
            onPress={(e) => {
              e.stopPropagation();
              ouvrir(r);
            }}
            accessibilityLabel={`${r.nom}, ${r.nombre} spectacle${r.nombre > 1 ? 's' : ''}`}
            tracksViewChanges={false}>
            <View style={[styles.repere, panneau?.repere.lieu_id === r.lieu_id && styles.repereChoisi]}>
              {r.nombre > 1 ? <Text style={styles.repereTexte}>{r.nombre}</Text> : <View style={styles.reperePoint} />}
            </View>
          </Marker>
        ))}
      </MapView>

      {deplacee ? (
        <Pressable style={styles.zone} onPress={rechercherIci} accessibilityRole="button">
          <Text style={styles.zoneTexte}>Rechercher dans cette zone</Text>
        </Pressable>
      ) : null}
      {chargement ? <ActivityIndicator style={styles.attente} color={Couleurs.rouge} /> : null}
      {erreur && !chargement && !deplacee ? (
        <Pressable style={styles.zone} onPress={rechercherIci} accessibilityRole="button">
          <Text style={styles.zoneTexte}>Impossible de charger la carte · Réessayer</Text>
        </Pressable>
      ) : null}
      {!chargement && !erreur && !deplacee && reperes.length === 0 ? (
        <View style={styles.vide}>
          <Text style={styles.videTexte}>Aucun lieu avec un spectacle dans cette zone.</Text>
        </View>
      ) : null}

      {panneau ? (
        <View style={styles.panneau}>
          <View style={styles.panneauEntete}>
            <Text style={styles.panneauTitre} numberOfLines={1}>{panneau.repere.nom}</Text>
            <Pressable onPress={() => setPanneau(null)} hitSlop={12} accessibilityRole="button">
              <Text style={styles.fermer}>Fermer</Text>
            </Pressable>
          </View>
          {panneau.etat === 'chargement' ? (
            <ActivityIndicator color={Couleurs.rouge} />
          ) : panneau.etat === 'erreur' ? (
            <Text style={styles.videTexte}>Impossible de charger les spectacles de ce lieu.</Text>
          ) : (
            <ScrollView contentContainerStyle={styles.panneauListe}>
              {panneau.cartes.map((c) => (
                <CarteSpectacle
                  key={`${c.spectacle.id}-${c.date_locale}`}
                  carte={c}
                  genre={libelleGenre(c.spectacle.genre_id)}
                  onPress={() => ouvrirFiche(c.spectacle.id, c.seances[0]?.representation_id, { lat: region.latitude, lon: region.longitude })}
                />
              ))}
              {panneau.journee.map((c) => (
                <CarteSansHoraire
                  key={c.representation_id}
                  carte={c}
                  genre={libelleGenre(c.spectacle.genre_id)}
                  onPress={() => ouvrirFiche(c.spectacle.id, c.representation_id, { lat: region.latitude, lon: region.longitude })}
                />
              ))}
            </ScrollView>
          )}
        </View>
      ) : null}
    </View>
  );
}

const styles = StyleSheet.create({
  cadre: { flex: 1, marginHorizontal: -Espaces.bord, overflow: 'hidden' },
  repere: {
    minWidth: 26, height: 26, paddingHorizontal: 6, borderRadius: 13, alignItems: 'center', justifyContent: 'center',
    backgroundColor: Couleurs.rouge, borderWidth: 2, borderColor: '#FFFFFF',
  },
  repereChoisi: { backgroundColor: Couleurs.encre },
  repereTexte: { fontFamily: Polices.texteTresGras, fontSize: 12, color: '#FFFFFF' },
  reperePoint: { width: 8, height: 8, borderRadius: 4, backgroundColor: '#FFFFFF' },
  zone: {
    position: 'absolute', top: 12, alignSelf: 'center', paddingVertical: 10, paddingHorizontal: 16, borderRadius: 20,
    backgroundColor: Couleurs.encre,
  },
  zoneTexte: { fontFamily: Polices.texteGras, fontSize: 14, color: '#FFFFFF' },
  attente: { position: 'absolute', top: 18, alignSelf: 'center' },
  vide: { position: 'absolute', top: 12, left: Espaces.bord, right: Espaces.bord, padding: 12, borderRadius: Espaces.rayonCarte, backgroundColor: Couleurs.carte },
  videTexte: { fontFamily: Polices.texte, fontSize: 14, color: Couleurs.gris, textAlign: 'center' },
  panneau: {
    position: 'absolute', left: 0, right: 0, bottom: 0, height: '78%', paddingTop: 14, paddingHorizontal: Espaces.bord, paddingBottom: 96, gap: 12,
    borderTopLeftRadius: 20, borderTopRightRadius: 20, backgroundColor: Couleurs.fond,
    shadowColor: '#000', shadowOpacity: 0.12, shadowRadius: 12, shadowOffset: { width: 0, height: -2 },
  },
  panneauEntete: { flexDirection: 'row', alignItems: 'center', justifyContent: 'space-between', gap: 12 },
  panneauTitre: { flex: 1, fontFamily: Polices.titre, fontSize: 20, color: Couleurs.encre },
  fermer: { fontFamily: Polices.texteGras, fontSize: 15, color: Couleurs.gris },
  panneauListe: { gap: 10, paddingBottom: 12 },
});
