La page d'accueil du back-office, **Mes actions du jour**. Elle réunit tout ce qui attend une décision humaine, toutes files confondues. Le nombre de décisions à prendre s'affiche à côté du menu.

### Mes actions du jour

Une **carte à la fois** (« Carte 1 sur 12 »). **Les plus récentes passent en premier** : d'abord les cartes arrivées aujourd'hui (en général, ce que la nuit a ajouté), puis celles d'hier, et ainsi de suite ; dans un même jour, la plus urgente d'abord. En haut, le nombre de cartes « ajoutées aujourd'hui » : au minimum, traitez celles-là chaque jour. Chaque carte porte sa date d'arrivée (« Ajoutée aujourd'hui (ven. 23 oct.) », « Ajoutée hier », « Ajoutée le lun. 19 oct. ») : pour une file confiée à l'assistant IA, c'est le jour où l'IA l'a examinée ; pour les autres, le jour où l'élément est entré dans la file.

Sur chaque carte :
- **la question en clair** : « Faut-il publier cette annonce dans l'agenda ? », « Ces deux séances sont-elles la même ? », « Est-ce le même spectacle dans tous ces lieux ? », « Quel genre pour cette catégorie ? » ;
- **les faits utiles** : titres, billetteries, dates et heures, lieux, prix. Deux séances à comparer sont présentées côte à côte (Séance A, Séance B) ;
- **toujours un lien « Voir chez la billetterie »** : l'annonce à trier, chacune des deux séances, chaque lieu d'un spectacle à contrôler, des exemples d'annonces d'une catégorie à classer ;
- **l'avis de l'IA** : sa proposition (en observation), ou pourquoi elle n'a pas tranché (l'avis de chaque modèle, ou le garde-fou qui l'a arrêtée) ;
- **des boutons dont la conséquence est écrite dessous** (« Une seule date dans l'app », « N'apparaîtra jamais dans l'app »…). Le clic décide tout de suite, comme dans la file, et passe à la carte suivante. **Appliquer la proposition de l'IA** reprend sa proposition telle quelle.
- **Plus tard** : passe à la carte suivante ; la carte revient quand vous cliquez **Les reprendre**, ou le lendemain.
- Pour un spectacle à contrôler, chaque lieu a un numéro (**Spectacle 1** au départ). Donnez le même numéro aux lieux qui jouent le même spectacle, puis **Séparer selon les numéros** : le spectacle 1 garde ses dates, chaque autre numéro devient un nouveau spectacle (par exemple, sur 7 lieux : 2 en « 1 », 3 en « 2 » et 2 en « 3 » donnent trois spectacles). Les collectes suivantes rattachent chaque lieu à son spectacle.
- **Supprimer depuis une carte** : les petits boutons rouges masquent ce qu'ils désignent (on ne supprime pas : la collecte recréerait tout). Immédiat, réversible depuis la fiche (un lien retiré : fiche du spectacle › Séances collectées › **Remettre le lien**), et jamais réaffiché par la collecte. L'infobulle de chaque bouton dit sa conséquence :
  - **Masquer le spectacle** : le spectacle et toutes ses dates disparaissent de l'app ;
  - **Masquer le lieu** : le lieu et toutes ses séances, tous spectacles confondus ;
  - **Masquer la séance** (deux séances à comparer) ou **Masquer ces dates** (un lieu d'un spectacle à contrôler) ;
  - **Retirer ce lien** (deux séances à comparer) : seul le lien de cette billetterie disparaît de la séance, les autres billetteries restent.
  La carte est traitée quand sa question n'a plus d'objet (spectacle à contrôler masqué, lieu à vérifier masqué, ou une des deux séances à comparer touchée) ; sinon elle reste affichée pour que vous décidiez.
- Pour un **lieu à vérifier** (nom imprécis comme « Paris » ou « Salle des fêtes », position approximative, commune introuvable) : la carte montre ce qu'a écrit la billetterie, l'adresse (lien vers la carte) et les lieux connus à moins de 300 m. Trois réponses : **Enregistrer ce nom** (le vrai nom, que les collectes ne changeront plus), **Rattacher au lieu choisi** (un lieu proche, ou trouvé par la recherche : ses séances y passent et les collectes suivantes y vont directement) ou **Le lieu est bon**. Pour corriger l'adresse ou la position, le lien ouvre la fiche du lieu.

Pour une file confiée à l'assistant IA (Réglages › Assistant IA), seuls apparaissent les éléments qu'il a examinés sans trancher, ou ce qu'il propose en observation ; ceux qu'il n'a pas encore examinés sont comptés à part (« en cours d'examen par l'IA »). Pour les autres files, tous les éléments en attente apparaissent.

### Les séances passées

Chaque jour à l'heure réglée dans **Paramètres → Assistant IA → Heure de sortie des séances passées** (4 h par défaut), avant que l'assistant IA ne reprenne, les éléments dont toutes les séances sont passées sortent de la boîte de travail (« sans objet ») : annonces à trier, catégories sans séance à venir, paires de séances, spectacles à contrôler. Ce n'est pas une décision : une annonce encore au programme revient d'elle-même à la collecte suivante de sa billetterie.

### Toutes les files

- **Un compteur par file** (À trier, À classer, Doublons probables…) : le nombre d'éléments en attente, dont les urgents. Un clic ouvre la file. Seules les files de votre compte apparaissent.
- **À traiter en priorité** : les éléments les plus urgents de toutes les files, avec les mêmes boutons que dans chaque file. La décision s'applique tout de suite.

### L'urgence

Chaque élément reçoit une urgence, d'après la date de la séance concernée et sa ville :

| Urgence | Signification |
|---|---|
| Ce soir · ville pilote | La séance a lieu ce soir dans une ville pilote |
| Urgent | Ce soir ailleurs, ou dans les 7 jours dans une ville pilote |
| Bientôt | Une ville pilote plus tard, ou dans les 7 jours ailleurs |
| Plus tard | Le reste |

Une séance avant 4 h du matin compte pour la soirée de la veille.

### Les actions

- **Recalculer l'urgence** : reclasse les éléments maintenant. C'est aussi fait automatiquement toutes les 30 minutes.
- **Ouvrir la file** : ouvre la file de l'élément, pour voir ses voisins ou utiliser les filtres.

> Partout dans le back-office, le lien **Aide** en haut d'un écran ouvre sa documentation.
