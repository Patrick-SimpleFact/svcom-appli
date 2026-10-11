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
- Les dossiers `ios/` et `android/` sont **générés** (`npx expo prebuild`) et ne sont pas commités : on règle tout dans `app.json`.
