Des séances que la collecte a **déjà fusionnées automatiquement** alors que leurs heures différaient un peu (1 à 30 minutes). La fusion est appliquée ; cette file sert à vérifier que la règle se trompe rarement. Exception : quand une billetterie enchaîne les séances du même spectacle (moins d'une heure d'écart le même jour), la collecte ne fusionne plus automatiquement : la paire va dans « Doublons probables ».

> **Différence avec Doublons probables** : ici la fusion est déjà faite, vous la confirmez ou l'annulez. Dans « Doublons probables », rien n'est fusionné tant que vous n'avez pas décidé.

### Les actions

- **Confirmer** : c'était bien la même séance.
- **Séparer** : annule la fusion ; les deux séances redeviennent distinctes.

La décision est mémorisée pour les collectes suivantes. Cette file peut être désactivée dans **Réglages** (« File Fusions à contrôler active ») si la règle s'avère fiable.

### L'assistant IA

Si la file est en mode **Observation** ou **Actif** (Réglages › Assistant IA), l'assistant examine les éléments en attente, les plus urgents d'abord, en cascade :
- **1er niveau** : deux modèles répondent chacun ; s'ils disent la même chose, tous deux sûrs à au moins 90 %, la décision est retenue ;
- **2e niveau** : sinon, un troisième modèle tranche s'il est sûr ;
- sinon, ou si un **garde-fou** s'y oppose (séances enchaînées par une billetterie, billetterie qui vend elle-même les deux horaires, lieux différents, titres trop différents), l'élément reste **à décider** par vous.

La colonne **Proposition IA** montre la décision retenue (avec sa confiance et sa justification), ou « À décider » avec l'avis de chaque modèle en dessous. Le filtre **Proposition IA** isole les lignes avec ou sans proposition.
- En **Observation**, rien n'est modifié : vous acceptez (**Accepter**, ou **Accepter les propositions IA** sur une sélection) ou décidez autrement.
- En **Actif**, l'assistant applique lui-même ce qu'il a décidé, comme si vous aviez cliqué ; le reste attend votre décision.
