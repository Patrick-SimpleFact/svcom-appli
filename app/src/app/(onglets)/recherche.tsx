import { router } from 'expo-router';
import { useEffect, useRef, useState } from 'react';
import { ActivityIndicator, Keyboard, Pressable, ScrollView, StyleSheet, Text, TextInput, View } from 'react-native';
import { SafeAreaView } from 'react-native-safe-area-context';
import Svg, { Circle, Path } from 'react-native-svg';

import { CarteSansHoraire, CarteSpectacle, ouvrirFiche } from '@/components/carte-spectacle';
import { MessagesService } from '@/components/messages-service';
import { Couleurs, Espaces, Polices } from '@/constants/theme';
import { useDemarrage } from '@/demarrage/demarrage';
import { jourLong } from '@/fiche/fiche';
import { ajouterJours, versCarte } from '@/fiche/pages';
import {
  ajouterRecente, chargerPropositions, chercher, type Criteres, enregistrerRecentes, jourTalon, lireRecentes, type Propositions, type Recente, type Resultats, VILLES_PILOTES, weekEnd,
} from '@/recherche/recherche';

/** Genres proposés en raccourci (maquette « Rechercher »). */
const GENRES_RACCOURCIS = ['theatre', 'humour', 'concert', 'danse', 'cirque-magie'];

/** Délai après la dernière lettre avant de demander des propositions (réponse du BO < 300 ms, F4.1). */
const ATTENTE_SAISIE_MS = 200;

type Etat = { mode: 'saisie' } | { mode: 'resultats'; criteres: Criteres; resultats: Resultats | null; erreur: boolean };

