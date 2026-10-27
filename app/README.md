# Spettacoli — application mobile

React Native avec **Expo** (SDK 57, Expo Router), compilée **sur le Mac**, sans service EAS (décision D6).
Plan de développement : `docs/plan-fonctionnalites.md`, bloc 7 (une étape = une session).

## Lancer l'app sur le simulateur iPhone

Prérequis : **Xcode 26.4 ou plus récent** (exigé par Expo SDK 57 ; avec Xcode 26.3, la compilation échoue dans `expo-modules-jsi`, « SWIFT_RETURNS_RETAINED »).

```bash
cd app
npm install
npx expo run:ios --device "iPhone 18 Pro"   # 1re fois : compile l'app (quelques minutes), puis la lance
```

Ensuite, tant que le code natif ne change pas : `npx expo start` puis touche `i` (rechargement instantané à chaque modification).

## Lancer l'app sur l'émulateur Android

Prérequis : **Android Studio** (installation « Standard » : SDK + émulateur) et **Java 17** (`brew install openjdk@17`). Le Java 25 fourni avec Android Studio fait échouer la compilation native (`configureCMakeDebug`, « A restricted method in java.lang.System has been called »).

```bash
export JAVA_HOME=/opt/homebrew/opt/openjdk@17/libexec/openjdk.jdk/Contents/Home ANDROID_HOME=~/Library/Android/sdk
~/Library/Android/sdk/emulator/emulator -avd Medium_Phone_API_37.0 &   # 1er démarrage : 3 à 5 minutes
cd app
npx expo prebuild -p android        # après un changement de app.json, app.config.ts ou d'un module natif
npx expo run:android                # 1re fois : compile l'app (plusieurs minutes)
```

- **API** : l'émulateur ne connaît ni `spettacoli.test` ni le certificat de Herd. Il passe par `EXPO_PUBLIC_API_URL_ANDROID=http://localhost:8000` (`app/.env`) :

  ```bash
  cd backoffice && php artisan serve --port=8000            # le back-office en HTTP sur le Mac
  ~/Library/Android/sdk/platform-tools/adb reverse tcp:8000 tcp:8000   # à refaire à chaque démarrage de l'émulateur
  ```

- **Carte** : sans clé Google Maps, ouvrir la carte **fait planter l'app**. Clé dans `app/.env` (`GOOGLE_MAPS_ANDROID_KEY`, projet Google Cloud « Spettacoli », restreinte au paquet `fr.spettacoli.app`), lue par `app.config.ts` ; puis `npx expo prebuild -p android` et `npx expo run:android`.
- **Position** : le GPS de l'émulateur ne répond qu'aux positions envoyées (panneau « … › Location ») ; sans elles, l'app part de la dernière position connue et affiche « Position approximative ».

## Version de publication (Google Play, TestFlight)

