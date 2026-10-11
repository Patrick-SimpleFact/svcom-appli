import DateTimePicker from '@react-native-community/datetimepicker';
import { useState } from 'react';
import { ActivityIndicator, Modal, Pressable, ScrollView, StyleSheet, Switch, Text, View } from 'react-native';
import { useSafeAreaInsets } from 'react-native-safe-area-context';

import { dateDuJour, jourDe } from '@/autour/autour';
import { coordonnees } from '@/autour/position';
import { ChoixVille } from '@/components/choix-ville';
import { Couleurs, Espaces, Polices } from '@/constants/theme';
import { useDemarrage } from '@/demarrage/demarrage';
import { RAYONS_M } from '@/gouts/gouts';
import { type Criteres, joursDe, LIBELLES_MOMENTS, LIBELLES_TRIS, libellePrixMax, type Moment, type Quand, type Tri } from '@/recherche/recherche';
/** Rayons fixes autour d'une ville ou de la position (F2.4), sans « Automatique ». */
const RAYONS_FIXES = RAYONS_M.filter((r): r is NonNullable<typeof r> => r !== null);

const QUANDS: { quand: Quand | null; libelle: string }[] = [
  { quand: null, libelle: 'Toutes les dates' },
  { quand: 'aujourdhui', libelle: 'Aujourd’hui' },
  { quand: 'demain', libelle: 'Demain' },
  { quand: 'week_end', libelle: 'Ce week-end' },
  { quand: 'date', libelle: 'Une date' },
  { quand: 'periode', libelle: 'Une période' },
];

