import { Image } from 'expo-image';
import { router, useLocalSearchParams } from 'expo-router';
import * as WebBrowser from 'expo-web-browser';
import { useEffect, useRef, useState } from 'react';
import { ActivityIndicator, Linking, Modal, Platform, Pressable, ScrollView, StyleSheet, Text, View } from 'react-native';
import { SafeAreaView, useSafeAreaInsets } from 'react-native-safe-area-context';
import Svg, { Circle, Path } from 'react-native-svg';

import { useCompte } from '@/compte/compte';
import { BoutonSuivre } from '@/components/bouton-suivre';
import { SignalerErreur } from '@/components/signaler-erreur';
import { Couleurs, Espaces, Polices } from '@/constants/theme';
import {
  aDistance, type Billetterie, chargerFiche, duree, type Fiche, heureSeance, jourLong, libellePrix, lienSortie, miseAJour, type Origine, prixBilletterie,
  reservation, type Seance, texteBouton, trajet,
} from '@/fiche/fiche';
import { useFavoris } from '@/favoris/favoris';
import { itineraire } from '@/fiche/itineraire';
import { type AutresDates, chargerAutresDates, libelleDate } from '@/fiche/pages';
import { revenir } from '@/navigation';

/** Fiche spectacle (F5, maquette « Fiche spectacle »), ouverte sur la séance touchée. */
export default function FicheSpectacle() {
  const params = useLocalSearchParams<{ id: string; representation?: string; origine?: Origine; lat?: string; lon?: string }>();
  const [fiche, setFiche] = useState<Fiche | null>(null);
  const [erreur, setErreur] = useState(false);
  const [essai, setEssai] = useState(0);

  useEffect(() => {
    let actif = true;
    const position = params.lat && params.lon ? { lat: Number(params.lat), lon: Number(params.lon) } : undefined;
    chargerFiche(Number(params.id), params.representation ? Number(params.representation) : undefined, position)
      .then((f) => actif && setFiche(f))
      .catch(() => actif && setErreur(true));

    return () => {
      actif = false;
    };
  }, [params.id, params.representation, params.lat, params.lon, essai]);

  if (!fiche) {
    return (
      <SafeAreaView style={[styles.fond, styles.centre]}>
        <BoutonRond onPress={revenir} label="Retour" style={styles.retourSeul} sombre={false}>
          <Path d="M15 5l-7 7 7 7" />
        </BoutonRond>
        {erreur ? (
          <View style={styles.erreur}>
            <Text style={styles.erreurTexte}>Impossible d’ouvrir la fiche pour le moment.</Text>
            <Pressable
              onPress={() => {
                setErreur(false);
                setEssai((n) => n + 1);
              }}
              accessibilityRole="button">
              <Text style={styles.lien}>Réessayer</Text>
            </Pressable>
          </View>
        ) : (
          <ActivityIndicator color={Couleurs.rouge} />
        )}
      </SafeAreaView>
    );
  }

  const position = params.lat && params.lon ? { lat: Number(params.lat), lon: Number(params.lon) } : null;

  return (
    <ContenuFiche
      key={`${fiche.id}-${params.representation ?? ''}`}
      fiche={fiche}
      representation={params.representation ? Number(params.representation) : undefined}
      origine={params.origine ?? 'fiche'}
      position={position}
    />
  );
}

