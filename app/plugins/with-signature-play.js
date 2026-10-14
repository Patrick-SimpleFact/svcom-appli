// Signe les builds Android de publication (bundleRelease) avec la clé d'envoi Google Play.
// Le modèle généré par `expo prebuild` signe la version Release avec la clé de débogage,
// refusée par le Play Store. La clé et ses mots de passe restent hors du dépôt, dans
// ~/.gradle/gradle.properties (SPETTACOLI_UPLOAD_*, voir README) ; sans eux, on garde la clé de débogage.
const { withAppBuildGradle } = require('expo/config-plugins');

const SIGNATURE = `
        release {
            if (project.hasProperty('SPETTACOLI_UPLOAD_STORE_FILE')) {
                storeFile file(SPETTACOLI_UPLOAD_STORE_FILE)
                storePassword SPETTACOLI_UPLOAD_STORE_PASSWORD
                keyAlias SPETTACOLI_UPLOAD_KEY_ALIAS
                keyPassword SPETTACOLI_UPLOAD_KEY_PASSWORD
            }
        }`;

const withSignaturePlay = (config) =>
  withAppBuildGradle(config, (cfg) => {
    let src = cfg.modResults.contents;
    if (!src.includes('SPETTACOLI_UPLOAD_STORE_FILE')) {
      // Déclare la signature « release » à côté de celle de débogage…
      src = src.replace(/(signingConfigs \{\n\s+debug \{[^}]*\})/, `$1${SIGNATURE}`);
      // … et l'utilise pour la version Release quand la clé est configurée.
      src = src.replace(
        /(release \{\n\s+\/\/ Caution![^\n]*\n[^\n]*\n\s+)signingConfig signingConfigs\.debug/,
        "$1signingConfig project.hasProperty('SPETTACOLI_UPLOAD_STORE_FILE') ? signingConfigs.release : signingConfigs.debug",
      );
    }
    cfg.modResults.contents = src;
    return cfg;
  });

module.exports = withSignaturePlay;
