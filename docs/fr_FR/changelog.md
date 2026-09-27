# Changelog

## 0.2

- **Capteur introuvable** : une règle dont le capteur a été supprimé, ou dont
  l'équipement est désactivé, passe en avertissement au lieu de se taire.
- **Critique sans action** : les actions d'avertissement sont jouées.
- **Réglages incohérents signalés** sur la page : seuils ou plage inversés,
  hystérésis trop large, règle sans seuil ou sans capteur.
- **Hausse et baisse rapides** : les mesures sont regroupées par tranches, la
  fenêtre n'est plus tronquée pour un capteur qui publie chaque seconde.
- Désactiver l'équipement oublie l'alerte en cours ; les commandes ne restent
  plus figées sur « Critique ».
- Page : rafraîchissement automatique, état sur chaque tuile, confirmation
  avant de désactiver, date affichée pour une suspension au-delà de minuit.
- Une alerte critique n'est plus écrite en erreur dans le journal du plugin.

## 0.1

- Première version : surveillances à plusieurs règles (au-dessus, en dessous,
  hors plage, égal à, hausse et baisse rapides), deux niveaux, durée de
  confirmation, hystérésis, capteur muet.
- Profils : frigo, congélateur, incendie (seuil et hausse rapide), hors-gel,
  fuite d'eau, humidité de cave, CO2.
- Actions par niveau et au retour à la normale, au format des scénarios, avec
  bouton de test ; rappels, acquittement, suspension temporaire.
- Commandes pour les scénarios et le tableau de bord, journal, vue d'ensemble.
