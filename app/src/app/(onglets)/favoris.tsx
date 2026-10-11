import { router } from 'expo-router';
import { useEffect, useState } from 'react';
import { ActionSheetIOS, ActivityIndicator, Alert, Modal, Platform, Pressable, RefreshControl, ScrollView, StyleSheet, Text, View } from 'react-native';
import { SafeAreaView, useSafeAreaInsets } from 'react-native-safe-area-context';
import Svg, { Path } from 'react-native-svg';

import { heure } from '@/autour/autour';
import { useCompte } from '@/compte/compte';
import { lirePreferences, modifierPreferences } from '@/compte/profil';
import { ouvrirFiche } from '@/components/carte-spectacle';
import { ChoixVille } from '@/components/choix-ville';
import { MessagesService } from '@/components/messages-service';
import { Couleurs, Espaces, Polices } from '@/constants/theme';
import { type Favori, type Nouveaute, type Suivi, useFavoris } from '@/favoris/favoris';
import { jourTalon } from '@/recherche/recherche';

/** Rayons proposés pour la zone des alertes (F3.1 : de 10 à 100 km, 30 km par défaut). */
const RAYONS_ALERTES = [10, 20, 30, 50, 100];

/** Onglet « Favoris » (F3, maquette « Favoris ») : spectacles mis de côté, lieux et artistes suivis, nouveautés. */
export default function Favoris() {
  const { utilisateur } = useCompte();
  const { favoris, suivis, nouveautes, recharger } = useFavoris();
  const [onglet, setOnglet] = useState<'spectacles' | 'suivis'>('spectacles');
  const [passes, setPasses] = useState(false);
  const [rafraichissement, setRafraichissement] = useState(false);

  if (!utilisateur) {
    return (
      <SafeAreaView style={styles.fond} edges={['top']}>
        <ScrollView contentContainerStyle={styles.contenu}>
          <Text style={styles.titre} accessibilityRole="header">Favoris</Text>
          <MessagesService />
          <View style={styles.invite}>
            <View style={styles.inviteIcone}>
              <Svg width={26} height={26} viewBox="0 0 24 24" fill="none" stroke={Couleurs.rouge} strokeWidth={2} strokeLinecap="round" strokeLinejoin="round">
                <Path d="M12 20s-7-4.4-7-10a4 4 0 0 1 7-2.6A4 4 0 0 1 19 10c0 5.6-7 10-7 10z" />
              </Svg>
            </View>
            <Text style={styles.inviteTitre}>Gardez vos spectacles préférés</Text>
            <Text style={styles.inviteTexte}>Avec un compte, retrouvez vos favoris sur tous vos appareils, suivez vos lieux et artistes, et recevez un rappel le jour du spectacle.</Text>
            <Pressable style={styles.inviteBouton} onPress={() => router.push({ pathname: '/connexion', params: { raison: 'favori' } })} accessibilityRole="button">
              <Text style={styles.inviteBoutonTexte}>Créer un compte ou se connecter</Text>
            </Pressable>
          </View>
        </ScrollView>
      </SafeAreaView>
    );
  }

  const rafraichir = async () => {
    setRafraichissement(true);
    await recharger().catch(() => {});
    setRafraichissement(false);
  };

  const nonVues = nouveautes.filter((n) => !n.vue);
  const aucunFavori = !favoris.ce_soir.length && !favoris.a_venir.length && !favoris.passes.length;

  return (
    <SafeAreaView style={styles.fond} edges={['top']}>
      <ScrollView contentContainerStyle={styles.contenu} refreshControl={<RefreshControl refreshing={rafraichissement} onRefresh={rafraichir} tintColor={Couleurs.rouge} />}>
        <Text style={styles.titre} accessibilityRole="header">Favoris</Text>
        <MessagesService />

        <View style={styles.segments} accessibilityRole="tablist">
          <Segment libelle="Spectacles" actif={onglet === 'spectacles'} onPress={() => setOnglet('spectacles')} />
          <Segment libelle={`Lieux et artistes${suivis.length ? ` · ${suivis.length}` : ''}`} actif={onglet === 'suivis'} onPress={() => setOnglet('suivis')} />
        </View>

        {onglet === 'spectacles' ? (
          <>
            {nonVues.length ? <Nouveautes liste={nonVues} /> : null}
            {aucunFavori ? (
              <Text style={styles.vide}>Touchez le cœur d’une fiche pour retrouver ici les spectacles qui vous tentent.</Text>
            ) : null}
            {favoris.ce_soir.length ? (
              <Groupe titre="Ce soir">
                {favoris.ce_soir.map((f) => (
                  <CarteFavori key={f.id} favori={f} ceSoir />
                ))}
              </Groupe>
            ) : null}
            {favoris.a_venir.length ? (
              <Groupe titre="À venir">
                {favoris.a_venir.map((f) => (
                  <CarteFavori key={f.id} favori={f} />
                ))}
              </Groupe>
            ) : null}
            {favoris.passes.length ? (
              passes ? (
                <Groupe titre="Passés">
                  {favoris.passes.map((f) => (
                    <CarteFavori key={f.id} favori={f} passe />
                  ))}
                </Groupe>
              ) : (
                <Pressable onPress={() => setPasses(true)} accessibilityRole="button">
                  <Text style={styles.lien}>Voir les spectacles passés ({favoris.passes.length})</Text>
                </Pressable>
              )
            ) : null}
            {!aucunFavori ? <Text style={styles.aide}>Appui long sur un spectacle pour le retirer de vos favoris.</Text> : null}
          </>
        ) : (
          <Suivis suivis={suivis} />
        )}
      </ScrollView>
    </SafeAreaView>
  );
}

