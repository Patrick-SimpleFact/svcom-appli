// Active R8 (réduction et obscurcissement du code) et la suppression des ressources
// inutilisées dans la version Release Android. Le modèle généré par `expo prebuild` les
// laisse désactivés ; la Play Console signale alors une optimisation du code DEX trop faible.
// Le fichier de correspondance (mapping.txt) est joint au .aab : la Play Console
// désobscurcit elle-même les traces de plantage.
const { withGradleProperties } = require('expo/config-plugins');

const PROPRIETES = {
  'android.enableMinifyInReleaseBuilds': 'true',
  'android.enableShrinkResourcesInReleaseBuilds': 'true',
};

const withObscurcissement = (config) =>
  withGradleProperties(config, (cfg) => {
    for (const [key, value] of Object.entries(PROPRIETES)) {
      cfg.modResults = cfg.modResults.filter((item) => item.type !== 'property' || item.key !== key);
      cfg.modResults.push({ type: 'property', key, value });
    }
    return cfg;
  });

module.exports = withObscurcissement;
