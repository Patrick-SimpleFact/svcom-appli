import { router, useLocalSearchParams } from 'expo-router';
import * as WebBrowser from 'expo-web-browser';
import { useState } from 'react';
import { ActivityIndicator, KeyboardAvoidingView, Platform, Pressable, ScrollView, StyleSheet, Text, TextInput, View } from 'react-native';
import { useSafeAreaInsets } from 'react-native-safe-area-context';
import Svg, { Path } from 'react-native-svg';

import { ErreurApi } from '@/api/client';
import { ajouterFavori, demanderCode, messageErreur, modifierMoi, tropJeune, useCompte, verifierCode } from '@/compte/compte';
import { Couleurs, Espaces, Polices } from '@/constants/theme';
import { useDemarrage } from '@/demarrage/demarrage';
import { lireGouts, useGouts } from '@/gouts/gouts';

type Etape = 'email' | 'code' | 'profil' | 'trop_jeune';

/** Pourquoi on propose le compte, au moment où il sert (F1.3). */
const RAISONS: Record<string, string> = {
  favori: 'Créez un compte pour retrouver vos favoris sur tous vos appareils.',
  suivre: 'Créez un compte pour être prévenu des nouvelles dates.',
  profil: 'Retrouvez vos goûts et vos favoris sur tous vos appareils.',
};

/**
 * Créer un compte ou se connecter (F1.3–F1.5), sans mot de passe : un code à 6 chiffres reçu par e-mail.
 * Puis, une seule fois : prénom, mois et année de naissance, conditions. L'action en cours (le favori) est ensuite terminée.
 */