function Segment({ libelle, actif, onPress }: { libelle: string; actif: boolean; onPress: () => void }) {
  return (
    <Pressable style={[styles.segment, actif && styles.segmentActif]} onPress={onPress} accessibilityRole="tab" accessibilityState={{ selected: actif }}>
      <Text style={[styles.segmentTexte, actif && styles.segmentTexteActif]}>{libelle}</Text>
    </Pressable>
  );
}

function Groupe({ titre, children }: { titre: string; children: React.ReactNode }) {
  return (
    <View style={styles.groupe}>
      <Text style={styles.rubrique}>{titre}</Text>
      {children}
    </View>
  );
}

/** Choisir une action dans une feuille du système (iPhone), ou confirmer (Android). */
function proposer(titre: string, action: string, faire: () => void) {
  if (Platform.OS === 'ios') {
    ActionSheetIOS.showActionSheetWithOptions({ title: titre, options: [action, 'Annuler'], destructiveButtonIndex: 0, cancelButtonIndex: 1 }, (i) => i === 0 && faire());
  } else {
    Alert.alert(titre, undefined, [{ text: 'Annuler', style: 'cancel' }, { text: action, style: 'destructive', onPress: faire }]);
  }
}

/** Un favori : talon (jour et heure), titre, lieu ; « Ce soir » encadré en rouge (maquette). */
function CarteFavori({ favori, ceSoir, passe }: { favori: Favori; ceSoir?: boolean; passe?: boolean }) {
  const { retirer } = useFavoris();
  const r = favori.representation;
  const lieu = [r?.lieu.nom, r?.lieu.ville].filter(Boolean).join(', ');

  return (
    <Pressable
      style={({ pressed }) => [styles.carte, ceSoir && styles.carteCeSoir, passe && styles.cartePassee, pressed && styles.appuyee]}
      onPress={() => ouvrirFiche(favori.spectacle.id, r?.id, null, 'favori')}
      onLongPress={() => proposer(favori.spectacle.titre, 'Retirer des favoris', () => retirer(favori.id).catch(() => {}))}
      accessibilityRole="button"
      accessibilityActions={[{ name: 'retirer', label: 'Retirer des favoris' }]}
      onAccessibilityAction={(e) => e.nativeEvent.actionName === 'retirer' && retirer(favori.id).catch(() => {})}>
      <View style={styles.talon}>
        {r && !ceSoir ? <Text style={styles.talonJour}>{jourTalon(r.date_locale)}</Text> : null}
        <Text style={styles.talonHeure}>{r?.debut ? heure(r.debut) : '—'}</Text>
      </View>
      <View style={styles.details}>
        <Text style={styles.carteTitre} numberOfLines={2}>{favori.spectacle.titre}</Text>
        {lieu ? <Text style={styles.carteLieu} numberOfLines={1}>{lieu}</Text> : null}
        {r?.complet ? <Text style={styles.badge}>Complet</Text> : null}
        {!r ? <Text style={styles.carteLieu}>Plus aucune date à venir</Text> : null}
      </View>
    </Pressable>
  );
}

