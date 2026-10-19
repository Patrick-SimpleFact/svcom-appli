import * as Location from 'expo-location';
import { router, useFocusEffect } from 'expo-router';
import * as WebBrowser from 'expo-web-browser';
import { useCallback, useEffect, useState } from 'react';
import { ActivityIndicator, Linking, Modal, Pressable, ScrollView, StyleSheet, Switch, Text, View } from 'react-native';
import { SafeAreaView, useSafeAreaInsets } from 'react-native-safe-area-context';
import Svg, { Path, Rect } from 'react-native-svg';

import { BUILD_APP, VERSION_APP } from '@/api/client';
import { modifierMoi, useCompte } from '@/compte/compte';
import {
  exporterMesDonnees, ligneCompte, lirePreferences, modifierPreferences, nombreSuivis, type Preferences, resumeGenres, supprimerMonCompte,
} from '@/compte/profil';
import { MessagesService } from '@/components/messages-service';
import { Couleurs, Espaces, Polices } from '@/constants/theme';
import { useDemarrage } from '@/demarrage/demarrage';
import { libelleRayon, useGouts } from '@/gouts/gouts';
import { choisir, useChoixSuggestion } from '@/suggestion/suggestion';
import { demanderAutorisation, etatAutorisation, transmettreJeton } from '@/notifications/notifications';

