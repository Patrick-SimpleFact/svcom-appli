Les **réglages de l'app et de la collecte**. La liste est fixe : seules les valeurs changent, et chaque réglage est expliqué dans sa fiche.

### Les groupes

- **Alertes** : zone et heure de la notification quotidienne.
- **Assistant IA** : un mode par file de la boîte de travail (À trier, À classer, Doublons probables, Fusions à contrôler, Spectacles à contrôler). **Désactivé** : rien ne change. **Observation** : l'assistant décide, sans rien modifier. **Actif** : il applique ce qu'il a décidé ; le reste va dans « Mes actions du jour ».
  - **Modèles du 1er niveau** : deux modèles peu coûteux, interrogés sur chaque élément. Leur décision compte seulement s'ils répondent la même chose, tous deux au moins aussi sûrs que la **confiance minimale** (90 % par défaut).
  - **Modèle du 2e niveau** : interrogé seulement quand le 1er niveau n'a pas tranché ; sa décision compte s'il est sûr. Vide : pas de 2e niveau.
  - Les modèles se choisissent dans la liste des modèles « cloud » d'ollama.com (mise à jour toutes les 6 heures). Les modèles facturés en plus du forfait (kimi-k3, deepseek-v4-pro) n'y figurent pas et sont refusés. À l'enregistrement, le BO vérifie que l'Ollama du serveur connaît le modèle.
  - **Part du forfait Ollama gardée**, sur 5 heures et sur la semaine : l'assistant s'arrête en dessous et reprend après la remise à zéro. Le forfait est partagé avec Open WebUI, n8n et vos sessions.
  - **Éléments par demande** : taille des lots envoyés aux modèles.
- **App** : version minimale (en dessous, l'app est bloquée sur l'écran « mettez à jour ») ; **dernier build iOS / Android disponible** (un téléphone qui a un build plus ancien voit le bandeau « Une nouvelle version de Spettacoli est disponible », sans être bloqué). À chaque nouvelle version, changer le numéro **une fois la version visible chez les testeurs** (TestFlight ou Google Play), sinon le bandeau envoie vers une boutique où elle n'est pas encore.
- **Autour de moi** : rayon automatique et ses paliers, seuil de couverture faible, délais des messages de chargement.
- **Collecte** : horizon des séances, écarts d'heure pour fusionner ou signaler un doublon, file « Fusions à contrôler ».
- **Pistes** : nombre maximum de pistes par jour et par téléphone.
- **Recherche** : paliers du filtre de prix.
- **Suggestions** : activation, part de sponsorisé, distance, relances.
- **Supervision** : rayon de la couverture, destinataires et seuils des alertes.

### Les actions

- **Modifier** : change la valeur. Elle s'applique tout de suite (ou à la prochaine collecte pour les réglages de collecte).