/** Onglet « Rechercher » (F4, maquette « Rechercher ») : une seule barre pour les spectacles, artistes, lieux et villes. */
export default function Recherche() {
  const { configuration } = useDemarrage();
  const [texte, setTexte] = useState('');
  const [propositions, setPropositions] = useState<Propositions | null>(null);
  const [recentes, setRecentes] = useState<Recente[]>([]);
  const [etat, setEtat] = useState<Etat>({ mode: 'saisie' });
  const pageEnCours = useRef(false);

  useEffect(() => {
    lireRecentes().then(setRecentes);
  }, []);

  // Propositions pendant la frappe, dès 2 caractères.
  useEffect(() => {
    const saisie = texte.trim();
    if (etat.mode !== 'saisie' || saisie.length < 2) {
      return;
    }
    let actif = true;
    const minuteur = setTimeout(() => {
      chargerPropositions(saisie)
        .then((p) => actif && setPropositions(p))
        .catch(() => {});
    }, ATTENTE_SAISIE_MS);

    return () => {
      actif = false;
      clearTimeout(minuteur);
    };
  }, [texte, etat.mode]);

  const memoriser = (r: Recente) => {
    const liste = ajouterRecente(recentes, r);
    setRecentes(liste);
    enregistrerRecentes(liste);
  };

  const lancer = (criteres: Criteres, recente?: Recente) => {
    Keyboard.dismiss();
    if (recente) {
      memoriser(recente);
    }
    setEtat({ mode: 'resultats', criteres, resultats: null, erreur: false });
    chercher(criteres)
      .then((resultats) => setEtat((e) => (e.mode === 'resultats' && e.criteres === criteres ? { ...e, resultats } : e)))
      .catch(() => setEtat((e) => (e.mode === 'resultats' && e.criteres === criteres ? { ...e, erreur: true } : e)));
  };

  const valider = () => {
    const q = texte.trim();
    if (q.length >= 2) {
      const criteres = { q, libelle: q };
      lancer(criteres, { type: 'texte', libelle: q, criteres });
    }
  };

  const ouvrirVille = (ville: { id: number; nom: string }) => {
    setTexte(ville.nom);
    const criteres = { ville, libelle: ville.nom };
    lancer(criteres, { type: 'ville', libelle: ville.nom, criteres });
  };

  const ouvrir = (r: Recente) => {
    if (r.type === 'texte' || r.type === 'ville') {
      setTexte(r.criteres.q ?? r.criteres.ville?.nom ?? '');
      lancer(r.criteres, r);
      return;
    }
    memoriser(r);
    if (r.type === 'spectacle') {
      ouvrirFiche(r.id, undefined, null, 'recherche');
    } else {
      router.push({ pathname: r.type === 'lieu' ? '/lieu/[id]' : '/artiste/[id]', params: { id: String(r.id) } });
    }
  };

  /** Ville pilote : retrouvée par son nom parmi les villes proposées. */
  const villePilote = async (nom: string) => {
    const p = await chargerPropositions(nom).catch(() => null);
    const ville = p?.villes.find((v) => v.nom === nom) ?? p?.villes[0];
    if (ville) {
      ouvrirVille({ id: ville.id, nom: ville.nom });
    }
  };

  const effacer = () => {
    setTexte('');
    setPropositions(null);
    setEtat({ mode: 'saisie' });
  };

  // Chargement continu des résultats (F4.4).
  const suite = async () => {
    if (etat.mode !== 'resultats' || !etat.resultats?.suivant || pageEnCours.current) {
      return;
    }
    pageEnCours.current = true;
    const { criteres, resultats } = etat;
    try {
      const plus = await chercher(criteres, resultats.suivant);
      setEtat((e) => (e.mode === 'resultats' && e.criteres === criteres && e.resultats ? { ...e, resultats: { ...e.resultats, jours: ajouterJours(e.resultats.jours, plus.jours), suivant: plus.suivant } } : e));
    } catch {
      // Nouvel essai au prochain défilement.
    } finally {
      pageEnCours.current = false;
    }
  };

  const genres = configuration?.genres ?? [];
  const libelleGenre = (id: number) => genres.find((g) => g.id === id)?.libelle ?? '';
  const saisie = texte.trim();

  let contenu;
  if (etat.mode === 'resultats') {
    contenu = <ListeResultats etat={etat} relancer={lancer} libelleGenre={libelleGenre} />;
  } else if (saisie.length >= 2 && propositions) {
    contenu = <ListePropositions propositions={propositions} ouvrir={ouvrir} ouvrirVille={ouvrirVille} />;
  } else {
    const raccourcis: { libelle: string; criteres: Criteres }[] = [
      { libelle: 'Ce week-end', criteres: { ...weekEnd(new Date()), libelle: 'Ce week-end' } },
      ...genres.filter((g) => GENRES_RACCOURCIS.includes(g.slug)).map((g) => ({ libelle: g.libelle, criteres: { genres: [g.id], libelle: g.libelle } })),
      { libelle: 'Jeune public', criteres: { jeune_public: true, libelle: 'Jeune public' } },
    ];
    contenu = (
      <View style={styles.avant}>
        {recentes.length ? (
          <View>
            <View style={styles.rubriqueLigne}>
              <Text style={styles.rubrique}>Recherches récentes</Text>
              <Pressable
                onPress={() => {
                  setRecentes([]);
                  enregistrerRecentes([]);
                }}
                accessibilityRole="button">
                <Text style={styles.effacerTexte}>Effacer</Text>
              </Pressable>
            </View>
            {recentes.map((r) => (
              <Pressable key={`${r.type}-${r.libelle}`} style={styles.recente} onPress={() => ouvrir(r)} accessibilityRole="button">
                <Svg width={18} height={18} viewBox="0 0 24 24" fill="none" stroke={Couleurs.gris} strokeWidth={2} strokeLinecap="round" strokeLinejoin="round">
                  <Circle cx={12} cy={12} r={9} />
                  <Path d="M12 7v5l3 2" />
                </Svg>
                <Text style={styles.recenteTexte} numberOfLines={1}>{r.libelle}</Text>
                <Text style={styles.recenteType}>{TYPES[r.type]}</Text>
              </Pressable>
            ))}
          </View>
        ) : null}
        <View style={styles.bloc}>
          <Text style={styles.rubrique}>Raccourcis</Text>
          <View style={styles.puces}>
            {raccourcis.map((r, i) => (
              <Pressable key={r.libelle} style={[styles.puce, i === 0 && styles.puceSombre]} onPress={() => lancer(r.criteres)} accessibilityRole="button">
                <Text style={[styles.puceTexte, i === 0 && styles.puceTexteSombre]}>{r.libelle}</Text>
              </Pressable>
            ))}
          </View>
        </View>
        <View style={styles.bloc}>
          <Text style={styles.rubrique}>Villes pilotes</Text>
          <View style={styles.villes}>
            {VILLES_PILOTES.map((v) => (
              <Pressable key={v} style={styles.ville} onPress={() => villePilote(v)} accessibilityRole="button">
                <Text style={styles.villeTexte}>{v}</Text>
              </Pressable>
            ))}
          </View>
        </View>
      </View>
    );
  }

  return (
    <SafeAreaView style={styles.fond} edges={['top']}>
      <View style={styles.entete}>
        <Text style={styles.titre} accessibilityRole="header">Rechercher</Text>
        <View style={[styles.champ, saisie && styles.champActif]}>
          <Svg width={18} height={18} viewBox="0 0 24 24" fill="none" stroke={Couleurs.gris} strokeWidth={2} strokeLinecap="round" strokeLinejoin="round">
            <Circle cx={11} cy={11} r={7} />
            <Path d="M20 20l-3.5-3.5" />
          </Svg>
          <TextInput
            style={styles.saisie}
            value={texte}
            onChangeText={(t) => {
              setTexte(t);
              if (etat.mode !== 'saisie') {
                setEtat({ mode: 'saisie' });
              }
            }}
            onSubmitEditing={valider}
            placeholder="Un spectacle, un artiste, un lieu, une ville"
            placeholderTextColor={Couleurs.gris}
            returnKeyType="search"
            autoCorrect={false}
            accessibilityLabel="Rechercher"
          />
          {texte ? (
            <Pressable style={styles.effacer} onPress={effacer} accessibilityRole="button" accessibilityLabel="Effacer">
              <Svg width={16} height={16} viewBox="0 0 24 24" fill="none" stroke={Couleurs.encre} strokeWidth={2.4} strokeLinecap="round">
                <Path d="M6 6l12 12M18 6L6 18" />
              </Svg>
            </Pressable>
          ) : null}
        </View>
        <MessagesService />
      </View>
      <ScrollView
        contentContainerStyle={styles.contenu}
        keyboardShouldPersistTaps="handled"
        keyboardDismissMode="on-drag"
        onScroll={(e) => {
          const { layoutMeasurement, contentOffset, contentSize } = e.nativeEvent;
          if (layoutMeasurement.height + contentOffset.y > contentSize.height - 600) {
            suite();
          }
        }}
        scrollEventThrottle={200}>
        {contenu}
      </ScrollView>
    </SafeAreaView>
  );
}

