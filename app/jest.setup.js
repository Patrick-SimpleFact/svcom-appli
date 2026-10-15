// Stockage du téléphone simulé en mémoire pendant les tests.
jest.mock('@react-native-async-storage/async-storage', () => require('@react-native-async-storage/async-storage/jest/async-storage-mock'));

// Connexion Apple et Google (M10b) : modules natifs absents pendant les tests.
jest.mock('expo-apple-authentication', () => ({ isAvailableAsync: jest.fn(async () => false), signInAsync: jest.fn(), AppleAuthenticationScope: { FULL_NAME: 0, EMAIL: 1 } }));
jest.mock('@react-native-google-signin/google-signin', () => ({
  GoogleSignin: { configure: jest.fn(), hasPlayServices: jest.fn(async () => true), signIn: jest.fn(), signOut: jest.fn(async () => null) },
  isSuccessResponse: (r) => r?.type === 'success',
  statusCodes: { SIGN_IN_CANCELLED: 'SIGN_IN_CANCELLED', IN_PROGRESS: 'IN_PROGRESS' },
}));

// Notifications (M12b) : module natif absent pendant les tests.
jest.mock('expo-notifications', () => ({
  setNotificationHandler: jest.fn(),
  setNotificationChannelAsync: jest.fn(async () => null),
  getPermissionsAsync: jest.fn(async () => ({ status: 'undetermined', canAskAgain: true })),
  requestPermissionsAsync: jest.fn(async () => ({ status: 'denied' })),
  getDevicePushTokenAsync: jest.fn(async () => ({ type: 'ios', data: '' })),
  useLastNotificationResponse: jest.fn(() => null),
  setBadgeCountAsync: jest.fn(async () => true),
  AndroidImportance: { DEFAULT: 3 },
}));
