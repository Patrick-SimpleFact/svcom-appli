// Active R8 (réduction, optimisation et obscurcissement du code) et la suppression des
// ressources inutilisées dans la version Release Android. Le modèle généré par `expo prebuild`
// les laisse désactivés ; la Play Console signale alors une optimisation du code DEX trop faible.
// Elle demande aussi les optimisations R8 (proguard-android-optimize.txt au lieu de
// proguard-android.txt, qui les coupe) et la réduction optimisée des ressources.
// Le fichier de correspondance (mapping.txt) est joint au .aab : la Play Console
// désobscurcit elle-même les traces de plantage.
const { withAppBuildGradle, withGradleProperties } = require('expo/config-plugins');

const PROPRIETES = {
  'android.enableMinifyInReleaseBuilds': 'true',
  'android.enableShrinkResourcesInReleaseBuilds': 'true',
  'android.r8.optimizedResourceShrinking': 'true',
};

const withObscurcissement = (config) => {
  config = withGradleProperties(config, (cfg) => {
    for (const [key, value] of Object.entries(PROPRIETES)) {
      cfg.modResults = cfg.modResults.filter((item) => item.type !== 'property' || item.key !== key);
      cfg.modResults.push({ type: 'property', key, value });
    }
    return cfg;
  });
  return withAppBuildGradle(config, (cfg) => {
    cfg.modResults.contents = cfg.modResults.contents.replace(
      'getDefaultProguardFile("proguard-android.txt")',
      'getDefaultProguardFile("proguard-android-optimize.txt")',
    );
    return cfg;
  });
};

module.exports = withObscurcissement;
