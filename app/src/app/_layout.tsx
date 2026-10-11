import { BricolageGrotesque_700Bold, BricolageGrotesque_800ExtraBold } from '@expo-google-fonts/bricolage-grotesque';
import { DMSans_400Regular, DMSans_500Medium, DMSans_600SemiBold, DMSans_700Bold } from '@expo-google-fonts/dm-sans';
import { useFonts } from 'expo-font';
import { DefaultTheme, ThemeProvider } from 'expo-router';
import * as SplashScreen from 'expo-splash-screen';
import { StatusBar } from 'expo-status-bar';
import { useEffect } from 'react';

import AppTabs from '@/components/app-tabs';
import { EcranMiseAJour } from '@/components/mise-a-jour';
import { Couleurs } from '@/constants/theme';
import { Demarrage, useDemarrage } from '@/demarrage/demarrage';

SplashScreen.preventAutoHideAsync();

/** Thème de navigation aux couleurs des maquettes (fond crème, accent rouge). */
const theme = {
  ...DefaultTheme,
  colors: { ...DefaultTheme.colors, background: Couleurs.fond, card: Couleurs.carte, text: Couleurs.encre, primary: Couleurs.rouge, border: Couleurs.trait },
};

export default function Racine() {
  // Polices des maquettes (SIL Open Font License), embarquées dans l'app.
  const [chargees, erreur] = useFonts({
    BricolageGrotesque_700Bold, BricolageGrotesque_800ExtraBold, DMSans_400Regular, DMSans_500Medium, DMSans_600SemiBold, DMSans_700Bold,
  });

  useEffect(() => {
    if (chargees || erreur) {
      SplashScreen.hideAsync();
    }
  }, [chargees, erreur]);

  if (!chargees && !erreur) {
    return null;
  }

  return (
    <ThemeProvider value={theme}>
      <StatusBar style="dark" />
      <Demarrage>
        <Contenu />
      </Demarrage>
    </ThemeProvider>
  );
}

/** Toute l'app, sauf si le BO exige une version plus récente (F2.8). */
function Contenu() {
  const { versionObsolete } = useDemarrage();

  return versionObsolete ? <EcranMiseAJour /> : <AppTabs />;
}
