/// <reference types="jest" />
import { router } from 'expo-router';

import { revenir } from '@/navigation';

jest.mock('expo-router', () => ({ router: { canGoBack: jest.fn(), back: jest.fn(), replace: jest.fn() } }));

describe('Fermer / Retour', () => {
  it('revient à l’écran précédent s’il y en a un', () => {
    (router.canGoBack as jest.Mock).mockReturnValue(true);
    revenir();
    expect(router.back).toHaveBeenCalled();
    expect(router.replace).not.toHaveBeenCalled();
  });

  it('sinon retourne à l’accueil (écran rouvert seul après un rechargement)', () => {
    jest.clearAllMocks();
    (router.canGoBack as jest.Mock).mockReturnValue(false);
    revenir();
    expect(router.back).not.toHaveBeenCalled();
    expect(router.replace).toHaveBeenCalledWith('/');
  });
});