export default function Connexion() {
  const params = useLocalSearchParams<{ raison?: string; spectacle?: string; representation?: string }>();
  const marges = useSafeAreaInsets();
  const { configuration } = useDemarrage();
  const { gouts, modifierGouts } = useGouts();
  const { connecter, mettreAJour, deconnecter } = useCompte();
  const [etape, setEtape] = useState<Etape>('email');
  const [email, setEmail] = useState('');
  const [code, setCode] = useState('');
  const [prenom, setPrenom] = useState('');
  const [mois, setMois] = useState('');
  const [annee, setAnnee] = useState('');
  const [cgu, setCgu] = useState(false);
  const [lettre, setLettre] = useState(false);
  const [envoi, setEnvoi] = useState(false);
  const [erreur, setErreur] = useState<string | null>(null);

  /** Après la connexion : on termine ce que l'utilisateur voulait faire, sans repasser par la fiche (F1.3). */
  const terminer = async () => {
    if (params.raison === 'favori' && params.spectacle) {
      await ajouterFavori(Number(params.spectacle), params.representation ? Number(params.representation) : undefined).catch(() => {});
    }
    router.back();
  };

  const agir = async (action: () => Promise<void>) => {
    setEnvoi(true);
    setErreur(null);
    try {
      await action();
    } catch (e) {
      setErreur(messageErreur(e));
    } finally {
      setEnvoi(false);
    }
  };

  const envoyerCode = () =>
    agir(async () => {
      await demanderCode(email.trim());
      setCode('');
      setEtape('code');
    });

  const validerCode = () =>
    agir(async () => {
      const connexion = await verifierCode(email.trim(), code, gouts);
      await connecter(connexion);
      // Goûts du compte retrouvés sur ce téléphone (F1.2) ; ceux du mode invité ont été repris si le compte n'en avait pas.
      const prefs = connexion.utilisateur.preferences;
      if (prefs && (prefs.genres.length || prefs.rayon_m !== null)) {
        modifierGouts(lireGouts(JSON.stringify(prefs)));
      }
      if (connexion.utilisateur.profil_complet) {
        await terminer();
      } else {
        setPrenom(connexion.utilisateur.prenom ?? '');
        setEtape('profil');
      }
    });

  const moisNb = Number(mois);
  const anneeNb = Number(annee);
  const profilValide = prenom.trim().length > 0 && moisNb >= 1 && moisNb <= 12 && anneeNb >= 1900 && anneeNb <= new Date().getFullYear() && cgu;

  const enregistrerProfil = () =>
    agir(async () => {
      if (tropJeune(moisNb, anneeNb, new Date())) {
        // Le BO refuse aussi et efface le compte tout juste créé (F1.4).
        await modifierMoi({ naissance_mois: moisNb, naissance_annee: anneeNb }).catch(() => {});
        await deconnecter();
        setEtape('trop_jeune');
        return;
      }
      try {
        mettreAJour(await modifierMoi({ prenom: prenom.trim(), naissance_mois: moisNb, naissance_annee: anneeNb, cgu_acceptees: true, lettre_info: lettre }));
      } catch (e) {
        if (e instanceof ErreurApi && e.code === 'age_minimum') {
          await deconnecter();
          setEtape('trop_jeune');
          return;
        }
        throw e;
      }
      await terminer();
    });

  const liens = configuration?.liens;

  return (
    <KeyboardAvoidingView style={styles.fond} behavior={Platform.OS === 'ios' ? 'padding' : undefined}>
      <View style={styles.entete}>
        <Pressable onPress={() => router.back()} hitSlop={12} accessibilityRole="button">
          <Text style={styles.fermer}>Fermer</Text>
        </Pressable>
      </View>
      <ScrollView contentContainerStyle={[styles.contenu, { paddingBottom: 24 + marges.bottom }]} keyboardShouldPersistTaps="handled">
        {etape === 'email' ? (
          <>
            <Text style={styles.titre} accessibilityRole="header">Créer un compte ou se connecter</Text>
            <Text style={styles.texte}>{RAISONS[params.raison ?? 'profil'] ?? RAISONS.profil}</Text>
            <Champ libelle="Votre e-mail" valeur={email} changer={setEmail} clavier="email-address" contenu="emailAddress" valider={envoyerCode} />
            <Text style={styles.aide}>Pas de mot de passe : nous vous envoyons un code à 6 chiffres.</Text>
            <Bouton texte="Recevoir un code" actif={/^\S+@\S+\.\S+$/.test(email.trim())} envoi={envoi} onPress={envoyerCode} />
          </>
        ) : etape === 'code' ? (
          <>
            <Text style={styles.titre} accessibilityRole="header">Entrez le code</Text>
            <Text style={styles.texte}>Un code à 6 chiffres vient d’être envoyé à {email.trim()}. Il est valable 10 minutes.</Text>
            <Champ libelle="Code" valeur={code} changer={(t) => setCode(t.replace(/\D/g, '').slice(0, 6))} clavier="number-pad" contenu="oneTimeCode" valider={validerCode} grand />
            <Bouton texte="Valider" actif={code.length === 6} envoi={envoi} onPress={validerCode} />
            <View style={styles.liensBas}>
              <Pressable onPress={envoyerCode} accessibilityRole="button">
                <Text style={styles.lien}>Renvoyer le code</Text>
              </Pressable>
              <Pressable onPress={() => setEtape('email')} accessibilityRole="button">
                <Text style={styles.lien}>Changer d’e-mail</Text>
              </Pressable>
            </View>
          </>
        ) : etape === 'profil' ? (
          <>
            <Text style={styles.titre} accessibilityRole="header">Faisons connaissance</Text>
            <Text style={styles.texte}>Votre prénom seulement, et votre mois de naissance : Spettacoli est réservé aux 15 ans et plus.</Text>
            <Champ libelle="Prénom" valeur={prenom} changer={setPrenom} contenu="givenName" />
            <View style={styles.ligne}>
              <View style={styles.colonne}>
                <Champ libelle="Mois (1 à 12)" valeur={mois} changer={(t) => setMois(t.replace(/\D/g, '').slice(0, 2))} clavier="number-pad" />
              </View>
              <View style={styles.colonne}>
                <Champ libelle="Année" valeur={annee} changer={(t) => setAnnee(t.replace(/\D/g, '').slice(0, 4))} clavier="number-pad" />
              </View>
            </View>
            <Case coche={cgu} basculer={() => setCgu(!cgu)}>
              <Text style={styles.caseTexte}>
                J’accepte les{' '}
                <Text style={styles.lienFort} onPress={() => liens?.conditions && WebBrowser.openBrowserAsync(liens.conditions)}>conditions d’utilisation</Text> et j’ai lu la{' '}
                <Text style={styles.lienFort} onPress={() => liens?.confidentialite && WebBrowser.openBrowserAsync(liens.confidentialite)}>politique de confidentialité</Text>.
              </Text>
            </Case>
            <Case coche={lettre} basculer={() => setLettre(!lettre)}>
              <Text style={styles.caseTexte}>Je veux recevoir la lettre d’information de Spettacoli (facultatif).</Text>
            </Case>
            <Bouton texte="Terminer" actif={profilValide} envoi={envoi} onPress={enregistrerProfil} />
          </>
        ) : (
          <>
            <Text style={styles.titre} accessibilityRole="header">Pas encore de compte</Text>
            <Text style={styles.texte}>
              Les comptes Spettacoli sont réservés aux 15 ans et plus. Rien n’a été conservé. Vous pouvez continuer à utiliser l’app sans compte : la recherche, les fiches
              et les réservations restent disponibles.
            </Text>
            <Bouton texte="Continuer sans compte" actif envoi={false} onPress={() => router.back()} />
          </>
        )}
        {erreur ? <Text style={styles.erreur} accessibilityRole="alert">{erreur}</Text> : null}
      </ScrollView>
    </KeyboardAvoidingView>
  );
}