/** Panneau des filtres de la recherche (F4.3) : on règle un brouillon, appliqué par « Voir les résultats ». */
export function FiltresRecherche({ visible, criteres, appliquer, fermer }: { visible: boolean; criteres: Criteres; appliquer: (c: Criteres) => void; fermer: () => void }) {
  const marges = useSafeAreaInsets();
  const { configuration } = useDemarrage();
  const [brouillon, setBrouillon] = useState(criteres);
  const [choixVille, setChoixVille] = useState(false);
  const [localisation, setLocalisation] = useState<'attente' | 'refusee' | null>(null);
  const [ouvert, setOuvert] = useState(visible);

  // Chaque ouverture repart des filtres en cours.
  if (visible !== ouvert) {
    setOuvert(visible);
    if (visible) {
      setBrouillon(criteres);
      setChoixVille(false);
      setLocalisation(null);
    }
  }

  const maj = (champs: Partial<Criteres>) => setBrouillon((b) => ({ ...b, ...champs }));
  const aujourdhui = new Date();
  const limite = new Date(aujourdhui.getFullYear() + 1, aujourdhui.getMonth(), aujourdhui.getDate());
  const paliers = configuration?.parametres.paliers_prix ?? [0, 15, 30];
  const genres = configuration?.genres ?? [];

  const choisirQuand = (quand: Quand | null) => {
    if (!quand) {
      maj({ quand: undefined, du: undefined, au: undefined });
      return;
    }
    const choisis = { du: brouillon.du ?? jourDe(aujourdhui), au: brouillon.au };
    maj({ quand, ...joursDe(quand, aujourdhui, choisis) });
  };

  const autourDeMoi = async () => {
    setLocalisation('attente');
    const ici = await coordonnees().catch(() => null);
    if (ici) {
      maj({ position: { lat: ici.lat, lon: ici.lon }, ville: undefined, rayon: brouillon.rayon ?? 10000 });
      setLocalisation(null);
    } else {
      setLocalisation('refusee');
    }
  };

  const basculerGenre = (id: number) => {
    const actuels = brouillon.genres ?? [];
    maj({ genres: actuels.includes(id) ? actuels.filter((g) => g !== id) : [...actuels, id] });
  };

  const ou = brouillon.position ? 'moi' : brouillon.ville ? 'ville' : 'partout';

  return (
    <Modal visible={visible} animationType="slide" presentationStyle="pageSheet" onRequestClose={fermer}>
      <View style={styles.fond}>
        <View style={styles.entete}>
          <Text style={styles.titre}>Filtres</Text>
          <Pressable onPress={fermer} hitSlop={12} accessibilityRole="button">
            <Text style={styles.fermer}>Fermer</Text>
          </Pressable>
        </View>

        <ScrollView contentContainerStyle={styles.contenu} keyboardShouldPersistTaps="handled">
          <Section titre="Où">
            <View style={styles.puces}>
              <Puce libelle="Partout en France" actif={ou === 'partout'} onPress={() => maj({ position: undefined, ville: undefined, rayon: undefined })} />
              <Puce libelle="Autour de moi" actif={ou === 'moi'} onPress={autourDeMoi} />
              {brouillon.ville ? <Puce libelle={brouillon.ville.nom} actif={ou === 'ville'} onPress={() => maj({ position: undefined })} /> : null}
              <Puce libelle="Une ville…" actif={choixVille} onPress={() => setChoixVille(!choixVille)} />
            </View>
            {localisation === 'attente' ? <ActivityIndicator color={Couleurs.rouge} style={styles.gauche} /> : null}
            {localisation === 'refusee' ? <Text style={styles.aide}>Votre position n’est pas disponible : choisissez une ville.</Text> : null}
            {choixVille ? (
              <ChoixVille
                choisir={(v) => {
                  maj({ ville: { id: v.id, nom: v.nom }, position: undefined, rayon: brouillon.rayon ?? 10000 });
                  setChoixVille(false);
                }}
              />
            ) : null}
            {ou !== 'partout' ? (
              <View style={styles.puces}>
                {RAYONS_FIXES.map((r) => (
                  <Puce key={r} libelle={`${r / 1000} km`} actif={(brouillon.rayon ?? 10000) === r} onPress={() => maj({ rayon: r })} />
                ))}
              </View>
            ) : null}
          </Section>

          <Section titre="Quand">
            <View style={styles.puces}>
              {QUANDS.map((q) => (
                <Puce key={q.libelle} libelle={q.libelle} actif={(brouillon.quand ?? null) === q.quand} onPress={() => choisirQuand(q.quand)} />
              ))}
            </View>
            {brouillon.quand === 'date' || brouillon.quand === 'periode' ? (
              <View style={styles.calendriers}>
                <Calendrier
                  libelle={brouillon.quand === 'periode' ? 'Du' : 'Le'}
                  jour={brouillon.du}
                  min={aujourdhui}
                  max={limite}
                  choisir={(du) => maj(brouillon.quand === 'date' ? { du, au: du } : { du, au: brouillon.au && brouillon.au >= du ? brouillon.au : du })}
                />
                {brouillon.quand === 'periode' ? (
                  <Calendrier libelle="Au" jour={brouillon.au} min={brouillon.du ? dateDuJour(brouillon.du) : aujourdhui} max={limite} choisir={(au) => maj({ au })} />
                ) : null}
              </View>
            ) : null}
          </Section>

          <Section titre="Genres">
            <View style={styles.puces}>
              {genres.map((g) => (
                <Puce key={g.id} libelle={g.libelle} actif={(brouillon.genres ?? []).includes(g.id)} onPress={() => basculerGenre(g.id)} />
              ))}
              <Puce libelle="Jeune public" actif={!!brouillon.jeune_public} onPress={() => maj({ jeune_public: !brouillon.jeune_public })} />
            </View>
          </Section>

          <Section titre="Moment de la journée">
            <View style={styles.puces}>
              {(Object.keys(LIBELLES_MOMENTS) as Moment[]).map((m) => (
                <Puce key={m} libelle={LIBELLES_MOMENTS[m]} actif={brouillon.moment === m} onPress={() => maj({ moment: brouillon.moment === m ? undefined : m })} />
              ))}
            </View>
          </Section>

          <Section titre="Prix">
            <View style={styles.puces}>
              <Puce libelle="Tous" actif={brouillon.prix_max === undefined} onPress={() => maj({ prix_max: undefined })} />
              {paliers.map((p) => (
                <Puce key={p} libelle={libellePrixMax(p)} actif={brouillon.prix_max === p} onPress={() => maj({ prix_max: p })} />
              ))}
            </View>
          </Section>

          <Section titre="Disponibilité">
            <View style={styles.ligne}>
              <Text style={styles.ligneTexte}>Masquer les séances complètes</Text>
              <Switch value={!!brouillon.masquer_complets} onValueChange={(v) => maj({ masquer_complets: v })} trackColor={{ true: Couleurs.rouge }} />
            </View>
          </Section>

          <Section titre="Trier">
            <View style={styles.puces}>
              {(Object.keys(LIBELLES_TRIS) as Tri[])
                .filter((t) => t !== 'distance' || ou !== 'partout')
                .map((t) => (
                  <Puce key={t} libelle={LIBELLES_TRIS[t]} actif={(brouillon.tri ?? 'date') === t} onPress={() => maj({ tri: t })} />
                ))}
            </View>
          </Section>
        </ScrollView>

        <View style={[styles.pied, { paddingBottom: 12 + marges.bottom }]}>
          <Pressable
            style={styles.effacer}
            onPress={() => setBrouillon({ q: brouillon.q, libelle: brouillon.q ?? 'Tous les spectacles' })}
            accessibilityRole="button">
            <Text style={styles.effacerTexte}>Tout effacer</Text>
          </Pressable>
          <Pressable
            style={styles.voir}
            onPress={() => appliquer({ ...brouillon, tri: brouillon.tri === 'distance' && ou === 'partout' ? 'date' : brouillon.tri })}
            accessibilityRole="button">
            <Text style={styles.voirTexte}>Voir les résultats</Text>
          </Pressable>
        </View>
      </View>
    </Modal>
  );
}