/** Onglet « Profil » (F1.6, maquette « Profil ») : sans compte ou connecté. */
export default function Profil() {
  const { configuration } = useDemarrage();
  const { utilisateur, mettreAJour, deconnecter } = useCompte();
  const { gouts } = useGouts();
  const [preferences, setPreferences] = useState<Preferences | null>(null);
  const [suivis, setSuivis] = useState<number | null>(null);
  const suggestion = useChoixSuggestion() === 'oui';
  const [localisation, setLocalisation] = useState<string>('…');
  const [notifications, setNotifications] = useState<'a_demander' | 'accordee' | 'refusee' | null>(null);
  const [suppression, setSuppression] = useState(false);
  const [message, setMessage] = useState<string | null>(null);

  // À chaque retour sur l'onglet : la localisation a pu changer dans les Réglages du téléphone.
  useFocusEffect(
    useCallback(() => {
      Location.getForegroundPermissionsAsync()
        .then((p) => setLocalisation(p.granted ? 'Autorisée' : p.canAskAgain ? 'Pas encore demandée' : 'Refusée'))
        .catch(() => setLocalisation('Inconnue'));
      etatAutorisation().then(setNotifications).catch(() => setNotifications(null));
    }, []),
  );

  useEffect(() => {
    if (!utilisateur) {
      return;
    }
    lirePreferences().then(setPreferences).catch(() => {});
    nombreSuivis().then(setSuivis).catch(() => {});
  }, [utilisateur]);

  const libelleGenre = (id: number) => configuration?.genres.find((g) => g.id === id)?.libelle ?? '';
  const ouvrirPage = (adresse?: string) => adresse && WebBrowser.openBrowserAsync(adresse);

  // Réglage permanent de la suggestion à l'ouverture (F6.2), partagé avec la question de l'accueil.
  const basculerSuggestion = (oui: boolean) => choisir(oui ? 'oui' : 'desactive');

  const basculerPreference = (champ: 'alertes_actives' | 'rappel_jour_j', valeur: boolean) => {
    setPreferences((p) => (p ? { ...p, [champ]: valeur } : p));
    modifierPreferences({ [champ]: valeur })
      .then(setPreferences)
      .catch(() => setPreferences((p) => (p ? { ...p, [champ]: !valeur } : p)));
  };

  const basculerLettre = (valeur: boolean) => {
    if (!utilisateur) {
      return;
    }
    mettreAJour({ ...utilisateur, lettre_info: valeur });
    modifierMoi({ lettre_info: valeur })
      .then(mettreAJour)
      .catch(() => mettreAJour({ ...utilisateur, lettre_info: !valeur }));
  };

  const exporter = () => {
    setMessage(null);
    exporterMesDonnees()
      .then(() => setMessage(`Vos données vont être envoyées à ${utilisateur?.email ?? 'votre adresse'}.`))
      .catch(() => setMessage('Impossible d’envoyer vos données pour le moment. Réessayez dans un instant.'));
  };

  return (
    <SafeAreaView style={styles.fond} edges={['top']}>
      <ScrollView contentContainerStyle={styles.contenu}>
        <Text style={styles.titre} accessibilityRole="header">Profil</Text>
        <MessagesService />

        {utilisateur ? (
          <View style={styles.compte}>
            <View style={styles.initiale}>
              <Text style={styles.initialeTexte}>{(utilisateur.prenom ?? utilisateur.email ?? '?').slice(0, 1).toUpperCase()}</Text>
            </View>
            <View style={styles.compteTextes}>
              <Text style={styles.compteNom}>{utilisateur.prenom ?? 'Mon compte'}</Text>
              {utilisateur.email ? <Text style={styles.compteEmail}>{utilisateur.email}</Text> : null}
              <Text style={styles.compteDetail}>{ligneCompte(utilisateur.methodes, utilisateur.naissance_mois, utilisateur.naissance_annee)}</Text>
            </View>
          </View>
        ) : (
          <View style={styles.invite}>
            <Text style={styles.inviteTitre}>Vous utilisez Spettacoli sans compte</Text>
            <Text style={styles.inviteTexte}>Créez un compte pour garder vos favoris, suivre vos lieux et artistes, et retrouver vos goûts sur tous vos appareils.</Text>
            <Pressable style={styles.inviteBouton} onPress={() => router.push({ pathname: '/connexion', params: { raison: 'profil' } })} accessibilityRole="button">
              <Text style={styles.inviteBoutonTexte}>Créer un compte ou se connecter</Text>
            </Pressable>
          </View>
        )}

        <Section titre="Mes goûts">
          <Lien libelle="Genres aimés" valeur={resumeGenres(gouts.genres, libelleGenre)} onPress={() => router.push('/gouts')} />
          <Lien libelle="Rayon par défaut" valeur={libelleRayon(gouts.rayon_m)} onPress={() => router.push('/gouts')} separe />
          {utilisateur ? <Lien libelle="Lieux et artistes suivis" valeur={suivis === null ? '' : String(suivis)} separe /> : <Verrou libelle="Lieux et artistes suivis" />}
        </Section>

        <Section titre="Réglages">
          <Interrupteur libelle="Suggestion à l’ouverture" sous="Une fois par jour au plus" valeur={suggestion} changer={basculerSuggestion} />
          {utilisateur ? (
            <>
              <Interrupteur libelle="Alertes" sous="Lieux et artistes suivis · 1 par jour à 18 h" valeur={!!preferences?.alertes_actives} changer={(v) => basculerPreference('alertes_actives', v)} separe />
              <Interrupteur libelle="Rappel le jour J" sous="Pour vos favoris" valeur={!!preferences?.rappel_jour_j} changer={(v) => basculerPreference('rappel_jour_j', v)} separe />
            </>
          ) : (
            <>
              <Verrou libelle="Alertes" />
              <Verrou libelle="Rappel le jour J" />
            </>
          )}
          <Lien libelle="Localisation" valeur={localisation} onPress={() => Linking.openSettings()} separe />
          {/* Notifications (F3.4, M12b) : demandées ici si jamais proposées, sinon réglées dans le téléphone. */}
          <Lien
            libelle="Notifications"
            valeur={notifications === 'accordee' ? 'Autorisées' : notifications === 'refusee' ? 'Refusées' : notifications === 'a_demander' ? 'Pas encore demandées' : '…'}
            onPress={() =>
              notifications === 'a_demander'
                ? demanderAutorisation().then((ok) => (ok ? transmettreJeton() : undefined)).then(() => etatAutorisation().then(setNotifications)).catch(() => {})
                : Linking.openSettings()
            }
            separe
          />
          {utilisateur ? <Interrupteur libelle="Lettre d’information" sous="Facultative" valeur={utilisateur.lettre_info} changer={basculerLettre} separe /> : null}
        </Section>

        <Section titre="Aidez-nous">
          <Lien libelle="Il manque un spectacle ou une salle ?" onPress={() => router.push({ pathname: '/piste', params: { origine: 'profil' } })} />
          {utilisateur ? <Lien libelle="Mes propositions" onPress={() => router.push('/propositions')} separe /> : null}
        </Section>

        <Section titre="Vous êtes un théâtre ?">
          <Lien libelle="Demander un espace salle" sous="Publiez vous-même votre programme" onPress={() => ouvrirPage(configuration?.liens.espace_salle)} />
        </Section>

        <Section titre="À propos">
          <Lien libelle="Sources des données" onPress={() => router.push('/sources')} />
          <Lien libelle="Conditions d’utilisation" onPress={() => ouvrirPage(configuration?.liens.conditions)} separe />
          <Lien libelle="Politique de confidentialité" onPress={() => ouvrirPage(configuration?.liens.confidentialite)} separe />
          <Lien libelle="Mentions légales" onPress={() => ouvrirPage(configuration?.liens.mentions_legales)} separe />
        </Section>

        {utilisateur ? (
          <Section titre="Compte">
            <Lien libelle="Recevoir mes données" sous="Envoyées par e-mail" onPress={exporter} />
            <Lien libelle="Se déconnecter" onPress={deconnecter} separe />
            <Lien libelle="Supprimer mon compte" onPress={() => setSuppression(true)} danger separe />
          </Section>
        ) : null}
        {message ? <Text style={styles.message} accessibilityRole="alert">{message}</Text> : null}

        <Text style={styles.version}>Spettacoli · version {VERSION_APP}{BUILD_APP ? ` (build ${BUILD_APP})` : ''}</Text>
      </ScrollView>

      <SuppressionCompte visible={suppression} fermer={() => setSuppression(false)} supprimer={async () => {
        await supprimerMonCompte();
        await deconnecter();
      }} />
    </SafeAreaView>
  );
}

