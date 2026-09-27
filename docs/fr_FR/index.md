# Alertes BE

Le plugin surveille des capteurs et agit quand l'un d'eux franchit un seuil.

Jeedom sait déjà colorer une commande en « warning » ou « danger » selon sa
valeur, mais ces seuils sont cachés dans la configuration de chaque commande,
sans vue d'ensemble, sans suivi de l'alerte, sans rappel ni acquittement. Ce
plugin en fait des **surveillances** : un équipement par chose à surveiller,
ses règles, ses actions, son état, son journal.

## Une surveillance

Un équipement = une surveillance : « Frigo cuisine », « Incendie garage »,
« Cave ». Elle contient une ou plusieurs **règles**, et son niveau est le pire
de ses règles :

| Niveau | Signification |
|---|---|
| Normal | rien à signaler |
| Avertissement | à regarder : le frigo remonte, un capteur ne répond plus |
| Critique | il faut agir : le frigo est chaud, il y a de l'eau par terre |

## Les règles

Chaque règle surveille une commande info de n'importe quel plugin.

| Type | Exemple |
|---|---|
| Au-dessus d'un seuil | frigo ≥ 7 °C, CO2 ≥ 1000 ppm |
| En dessous d'un seuil | hors-gel ≤ 3 °C |
| Hors d'une plage | humidité de la cave hors 40–70 % |
| Égal à | détecteur de fuite = 1, contact = « open », ou plusieurs valeurs : `open\|ouvert` |
| Hausse rapide | +8 °C en 2 minutes |
| Baisse rapide | −5 °C en 10 minutes |

Et pour chacune :

- **Avertissement** et **Critique** : les deux seuils. Un seuil vide n'est pas
  utilisé : une règle peut n'avoir qu'un niveau.
- **Confirmation** : la valeur doit rester au-delà du seuil tout ce temps avant
  l'alerte, chaque niveau comptant pour lui-même. C'est ce qui empêche une porte
  de frigo ouverte de déclencher quoi que ce soit. Une seule mesure revenue sous
  le seuil remet le compteur à zéro. 0 : immédiat.
- **Hystérésis** : l'alerte ne s'arrête qu'une fois la valeur revenue de cet
  écart en deçà du seuil. Un frigo en alerte à 7 °C avec 1 °C d'hystérésis ne
  revient à la normale qu'à 6 °C : une valeur qui danse autour du seuil ne fait
  pas clignoter l'alerte.
- **Muet après** : si le capteur ne publie plus rien depuis ce temps, la règle
  passe en avertissement. Jeedom garde la dernière valeur d'un capteur à la pile
  vide ; sans ce contrôle, il dirait « tout va bien » indéfiniment. Beaucoup de
  capteurs ne publient qu'au changement : ne descendez pas trop bas.
- **Égal à** : la comparaison ignore les majuscules. Plusieurs valeurs se
  séparent par `|` : `open|ouvert|1` reconnaît les trois, utile quand deux
  contacts d'un même rôle ne rendent pas le même texte.
- **Fenêtre** (hausse et baisse rapides) : le temps sur lequel se mesure la
  variation, depuis le point le plus bas (ou le plus haut) de la fenêtre.

Une hausse rapide se tait dès que la température se stabilise, même haute : pour
un incendie, mettez deux règles sur le même capteur, le profil « Incendie —
température » et le profil « Incendie — hausse rapide ».

Une valeur illisible (vide, texte dans un capteur numérique) ne change rien :
la règle garde son niveau. Un capteur **introuvable** — supprimé, ou dont
l'équipement est désactivé — met la règle en avertissement : une surveillance
qui ne voit plus rien ne doit pas dire « tout va bien ».

La page signale les réglages qui ne feront pas ce qu'on croit : seuils
inversés, plage inversée, hystérésis plus large que l'écart entre les deux
seuils, règle sans seuil ou sans capteur.

### Profils

Le bouton « Ajouter une règle » propose des règles préremplies, toutes
modifiables ensuite :

| Profil | Règle |
|---|---|
| Frigo | ≥ 7 °C / ≥ 10 °C, confirmation 20 min, hystérésis 1 °C, muet après 3 h |
| Congélateur | ≥ −15 °C / ≥ −12 °C, confirmation 30 min |
| Incendie — température | ≥ 50 °C / ≥ 57 °C, immédiat |
| Incendie — hausse rapide | +5 °C / +8 °C en 2 min, immédiat |
| Hors-gel | ≤ 5 °C / ≤ 3 °C, confirmation 10 min |
| Fuite d'eau | = 1 en critique, immédiat |
| Humidité (cave) | hors 40–70 % / hors 30–80 %, confirmation 1 h |
| CO2 | ≥ 1000 / ≥ 1500 ppm, confirmation 5 min |

## Les actions

