import { BricolageGrotesque_700Bold, BricolageGrotesque_800ExtraBold } from '@expo-google-fonts/bricolage-grotesque';
import { DMSans_400Regular, DMSans_500Medium, DMSans_600SemiBold, DMSans_700Bold } from '@expo-google-fonts/dm-sans';
import { useFonts } from 'expo-font';
import { DefaultTheme, Stack, ThemeProvider } from 'expo-router';
import * as SplashScreen from 'expo-splash-screen';
import { StatusBar } from 'expo-status-bar';
import { useEffect } from 'react';

import { EcranMiseAJour } from '@/components/mise-a-jour';
import { PremierLancement } from '@/components/premier-lancement';
import { Couleurs } from '@/constants/theme';
import { CompteProvider } from '@/compte/compte';
import { FavorisProvider } from '@/favoris/favoris';
import { Demarrage, useDemarrage } from '@/demarrage/demarrage';
import { GoutsProvider, useGouts } from '@/gouts/gouts';

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
        <GoutsProvider>
          <CompteProvider>
            <FavorisProvider>
              <Contenu />
            </FavorisProvider>
          </CompteProvider>
        </GoutsProvider>
      </Demarrage>
    </ThemeProvider>
  );
}

/** Toute l'app, sauf si le BO exige une version plus récente (F2.8) ; bienvenue et goûts au premier lancement (F1.1). */
function Contenu() {
  const { versionObsolete } = useDemarrage();
  const { charge, premierLancementFait } = useGouts();

  if (versionObsolete) {
    return <EcranMiseAJour />;
  }
  if (!charge) {
    return null;
  }

  // Onglets, et par-dessus les écrans ouverts depuis une carte (fiche spectacle, F5).
  return premierLancementFait ? (
    <Stack screenOptions={{ headerShown: false }}>
      {/* Connexion (F1.3) : une feuille par-dessus l'écran en cours, qu'on referme pour y revenir. */}
      <Stack.Screen name="connexion" options={{ presentation: 'modal' }} />
      <Stack.Screen name="gouts" options={{ presentation: 'modal' }} />
      <Stack.Screen name="sources" options={{ presentation: 'modal' }} />
    </Stack>
  ) : (
    <PremierLancement />
  );
}