/**
 * Supprimer mon compte (F1.7), en deux étapes : cette confirmation, puis l'accès est coupé tout de suite.
 * Les données sont effacées définitivement sous 30 jours.
 */
function SuppressionCompte({ visible, fermer, supprimer }: { visible: boolean; fermer: () => void; supprimer: () => Promise<void> }) {
  const marges = useSafeAreaInsets();
  const [etat, setEtat] = useState<'question' | 'envoi' | 'fait' | 'erreur'>('question');

  const confirmer = async () => {
    setEtat('envoi');
    try {
      await supprimer();
      setEtat('fait');
    } catch {
      setEtat('erreur');
    }
  };

  const terminer = () => {
    setEtat('question');
    fermer();
  };

  return (
    <Modal visible={visible} transparent animationType="slide" onRequestClose={terminer}>
      <View style={styles.voile}>
        <View style={[styles.feuille, { paddingBottom: 24 + marges.bottom }]}>
          {etat === 'fait' ? (
            <>
              <Text style={styles.feuilleTitre}>Compte supprimé</Text>
              <Text style={styles.feuilleTexte}>Votre accès est coupé. Vos données seront effacées définitivement sous 30 jours. Vous pouvez continuer à utiliser Spettacoli sans compte.</Text>
              <Pressable style={styles.boutonSombre} onPress={terminer} accessibilityRole="button">
                <Text style={styles.boutonTexte}>Fermer</Text>
              </Pressable>
            </>
          ) : (
            <>
              <Text style={styles.feuilleTitre}>Supprimer votre compte ?</Text>
              <Text style={styles.feuilleTexte}>
                Vos favoris, vos suivis et vos alertes seront perdus. L’accès est coupé tout de suite ; vos données sont effacées définitivement sous 30 jours.
              </Text>
              {etat === 'erreur' ? <Text style={styles.message}>La suppression n’a pas abouti. Réessayez dans un instant.</Text> : null}
              <Pressable style={styles.boutonDanger} onPress={confirmer} disabled={etat === 'envoi'} accessibilityRole="button">
                {etat === 'envoi' ? <ActivityIndicator color="#FFFFFF" /> : <Text style={styles.boutonTexte}>Supprimer définitivement</Text>}
              </Pressable>
              <Pressable style={styles.annuler} onPress={terminer} accessibilityRole="button">
                <Text style={styles.annulerTexte}>Annuler</Text>
              </Pressable>
            </>
          )}
        </View>
      </View>
    </Modal>
  );
}