function ContenuFiche({ fiche, representation, origine, position }: { fiche: Fiche; representation?: number; origine: Origine; position: { lat: number; lon: number } | null }) {
  const marges = useSafeAreaInsets();
  const [choisie, setChoisie] = useState<number | undefined>(() => (fiche.seances.some((s) => s.representation_id === representation) ? representation : fiche.seances.find((s) => !s.complet)?.representation_id ?? fiche.seances[0]?.representation_id));
  const [suite, setSuite] = useState(false);
  const [feuille, setFeuille] = useState(false);
  const [dates, setDates] = useState(false);
  const seance = fiche.seances.find((s) => s.representation_id === choisie);
  const resa = reservation(fiche, seance);
  const lieu = fiche.lieu;
  const genre = fiche.classification || fiche.genre?.libelle || '';

  // Lien choisi dans « Où réserver ? » : ouvert seulement une fois la feuille refermée (voir `onDismiss`).
  const enAttente = useRef<string | null>(null);
  const feuilleOuverte = useRef(false);
  useEffect(() => {
    feuilleOuverte.current = feuille;
  }, [feuille]);

  /** Navigateur intégré : « Fermer » ramène à la fiche, et le suivi d'affiliation fonctionne (F5.4). */
  const naviguer = (adresse: string) => WebBrowser.openBrowserAsync(adresse, { controlsColor: Couleurs.rouge, dismissButtonStyle: 'close' });

  const ouvrir = (b: Billetterie, bouton: 'principal' | 'autre') => {
    const adresse = lienSortie(b, origine, bouton, lieu?.distance_m ?? null);
    if (feuilleOuverte.current && Platform.OS === 'ios') {
      // iOS ne sait pas présenter le navigateur pendant qu'une feuille se referme : l'app semblait figée.
      enAttente.current = adresse;
      setFeuille(false);
      return;
    }
    setFeuille(false);
    naviguer(adresse);
  };

  const apresFermeture = () => {
    const adresse = enAttente.current;
    enAttente.current = null;
    if (adresse) {
      naviguer(adresse);
    }
  };

  const mentions = [
    fiche.duree_minutes ? { icone: 'duree' as const, texte: duree(fiche.duree_minutes) } : null,
    fiche.age_min ? { icone: 'age' as const, texte: `Dès ${fiche.age_min} ans` } : fiche.jeune_public ? { icone: 'age' as const, texte: 'Jeune public' } : null,
    fiche.festival ? { icone: 'festival' as const, texte: fiche.festival } : null,
  ].filter((m): m is NonNullable<typeof m> => m !== null);

  return (
    <View style={styles.fond}>
      <ScrollView contentContainerStyle={{ paddingBottom: 130 + marges.bottom }}>
        <EnTete fiche={fiche} genre={genre} hautSur={marges.top} representation={choisie} />

        <View style={styles.corps}>
          {mentions.length ? (
            <View style={styles.mentions}>
              {mentions.map((m) => (
                <View key={m.texte} style={[styles.mention, m.icone === 'duree' ? styles.mentionBleue : styles.mentionOrange]}>
                  <Text style={[styles.mentionTexte, m.icone === 'duree' ? styles.mentionTexteBleu : styles.mentionTexteOrange]}>{m.texte}</Text>
                </View>
              ))}
            </View>
          ) : null}

          {lieu ? (
            <View style={styles.ligneLieu}>
              <Svg width={18} height={18} viewBox="0 0 24 24" fill="none" stroke={Couleurs.gris} strokeWidth={2} strokeLinecap="round" strokeLinejoin="round">
                <Path d="M12 21s-7-6.2-7-11a7 7 0 0 1 14 0c0 4.8-7 11-7 11z" />
                <Circle cx={12} cy={10} r={2.5} />
              </Svg>
              <View style={styles.lieuTextes}>
                <Text style={styles.lieuTexte}>
                  <Text style={styles.lieuNom}>{lieu.nom}</Text>
                  {aDistance(lieu.distance_m) ? ` · ${lieu.position_approximative ? '≈ ' : ''}${aDistance(lieu.distance_m)}` : ''}
                </Text>
                <View style={styles.lieuActions}>
                  <Pressable onPress={() => ouvrirLieu(lieu.id, position)} accessibilityRole="link">
                    <Text style={styles.lienPetit}>Autres spectacles du lieu</Text>
                  </Pressable>
                  <BoutonSuivre type="lieu" id={lieu.id} petit />
                </View>
              </View>
            </View>
          ) : null}

          {fiche.jour && seance ? (
            <View style={styles.section}>
              <Text style={styles.rubrique}>{jourLong(fiche.jour)}</Text>
              {seance.type === 'seance' ? (
                <View style={styles.seances}>
                  {fiche.seances.map((s) => (
                    <PuceSeance key={s.representation_id} seance={s} choisie={s.representation_id === choisie} onPress={() => setChoisie(s.representation_id)} />
                  ))}
                  {fiche.autres_dates > 0 ? <BoutonAutresDates onPress={() => setDates(true)} /> : null}
                </View>
              ) : (
                <>
                  <SansHoraire lieu={lieu} />
                  {fiche.autres_dates > 0 ? <BoutonAutresDates onPress={() => setDates(true)} /> : null}
                </>
              )}
            </View>
          ) : null}

          <View style={styles.prix}>
            <Text style={styles.rubrique}>Prix</Text>
            <Text style={styles.prixValeur}>{libellePrix(fiche.prix)}</Text>
          </View>

          <View style={styles.section}>
            <Text style={styles.rubrique}>L’histoire</Text>
            {fiche.description ? (
              <>
                <Text style={styles.histoire} numberOfLines={suite ? undefined : 3}>{fiche.description}</Text>
                <Pressable onPress={() => setSuite(!suite)} accessibilityRole="button">
                  <Text style={styles.lien}>{suite ? 'Réduire' : 'Lire la suite'}</Text>
                </Pressable>
              </>
            ) : (
              <View style={styles.encadre}>
                <Text style={styles.encadreTexte}>Aucune description n’a été fournie pour ce spectacle.</Text>
                {seance?.billetteries[0] ? (
                  <Pressable onPress={() => ouvrir(seance.billetteries[0], 'autre')} accessibilityRole="link">
                    <Text style={styles.lienFort}>En savoir plus sur {seance.billetteries[0].source} ↗</Text>
                  </Pressable>
                ) : null}
              </View>
            )}
          </View>

          {fiche.artistes.length ? (
            <View style={styles.section}>
              <Text style={styles.rubrique}>Avec</Text>
              <Text style={styles.histoire}>{fiche.artistes.map((a) => (a.role ? `${a.nom} (${a.role})` : a.nom)).join(', ')}</Text>
            </View>
          ) : null}

          {lieu ? <Acces lieu={lieu} /> : null}

          <View style={styles.basFiche}>
            {fiche.mentions.length ? fiche.mentions.map((m) => <Text key={m} style={styles.source}>{m}</Text>) : null}
            <Text style={styles.source}>
              Source : {fiche.sources.join(', ')}
              {miseAJour(fiche.mis_a_jour_le) ? ` · ${miseAJour(fiche.mis_a_jour_le)}` : ''}
            </Text>
            <SignalerErreur representationId={choisie} />
          </View>
        </View>
      </ScrollView>

      <AutresDatesFeuille visible={dates} fermer={() => setDates(false)} fiche={fiche} position={position} />

      <BarreReservation resa={resa} seance={seance} ouvrir={ouvrir} comparer={() => setFeuille(true)} choisir={setChoisie} basSur={marges.bottom} />

      {resa.cas === 'reserver' ? (
        <Modal visible={feuille} transparent animationType="slide" onRequestClose={() => setFeuille(false)} onDismiss={apresFermeture}>
          <Pressable style={styles.voile} onPress={() => setFeuille(false)} accessibilityLabel="Fermer">
            <Pressable style={[styles.feuille, { paddingBottom: 26 + marges.bottom }]} onPress={() => {}}>
              <View style={styles.poignee} />
              <View style={styles.feuilleEntete}>
                <Text style={styles.feuilleTitre}>Où réserver ?</Text>
                <BoutonRond onPress={() => setFeuille(false)} label="Fermer" sombre={false}>
                  <Path d="M6 6l12 12M18 6L6 18" />
                </BoutonRond>
              </View>
              {[resa.principale, ...resa.autres].map((b, i) => (
                <Pressable key={b.offre_id} style={[styles.option, i === 0 && styles.optionRecommandee]} onPress={() => ouvrir(b, i === 0 ? 'principal' : 'autre')} accessibilityRole="link">
                  <View style={styles.optionTextes}>
                    <Text style={styles.optionNom}>{b.billetterie ? b.source : `Site de l’organisateur (${b.source})`}</Text>
                    {i === 0 && b.recommandee ? <Text style={styles.optionReco}>Recommandé</Text> : null}
                  </View>
                  <Text style={styles.optionPrix}>{prixBilletterie(b)} ↗</Text>
                </Pressable>
              ))}
              <Text style={styles.source}>Recommandation : billetterie qui a encore des places, puis la moins chère. Le billet est vendu par la billetterie d’origine.</Text>
            </Pressable>
          </Pressable>
        </Modal>
      ) : null}
    </View>
  );
}