const TYPES: Record<Recente['type'], string> = { texte: 'Recherche', ville: 'Ville', lieu: 'Lieu', artiste: 'Artiste', spectacle: 'Spectacle' };

/** Pendant la frappe : propositions regroupées par type (maquette : artistes, lieux, spectacles, villes). */
function ListePropositions({ propositions, ouvrir, ouvrirVille }: { propositions: Propositions; ouvrir: (r: Recente) => void; ouvrirVille: (v: { id: number; nom: string }) => void }) {
  const groupes = [
    { titre: 'Artistes', marque: 'Ar', fond: '#E3EAFB', encre: '#1E3A8A', items: propositions.artistes.map((a) => ({ cle: `a${a.id}`, libelle: a.nom, sous: a.sous_titre, choisir: () => ouvrir({ type: 'artiste', libelle: a.nom, id: a.id }) })) },
    { titre: 'Lieux', marque: 'Li', fond: '#FCE9CF', encre: '#7A3E00', items: propositions.lieux.map((l) => ({ cle: `l${l.id}`, libelle: l.nom, sous: l.sous_titre, choisir: () => ouvrir({ type: 'lieu', libelle: l.nom, id: l.id }) })) },
    { titre: 'Spectacles', marque: 'Sp', fond: '#FBE1DC', encre: '#8E2410', items: propositions.spectacles.map((s) => ({ cle: `s${s.id}`, libelle: s.titre, sous: s.sous_titre, choisir: () => ouvrir({ type: 'spectacle', libelle: s.titre, id: s.id }) })) },
    { titre: 'Villes', marque: 'Vi', fond: '#DDF0E7', encre: '#0F5A43', items: propositions.villes.map((v) => ({ cle: `v${v.id}`, libelle: v.nom, sous: v.sous_titre, choisir: () => ouvrirVille({ id: v.id, nom: v.nom }) })) },
  ].filter((g) => g.items.length);

  if (!groupes.length) {
    return <Text style={styles.texte}>Aucune proposition. Touchez « Rechercher » sur le clavier pour chercher ce texte partout.</Text>;
  }

  return (
    <View style={styles.groupes}>
      {groupes.map((g) => (
        <View key={g.titre}>
          <Text style={styles.groupeTitre}>{g.titre}</Text>
          {g.items.map((it) => (
            <Pressable key={it.cle} style={styles.proposition} onPress={it.choisir} accessibilityRole="button">
              <View style={[styles.marque, { backgroundColor: g.fond }]}>
                <Text style={[styles.marqueTexte, { color: g.encre }]}>{g.marque}</Text>
              </View>
              <View style={styles.propositionTextes}>
                <Text style={styles.propositionLibelle} numberOfLines={1}>{it.libelle}</Text>
                {it.sous ? <Text style={styles.propositionSous} numberOfLines={1}>{it.sous}</Text> : null}
              </View>
            </Pressable>
          ))}
        </View>
      ))}
    </View>
  );
}

