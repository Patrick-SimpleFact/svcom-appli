// iOS 27 arrête l'app au lancement si elle n'adopte pas le cycle de vie « scene ».
// Expo SDK 57 fournit ExpoAppSceneDelegate, mais le modèle généré par `expo prebuild`
// ne l'active pas encore : ce plugin le branche (Info.plist + AppDelegate).
// À retirer quand le modèle d'Expo le fera lui-même.
const { withAppDelegate, withInfoPlist } = require('expo/config-plugins');

const withSceneLifecycle = (config) => {
  config = withInfoPlist(config, (cfg) => {
    cfg.modResults.UIApplicationSceneManifest = {
      UIApplicationSupportsMultipleScenes: false,
      UISceneConfigurations: {
        UIWindowSceneSessionRoleApplication: [
          {
            UISceneConfigurationName: 'Default Configuration',
            UISceneDelegateClassName: 'EXExpoAppSceneDelegate',
          },
        ],
      },
    };
    return cfg;
  });

  return withAppDelegate(config, (cfg) => {
    let src = cfg.modResults.contents;
    if (!src.includes('ExpoReactNativeFactoryProvider')) {
      src = src.replace(
        'class AppDelegate: ExpoAppDelegate {',
        'class AppDelegate: ExpoAppDelegate, ExpoReactNativeFactoryProvider {',
      );
    }
    // La fenêtre et React Native sont démarrés par ExpoAppSceneDelegate.
    src = src.replace(
      /#if os\(iOS\) \|\| os\(tvOS\)\n\s*window = UIWindow\(frame: UIScreen\.main\.bounds\)\n\s*factory\.startReactNative\([\s\S]*?\)\n#endif\n/,
      '    // iOS 27 : la fenêtre et React Native sont démarrés par ExpoAppSceneDelegate (Info.plist).\n',
    );
    if (!src.includes('ExpoReactNativeFactoryProvider') || src.includes('UIScreen.main.bounds')) {
      throw new Error('with-scene-lifecycle : AppDelegate.swift a changé, adapter le plugin.');
    }
    cfg.modResults.contents = src;
    return cfg;
  });
};

module.exports = withSceneLifecycle;