function BoutonAutresDates({ onPress }: { onPress: () => void }) {
  return (
    <Pressable style={styles.autresDates} onPress={onPress} accessibilityRole="button">
      <Text style={styles.lien}>Autres dates</Text>
    </Pressable>
  );
}

/** Ouvre la page du lieu (F4.2), avec la position pour les distances. */
function ouvrirLieu(id: number, position: { lat: number; lon: number } | null) {
  router.push({ pathname: '/lieu/[id]', params: { id: String(id), ...(position ? { lat: String(position.lat), lon: String(position.lon) } : {}) } });
}

/**
 * Autres dates (F5.3) : toutes les représentations à venir, par lieu, le plus proche d'abord.
 * Choisir une date rouvre la fiche sur cette séance (le lieu peut changer).
 */
function AutresDatesFeuille({ visible, fermer, fiche, position }: { visible: boolean; fermer: () => void; fiche: Fiche; position: { lat: number; lon: number } | null }) {
  const marges = useSafeAreaInsets();
  const [reponse, setReponse] = useState<AutresDates | null>(null);
  const [erreur, setErreur] = useState(false);

  useEffect(() => {
    if (!visible) {
      return;
    }
    let actif = true;
    chargerAutresDates(fiche.id, position ?? undefined)
      .then((r) => actif && setReponse(r))
      .catch(() => actif && setErreur(true));

    return () => {
      actif = false;
    };
  }, [visible, fiche.id, position]);

  const choisir = (representation: number) => {
    fermer();
    router.setParams({ representation: String(representation) });
  };

  return (
    <Modal visible={visible} transparent animationType="slide" onRequestClose={fermer}>
      <Pressable style={styles.voile} onPress={fermer} accessibilityLabel="Fermer">
        <Pressable style={[styles.feuille, styles.feuilleHaute, { paddingBottom: 26 + marges.bottom }]} onPress={() => {}}>
          <View style={styles.poignee} />
          <View style={styles.feuilleEntete}>
            <Text style={styles.feuilleTitre}>Autres dates</Text>
            <BoutonRond onPress={fermer} label="Fermer" sombre={false}>
              <Path d="M6 6l12 12M18 6L6 18" />
            </BoutonRond>
          </View>
          {reponse ? (
            <ScrollView contentContainerStyle={styles.datesListe}>
              {reponse.lieux.map((groupe) => (
                <View key={groupe.lieu.id} style={styles.datesGroupe}>
                  <Text style={styles.datesLieu}>
                    {[groupe.lieu.ville, groupe.lieu.nom].filter(Boolean).join(' · ')} — {groupe.dates} date{groupe.dates > 1 ? 's' : ''}
                  </Text>
                  {groupe.representations.map((r) => (
                    <Pressable key={r.representation_id} style={styles.dateLigne} onPress={() => choisir(r.representation_id)} accessibilityRole="button">
                      <Text style={[styles.dateTexte, r.complet && styles.dateComplete]}>
                        {libelleDate(r)}
                        {r.complet ? ' · Complet' : ''}
                      </Text>
                      <Text style={styles.dateFleche}>›</Text>
                    </Pressable>
                  ))}
                </View>
              ))}
            </ScrollView>
          ) : erreur ? (
            <Text style={styles.encadreTexte}>Impossible de charger les autres dates pour le moment.</Text>
          ) : (
            <ActivityIndicator color={Couleurs.rouge} />
          )}
        </Pressable>
      </Pressable>
    </Modal>
  );
}