/** Résultats groupés par jour (F4.4), ou ce qu'on propose quand il n'y en a pas (F4.5). */
function ListeResultats({ etat, relancer, libelleGenre }: { etat: Extract<Etat, { mode: 'resultats' }>; relancer: (c: Criteres) => void; libelleGenre: (id: number) => string }) {
  const { criteres, resultats, erreur } = etat;

  if (erreur) {
    return (
      <View style={styles.bloc}>
        <Text style={styles.texte}>Impossible de chercher pour le moment.</Text>
        <Pressable onPress={() => relancer(criteres)} accessibilityRole="button">
          <Text style={styles.lien}>Réessayer</Text>
        </Pressable>
      </View>
    );
  }
  if (!resultats) {
    return <ActivityIndicator color={Couleurs.rouge} style={styles.attente} />;
  }

  if (resultats.total === 0) {
    const { elargir, correction } = resultats;

    return (
      <View style={styles.aucun}>
        <Text style={styles.aucunTitre}>Aucun résultat pour « {criteres.libelle} »</Text>
        {correction ? (
          <Pressable onPress={() => relancer({ ...criteres, q: correction, libelle: correction })} accessibilityRole="button">
            <Text style={styles.texte}>
              Vouliez-vous dire <Text style={styles.correction}>{correction}</Text> ?
            </Text>
          </Pressable>
        ) : null}
        {elargir && elargir.total > 0 ? (
          <Pressable
            style={styles.elargir}
            onPress={() => relancer(elargir.rayon_m ? { ...criteres, rayon: elargir.rayon_m } : { ...criteres, au: elargir.au })}
            accessibilityRole="button">
            <View style={styles.elargirTextes}>
              <Text style={styles.elargirTitre}>Élargir la recherche</Text>
              <Text style={styles.propositionSous}>
                {elargir.rayon_m ? `Voir les spectacles dans un rayon de ${elargir.rayon_m / 1000} km` : 'Voir les 30 prochains jours'} ({elargir.total})
              </Text>
            </View>
            <Text style={styles.fleche}>›</Text>
          </Pressable>
        ) : null}
        <View style={styles.manque}>
          <Text style={styles.manqueTitre}>Il manque ce spectacle ?</Text>
          <Text style={styles.manqueTexte}>Signalez-le-nous : votre recherche et la ville seront déjà remplies.</Text>
          {/* Le formulaire de piste (F8), pré-rempli avec la recherche, arrive en M14. */}
          <Pressable style={styles.manqueBouton} onPress={() => {}} accessibilityRole="button">
            <Text style={styles.manqueBoutonTexte}>Signaler un spectacle manquant</Text>
          </Pressable>
        </View>
      </View>
    );
  }

  return (
    <View style={styles.resultats}>
      <Text style={styles.compte}>
        {resultats.total_plus ? 'Plus de ' : ''}
        {resultats.total} spectacle{resultats.total > 1 ? 's' : ''} · {criteres.libelle}
      </Text>
      {resultats.jours.map((jour) => (
        <View key={jour.date} style={styles.jour}>
          <Text style={styles.jourTitre}>{jourLong(jour.date)}</Text>
          {jour.cartes.map((c) => {
            const carte = versCarte(c);
            const ouvrir = () => ouvrirFiche(c.spectacle.id, 'horaire' in carte ? carte.horaire.seances[0]?.representation_id : carte.journee.representation_id, null, 'recherche');

            return 'horaire' in carte ? (
              <CarteSpectacle key={`${c.spectacle.id}-${c.lieu.id}-${c.date_locale}`} carte={carte.horaire} genre={libelleGenre(c.spectacle.genre_id)} jour={jourTalon(jour.date)} onPress={ouvrir} />
            ) : (
              <CarteSansHoraire key={`${c.spectacle.id}-${carte.journee.representation_id}`} carte={carte.journee} genre={libelleGenre(c.spectacle.genre_id)} onPress={ouvrir} />
            );
          })}
        </View>
      ))}
      {resultats.suivant ? <ActivityIndicator color={Couleurs.rouge} style={styles.attente} /> : null}
    </View>
  );
}

