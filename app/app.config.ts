import type { ConfigContext, ExpoConfig } from 'expo/config';

/**
 * Complète app.json avec ce qui ne doit pas être commité.
 * Clé Google Maps (Android) : sans elle, ouvrir la carte fait planter l'app. Elle vient de app/.env
 * (GOOGLE_MAPS_ANDROID_KEY, voir README) ; il faut relancer `npx expo prebuild -p android` après l'avoir changée.
 */
export default ({ config }: ConfigContext): ExpoConfig => ({
  ...(config as ExpoConfig),
  android: {
    ...config.android,
    config: {
      ...config.android?.config,
      googleMaps: { apiKey: process.env.GOOGLE_MAPS_ANDROID_KEY },
    },
  },
});
