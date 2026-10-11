import { useEffect, useState } from 'react';
import { StyleSheet, Text, View } from 'react-native';

import { API_URL, apiJoignable } from '@/api/client';
import { AVenir, Ecran } from '@/components/ecran';
import { Couleurs, Polices } from '@/constants/theme';

/** Onglet « Autour de moi » (maquette « Accueil autour de moi ») : la liste viendra en M04-M05. */
export default function AutourDeMoi() {
  const [api, setApi] = useState<'test' | 'ok' | 'ko'>('test');

  useEffect(() => {
    apiJoignable().then((ok) => setApi(ok ? 'ok' : 'ko'));
  }, []);

  return (
    <Ecran titre={'Ce soir,\nprès de vous'}>
      <AVenir texte="La liste des spectacles autour de vous arrive aux étapes M04 et M05." />
      <View style={styles.etat} accessibilityLiveRegion="polite">
        <View style={[styles.pastille, { backgroundColor: api === 'ok' ? '#1F8A4C' : api === 'ko' ? Couleurs.rouge : Couleurs.trait }]} />
        <Text style={styles.texte}>
          {api === 'test' ? 'Connexion au back-office…' : api === 'ok' ? 'Back-office joignable' : 'Back-office injoignable'}
          {'\n'}
          <Text style={styles.adresse}>{API_URL}</Text>
        </Text>
      </View>
    </Ecran>
  );
}

const styles = StyleSheet.create({
  etat: { flexDirection: 'row', alignItems: 'center', gap: 10, padding: 14, borderRadius: 14, backgroundColor: Couleurs.carte, borderWidth: 1, borderColor: Couleurs.traitCarte },
  pastille: { width: 12, height: 12, borderRadius: 6 },
  texte: { fontFamily: Polices.texteGras, fontSize: 15, color: Couleurs.encre, lineHeight: 21 },
  adresse: { fontFamily: Polices.texte, fontSize: 13, color: Couleurs.gris },
});