// Valeurs de la maquette « Rechercher ».
const styles = StyleSheet.create({
  fond: { flex: 1, backgroundColor: Couleurs.fond },
  entete: { gap: 14, paddingTop: 12, paddingBottom: 12, paddingHorizontal: Espaces.bord },
  titre: { fontFamily: Polices.titre, fontSize: 30, lineHeight: 32, letterSpacing: -0.6, color: Couleurs.encre },
  champ: {
    flexDirection: 'row', alignItems: 'center', gap: 10, height: 50, paddingLeft: 14, paddingRight: 8, borderRadius: Espaces.rayonCarte,
    borderWidth: 1, borderColor: Couleurs.trait, backgroundColor: Couleurs.carte,
  },
  champActif: { borderWidth: 2, borderColor: Couleurs.encre },
  saisie: { flex: 1, minWidth: 0, fontFamily: Polices.texte, fontSize: 15, color: Couleurs.encre },
  effacer: { width: 36, height: 36, borderRadius: 18, alignItems: 'center', justifyContent: 'center', backgroundColor: '#F2EFEA' },
  contenu: { paddingHorizontal: Espaces.bord, paddingBottom: 120 },
  avant: { gap: 22, paddingTop: 6 },
  bloc: { gap: 10 },
  rubriqueLigne: { flexDirection: 'row', alignItems: 'center', justifyContent: 'space-between' },
  rubrique: { fontFamily: Polices.texteTresGras, fontSize: 13, letterSpacing: 0.8, textTransform: 'uppercase', color: Couleurs.gris },
  effacerTexte: { fontFamily: Polices.texteGras, fontSize: 13, color: '#4A4A55', textDecorationLine: 'underline', paddingVertical: 10 },
  recente: { flexDirection: 'row', alignItems: 'center', gap: 12, minHeight: 48, borderBottomWidth: 1, borderBottomColor: Couleurs.traitCarte },
  recenteTexte: { flex: 1, fontFamily: Polices.texte, fontSize: 15, color: Couleurs.encre },
  recenteType: { fontFamily: Polices.texte, fontSize: 12, color: Couleurs.gris },
  puces: { flexDirection: 'row', flexWrap: 'wrap', gap: 8 },
  puce: { height: 40, paddingHorizontal: 14, borderRadius: 20, borderWidth: 1, borderColor: Couleurs.trait, backgroundColor: Couleurs.carte, justifyContent: 'center' },
  puceSombre: { borderColor: Couleurs.encre, backgroundColor: Couleurs.encre },
  puceTexte: { fontFamily: Polices.texteMoyen, fontSize: 14, color: Couleurs.encre },
  puceTexteSombre: { fontFamily: Polices.texteGras, color: '#FFFFFF' },
  villes: { flexDirection: 'row', gap: 8 },
  ville: { flex: 1, height: 64, borderRadius: Espaces.rayonCarte, borderWidth: 1, borderColor: Couleurs.traitCarte, backgroundColor: Couleurs.carte, alignItems: 'center', justifyContent: 'center' },
  villeTexte: { fontFamily: Polices.titre, fontSize: 17, color: Couleurs.encre },
  groupes: { gap: 18 },
  groupeTitre: { marginBottom: 2, fontFamily: Polices.texteTresGras, fontSize: 12, letterSpacing: 1, textTransform: 'uppercase', color: Couleurs.gris },
  proposition: { flexDirection: 'row', alignItems: 'center', gap: 12, minHeight: 56, borderBottomWidth: 1, borderBottomColor: Couleurs.traitCarte },
  marque: { width: 38, height: 38, borderRadius: 10, alignItems: 'center', justifyContent: 'center' },
  marqueTexte: { fontFamily: Polices.titreFort, fontSize: 15 },
  propositionTextes: { flex: 1, minWidth: 0, gap: 1 },
  propositionLibelle: { fontFamily: Polices.texteGras, fontSize: 15, color: Couleurs.encre },
  propositionSous: { fontFamily: Polices.texte, fontSize: 13, color: Couleurs.gris },
  texte: { fontFamily: Polices.texte, fontSize: 15, lineHeight: 22, color: Couleurs.encre },
  lien: { fontFamily: Polices.texteTresGras, fontSize: 14, color: Couleurs.encre, textDecorationLine: 'underline', paddingVertical: 6 },
  attente: { marginVertical: 20 },
  aucun: { gap: 14, paddingTop: 4 },
  aucunTitre: { fontFamily: Polices.titre, fontSize: 22, lineHeight: 26, color: Couleurs.encre },
  correction: { fontFamily: Polices.texteTresGras, textDecorationLine: 'underline' },
  elargir: {
    flexDirection: 'row', alignItems: 'center', justifyContent: 'space-between', gap: 12, minHeight: 64, paddingVertical: 12, paddingHorizontal: 16,
    borderRadius: Espaces.rayonCarte, borderWidth: 1, borderColor: Couleurs.traitCarte, backgroundColor: Couleurs.carte,
  },
  elargirTextes: { flex: 1, gap: 2 },
  elargirTitre: { fontFamily: Polices.texteTresGras, fontSize: 15, color: Couleurs.encre },
  fleche: { fontFamily: Polices.texte, fontSize: 22, color: Couleurs.gris },
  manque: { gap: 10, padding: 16, borderRadius: 16, backgroundColor: Couleurs.encre },
  manqueTitre: { fontFamily: Polices.texteTresGras, fontSize: 15, color: '#FFFFFF' },
  manqueTexte: { fontFamily: Polices.texte, fontSize: 13, lineHeight: 19, color: 'rgba(255,255,255,0.78)' },
  manqueBouton: { height: 44, borderRadius: 22, alignItems: 'center', justifyContent: 'center', backgroundColor: Couleurs.rouge },
  manqueBoutonTexte: { fontFamily: Polices.texteTresGras, fontSize: 14, color: '#FFFFFF' },
  resultats: { gap: 10 },
  compte: { fontFamily: Polices.texte, fontSize: 13, color: Couleurs.gris },
  jour: { gap: 10 },
  jourTitre: { marginTop: 4, fontFamily: Polices.texteTresGras, fontSize: 13, letterSpacing: 0.8, textTransform: 'uppercase', color: Couleurs.gris },
});