function Champ(props: {
  libelle: string;
  valeur: string;
  changer: (t: string) => void;
  clavier?: 'email-address' | 'number-pad';
  contenu?: 'emailAddress' | 'oneTimeCode' | 'givenName';
  valider?: () => void;
  grand?: boolean;
}) {
  return (
    <View style={styles.champBloc}>
      <Text style={styles.libelle}>{props.libelle}</Text>
      <TextInput
        style={[styles.champ, props.grand && styles.champGrand]}
        value={props.valeur}
        onChangeText={props.changer}
        keyboardType={props.clavier}
        textContentType={props.contenu}
        autoComplete={props.contenu === 'oneTimeCode' ? 'one-time-code' : props.contenu === 'emailAddress' ? 'email' : props.contenu === 'givenName' ? 'given-name' : undefined}
        autoCapitalize={props.contenu === 'givenName' ? 'words' : 'none'}
        autoCorrect={false}
        autoFocus
        onSubmitEditing={props.valider}
        accessibilityLabel={props.libelle}
      />
    </View>
  );
}

function Case({ coche, basculer, children }: { coche: boolean; basculer: () => void; children: React.ReactNode }) {
  return (
    <Pressable style={styles.case} onPress={basculer} accessibilityRole="checkbox" accessibilityState={{ checked: coche }}>
      <View style={[styles.carre, coche && styles.carreCoche]}>
        {coche ? (
          <Svg width={14} height={14} viewBox="0 0 24 24" fill="none" stroke="#FFFFFF" strokeWidth={3} strokeLinecap="round" strokeLinejoin="round">
            <Path d="M5 12l5 5 9-10" />
          </Svg>
        ) : null}
      </View>
      <View style={styles.caseContenu}>{children}</View>
    </Pressable>
  );
}

function Bouton({ texte, actif, envoi, onPress }: { texte: string; actif: boolean; envoi: boolean; onPress: () => void }) {
  return (
    <Pressable style={[styles.bouton, !actif && styles.boutonInactif]} onPress={onPress} disabled={!actif || envoi} accessibilityRole="button" accessibilityState={{ disabled: !actif }}>
      {envoi ? <ActivityIndicator color="#FFFFFF" /> : <Text style={styles.boutonTexte}>{texte}</Text>}
    </Pressable>
  );
}

const styles = StyleSheet.create({
  fond: { flex: 1, backgroundColor: Couleurs.fond },
  entete: { flexDirection: 'row', justifyContent: 'flex-end', padding: Espaces.bord },
  fermer: { fontFamily: Polices.texteGras, fontSize: 16, color: Couleurs.gris },
  contenu: { gap: 16, paddingHorizontal: Espaces.bord },
  titre: { fontFamily: Polices.titreFort, fontSize: 30, lineHeight: 32, letterSpacing: -0.6, color: Couleurs.encre },
  texte: { fontFamily: Polices.texte, fontSize: 15, lineHeight: 22, color: Couleurs.gris },
  aide: { fontFamily: Polices.texte, fontSize: 13, color: Couleurs.gris },
  champBloc: { gap: 6 },
  libelle: { fontFamily: Polices.texteGras, fontSize: 14, color: Couleurs.encre },
  champ: {
    height: 50, paddingHorizontal: 14, borderRadius: Espaces.rayonCarte, borderWidth: 1, borderColor: Couleurs.trait, backgroundColor: Couleurs.carte,
    fontFamily: Polices.texte, fontSize: 16, color: Couleurs.encre,
  },
  champGrand: { height: 60, fontFamily: Polices.titre, fontSize: 28, letterSpacing: 8, textAlign: 'center' },
  ligne: { flexDirection: 'row', gap: 12 },
  colonne: { flex: 1 },
  case: { flexDirection: 'row', alignItems: 'flex-start', gap: 12 },
  carre: { width: 24, height: 24, marginTop: 1, borderRadius: 6, borderWidth: 2, borderColor: Couleurs.trait, backgroundColor: Couleurs.carte, alignItems: 'center', justifyContent: 'center' },
  carreCoche: { borderColor: Couleurs.encre, backgroundColor: Couleurs.encre },
  caseContenu: { flex: 1 },
  caseTexte: { fontFamily: Polices.texte, fontSize: 14, lineHeight: 20, color: Couleurs.encre },
  lienFort: { fontFamily: Polices.texteTresGras, textDecorationLine: 'underline' },
  bouton: { height: 54, borderRadius: 27, alignItems: 'center', justifyContent: 'center', backgroundColor: Couleurs.encre },
  boutonInactif: { opacity: 0.35 },
  boutonTexte: { fontFamily: Polices.texteTresGras, fontSize: 16, color: '#FFFFFF' },
  liensBas: { flexDirection: 'row', justifyContent: 'space-between' },
  lien: { fontFamily: Polices.texteGras, fontSize: 14, color: Couleurs.encre, textDecorationLine: 'underline', paddingVertical: 8 },
  erreur: { fontFamily: Polices.texteGras, fontSize: 14, lineHeight: 20, color: Couleurs.rouge },
});