/** En-tête visuel : image de la source, ou affiche typographique (jamais de photo générique, F5.2). */
function EnTete({ fiche, genre, hautSur, representation }: { fiche: Fiche; genre: string; hautSur: number; representation?: number }) {
  const boutons = (
    <View style={styles.enteteBoutons}>
      <BoutonRond onPress={revenir} label="Retour">
        <Path d="M15 5l-7 7 7 7" />
      </BoutonRond>
      <Coeur spectacleId={fiche.id} representation={representation} />
    </View>
  );

  if (fiche.image_url) {
    return (
      <View style={[styles.entete, styles.enteteSombre, { height: 260 + hautSur, paddingTop: 16 + hautSur }]}>
        <Image source={fiche.image_url} style={StyleSheet.absoluteFill} contentFit="cover" accessibilityLabel={`Visuel de ${fiche.titre}`} />
        <View style={[StyleSheet.absoluteFill, styles.voileImage]} />
        {boutons}
        <View style={styles.enteteTextes}>
          {genre ? <Text style={styles.etiquette}>{genre}</Text> : null}
          <Text style={styles.titre} accessibilityRole="header">{fiche.titre}</Text>
        </View>
      </View>
    );
  }

  return (
    <View style={[styles.entete, styles.affiche, { height: 260 + hautSur, paddingTop: 16 + hautSur }]}>
      {boutons}
      <View style={styles.enteteTextes}>
        {genre ? <Text style={styles.afficheGenre}>{genre}</Text> : null}
        <Text style={styles.afficheTitre} numberOfLines={4} adjustsFontSizeToFit accessibilityRole="header">{fiche.titre}</Text>
      </View>
    </View>
  );
}

/**
 * Favori (F5.5) : compte requis (D2). En mode invité, le cœur propose le compte avec sa raison,
 * puis le favori est enregistré sans repasser par la fiche (F1.3).
 */
