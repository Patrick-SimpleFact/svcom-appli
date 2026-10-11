import { router } from 'expo-router';
import { Pressable, StyleSheet, Text, View } from 'react-native';

import { type Badge, type Carte, type CarteJournee, distance, estComplete, mentionJournee, talon } from '@/autour/autour';
import { Couleurs, Espaces, Polices } from '@/constants/theme';

/** Ouvre la fiche (F5.1) sur la séance de la carte touchée ; la position sert seulement à la distance. */
export function ouvrirFiche(spectacleId: number, representationId: number | undefined, position: { lat: number; lon: number } | null, origine = 'liste_ce_soir') {
  router.push({
    pathname: '/spectacle/[id]',
    params: { id: String(spectacleId), origine, ...(representationId ? { representation: String(representationId) } : {}), ...(position ? { lat: String(position.lat), lon: String(position.lon) } : {}) },
  });
}

const LIBELLES_BADGES: Partial<Record<Badge, string>> = { gratuit: 'Gratuit', derniere_places: 'Dernières places', horaire_a_confirmer: 'Horaire à confirmer' };

/** Carte d'un spectacle à horaire (maquette « Accueil autour de moi ») : talon horaire à gauche, détails à droite. */
export function CarteSpectacle({ carte, genre, onPress }: { carte: Carte; genre: string; onPress?: () => void }) {
  const { heure, suite } = talon(carte);
  const complete = estComplete(carte);

  return (
    <Pressable
      style={({ pressed }) => [styles.carte, complete && styles.complete, pressed && styles.appuyee]}
      onPress={onPress}
      accessibilityRole="button"
      accessibilityLabel={`${heure}, ${carte.spectacle.titre}, ${carte.lieu.nom}${complete ? ', complet' : ''}`}>
      <View style={styles.talon}>
        <Text style={styles.heure}>{heure}</Text>
        {suite ? <Text style={styles.suite}>{suite}</Text> : null}
      </View>
      <Details spectacle={carte.spectacle} genre={genre} lieu={carte.lieu} distanceM={carte.distance_m} badges={carte.badges} complete={complete} />
      <Pointilles />
      <Encoches />
    </Pressable>
  );
}

/** Carte du bloc « Toute la journée » : la mention (« Horaire à confirmer auprès du lieu »…) est toujours affichée (F2.2). */
export function CarteSansHoraire({ carte, genre, onPress }: { carte: CarteJournee; genre: string; onPress?: () => void }) {
  return (
    <Pressable
      style={({ pressed }) => [styles.carte, pressed && styles.appuyee]}
      onPress={onPress}
      accessibilityRole="button"
      accessibilityLabel={`${carte.spectacle.titre}, ${carte.lieu.nom}, ${mentionJournee(carte)}`}>
      <Details
        spectacle={carte.spectacle}
        genre={genre}
        lieu={carte.lieu}
        distanceM={carte.distance_m}
        badges={carte.badges.filter((b) => b !== 'horaire_a_confirmer')}
        mention={mentionJournee(carte)}
      />
    </Pressable>
  );
}

function Details(props: {
  spectacle: Carte['spectacle'];
  genre: string;
  lieu: Carte['lieu'];
  distanceM: number;
  badges: Badge[];
  complete?: boolean;
  mention?: string;
}) {
  const badges = props.badges.map((b) => LIBELLES_BADGES[b]).filter((b): b is string => !!b);

  return (
    <View style={styles.details}>
      <Text style={styles.genre} numberOfLines={1}>{props.spectacle.classification || props.genre}</Text>
      <Text style={styles.titre} numberOfLines={2}>{props.spectacle.titre}</Text>
      <Text style={styles.lieu} numberOfLines={1}>
        {props.lieu.nom} · {distance(props.distanceM, props.lieu.position_approximative)}
      </Text>
      {props.mention ? <Text style={styles.mention}>{props.mention}</Text> : null}
      {props.complete || badges.length ? (
        <View style={styles.badges}>
          {props.complete ? <Text style={[styles.badge, styles.badgeComplet]}>Complet</Text> : null}
          {badges.map((b) => (
            <Text key={b} style={styles.badge}>{b}</Text>
          ))}
        </View>
      ) : null}
    </View>
  );
}

/**
 * Ligne pointillée du talon : React Native ne sait pas tracer un seul côté en pointillés,
 * on trace donc un cadre pointillé dont seul le bord gauche dépasse de son conteneur.
 */
function Pointilles() {
  return (
    <View style={styles.pointilles} pointerEvents="none">
      <View style={styles.cadrePointille} />
    </View>
  );
}

/** Les deux demi-cercles du talon, façon billet de spectacle. */
function Encoches() {
  return (
    <>
      <View style={[styles.encoche, styles.encocheHaut]} />
      <View style={[styles.encoche, styles.encocheBas]} />
    </>
  );
}

const LARGEUR_TALON = 76;

const styles = StyleSheet.create({
  carte: {
    flexDirection: 'row', backgroundColor: Couleurs.carte, borderWidth: 1, borderColor: Couleurs.traitCarte,
    borderRadius: Espaces.rayonCarte, overflow: 'hidden',
  },
  complete: { opacity: 0.55 },
  appuyee: { opacity: 0.8 },
  talon: { width: LARGEUR_TALON, alignItems: 'center', justifyContent: 'center', gap: 2 },
  pointilles: { position: 'absolute', left: LARGEUR_TALON - 0.75, top: 0, bottom: 0, width: 1.5, overflow: 'hidden' },
  cadrePointille: { position: 'absolute', left: 0, top: -2, bottom: -2, width: 12, borderWidth: 1.5, borderStyle: 'dashed', borderColor: '#DDD8D1' },
  heure: { fontFamily: Polices.titre, fontSize: 21, letterSpacing: -0.2, color: Couleurs.encre },
  suite: { fontFamily: Polices.texte, fontSize: 11, color: Couleurs.gris, textAlign: 'center', paddingHorizontal: 4 },
  details: { flex: 1, minWidth: 0, paddingVertical: 11, paddingHorizontal: 14, gap: 3 },
  genre: { fontFamily: Polices.texteTresGras, fontSize: 11, letterSpacing: 0.9, textTransform: 'uppercase', color: Couleurs.rouge },
  titre: { fontFamily: Polices.texteGras, fontSize: 16, lineHeight: 20, color: Couleurs.encre },
  lieu: { fontFamily: Polices.texte, fontSize: 13, color: Couleurs.gris },
  mention: { fontFamily: Polices.texteMoyen, fontSize: 12, color: Couleurs.encre },
  badges: { flexDirection: 'row', flexWrap: 'wrap', gap: 6, marginTop: 4 },
  badge: {
    fontFamily: Polices.texteGras, fontSize: 11, color: Couleurs.encre, overflow: 'hidden',
    paddingVertical: 2, paddingHorizontal: 8, borderRadius: 999, backgroundColor: Couleurs.fond,
  },
  badgeComplet: { color: '#FFFFFF', backgroundColor: Couleurs.encre },
  encoche: {
    position: 'absolute', left: LARGEUR_TALON - 7, width: 14, height: 14, borderRadius: 7,
    backgroundColor: Couleurs.fond, borderWidth: 1, borderColor: Couleurs.traitCarte,
  },
  encocheHaut: { top: -8 },
  encocheBas: { bottom: -8 },
});