- Les builds de publication visent l'API en ligne (`app/.env.production`, chargé quand `NODE_ENV=production`).
- **Android** : signés avec la **clé d'envoi Google Play** (`~/.android-cles/spettacoli-upload.jks`, alias `spettacoli-upload`). Les mots de passe sont dans `~/.gradle/gradle.properties` (`SPETTACOLI_UPLOAD_*`), lus par le plugin `plugins/with-signature-play.js`. Sans eux, la version Release est signée avec la clé de débogage (refusée par le Play Store). **Sauvegarder** le fichier `.jks` et ses mots de passe hors du Mac (gestionnaire de mots de passe). En cas de perte, Google peut réinitialiser la clé d'envoi (Play Console › Intégrité de l'application), mais cela prend plusieurs jours.
- Augmenter `ios.buildNumber` dans `app.json` à chaque envoi : c'est le numéro de build des deux stores (`app.config.ts` le reprend comme `android.versionCode`). Puis :

  ```bash
  export JAVA_HOME=/opt/homebrew/opt/openjdk@17/libexec/openjdk.jdk/Contents/Home ANDROID_HOME=~/Library/Android/sdk
  CI=1 npx expo prebuild -p android --clean
  cd android && NODE_ENV=production ./gradlew bundleRelease   # → android/app/build/outputs/bundle/release/app-release.aab
  ```

- **Obscurcissement Android (R8)** : activé pour la version Release par `plugins/with-obscurcissement.js` (la Play Console demandait une meilleure optimisation du code DEX, à corriger avant février 2027), avec les optimisations R8 (`proguard-android-optimize.txt`) et la réduction optimisée des ressources (`android.r8.optimizedResourceShrinking`) depuis le build 7. Le passage au plug-in Android Gradle 9, aussi suggéré, attend React Native (8.12 imposé en 0.86). Le fichier de correspondance est joint au `.aab`, rien à déposer à part. Si une bibliothèque native plante seulement en Release, ajouter une règle `-keep` (plugin ou règles de la bibliothèque) plutôt que de désactiver R8.

- **iOS avec Xcode 27.1 (Swift 6.4), constaté le 19/10/2026 au build 4** : l'archive Release échoue (`cannot find type '__ObjC::expo' in scope`, `this SDK is not supported by the compiler`) tant qu'Expo SDK 57 n'est pas corrigé. Avant d'archiver :
  1. modules Expo compilés depuis les sources (les précompilés sont faits avec Swift 6.3.1) : `cd ios && EXPO_USE_PRECOMPILED_MODULES=0 pod install` ;
  2. dans `node_modules/expo-modules-jsi/apple/scripts/build-xcframework.sh`, le nettoyage de l'interface publique doit aussi retirer la nouvelle syntaxe `extension __ObjC::expo…` : remplacer `sed '/^extension __ObjC\./,` par `sed -E '/^extension __ObjC(\.|::)/,`, puis `bash node_modules/expo-modules-jsi/apple/scripts/clear-caches.sh`. Ce correctif est perdu à chaque `npm install`.

- Clé Google Maps (projet Google Cloud « Spettacolli », « Maps Platform API Key ») : limitée à **Maps SDK for Android** et aux applications Android `fr.spettacoli.app` signées par l'une de ces clés (SHA-1). Une clé de signature absente de la liste fait planter la carte.

  | Clé | SHA-1 |
  |---|---|
  | Débogage (émulateur, `android/app/debug.keystore`) | `5E:8F:16:06:2E:A3:CD:2C:4A:0D:54:78:76:BA:A6:F3:8C:AB:F6:25` |
  | Envoi (`~/.android-cles/spettacoli-upload.jks`) | `88:33:14:FD:B9:A9:80:CE:62:67:9E:90:0F:E9:76:EC:7F:63:FB:03` |
  | Signature de Google Play (Play Console › Signature d'application) | `5A:F3:DC:BC:E2:C7:05:35:28:10:79:BA:9E:02:52:E2:3E:11:00:72` |

- Liens universels Android (à venir) : SHA-256 de la clé de signature de Google Play, pour `assetlinks.json` : `C2:51:76:64:25:A3:C0:9E:F2:44:A6:4D:DE:7E:28:FC:9E:1E:06:0E:DA:8C:86:8A:3B:B5:F7:CE:F9:56:81:C2`.

## L'API locale (Herd)

- Adresse : `app/.env` → `EXPO_PUBLIC_API_URL=https://spettacoli.test` (le back-office servi par Herd sur le Mac).
- Le simulateur doit faire confiance au certificat HTTPS de Herd, **une fois par simulateur** :

  ```bash
  xcrun simctl keychain "iPhone 18 Pro" add-root-cert "$HOME/Library/Application Support/Herd/config/valet/CA/LaravelValetCASelfSigned.pem"
  ```

- L'onglet « Autour de moi » indique si le back-office est joignable.
- Sur un vrai iPhone, `spettacoli.test` n'existe pas : voir le plan (test sur téléphone, à mettre en place quand une étape en aura besoin).

## Conventions

- Écrans dans `src/app/` (un fichier = un écran, Expo Router) ; le reste dans `src/components`, `src/api`, `src/constants`.
- Couleurs et polices des maquettes : `src/constants/theme.ts` (Bricolage Grotesque, DM Sans, accent `#D23A1F`).
- Avant de valider une étape : `npx tsc --noEmit` et `npx expo lint`.
- Les dossiers `ios/` et `android/` sont **générés** (`npx expo prebuild`) et ne sont pas commités : on règle tout dans `app.json` (et `app.config.ts` pour ce qui vient de `.env`).