Trois listes, au format des scénarios — une commande (notification, sirène,
lampe) ou un bloc (message, scénario, variable) :

- **Avertissement** : quand la surveillance passe de normal à avertissement ;
- **Critique** : quand elle passe en critique, depuis normal ou avertissement.
  Laissée vide, ce sont les actions d'avertissement qui sont jouées : qui n'a
  réglé qu'une notification la reçoit aussi quand le frigo passe à 12 °C ;
- **Retour à la normale** : quand tout est rentré dans l'ordre.

Critique → avertissement ne déclenche rien : l'alerte n'est pas finie. C'est
seulement noté au journal.

Dans les titres et les messages, ces mots sont remplacés :

| Mot | Remplacé par |
|---|---|
| `#equipement#` | le nom de la surveillance |
| `#objet#` | sa pièce (objet parent), vide sinon |
| `#niveau#` | Avertissement, Critique, Normal |
| `#message#` | les règles en alerte, en clair : « Frigo : 11,2 °C (≥ 10 °C) » |
| `#regle#`, `#capteur#` | la pire règle et son capteur |
| `#valeur#`, `#unite#`, `#seuil#` | sa valeur, son unité, le seuil franchi |
| `#pic#` | le pire atteint pendant l'alerte |
| `#depuis#`, `#duree#` | l'heure du début, la durée |
| `#heure#` | l'heure du déclenchement de l'action |
| `#rappel#` | le numéro du rappel (0 pour la première alerte) |
| `#acquitte_par#` | au retour à la normale, qui avait acquitté l'alerte ; vide sinon |

Le bouton **Tester** joue les actions enregistrées d'un niveau, avec un message
d'essai, sans rien changer à l'état de la surveillance.

Les blocs qui attendent (« Attendre », « Pause », « Faire une demande »…) sont
refusés : ils retiendraient le cron de Jeedom. Pour une séquence, lancez un
scénario.

### Rappels et acquittement

Tant que l'alerte dure et que personne ne l'a **acquittée**, les actions de son
niveau sont rejouées à intervalle régulier (30 minutes, trois fois au plus, par
défaut). Une montée en critique efface l'acquittement et recommence le compte :
on a acquitté un frigo à 8 °C, pas un frigo à 12 °C.

On acquitte depuis la page, la vue d'ensemble, le tableau de bord ou un
scénario (commande « Acquitter »).

### Centre de messages

Chaque alerte y est inscrite (réglable) : une trace dans Jeedom même si aucune
action n'est réglée, ou si la notification n'est pas partie. Un message par
alerte et par niveau — le passage en critique a le sien — qui reste après le
retour à la normale. Suspendre, désactiver ou supprimer la surveillance efface
ses messages.

## Suspendre, désactiver

- **Suspendre** coupe la surveillance pour un temps — nettoyer le frigo,
  dégivrer le congélateur — puis elle reprend seule.
- **Désactiver** la coupe jusqu'à nouvel ordre.

Dans les deux cas, une alerte en cours est oubliée sans jouer les actions de
retour à la normale. Il en va de même quand on désactive l'équipement lui-même
(case « Activer »). À la reprise, un problème toujours présent repasse par sa
durée de confirmation et redéclenche ses actions.

## Les commandes

| Commande | Type | Rôle |
|---|---|---|
| État | info texte | Normal, Avertissement, Critique, Suspendue, Désactivée |
| Message | info texte | les règles en alerte, en clair |
| Niveau | info numérique | 0, 1, 2 — historisée, pour les graphiques et les scénarios |
| En alerte | info binaire | 1 dès l'avertissement |
| Depuis | info texte | début de l'alerte |
| Acquittée | info binaire | |
| Surveillance active | info binaire | 0 si suspendue ou désactivée |
| Acquitter | action | |
| Activer / Désactiver la surveillance | action | |
| Suspendre (minutes) | action curseur | |
| Reprendre | action | |
| Rafraîchir | action | réévalue tout de suite |

« En alerte », « Surveillance active », « Activer » et « Désactiver la
surveillance » portent les types génériques d'une alarme (état, état activé,
armer, libérer) : l'application mobile et les ponts vers Homebridge ou Google
présentent ainsi chaque surveillance comme une alarme, qu'on peut suspendre ou
réactiver du téléphone. Un type générique changé à la main n'est jamais
écrasé.

## Fonctionnement

Aucun démon ni dépendance. Un écouteur réagit à chaque nouvelle valeur d'un
capteur surveillé — un incendie n'attend pas la minute suivante — et le cron de
Jeedom passe chaque minute pour ce qui dépend du temps seul : durées de
confirmation, rappels, capteurs muets.

L'état de chaque alerte est conservé en base : un redémarrage de Jeedom retrouve
une alerte en cours sans rejouer ses actions.
