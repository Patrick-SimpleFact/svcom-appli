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