/** « Nouveautés de vos suivis » (F3.4, maquette) : toucher une nouveauté ouvre la fiche et la marque comme vue. */
function Nouveautes({ liste }: { liste: Nouveaute[] }) {
  const { marquerVues } = useFavoris();
  const intro = (n: Nouveaute) =>
    n.type === 'nouveau_spectacle_lieu' ? `${n.representation?.lieu.nom ?? 'Un lieu suivi'} annonce` : n.type === 'nouvelle_date_artiste' ? 'Nouvelle date' : 'Ce soir';

  return (
    <View style={styles.nouveautes}>
      <View style={styles.nouveautesEntete}>
        <Text style={styles.nouveautesTitre}>Nouveautés de vos suivis · {liste.length}</Text>
        <Pressable onPress={() => marquerVues().catch(() => {})} accessibilityRole="button">
          <Text style={styles.nouveautesTout}>Tout marquer comme vu</Text>
        </Pressable>
      </View>
      {liste.slice(0, 5).map((n) => (
        <Pressable
          key={n.id}
          style={styles.nouveaute}
          onPress={() => {
            marquerVues([n.id]).catch(() => {});
            if (n.spectacle) {
              ouvrirFiche(n.spectacle.id, n.representation?.id, null, 'favori');
            }
          }}
          accessibilityRole="button">
          <Text style={styles.nouveauteIntro}>{intro(n)}</Text>
          <Text style={styles.nouveauteTitre}>
            {n.spectacle?.titre ?? ''}
            {n.representation ? ` · ${jourTalon(n.representation.date_locale)}` : ''}
          </Text>
        </Pressable>
      ))}
    </View>
  );
}

/** Lieux et artistes suivis (F3.1) : nouveautés non vues en pastille ; appui long pour ne plus suivre. */
function Suivis({ suivis }: { suivis: Suivi[] }) {
  const { nePlusSuivre } = useFavoris();
  const lieux = suivis.filter((s) => s.type === 'lieu');
  const artistes = suivis.filter((s) => s.type === 'artiste');

  const ligne = (s: Suivi, i: number) => (
    <Pressable
      key={s.id}
      style={[styles.suivi, i > 0 && styles.separe]}
      onPress={() => router.push({ pathname: s.type === 'lieu' ? '/lieu/[id]' : '/artiste/[id]', params: { id: String(s.cible.id) } })}
      onLongPress={() => proposer(s.cible.nom ?? '', 'Ne plus suivre', () => nePlusSuivre(s.id).catch(() => {}))}
      accessibilityRole="button"
      accessibilityActions={[{ name: 'arreter', label: 'Ne plus suivre' }]}
      onAccessibilityAction={(e) => e.nativeEvent.actionName === 'arreter' && nePlusSuivre(s.id).catch(() => {})}>
      <View style={[styles.marque, s.type === 'lieu' ? styles.marqueLieu : styles.marqueArtiste]}>
        <Text style={[styles.marqueTexte, { color: s.type === 'lieu' ? '#7A3E00' : '#1E3A8A' }]}>{s.type === 'lieu' ? 'Li' : 'Ar'}</Text>
      </View>
      <View style={styles.suiviTextes}>
        <Text style={styles.suiviNom} numberOfLines={1}>{s.cible.nom ?? ''}</Text>
        {s.cible.ville ? <Text style={styles.carteLieu}>{s.cible.ville}</Text> : null}
      </View>
      {s.nouveautes ? <Text style={styles.nouveau}>{s.nouveautes} nouveau{s.nouveautes > 1 ? 'x' : ''}</Text> : null}
    </Pressable>
  );

  return (
    <>
      <ZoneAlertes />
      {!suivis.length ? (
        <Text style={styles.vide}>Suivez un lieu ou un artiste depuis sa page pour être prévenu de ses nouveaux spectacles.</Text>
      ) : null}
      {lieux.length ? (
        <Groupe titre="Lieux suivis">
          <View style={styles.bloc}>{lieux.map(ligne)}</View>
        </Groupe>
      ) : null}
      {artistes.length ? (
        <Groupe titre="Artistes suivis">
          <View style={styles.bloc}>{artistes.map(ligne)}</View>
        </Groupe>
      ) : null}
      {suivis.length ? <Text style={styles.aide}>Appui long pour ne plus suivre.</Text> : null}
    </>
  );
}