function Section({ titre, children }: { titre: string; children: React.ReactNode }) {
  return (
    <View style={styles.section}>
      <Text style={styles.rubrique}>{titre}</Text>
      <View style={styles.bloc}>{children}</View>
    </View>
  );
}

function Lien(props: { libelle: string; sous?: string; valeur?: string; onPress?: () => void; separe?: boolean; danger?: boolean }) {
  return (
    <Pressable style={[styles.ligne, props.separe && styles.separe]} onPress={props.onPress} disabled={!props.onPress} accessibilityRole="button">
      <View style={styles.ligneTextes}>
        <Text style={[styles.ligneLibelle, props.danger && styles.danger]}>{props.libelle}</Text>
        {props.sous ? <Text style={styles.ligneSous}>{props.sous}</Text> : null}
      </View>
      {props.valeur ? <Text style={styles.ligneValeur}>{props.valeur}</Text> : null}
      {props.onPress ? (
        <Svg width={16} height={16} viewBox="0 0 24 24" fill="none" stroke={Couleurs.gris} strokeWidth={2} strokeLinecap="round" strokeLinejoin="round">
          <Path d="M9 6l6 6-6 6" />
        </Svg>
      ) : null}
    </Pressable>
  );
}

function Interrupteur(props: { libelle: string; sous: string; valeur: boolean; changer: (v: boolean) => void; separe?: boolean }) {
  return (
    <View style={[styles.ligne, props.separe && styles.separe]}>
      <View style={styles.ligneTextes}>
        <Text style={styles.ligneLibelle}>{props.libelle}</Text>
        <Text style={styles.ligneSous}>{props.sous}</Text>
      </View>
      <Switch value={props.valeur} onValueChange={props.changer} trackColor={{ false: Couleurs.trait, true: Couleurs.encre }} thumbColor={Couleurs.carte} accessibilityLabel={props.libelle} />
    </View>
  );
}

/** Réglage réservé au compte (maquette : grisé avec un cadenas, « Compte requis »). */
function Verrou({ libelle }: { libelle: string }) {
  return (
    <Pressable style={[styles.ligne, styles.separe]} onPress={() => router.push({ pathname: '/connexion', params: { raison: 'suivre' } })} accessibilityRole="button" accessibilityHint="Compte requis">
      <View style={styles.ligneTextes}>
        <Text style={[styles.ligneLibelle, styles.verrouTexte]}>{libelle}</Text>
        <Text style={styles.ligneSous}>Compte requis</Text>
      </View>
      <Svg width={16} height={16} viewBox="0 0 24 24" fill="none" stroke={Couleurs.gris} strokeWidth={2} strokeLinecap="round" strokeLinejoin="round">
        <Rect x={5} y={11} width={14} height={10} rx={2} />
        <Path d="M8 11V8a4 4 0 0 1 8 0v3" />
      </Svg>
    </Pressable>
  );
}