function Coeur({ spectacleId, representation }: { spectacleId: number; representation?: number }) {
  const { utilisateur } = useCompte();
  const { favoriDe, ajouter, retirer } = useFavoris();
  const favoriId = favoriDe(spectacleId);
  const favori = favoriId !== null;
  const [envoi, setEnvoi] = useState(false);

  const toucher = async () => {
    if (envoi) {
      return;
    }
    if (!utilisateur) {
      router.push({ pathname: '/connexion', params: { raison: 'favori', spectacle: String(spectacleId), ...(representation ? { representation: String(representation) } : {}) } });
      return;
    }
    setEnvoi(true);
    await (favoriId !== null ? retirer(favoriId) : ajouter(spectacleId, representation)).catch(() => {});
    setEnvoi(false);
  };

  return (
    <Pressable onPress={toucher} style={[styles.rond, styles.rondSombre]} accessibilityRole="button" accessibilityLabel={favori ? 'Retirer des favoris' : 'Ajouter aux favoris'} accessibilityState={{ selected: favori }} hitSlop={6}>
      <Svg width={20} height={20} viewBox="0 0 24 24" fill={favori ? Couleurs.rouge : 'none'} stroke={favori ? Couleurs.rouge : '#FFFFFF'} strokeWidth={2} strokeLinecap="round" strokeLinejoin="round">
        <Path d="M12 20s-7-4.4-7-10a4 4 0 0 1 7-2.6A4 4 0 0 1 19 10c0 5.6-7 10-7 10z" />
      </Svg>
    </Pressable>
  );
}

function PuceSeance({ seance, choisie, onPress }: { seance: Seance; choisie: boolean; onPress: () => void }) {
  return (
    <Pressable
      onPress={onPress}
      style={[styles.puce, seance.complet ? (choisie ? styles.puceCompleteChoisie : styles.puceComplete) : choisie ? styles.puceChoisie : null]}
      accessibilityRole="button"
      accessibilityState={{ selected: choisie }}
      accessibilityLabel={`${heureSeance(seance)}, ${seance.complet ? 'complet' : 'places disponibles'}`}>
      <Text style={[styles.puceHeure, choisie && !seance.complet && styles.puceTexteChoisi, seance.complet && styles.puceTexteComplet]}>{heureSeance(seance)}</Text>
      <Text style={[styles.puceEtat, choisie && !seance.complet && styles.puceTexteChoisi, seance.complet && styles.puceTexteComplet]}>{seance.complet ? 'Complet' : 'Places dispo'}</Text>
    </Pressable>
  );
}

/** Pas d'horaire précis : « Horaire à confirmer auprès du lieu » + Appeler / Site du lieu (F5.7). */
function SansHoraire({ lieu }: { lieu: Fiche['lieu'] }) {
  return (
    <View style={styles.encadre}>
      <Text style={styles.encadreTexte}>Horaire à confirmer auprès du lieu</Text>
      <View style={styles.actions}>
        {lieu?.telephone ? (
          <Pressable onPress={() => Linking.openURL(`tel:${lieu.telephone!.replace(/\s/g, '')}`)} accessibilityRole="button">
            <Text style={styles.lienFort}>Appeler</Text>
          </Pressable>
        ) : null}
        {lieu?.site_web ? (
          <Pressable onPress={() => WebBrowser.openBrowserAsync(lieu.site_web!)} accessibilityRole="link">
            <Text style={styles.lienFort}>Site du lieu ↗</Text>
          </Pressable>
        ) : null}
      </View>
    </View>
  );
}

function Acces({ lieu }: { lieu: NonNullable<Fiche['lieu']> }) {
  const ligne2 = [[lieu.code_postal, lieu.ville].filter(Boolean).join(' '), trajet(lieu.distance_m)].filter(Boolean).join(' · ');
  const sansAdresse = !lieu.adresse && !lieu.code_postal;

  return (
    <View style={styles.section}>
      <Text style={styles.rubrique}>Accès</Text>
      <View style={styles.acces}>
        <View style={styles.accesTextes}>
          <Text style={styles.accesAdresse}>{sansAdresse ? `${lieu.ville ?? lieu.nom} · adresse à confirmer` : lieu.adresse}</Text>
          {ligne2 && !sansAdresse ? <Text style={styles.accesSuite}>{ligne2}</Text> : null}
          {lieu.position_approximative && !sansAdresse ? <Text style={styles.accesSuite}>Adresse approximative</Text> : null}
        </View>
        {sansAdresse ? null : (
          <Pressable style={styles.itineraire} onPress={() => itineraire(lieu)} accessibilityRole="button">
            <Svg width={16} height={16} viewBox="0 0 24 24" fill="none" stroke={Couleurs.encre} strokeWidth={2} strokeLinecap="round" strokeLinejoin="round">
              <Path d="M3 11l18-8-8 18-2-8z" />
            </Svg>
            <Text style={styles.itineraireTexte}>Itinéraire</Text>
          </Pressable>
        )}
      </View>
    </View>
  );
}

