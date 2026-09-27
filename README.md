# Alertes BE — plugin Jeedom

Surveille des capteurs et déclenche des actions quand ils franchissent un
seuil : un frigo à 10 °C, un congélateur qui remonte, une pièce qui prend 8 °C
en deux minutes, une fuite d'eau, une cave trop humide.

Deux niveaux, avertissement et critique, une durée de confirmation, une
hystérésis, une alerte quand un capteur se tait, des profils prêts à l'emploi,
des rappels tant que personne n'a acquitté, un journal et une vue d'ensemble.

Documentation : [docs/fr_FR/index.md](docs/fr_FR/index.md).

## Développement

```bash
php tests/run.php            # le moteur, hors ligne
php tests/check-classes.php  # les pièges du cœur, contre le Jeedom installé
php tools/make-icon.php      # refait l'icône
```

Toute la décision est dans `core/class/alertebeEngine.class.php`, qui ne
connaît pas Jeedom ; `core/class/alertebe.class.php` lit les capteurs, appelle
le moteur, joue les actions et tient les commandes à jour.

## Licence

AGPL.
