import { router, useLocalSearchParams } from 'expo-router';
import * as WebBrowser from 'expo-web-browser';
import { useEffect, useState } from 'react';
import { ActivityIndicator, KeyboardAvoidingView, Platform, Pressable, ScrollView, StyleSheet, Switch, Text, TextInput, View } from 'react-native';
import { useSafeAreaInsets } from 'react-native-safe-area-context';

import { ErreurApi } from '@/api/client';
import { chercherVilles } from '@/autour/ville';
import { ChoixVille } from '@/components/choix-ville';
import { useCompte } from '@/compte/compte';
import { envoyerPiste, lieuConnu, normaliserLien, type ReponsePiste, TYPES_PISTE, type TypePiste } from '@/contributions/contributions';
import { Couleurs, Espaces, Polices } from '@/constants/theme';
import { useDemarrage } from '@/demarrage/demarrage';

/**
 * Proposer une piste (F8, maquette « Proposer une piste ») : une salle, un spectacle ou une billetterie qui manque.
 * Ouvert depuis la couverture faible, le bas des listes, la recherche sans résultat, une page lieu ou le Profil, pré-rempli.
 */
export default function ProposerPiste() {
  const params = useLocalSearchParams<{ ville?: string; ville_id?: string; nom?: string; type?: TypePiste }>();
  const marges = useSafeAreaInsets();
  const { utilisateur } = useCompte();
  const { configuration } = useDemarrage();
  const [type, setType] = useState<TypePiste>(params.type ?? 'salle');
  const [ville, setVille] = useState<{ id: number; nom: string } | null>(params.ville_id && params.ville ? { id: Number(params.ville_id), nom: params.ville } : null);
  const [choixVille, setChoixVille] = useState(false);
  const [nom, setNom] = useState(params.nom ?? '');
  const [lien, setLien] = useState('');
  const [commentaire, setCommentaire] = useState('');
  const [email, setEmail] = useState(utilisateur?.email ?? '');
  const [travaille, setTravaille] = useState(false);
  const [connu, setConnu] = useState<{ id: number; nom: string; ville: string | null } | null>(null);
  const [envoi, setEnvoi] = useState(false);
  const [erreur, setErreur] = useState<string | null>(null);
  const [envoyee, setEnvoyee] = useState<ReponsePiste | null>(null);

  // Ville connue seulement par son nom (accueil autour de la position) : retrouvée parmi les villes proposées.
  useEffect(() => {
    if (ville || !params.ville) {
      return;
    }
    let actif = true;
    chercherVilles(params.ville)
      .then((villes) => {
        const trouvee = villes.find((v) => v.nom === params.ville) ?? villes[0];
        if (actif && trouvee) {
          setVille({ id: trouvee.id, nom: trouvee.nom });
        }
      })
      .catch(() => {});

    return () => {
      actif = false;
    };
  }, [params.ville, ville]);

  // Salle déjà connue ? On le dit pendant la frappe (F8.2).
  useEffect(() => {
    if (type !== 'salle' || !ville || nom.trim().length < 3) {
      return;
    }
    let actif = true;
    const minuteur = setTimeout(() => {
      lieuConnu(nom.trim(), ville.id)
        .then((l) => actif && setConnu(l))
        .catch(() => {});
    }, 300);

    return () => {
      actif = false;
      clearTimeout(minuteur);
    };
  }, [type, ville, nom]);

  const lienValide = lien.trim() === '' || lien.trim() === 'https://' || normaliserLien(lien) !== null;
  const villeRequise = type !== 'billetterie_ou_agenda';
  const valide = nom.trim().length >= 2 && (!villeRequise || ville !== null) && lienValide;

  const envoyer = async () => {
    setEnvoi(true);
    setErreur(null);
    try {
      setEnvoyee(
        await envoyerPiste({
          type,
          ville_id: ville?.id,
          nom: nom.trim(),
          lien: normaliserLien(lien) ?? undefined,
          commentaire: commentaire.trim() || undefined,
          email: email.trim() || undefined,
          travaille_pour_le_lieu: travaille || undefined,
        }),
      );
    } catch (e) {
      setErreur(
        e instanceof ErreurApi && e.code === 'limite_atteinte'
          ? 'Vous avez déjà envoyé plusieurs propositions aujourd’hui. Merci ! Réessayez demain.'
          : e instanceof ErreurApi && e.code === 'donnees_invalides'
            ? 'Vérifiez les informations saisies.'
            : 'L’envoi n’a pas abouti. Réessayez dans un instant.',
      );
    } finally {
      setEnvoi(false);
    }
  };

  if (envoyee) {
    return (
      <View style={[styles.fond, styles.merci, { paddingBottom: 24 + marges.bottom }]}>
        <Text style={styles.titre} accessibilityRole="header">Merci !</Text>
        <Text style={styles.texte}>
          Nous étudions chaque proposition. Nous ne pouvons pas toutes les intégrer, mais chacune nous aide à savoir où chercher.
          {envoyee.reponse_possible ? ' Nous vous tiendrons au courant de la suite donnée à votre proposition.' : ''}
        </Text>
        {travaille && type === 'salle' ? (
          // F8.2 : quelqu'un qui travaille pour le lieu peut publier lui-même son programme (espace salle, F9).
          <Pressable style={styles.secondaire} onPress={() => configuration?.liens.espace_salle && WebBrowser.openBrowserAsync(configuration.liens.espace_salle)} accessibilityRole="link">
            <Text style={styles.secondaireTexte}>Demander un espace salle pour ce lieu ↗</Text>
          </Pressable>
        ) : null}
        <Pressable style={styles.bouton} onPress={() => router.back()} accessibilityRole="button">
          <Text style={styles.boutonTexte}>Fermer</Text>
        </Pressable>
      </View>
    );
  }

  return (
    <KeyboardAvoidingView style={styles.fond} behavior={Platform.OS === 'ios' ? 'padding' : undefined}>
      <View style={styles.entete}>
        <Pressable onPress={() => router.back()} hitSlop={12} accessibilityRole="button">
          <Text style={styles.fermer}>Fermer</Text>
        </Pressable>
      </View>
      <ScrollView contentContainerStyle={[styles.contenu, { paddingBottom: 24 + marges.bottom }]} keyboardShouldPersistTaps="handled">
        <Text style={styles.titre} accessibilityRole="header">Aidez-nous à couvrir votre ville</Text>
        <Text style={styles.texte}>Une salle, un festival, une billetterie qui manque ? Dites-nous où regarder.</Text>

        <Champ libelle="Que voulez-vous nous signaler ?">
          <View style={styles.puces}>
            {TYPES_PISTE.map((t) => (
              <Pressable key={t.type} style={[styles.puce, type === t.type && styles.puceActive]} onPress={() => setType(t.type)} accessibilityRole="button" accessibilityState={{ selected: type === t.type }}>
                <Text style={[styles.puceTexte, type === t.type && styles.puceTexteActive]}>{t.libelle}</Text>
              </Pressable>
            ))}
          </View>
        </Champ>

        <Champ libelle="Ville" facultatif={!villeRequise}>
          {choixVille || !ville ? (
            <ChoixVille
              choisir={(v) => {
                setVille({ id: v.id, nom: v.nom });
                setChoixVille(false);
              }}
            />
          ) : (
            <Pressable style={[styles.saisie, styles.saisieVille]} onPress={() => setChoixVille(true)} accessibilityRole="button" accessibilityHint="Changer de ville">
              <Text style={styles.saisieTexte}>{ville.nom}</Text>
              <Text style={styles.changer}>Changer</Text>
            </Pressable>
          )}
        </Champ>

        <Champ libelle={type === 'billetterie_ou_agenda' ? 'Nom du site ou de la billetterie' : 'Salle, festival ou spectacle'}>
          <TextInput style={styles.saisie} value={nom} onChangeText={(t) => { setNom(t); setConnu(null); }} placeholder="Ex. : le nom de la salle" placeholderTextColor={Couleurs.gris} />
          {connu ? (
            <Pressable onPress={() => router.push({ pathname: '/lieu/[id]', params: { id: String(connu.id) } })} accessibilityRole="link">
              <Text style={styles.connu}>
                {connu.nom} est déjà dans Spettacoli · <Text style={styles.connuLien}>Voir ses spectacles</Text>. Vous pouvez quand même nous écrire s’il en manque.
              </Text>
            </Pressable>
          ) : null}
        </Champ>

        <Champ libelle="Lien vers sa billetterie ou son site" facultatif>
          <TextInput style={styles.saisie} value={lien} onChangeText={setLien} placeholder="https://" placeholderTextColor={Couleurs.gris} autoCapitalize="none" autoCorrect={false} keyboardType="url" />
          {!lienValide ? <Text style={styles.erreur}>Ce n’est pas une adresse web (ex. : https://www.theatre.fr).</Text> : null}
        </Champ>

        <Champ libelle="Un mot pour nous" facultatif>
          <TextInput
            style={[styles.saisie, styles.zone]}
            value={commentaire}
            onChangeText={(t) => setCommentaire(t.slice(0, 500))}
            placeholder="Ex. : ils jouent tous les vendredis"
            placeholderTextColor={Couleurs.gris}
            multiline
          />
        </Champ>

        <Champ libelle="Votre e-mail, pour être prévenu" facultatif>
          <TextInput style={styles.saisie} value={email} onChangeText={setEmail} placeholder="vous@exemple.fr" placeholderTextColor={Couleurs.gris} autoCapitalize="none" autoCorrect={false} keyboardType="email-address" />
        </Champ>

        {type === 'salle' ? (
          <View style={styles.ligne}>
            <Text style={styles.ligneTexte}>Je travaille pour ce lieu</Text>
            <Switch value={travaille} onValueChange={setTravaille} trackColor={{ true: Couleurs.encre }} accessibilityLabel="Je travaille pour ce lieu" />
          </View>
        ) : null}

        <Text style={styles.aide}>Votre piste n’est pas publiée telle quelle : notre équipe l’étudie avant d’ajouter la source.</Text>
        {erreur ? <Text style={styles.erreur} accessibilityRole="alert">{erreur}</Text> : null}
        <Pressable style={[styles.envoyer, !valide && styles.inactif]} onPress={envoyer} disabled={!valide || envoi} accessibilityRole="button" accessibilityState={{ disabled: !valide }}>
          {envoi ? <ActivityIndicator color="#FFFFFF" /> : <Text style={styles.boutonTexte}>Envoyer la piste</Text>}
        </Pressable>
      </ScrollView>
    </KeyboardAvoidingView>
  );
}

function Champ({ libelle, facultatif, children }: { libelle: string; facultatif?: boolean; children: React.ReactNode }) {
  return (
    <View style={styles.champ}>
      <Text style={styles.libelle}>
        {libelle}
        {facultatif ? <Text style={styles.facultatif}> (facultatif)</Text> : null}
      </Text>
      {children}
    </View>
  );
}

// Valeurs de la maquette « Proposer une piste ».
const styles = StyleSheet.create({
  fond: { flex: 1, backgroundColor: Couleurs.fond },
  entete: { flexDirection: 'row', justifyContent: 'flex-end', padding: Espaces.bord },
  fermer: { fontFamily: Polices.texteGras, fontSize: 16, color: Couleurs.gris },
  contenu: { gap: 16, paddingHorizontal: Espaces.bord },
  titre: { fontFamily: Polices.titreFort, fontSize: 28, lineHeight: 30, letterSpacing: -0.6, color: Couleurs.encre },
  texte: { fontFamily: Polices.texte, fontSize: 15, lineHeight: 22, color: '#4A4A55' },
  champ: { gap: 6 },
  libelle: { fontFamily: Polices.texteTresGras, fontSize: 14, color: Couleurs.encre },
  facultatif: { fontFamily: Polices.texte, color: Couleurs.gris },
  saisie: { minHeight: 48, paddingHorizontal: 14, borderRadius: Espaces.rayonCarte, borderWidth: 1, borderColor: Couleurs.trait, backgroundColor: Couleurs.carte, fontFamily: Polices.texte, fontSize: 15, color: Couleurs.encre },
  saisieVille: { flexDirection: 'row', alignItems: 'center', justifyContent: 'space-between', backgroundColor: '#F2EFEA' },
  saisieTexte: { fontFamily: Polices.texte, fontSize: 15, color: Couleurs.encre },
  changer: { fontFamily: Polices.texteGras, fontSize: 13, color: Couleurs.gris, textDecorationLine: 'underline' },
  zone: { minHeight: 70, paddingTop: 12, textAlignVertical: 'top' },
  puces: { flexDirection: 'row', flexWrap: 'wrap', gap: 8 },
  puce: { height: 38, paddingHorizontal: 14, borderRadius: 19, borderWidth: 1, borderColor: Couleurs.trait, backgroundColor: Couleurs.carte, justifyContent: 'center' },
  puceActive: { borderColor: Couleurs.encre, backgroundColor: Couleurs.encre },
  puceTexte: { fontFamily: Polices.texteMoyen, fontSize: 14, color: Couleurs.encre },
  puceTexteActive: { fontFamily: Polices.texteGras, color: '#FFFFFF' },
  connu: { fontFamily: Polices.texte, fontSize: 13, lineHeight: 19, color: '#0F5A43' },
  connuLien: { fontFamily: Polices.texteTresGras, textDecorationLine: 'underline' },
  ligne: { flexDirection: 'row', alignItems: 'center', justifyContent: 'space-between', gap: 12 },
  ligneTexte: { flex: 1, fontFamily: Polices.texteGras, fontSize: 15, color: Couleurs.encre },
  aide: { fontFamily: Polices.texte, fontSize: 12, lineHeight: 17, color: '#4A4A55' },
  erreur: { fontFamily: Polices.texteGras, fontSize: 13, lineHeight: 19, color: Couleurs.rouge },
  envoyer: { height: 54, borderRadius: 27, alignItems: 'center', justifyContent: 'center', backgroundColor: Couleurs.rouge },
  inactif: { opacity: 0.4 },
  boutonTexte: { fontFamily: Polices.texteTresGras, fontSize: 16, color: '#FFFFFF' },
  merci: { justifyContent: 'center', gap: 18, paddingHorizontal: Espaces.bord },
  bouton: { height: 54, borderRadius: 27, alignItems: 'center', justifyContent: 'center', backgroundColor: Couleurs.encre },
  secondaire: { paddingVertical: 8 },
  secondaireTexte: { fontFamily: Polices.texteTresGras, fontSize: 15, color: Couleurs.encre, textDecorationLine: 'underline' },
});