// Valeurs de la maquette « Profil ».
const styles = StyleSheet.create({
  fond: { flex: 1, backgroundColor: Couleurs.fond },
  contenu: { gap: 20, paddingTop: 12, paddingHorizontal: Espaces.bord, paddingBottom: 120 },
  titre: { fontFamily: Polices.titreFort, fontSize: 30, lineHeight: 32, letterSpacing: -0.6, color: Couleurs.encre },
  invite: { gap: 12, padding: 18, borderRadius: 16, backgroundColor: Couleurs.encre },
  inviteTitre: { fontFamily: Polices.texteTresGras, fontSize: 18, lineHeight: 23, color: '#FFFFFF' },
  inviteTexte: { fontFamily: Polices.texte, fontSize: 14, lineHeight: 20, color: 'rgba(255,255,255,0.8)' },
  inviteBouton: { height: 48, borderRadius: 24, alignItems: 'center', justifyContent: 'center', backgroundColor: '#FFFFFF' },
  inviteBoutonTexte: { fontFamily: Polices.texteTresGras, fontSize: 15, color: Couleurs.encre },
  compte: { flexDirection: 'row', alignItems: 'center', gap: 14, padding: 16, borderRadius: 16, borderWidth: 1, borderColor: Couleurs.traitCarte, backgroundColor: Couleurs.carte },
  initiale: { width: 52, height: 52, borderRadius: 26, alignItems: 'center', justifyContent: 'center', backgroundColor: Couleurs.rouge },
  initialeTexte: { fontFamily: Polices.titreFort, fontSize: 22, color: '#FFFFFF' },
  compteTextes: { flex: 1, gap: 2 },
  compteNom: { fontFamily: Polices.titre, fontSize: 18, color: Couleurs.encre },
  compteEmail: { fontFamily: Polices.texte, fontSize: 14, color: Couleurs.encre },
  compteDetail: { fontFamily: Polices.texte, fontSize: 12, color: Couleurs.gris },
  section: { gap: 8 },
  rubrique: { paddingHorizontal: 4, fontFamily: Polices.texteTresGras, fontSize: 13, letterSpacing: 0.8, textTransform: 'uppercase', color: Couleurs.gris },
  bloc: { borderRadius: 16, borderWidth: 1, borderColor: Couleurs.traitCarte, backgroundColor: Couleurs.carte, overflow: 'hidden' },
  ligne: { flexDirection: 'row', alignItems: 'center', gap: 10, minHeight: 60, paddingVertical: 10, paddingHorizontal: 16 },
  separe: { borderTopWidth: 1, borderTopColor: '#F0ECE6' },
  ligneTextes: { flex: 1, gap: 2 },
  ligneLibelle: { fontFamily: Polices.texteMoyen, fontSize: 15, color: Couleurs.encre },
  ligneSous: { fontFamily: Polices.texte, fontSize: 12, color: Couleurs.gris },
  ligneValeur: { fontFamily: Polices.texte, fontSize: 13, color: Couleurs.gris, maxWidth: '45%', textAlign: 'right' },
  danger: { fontFamily: Polices.texteTresGras, color: '#B42318' },
  verrouTexte: { color: Couleurs.gris },
  message: { fontFamily: Polices.texteGras, fontSize: 14, lineHeight: 20, color: Couleurs.encre },
  version: { textAlign: 'center', fontFamily: Polices.texte, fontSize: 12, color: Couleurs.gris },
  voile: { flex: 1, justifyContent: 'flex-end', backgroundColor: 'rgba(22,22,29,0.45)' },
  feuille: { gap: 14, paddingTop: 22, paddingHorizontal: Espaces.bord, borderTopLeftRadius: 22, borderTopRightRadius: 22, backgroundColor: Couleurs.carte },
  feuilleTitre: { fontFamily: Polices.titre, fontSize: 22, color: Couleurs.encre },
  feuilleTexte: { fontFamily: Polices.texte, fontSize: 15, lineHeight: 22, color: '#4A4A55' },
  boutonDanger: { height: 52, borderRadius: 26, alignItems: 'center', justifyContent: 'center', backgroundColor: '#B42318' },
  boutonSombre: { height: 52, borderRadius: 26, alignItems: 'center', justifyContent: 'center', backgroundColor: Couleurs.encre },
  boutonTexte: { fontFamily: Polices.texteTresGras, fontSize: 16, color: '#FFFFFF' },
  annuler: { height: 44, alignItems: 'center', justifyContent: 'center' },
  annulerTexte: { fontFamily: Polices.texteGras, fontSize: 15, color: Couleurs.encre },
});