/** Bouton de réservation fixé en bas, toujours visible (F5.4). */
function BarreReservation(props: {
  resa: ReturnType<typeof reservation>;
  seance: Seance | undefined;
  ouvrir: (b: Billetterie, bouton: 'principal' | 'autre') => void;
  comparer: () => void;
  choisir: (id: number) => void;
  basSur: number;
}) {
  const { resa, seance, ouvrir, comparer, choisir, basSur } = props;
  let contenu;
  switch (resa.cas) {
    case 'reserver':
      contenu = (
        <>
          <Pressable style={styles.reserver} onPress={() => ouvrir(resa.principale, 'principal')} accessibilityRole="link">
            <Text style={styles.reserverTexte}>{texteBouton(resa.principale)} ↗</Text>
          </Pressable>
          {resa.autres.length ? (
            <Pressable onPress={comparer} accessibilityRole="button">
              <Text style={styles.sousBouton}>Autres billetteries ({resa.autres.length}) · comparer les prix</Text>
            </Pressable>
          ) : resa.principale.billetterie ? (
            <Text style={styles.sousTexte}>Le billet est vendu par la billetterie d’origine</Text>
          ) : null}
        </>
      );
      break;
    case 'complet':
      contenu = (
        <>
          <View style={[styles.reserver, styles.reserverInactif]}>
            <Text style={[styles.reserverTexte, styles.reserverTexteInactif]}>{seance?.type === 'seance' ? `Complet à ${heureSeance(seance)}` : 'Complet'}</Text>
          </View>
          {resa.autreSeance ? (
            <Pressable onPress={() => choisir(resa.autreSeance!.representation_id)} accessibilityRole="button">
              <Text style={styles.sousBouton}>Des places restent à {heureSeance(resa.autreSeance)} · choisir cette séance</Text>
            </Pressable>
          ) : null}
        </>
      );
      break;
    case 'entree_libre':
      contenu = (
        <>
          <Text style={styles.entreeLibre}>Entrée libre</Text>
          {resa.organisateur ? (
            <Pressable onPress={() => ouvrir(resa.organisateur!, 'principal')} accessibilityRole="link">
              <Text style={styles.sousBouton}>Voir sur le site de l’organisateur ↗</Text>
            </Pressable>
          ) : null}
        </>
      );
      break;
    case 'termine':
      contenu = <Text style={styles.entreeLibre}>Ce spectacle est terminé</Text>;
      break;
    default:
      contenu = <Text style={styles.sousTexte}>Aucune billetterie n’est connue pour cette séance.</Text>;
  }

  return <View style={[styles.barre, { paddingBottom: 14 + basSur }]}>{contenu}</View>;
}

function BoutonRond({ onPress, label, children, sombre = true, style }: { onPress: () => void; label: string; children: React.ReactNode; sombre?: boolean; style?: object }) {
  return (
    <Pressable onPress={onPress} style={[styles.rond, sombre ? styles.rondSombre : styles.rondClair, style]} accessibilityRole="button" accessibilityLabel={label} hitSlop={6}>
      <Svg width={20} height={20} viewBox="0 0 24 24" fill="none" stroke={sombre ? '#FFFFFF' : Couleurs.encre} strokeWidth={2.2} strokeLinecap="round" strokeLinejoin="round">
        {children}
      </Svg>
    </Pressable>
  );
}