/**
 * Zone des alertes (F3.1) : une ville et un rayon de 10 à 100 km (30 km par défaut).
 * Le BO garde la commune, jamais la position (F2.11).
 */
function ZoneAlertes() {
  const marges = useSafeAreaInsets();
  const [zone, setZone] = useState<{ ville: { id: number; nom: string } | null; rayon_km: number } | null>(null);
  const [ouverte, setOuverte] = useState(false);
  const [envoi, setEnvoi] = useState(false);

  useEffect(() => {
    lirePreferences()
      .then((p) => setZone(p.zone_alertes))
      .catch(() => {});
  }, []);

  const enregistrer = async (ville: { id: number; nom: string } | null, rayon: number) => {
    setEnvoi(true);
    try {
      const p = await modifierPreferences({ zone_alertes: { ville_id: ville?.id ?? null, rayon_km: rayon } });
      setZone(p.zone_alertes);
    } catch {
      // La zone affichée reste l'ancienne.
    }
    setEnvoi(false);
  };

  return (
    <>
      <Pressable style={styles.zone} onPress={() => setOuverte(true)} accessibilityRole="button">
        <View style={styles.suiviTextes}>
          <Text style={styles.suiviNom}>Zone des alertes</Text>
          <Text style={styles.carteLieu}>{zone ? `${zone.ville?.nom ?? 'Autour de votre position habituelle'} · ${zone.rayon_km} km` : '…'}</Text>
        </View>
        <Svg width={16} height={16} viewBox="0 0 24 24" fill="none" stroke={Couleurs.gris} strokeWidth={2} strokeLinecap="round" strokeLinejoin="round">
          <Path d="M9 6l6 6-6 6" />
        </Svg>
      </Pressable>
      <Modal visible={ouverte} animationType="slide" presentationStyle="pageSheet" onRequestClose={() => setOuverte(false)}>
        <ScrollView style={styles.fondFeuille} contentContainerStyle={[styles.feuille, { paddingBottom: 24 + marges.bottom }]} keyboardShouldPersistTaps="handled">
          <View style={styles.feuilleEntete}>
            <Text style={styles.feuilleTitre}>Zone des alertes</Text>
            <Pressable onPress={() => setOuverte(false)} hitSlop={12} accessibilityRole="button">
              <Text style={styles.fermer}>Fermer</Text>
            </Pressable>
          </View>
          <Text style={styles.aide}>Les nouvelles dates des artistes suivis vous sont signalées dans cette zone.</Text>
          <View style={styles.zoneActuelle} accessibilityLiveRegion="polite">
            <Text style={styles.zoneActuelleTexte}>
              Zone actuelle : {zone ? `${zone.ville?.nom ?? 'autour de votre position habituelle'} · ${zone.rayon_km} km` : '…'}
            </Text>
          </View>
          <Text style={styles.rubrique}>Rayon</Text>
          <View style={styles.puces}>
            {RAYONS_ALERTES.map((r) => (
              <Pressable key={r} style={[styles.puce, zone?.rayon_km === r && styles.puceActive]} onPress={() => enregistrer(zone?.ville ?? null, r)} accessibilityRole="button">
                <Text style={[styles.puceTexte, zone?.rayon_km === r && styles.puceTexteActive]}>{r} km</Text>
              </Pressable>
            ))}
          </View>
          <Text style={styles.rubrique}>Autour de</Text>
          {envoi ? <ActivityIndicator color={Couleurs.rouge} style={styles.gauche} /> : null}
          {/* Une ville choisie est enregistrée, puis la feuille se referme : la carte « Zone des alertes » l'affiche. */}
          <ChoixVille
            choisir={async (v) => {
              await enregistrer({ id: v.id, nom: v.nom }, zone?.rayon_km ?? 30);
              setOuverte(false);
            }}
          />
        </ScrollView>
      </Modal>
    </>
  );
}

