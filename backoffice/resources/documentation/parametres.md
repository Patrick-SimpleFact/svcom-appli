Les **réglages de l'app et de la collecte**. La liste est fixe : seules les valeurs changent, et chaque réglage est expliqué dans sa fiche.

### Les groupes

- **Alertes** : zone et heure de la notification quotidienne.
- **Assistant IA** : un mode par file de la boîte de travail (À trier, À classer, Doublons probables, Fusions à contrôler). **Désactivé** : rien ne change. **Observation** : l'assistant propose, sans rien modifier. **Actif** : il applique ce dont il est sûr (au-dessus de la **confiance minimale**), le reste attend votre décision. Aussi : le **modèle** utilisé (uniquement un modèle Ollama « cloud »), la **part du forfait Ollama gardée** pour les autres usages (l'assistant s'arrête en dessous et reprend après la remise à zéro) et le nombre d'**éléments par demande**.
- **App** : version minimale (en dessous, l'app est bloquée sur l'écran « mettez à jour ») ; **dernier build iOS / Android disponible** (un téléphone qui a un build plus ancien voit le bandeau « Une nouvelle version de Spettacoli est disponible », sans être bloqué). À chaque nouvelle version, changer le numéro **une fois la version visible chez les testeurs** (TestFlight ou Google Play), sinon le bandeau envoie vers une boutique où elle n'est pas encore.
- **Autour de moi** : rayon automatique et ses paliers, seuil de couverture faible, délais des messages de chargement.
- **Collecte** : horizon des séances, écarts d'heure pour fusionner ou signaler un doublon, file « Fusions à contrôler ».
- **Pistes** : nombre maximum de pistes par jour et par téléphone.
- **Recherche** : paliers du filtre de prix.
- **Suggestions** : activation, part de sponsorisé, distance, relances.
- **Supervision** : rayon de la couverture, destinataires et seuils des alertes.

### Les actions

- **Modifier** : change la valeur. Elle s'applique tout de suite (ou à la prochaine collecte pour les réglages de collecte).