// Valeurs de la maquette « Fiche spectacle ».
const styles = StyleSheet.create({
  fond: { flex: 1, backgroundColor: Couleurs.carte },
  centre: { alignItems: 'center', justifyContent: 'center' },
  retourSeul: { position: 'absolute', top: 60, left: Espaces.bord },
  erreur: { alignItems: 'center', gap: 10, padding: Espaces.bord },
  erreurTexte: { fontFamily: Polices.texte, fontSize: 16, color: Couleurs.encre, textAlign: 'center' },
  entete: { paddingHorizontal: Espaces.bord, paddingBottom: 22, justifyContent: 'space-between', overflow: 'hidden' },
  enteteSombre: { backgroundColor: '#2A2A33' },
  voileImage: { backgroundColor: 'rgba(0,0,0,0.45)' },
  affiche: { backgroundColor: Couleurs.rouge },
  enteteBoutons: { flexDirection: 'row', justifyContent: 'space-between' },
  enteteTextes: { gap: 6 },
  etiquette: {
    alignSelf: 'flex-start', overflow: 'hidden', paddingVertical: 4, paddingHorizontal: 10, borderRadius: 12, backgroundColor: Couleurs.rouge,
    fontFamily: Polices.texteTresGras, fontSize: 11, letterSpacing: 0.9, textTransform: 'uppercase', color: '#FFFFFF',
  },
  titre: { fontFamily: Polices.titreFort, fontSize: 32, lineHeight: 33, letterSpacing: -0.64, color: '#FFFFFF' },
  afficheGenre: { fontFamily: Polices.texteTresGras, fontSize: 12, letterSpacing: 1.7, textTransform: 'uppercase', color: 'rgba(255,255,255,0.8)' },
  afficheTitre: { fontFamily: Polices.titreFort, fontSize: 46, lineHeight: 42, letterSpacing: -1.4, textTransform: 'uppercase', color: '#FFFFFF' },
  rond: { width: 44, height: 44, borderRadius: 22, alignItems: 'center', justifyContent: 'center' },
  rondSombre: { backgroundColor: 'rgba(0,0,0,0.35)' },
  rondClair: { borderWidth: 1, borderColor: Couleurs.trait, backgroundColor: Couleurs.carte },
  corps: { paddingTop: 16, paddingHorizontal: Espaces.bord, gap: 20 },
  mentions: { flexDirection: 'row', flexWrap: 'wrap', gap: 8 },
  mention: { height: 30, paddingHorizontal: 12, borderRadius: 15, justifyContent: 'center' },
  mentionBleue: { backgroundColor: '#E3EAFB' },
  mentionOrange: { backgroundColor: '#FCE9CF' },
  mentionTexte: { fontFamily: Polices.texteTresGras, fontSize: 13 },
  mentionTexteBleu: { color: '#1E3A8A' },
  mentionTexteOrange: { color: '#7A3E00' },
  ligneLieu: { flexDirection: 'row', alignItems: 'flex-start', gap: 8 },
  lieuTextes: { flex: 1, gap: 2 },
  lieuActions: { flexDirection: 'row', alignItems: 'center', justifyContent: 'space-between', gap: 10 },
  lieuTexte: { fontFamily: Polices.texte, fontSize: 14, color: Couleurs.gris },
  lienPetit: { fontFamily: Polices.texteGras, fontSize: 13, color: Couleurs.encre, textDecorationLine: 'underline', paddingVertical: 6 },
  autresDates: { height: 52, justifyContent: 'center', paddingHorizontal: 10 },
  feuilleHaute: { maxHeight: '85%' },
  datesListe: { gap: 18, paddingBottom: 12 },
  datesGroupe: { gap: 4 },
  datesLieu: { fontFamily: Polices.texteTresGras, fontSize: 15, color: Couleurs.encre, marginBottom: 4 },
  dateLigne: { flexDirection: 'row', alignItems: 'center', justifyContent: 'space-between', paddingVertical: 12, borderBottomWidth: StyleSheet.hairlineWidth, borderBottomColor: Couleurs.trait },
  dateTexte: { fontFamily: Polices.texteMoyen, fontSize: 15, color: Couleurs.encre },
  dateComplete: { color: Couleurs.gris },
  dateFleche: { fontFamily: Polices.texte, fontSize: 20, color: Couleurs.gris },
  lieuNom: { fontFamily: Polices.texteGras, color: Couleurs.encre },
  section: { gap: 10 },
  rubrique: { fontFamily: Polices.texteTresGras, fontSize: 13, letterSpacing: 0.8, textTransform: 'uppercase', color: Couleurs.gris },
  seances: { flexDirection: 'row', flexWrap: 'wrap', gap: 8 },
  puce: {
    height: 52, minWidth: 76, paddingHorizontal: 14, borderRadius: 12, borderWidth: 2, borderColor: Couleurs.trait,
    alignItems: 'center', justifyContent: 'center', gap: 4, backgroundColor: Couleurs.carte,
  },
  puceChoisie: { backgroundColor: Couleurs.encre, borderColor: Couleurs.encre },
  puceComplete: { borderStyle: 'dashed', borderColor: '#CFC9C1', backgroundColor: Couleurs.fond },
  puceCompleteChoisie: { borderColor: '#8C8C96', backgroundColor: Couleurs.traitCarte },
  puceHeure: { fontFamily: Polices.titre, fontSize: 18, color: Couleurs.encre },
  puceEtat: { fontFamily: Polices.texteTresGras, fontSize: 10, letterSpacing: 0.6, textTransform: 'uppercase', color: Couleurs.encre },
  puceTexteChoisi: { color: '#FFFFFF' },
  puceTexteComplet: { color: '#8C8C96' },
  prix: { flexDirection: 'row', alignItems: 'center', justifyContent: 'space-between', paddingVertical: 14, paddingHorizontal: 16, borderRadius: Espaces.rayonCarte, borderWidth: 1, borderColor: Couleurs.traitCarte },
  prixValeur: { fontFamily: Polices.texteTresGras, fontSize: 16, color: Couleurs.encre },
  histoire: { fontFamily: Polices.texte, fontSize: 15, lineHeight: 22, color: Couleurs.encre },
  lien: { fontFamily: Polices.texteTresGras, fontSize: 14, color: Couleurs.encre, textDecorationLine: 'underline', paddingVertical: 6 },
  lienFort: { fontFamily: Polices.texteTresGras, fontSize: 14, color: Couleurs.encre },
  encadre: { gap: 6, paddingVertical: 14, paddingHorizontal: 16, borderRadius: Espaces.rayonCarte, backgroundColor: Couleurs.fond },
  encadreTexte: { fontFamily: Polices.texte, fontSize: 14, lineHeight: 20, color: '#4A4A55' },
  actions: { flexDirection: 'row', gap: 18 },
  acces: { flexDirection: 'row', alignItems: 'center', justifyContent: 'space-between', gap: 12, paddingVertical: 14, paddingHorizontal: 16, borderRadius: Espaces.rayonCarte, backgroundColor: Couleurs.fond },
  accesTextes: { flex: 1, gap: 2 },
  accesAdresse: { fontFamily: Polices.texteGras, fontSize: 14, color: Couleurs.encre },
  accesSuite: { fontFamily: Polices.texte, fontSize: 14, color: Couleurs.gris },
  itineraire: { height: 44, paddingHorizontal: 14, borderRadius: 22, borderWidth: 1, borderColor: '#D9D4CD', backgroundColor: Couleurs.carte, flexDirection: 'row', alignItems: 'center', gap: 6 },
  itineraireTexte: { fontFamily: Polices.texteGras, fontSize: 14, color: Couleurs.encre },
  basFiche: { gap: 4, paddingTop: 14, borderTopWidth: 1, borderTopColor: Couleurs.traitCarte },
  source: { fontFamily: Polices.texte, fontSize: 12, lineHeight: 17, color: Couleurs.gris },
  barre: { position: 'absolute', left: 0, right: 0, bottom: 0, paddingTop: 12, paddingHorizontal: Espaces.bord, gap: 6, backgroundColor: Couleurs.carte, borderTopWidth: 1, borderTopColor: Couleurs.traitCarte },
  reserver: { height: 54, borderRadius: 27, alignItems: 'center', justifyContent: 'center', backgroundColor: Couleurs.rouge },
  reserverInactif: { backgroundColor: Couleurs.traitCarte },
  reserverTexte: { fontFamily: Polices.texteTresGras, fontSize: 16, color: '#FFFFFF' },
  reserverTexteInactif: { color: Couleurs.gris },
  sousBouton: { fontFamily: Polices.texteTresGras, fontSize: 13, color: Couleurs.encre, textDecorationLine: 'underline', textAlign: 'center', paddingVertical: 6 },
  sousTexte: { fontFamily: Polices.texte, fontSize: 12, color: Couleurs.gris, textAlign: 'center', paddingVertical: 6 },
  entreeLibre: { fontFamily: Polices.titre, fontSize: 20, color: Couleurs.encre, textAlign: 'center', paddingVertical: 10 },
  voile: { flex: 1, justifyContent: 'flex-end', backgroundColor: 'rgba(22,22,29,0.45)' },
  feuille: { gap: 14, paddingTop: 10, paddingHorizontal: Espaces.bord, borderTopLeftRadius: 22, borderTopRightRadius: 22, backgroundColor: Couleurs.carte },
  poignee: { alignSelf: 'center', width: 40, height: 4, borderRadius: 2, backgroundColor: '#D9D4CD' },
  feuilleEntete: { flexDirection: 'row', alignItems: 'center', justifyContent: 'space-between' },
  feuilleTitre: { fontFamily: Polices.titre, fontSize: 22, color: Couleurs.encre },
  option: { flexDirection: 'row', alignItems: 'center', justifyContent: 'space-between', gap: 12, paddingVertical: 14, paddingHorizontal: 16, borderRadius: Espaces.rayonCarte, borderWidth: 1, borderColor: Couleurs.trait },
  optionRecommandee: { borderWidth: 2, borderColor: Couleurs.encre },
  optionTextes: { flex: 1, gap: 3 },
  optionNom: { fontFamily: Polices.texteTresGras, fontSize: 16, color: Couleurs.encre },
  optionReco: { fontFamily: Polices.texteTresGras, fontSize: 12, color: Couleurs.rouge },
  optionPrix: { fontFamily: Polices.texteTresGras, fontSize: 16, color: Couleurs.encre },
});
