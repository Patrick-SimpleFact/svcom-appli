/// <reference types="jest" />
import AsyncStorage from '@react-native-async-storage/async-storage';
import { fireEvent, render, screen } from '@testing-library/react-native';
import type { ReactNode } from 'react';
import { SafeAreaProvider } from 'react-native-safe-area-context';

import { PremierLancement } from '@/components/premier-lancement';
import { GoutsProvider, libelleRayon, lireGouts, useGouts } from '@/gouts/gouts';

const configuration = {
  genres: [
    { id: 1, slug: 'theatre', libelle: 'Théâtre' },
    { id: 2, slug: 'humour', libelle: 'Humour' },
    { id: 5, slug: 'danse', libelle: 'Danse' },
  ],
};

jest.mock('@/demarrage/demarrage', () => ({
  useDemarrage: () => ({ configuration, configurationTerminee: true }),
}));

/** Affiche l'écran avec les goûts du téléphone, et la sortie (l'accueil) quand le premier lancement est terminé. */
function Ecran() {
  const { charge, premierLancementFait } = useGouts();
  if (!charge) {
    return null;
  }

  return premierLancementFait ? null : <PremierLancement />;
}

const metriques = { frame: { x: 0, y: 0, width: 390, height: 844 }, insets: { top: 0, left: 0, right: 0, bottom: 0 } };
const avec = (enfant: ReactNode) => (
  <SafeAreaProvider initialMetrics={metriques}>
    <GoutsProvider>{enfant}</GoutsProvider>
  </SafeAreaProvider>
);

beforeEach(async () => {
  await AsyncStorage.clear();
});

describe('premier lancement', () => {
  it('va de la bienvenue aux goûts et enregistre les choix sur le téléphone', async () => {
    await render(avec(<Ecran />));
    expect(screen.getByText('Les spectacles autour de vous, ce soir.')).toBeTruthy();
    expect(screen.getByText('Étape 1 sur 2')).toBeTruthy();

    await fireEvent.press(screen.getByText('Commencer'));
    expect(screen.getByText('Qu’aimez-vous voir sur scène ?')).toBeTruthy();
    expect(screen.getByText('Étape 2 sur 2')).toBeTruthy();

    await fireEvent.press(screen.getByText('Théâtre'));
    await fireEvent.press(screen.getByText('Danse'));
    await fireEvent.press(screen.getByText('Humour'));
    await fireEvent.press(screen.getByText('Humour')); // décoché
    await fireEvent(screen.getByLabelText('Distance maximale'), 'valueChange', 4); // 4e palier après Automatique
    expect(screen.getByText('10 km')).toBeTruthy();
    await fireEvent.press(screen.getByText('Continuer'));

    expect(screen.queryByText('Qu’aimez-vous voir sur scène ?')).toBeNull();
    expect(JSON.parse((await AsyncStorage.getItem('spettacoli.gouts'))!)).toEqual({ genres: [1, 5], rayon_m: 10000 });
    expect(await AsyncStorage.getItem('spettacoli.premier_lancement_fait')).toBe('1');
  });

  it('« Passer » mène à l’accueil sans rien enregistrer', async () => {
    await render(avec(<Ecran />));
    await fireEvent.press(screen.getByText('Commencer'));
    await fireEvent.press(screen.getByText('Passer cette étape'));

    expect(screen.queryByText('Qu’aimez-vous voir sur scène ?')).toBeNull();
    expect(await AsyncStorage.getItem('spettacoli.gouts')).toBeNull();
    expect(await AsyncStorage.getItem('spettacoli.premier_lancement_fait')).toBe('1');
  });

  it('ne se remontre pas aux lancements suivants', async () => {
    await AsyncStorage.setItem('spettacoli.premier_lancement_fait', '1');
    await render(avec(<Ecran />));

    expect(screen.queryByText('Commencer')).toBeNull();
  });
});

describe('goûts enregistrés', () => {
  it('écarte ce qui n’est plus valable', () => {
    expect(lireGouts('{"genres":[1,"x",3],"rayon_m":7000}')).toEqual({ genres: [1, 3], rayon_m: null });
    expect(lireGouts('pas du JSON')).toEqual({ genres: [], rayon_m: null });
    expect(lireGouts(null)).toEqual({ genres: [], rayon_m: null });
  });

  it('affiche le rayon en clair', () => {
    expect(libelleRayon(null)).toBe('Automatique');
    expect(libelleRayon(25000)).toBe('25 km');
  });
});