function Section({ titre, children }: { titre: string; children: React.ReactNode }) {
  return (
    <View style={styles.section}>
      <Text style={styles.rubrique}>{titre}</Text>
      {children}
    </View>
  );
}

function Puce({ libelle, actif, onPress }: { libelle: string; actif: boolean; onPress: () => void }) {
  return (
    <Pressable style={[styles.puce, actif && styles.puceActive]} onPress={onPress} accessibilityRole="button" accessibilityState={{ selected: actif }}>
      <Text style={[styles.puceTexte, actif && styles.puceTexteActive]}>{libelle}</Text>
    </Pressable>
  );
}

function Calendrier({ libelle, jour, min, max, choisir }: { libelle: string; jour?: string; min: Date; max: Date; choisir: (jour: string) => void }) {
  return (
    <View style={styles.calendrier}>
      <Text style={styles.calendrierLibelle}>{libelle}</Text>
      <DateTimePicker
        mode="date"
        display="compact"
        locale="fr-FR"
        value={jour ? dateDuJour(jour) : min}
        minimumDate={min}
        maximumDate={max}
        accentColor={Couleurs.rouge}
        themeVariant="light"
        onValueChange={(_e, date) => choisir(jourDe(date))}
      />
    </View>
  );
}

const styles = StyleSheet.create({
  fond: { flex: 1, backgroundColor: Couleurs.fond },
  entete: { flexDirection: 'row', alignItems: 'center', justifyContent: 'space-between', padding: Espaces.bord },
  titre: { fontFamily: Polices.titre, fontSize: 24, color: Couleurs.encre },
  fermer: { fontFamily: Polices.texteGras, fontSize: 16, color: Couleurs.gris },
  contenu: { gap: 22, paddingHorizontal: Espaces.bord, paddingBottom: 24 },
  section: { gap: 10 },
  rubrique: { fontFamily: Polices.texteTresGras, fontSize: 13, letterSpacing: 0.8, textTransform: 'uppercase', color: Couleurs.gris },
  puces: { flexDirection: 'row', flexWrap: 'wrap', gap: 8 },
  puce: { height: 38, paddingHorizontal: 14, borderRadius: 19, borderWidth: 1, borderColor: Couleurs.trait, backgroundColor: Couleurs.carte, justifyContent: 'center' },
  puceActive: { borderColor: Couleurs.encre, backgroundColor: Couleurs.encre },
  puceTexte: { fontFamily: Polices.texteMoyen, fontSize: 14, color: Couleurs.encre },
  puceTexteActive: { fontFamily: Polices.texteGras, color: '#FFFFFF' },
  gauche: { alignSelf: 'flex-start' },
  aide: { fontFamily: Polices.texte, fontSize: 13, color: Couleurs.gris },
  calendriers: { gap: 8 },
  calendrier: { flexDirection: 'row', alignItems: 'center', justifyContent: 'space-between' },
  calendrierLibelle: { fontFamily: Polices.texteGras, fontSize: 15, color: Couleurs.encre },
  ligne: { flexDirection: 'row', alignItems: 'center', justifyContent: 'space-between', gap: 12 },
  ligneTexte: { flex: 1, fontFamily: Polices.texte, fontSize: 15, color: Couleurs.encre },
  pied: { flexDirection: 'row', alignItems: 'center', gap: 12, paddingTop: 12, paddingHorizontal: Espaces.bord, borderTopWidth: 1, borderTopColor: Couleurs.traitCarte, backgroundColor: Couleurs.carte },
  effacer: { paddingVertical: 14, paddingHorizontal: 6 },
  effacerTexte: { fontFamily: Polices.texteGras, fontSize: 14, color: '#4A4A55', textDecorationLine: 'underline' },
  voir: { flex: 1, height: 52, borderRadius: 26, alignItems: 'center', justifyContent: 'center', backgroundColor: Couleurs.encre },
  voirTexte: { fontFamily: Polices.texteTresGras, fontSize: 16, color: '#FFFFFF' },
});