// Valeurs de la maquette « Favoris ».
const styles = StyleSheet.create({
  fond: { flex: 1, backgroundColor: Couleurs.fond },
  contenu: { gap: 18, paddingTop: 12, paddingHorizontal: Espaces.bord, paddingBottom: 120 },
  titre: { fontFamily: Polices.titreFort, fontSize: 30, lineHeight: 32, letterSpacing: -0.6, color: Couleurs.encre },
  invite: { alignItems: 'center', gap: 12, paddingVertical: 22, paddingHorizontal: 18, borderRadius: 18, borderWidth: 1, borderColor: Couleurs.traitCarte, backgroundColor: Couleurs.carte },
  inviteIcone: { width: 56, height: 56, borderRadius: 28, alignItems: 'center', justifyContent: 'center', backgroundColor: '#FBE1DC' },
  inviteTitre: { fontFamily: Polices.titre, fontSize: 20, color: Couleurs.encre, textAlign: 'center' },
  inviteTexte: { fontFamily: Polices.texte, fontSize: 14, lineHeight: 21, color: '#4A4A55', textAlign: 'center' },
  inviteBouton: { alignSelf: 'stretch', height: 48, borderRadius: 24, alignItems: 'center', justifyContent: 'center', backgroundColor: Couleurs.encre },
  inviteBoutonTexte: { fontFamily: Polices.texteTresGras, fontSize: 15, color: '#FFFFFF' },
  segments: { flexDirection: 'row', padding: 4, borderRadius: Espaces.rayonCarte, backgroundColor: '#ECE8E2' },
  segment: { flex: 1, height: 40, borderRadius: 10, alignItems: 'center', justifyContent: 'center' },
  segmentActif: { backgroundColor: Couleurs.carte, shadowColor: '#000', shadowOpacity: 0.08, shadowRadius: 2, shadowOffset: { width: 0, height: 1 } },
  segmentTexte: { fontFamily: Polices.texteMoyen, fontSize: 14, color: '#4A4A55' },
  segmentTexteActif: { fontFamily: Polices.texteTresGras, color: Couleurs.encre },
  groupe: { gap: 10 },
  rubrique: { fontFamily: Polices.texteTresGras, fontSize: 13, letterSpacing: 0.8, textTransform: 'uppercase', color: Couleurs.gris },
  vide: { fontFamily: Polices.texte, fontSize: 15, lineHeight: 22, color: Couleurs.gris },
  aide: { fontFamily: Polices.texte, fontSize: 12, lineHeight: 17, color: Couleurs.gris },
  lien: { fontFamily: Polices.texteGras, fontSize: 14, color: '#4A4A55', textDecorationLine: 'underline', paddingVertical: 8 },
  carte: { flexDirection: 'row', borderRadius: Espaces.rayonCarte, borderWidth: 1, borderColor: Couleurs.traitCarte, backgroundColor: Couleurs.carte, overflow: 'hidden' },
  carteCeSoir: { borderWidth: 1.5, borderColor: Couleurs.rouge },
  cartePassee: { opacity: 0.55 },
  appuyee: { opacity: 0.8 },
  talon: { width: 76, alignItems: 'center', justifyContent: 'center', gap: 1, borderRightWidth: 1, borderRightColor: '#DDD8D1' },
  talonJour: { fontFamily: Polices.texteTresGras, fontSize: 11, letterSpacing: 0.4, textTransform: 'uppercase', color: Couleurs.gris },
  talonHeure: { fontFamily: Polices.titre, fontSize: 20, color: Couleurs.encre },
  details: { flex: 1, gap: 3, paddingVertical: 11, paddingHorizontal: 14 },
  carteTitre: { fontFamily: Polices.texteGras, fontSize: 16, lineHeight: 20, color: Couleurs.encre },
  carteLieu: { fontFamily: Polices.texte, fontSize: 13, color: Couleurs.gris },
  badge: { alignSelf: 'flex-start', overflow: 'hidden', marginTop: 4, paddingVertical: 2, paddingHorizontal: 8, borderRadius: 999, backgroundColor: Couleurs.encre, fontFamily: Polices.texteGras, fontSize: 11, color: '#FFFFFF' },
  nouveautes: { gap: 10, paddingVertical: 14, paddingHorizontal: 16, borderRadius: 16, backgroundColor: Couleurs.encre },
  nouveautesEntete: { flexDirection: 'row', alignItems: 'center', justifyContent: 'space-between', gap: 10 },
  nouveautesTitre: { flex: 1, fontFamily: Polices.texteTresGras, fontSize: 14, color: '#FFFFFF' },
  nouveautesTout: { fontFamily: Polices.texteGras, fontSize: 12, color: 'rgba(255,255,255,0.7)', textDecorationLine: 'underline' },
  nouveaute: { gap: 2, paddingVertical: 10, paddingHorizontal: 12, borderRadius: 12, backgroundColor: 'rgba(255,255,255,0.08)' },
  nouveauteIntro: { fontFamily: Polices.texte, fontSize: 12, color: 'rgba(255,255,255,0.7)' },
  nouveauteTitre: { fontFamily: Polices.texteGras, fontSize: 15, color: '#FFFFFF' },
  bloc: { borderRadius: 16, borderWidth: 1, borderColor: Couleurs.traitCarte, backgroundColor: Couleurs.carte, overflow: 'hidden' },
  suivi: { flexDirection: 'row', alignItems: 'center', gap: 12, minHeight: 64, paddingVertical: 10, paddingHorizontal: 16 },
  separe: { borderTopWidth: 1, borderTopColor: '#F0ECE6' },
  marque: { width: 40, height: 40, borderRadius: 10, alignItems: 'center', justifyContent: 'center' },
  marqueLieu: { backgroundColor: '#FCE9CF' },
  marqueArtiste: { backgroundColor: '#E3EAFB' },
  marqueTexte: { fontFamily: Polices.titreFort, fontSize: 15 },
  suiviTextes: { flex: 1, gap: 2 },
  suiviNom: { fontFamily: Polices.texteGras, fontSize: 15, color: Couleurs.encre },
  nouveau: { overflow: 'hidden', paddingVertical: 3, paddingHorizontal: 8, borderRadius: 10, backgroundColor: Couleurs.rouge, fontFamily: Polices.texteTresGras, fontSize: 11, color: '#FFFFFF' },
  zone: { flexDirection: 'row', alignItems: 'center', gap: 12, paddingVertical: 12, paddingHorizontal: 16, borderRadius: 16, borderWidth: 1, borderColor: Couleurs.traitCarte, backgroundColor: Couleurs.carte },
  fondFeuille: { flex: 1, backgroundColor: Couleurs.fond },
  zoneActuelle: { paddingVertical: 10, paddingHorizontal: 14, borderRadius: Espaces.rayonCarte, backgroundColor: '#DDF0E7' },
  zoneActuelleTexte: { fontFamily: Polices.texteGras, fontSize: 14, color: '#0F5A43' },
  feuille: { gap: 14, padding: Espaces.bord },
  feuilleEntete: { flexDirection: 'row', alignItems: 'center', justifyContent: 'space-between' },
  feuilleTitre: { fontFamily: Polices.titre, fontSize: 24, color: Couleurs.encre },
  fermer: { fontFamily: Polices.texteGras, fontSize: 16, color: Couleurs.gris },
  puces: { flexDirection: 'row', flexWrap: 'wrap', gap: 8 },
  puce: { height: 38, paddingHorizontal: 14, borderRadius: 19, borderWidth: 1, borderColor: Couleurs.trait, backgroundColor: Couleurs.carte, justifyContent: 'center' },
  puceActive: { borderColor: Couleurs.encre, backgroundColor: Couleurs.encre },
  puceTexte: { fontFamily: Polices.texteMoyen, fontSize: 14, color: Couleurs.encre },
  puceTexteActive: { fontFamily: Polices.texteGras, color: '#FFFFFF' },
  gauche: { alignSelf: 'flex-start' },
});
