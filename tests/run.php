<?php
/* Jeu d'essai hors ligne du plugin Alertes.
 *
 *   php tests/run.php
 *
 * Aucune dépendance : ni Jeedom, ni base, ni capteur. Le moteur ignore Jeedom
 * exprès, et c'est ce qui rend ce fichier possible.
 *
 * Une alerte se trompe en silence : un frigo qui sonne à chaque ouverture de
 * porte, une alerte qui clignote autour du seuil, un incendie repéré trop tard.
 * Chaque scénario ci-dessous rejoue une de ces situations, minute par minute.
 */

date_default_timezone_set('Europe/Brussels');
require_once __DIR__ . '/../core/class/alertebeEngine.class.php';

$ok = 0;
$ko = 0;

function verifie($_titre, $_obtenu, $_attendu) {
    global $ok, $ko;
    if ($_obtenu === $_attendu || (is_float($_obtenu) && is_numeric($_attendu) && abs($_obtenu - $_attendu) < 1e-6)) {
        $ok++;
        printf("  %-66s ok\n", $_titre);
        return;
    }
    $ko++;
    printf("  %-66s ÉCHEC : obtenu %s, attendu %s\n", $_titre,
           var_export($_obtenu, true), var_export($_attendu, true));
}

/* Une règle et son état, qu'on nourrit de mesures en faisant avancer l'horloge
 * comme le font le cron et l'écouteur du plugin. */
class Capteur {
    public $regle;
    public $etat = array();
    public $now;
    public $dernier;

    public function __construct($_regle) {
        $this->regle = alertebeEngine::cleanRule($_regle);
        $this->now = strtotime('2026-07-15 12:00:00');
    }

    public function mesure($_valeur, $_collecte = null) {
        $this->dernier = alertebeEngine::evaluateRule($this->regle, $_valeur,
            ($_collecte === null) ? $this->now : $_collecte, $this->etat, $this->now);
        $this->etat = $this->dernier['state'];
        return $this->dernier['level'];
    }

    public function avance($_minutes) {
        $this->now += (int) round($_minutes * 60);
    }
}

/* ------------------------------------------------------------------ 1 --- */
echo "1. Nettoyage des règles\n";
$r = alertebeEngine::cleanRule(array('type' => 'above', 'warning' => '7,5', 'critical' => '', 'delay' => 'abc'));
verifie('virgule relue comme un point', $r['warning'], 7.5);
verifie('seuil vide reste vide, pas zéro', $r['critical'], '');
verifie('délai illisible → 0', $r['delay'], 0);
verifie('identifiant posé', (bool) preg_match('/^r[0-9a-f]{8}$/', $r['id']), true);
$r2 = alertebeEngine::cleanRule(array('id' => $r['id'], 'type' => 'nimporte'));
verifie('identifiant conservé', $r2['id'], $r['id']);
verifie('type inconnu → au-dessus', $r2['type'], 'above');
$doubles = alertebeEngine::cleanRules(array($r, $r));
verifie('deux règles copiées : identifiants distincts', $doubles[0]['id'] !== $doubles[1]['id'], true);
$e = alertebeEngine::cleanRule(array('type' => 'equal', 'critical' => ' on ', 'warning_low' => 3));
verifie('égalité : valeur texte gardée', $e['critical'], 'on');
verifie('égalité : champs de plage ignorés', $e['warning_low'], '');
verifie('règle sans seuil repérée', alertebeEngine::ruleHasThreshold(alertebeEngine::cleanRule(array())), false);
verifie('profil frigo', alertebeEngine::profileRule('fridge')['warning'], 7.0);
verifie('profil inconnu', alertebeEngine::profileRule('xyz'), null);

/* ------------------------------------------------------------------ 2 --- */
echo "2. Frigo : la porte ouverte ne déclenche rien\n";
$frigo = new Capteur(alertebeEngine::PROFILES['fridge']);
verifie('4 °C : normal', $frigo->mesure(4), 0);
$frigo->avance(1);
verifie('porte ouverte, 9 °C : rien encore', $frigo->mesure(9), 0);
verifie('… mais en attente d\'avertissement', $frigo->dernier['pending']['level'], 1);
$frigo->avance(3);
verifie('porte refermée, 5 °C : normal', $frigo->mesure(5), 0);
verifie('plus rien en attente', $frigo->dernier['pending'], null);
$frigo->avance(1);
$frigo->mesure(8);
$frigo->avance(19);
verifie('8 °C depuis 19 min : pas encore', $frigo->mesure(8), 0);
$frigo->avance(1);
verifie('8 °C depuis 20 min : avertissement', $frigo->mesure(8), 1);

/* ------------------------------------------------------------------ 3 --- */
echo "3. Frigo : escalade, hystérésis, retour\n";
$frigo->avance(5);
$frigo->mesure(11);
verifie('11 °C : critique en attente', $frigo->dernier['pending']['level'], 2);
verifie('… toujours avertissement', $frigo->dernier['level'], 1);
$frigo->avance(20);
verifie('11 °C depuis 20 min : critique', $frigo->mesure(11), 2);
$frigo->avance(1);
verifie('9,5 °C : reste critique (hystérésis 1 °C)', $frigo->mesure(9.5), 2);
$frigo->avance(1);
verifie('8,9 °C : redescend en avertissement', $frigo->mesure(8.9), 1);
$frigo->avance(1);
verifie('6,5 °C : reste en avertissement (hystérésis)', $frigo->mesure(6.5), 1);
verifie('pic retenu', alertebeEngine::peakText($frigo->regle, $frigo->etat, '°C'), '11 °C');
$frigo->avance(1);
verifie('5,9 °C : normal', $frigo->mesure(5.9), 0);
verifie('pic gardé pour le message de fin', alertebeEngine::peakText($frigo->regle, $frigo->etat, '°C'), '11 °C');
$frigo->mesure(9);
$frigo->avance(20);
verifie('nouvelle alerte', $frigo->mesure(9), 1);
verifie('… pic repart de la nouvelle valeur', alertebeEngine::peakText($frigo->regle, $frigo->etat, '°C'), '9 °C');

/* ------------------------------------------------------------------ 4 --- */
echo "4. Valeur absente ou capteur muet\n";
$c = new Capteur(alertebeEngine::PROFILES['fridge']);
$c->mesure(12);
$c->avance(20);
verifie('critique', $c->mesure(12), 2);
$c->avance(1);
verifie('valeur illisible : on garde le niveau', $c->mesure('N/A'), 2);
verifie('… et on le dit', $c->dernier['missing'], true);
$m = new Capteur(alertebeEngine::PROFILES['fridge']);
$collecte = $m->now;
verifie('valeur fraîche : normal', $m->mesure(4, $collecte), 0);
$m->avance(179);
verifie('muet depuis 2 h 59 : normal', $m->mesure(4, $collecte), 0);
$m->avance(1);
verifie('muet depuis 3 h : avertissement', $m->mesure(4, $collecte), 1);
verifie('… signalé comme muet', $m->dernier['stale'], true);
verifie('message du capteur muet',
        alertebeEngine::describe($m->regle, $m->dernier, 'Frigo', 4, '°C'), 'Frigo : aucune nouvelle valeur depuis 3 h');
$m->avance(1);
verifie('il reparle : normal', $m->mesure(4), 0);

/* ------------------------------------------------------------------ 5 --- */
echo "5. Incendie : seuil immédiat et hausse rapide\n";
$feu = new Capteur(alertebeEngine::PROFILES['fire']);
verifie('25 °C : normal', $feu->mesure(25), 0);
verifie('58 °C : critique sans attendre', $feu->mesure(58), 2);
$v = new Capteur(alertebeEngine::PROFILES['fire_rate']);
verifie('22 °C', $v->mesure(22), 0);
$v->avance(0.5);
verifie('21 °C (petite baisse)', $v->mesure(21), 0);
$v->avance(0.5);
verifie('26 °C : +5 depuis le point bas → avertissement', $v->mesure(26), 1);
$v->avance(0.5);
verifie('30 °C : +9 en 1 min 30 → critique', $v->mesure(30), 2);
verifie('message de hausse', alertebeEngine::describe($v->regle, $v->dernier, 'Garage', 30, '°C'),
        'Garage : +9 °C en 2 min (seuil 8 °C), 30 °C maintenant');
$v->avance(5);
verifie('stable 5 min plus tard : la fenêtre a oublié le point bas', $v->mesure(30), 0);
$lent = new Capteur(alertebeEngine::PROFILES['fire_rate']);
$lent->mesure(20);
$niveau = 0;
for ($i = 1; $i <= 30; $i++) {
    $lent->avance(1);
    $niveau = max($niveau, $lent->mesure(20 + $i));
}
verifie('+1 °C par minute pendant 30 min : jamais en alerte', $niveau, 0);

/* ------------------------------------------------------------------ 6 --- */
echo "6. Hors-gel, plage, égalité\n";
$gel = new Capteur(alertebeEngine::PROFILES['frost']);
$gel->mesure(4);
$gel->avance(10);
verifie('4 °C depuis 10 min : avertissement', $gel->mesure(4), 1);
$gel->avance(10);
verifie('2 °C : critique en attente seulement', $gel->mesure(2), 1);
$gel->avance(10);
verifie('2 °C depuis 10 min : critique', $gel->mesure(2), 2);
$cave = new Capteur(array_merge(alertebeEngine::PROFILES['humidity'], array('delay' => 0)));
verifie('55 % : normal', $cave->mesure(55), 0);
verifie('72 % : avertissement', $cave->mesure(72), 1);
verifie('69 % : reste (hystérésis 2)', $cave->mesure(69), 1);
verifie('67 % : normal', $cave->mesure(67), 0);
verifie('25 % : critique', $cave->mesure(25), 2);
verifie('seuil de plage en clair', alertebeEngine::thresholdText($cave->regle, 2, '%'), 'hors 30–80 %');
$eau = new Capteur(alertebeEngine::PROFILES['leak']);
verifie('fuite 0 : normal', $eau->mesure(0), 0);
verifie('fuite 1 : critique', $eau->mesure(1), 2);
verifie('fuite « 1.0 » : critique', $eau->mesure('1.0'), 2);
verifie('fuite 0 : normal', $eau->mesure('0'), 0);
$porte = new Capteur(array('type' => 'equal', 'warning' => 'Open'));
verifie('texte sans casse : « open »', $porte->mesure('open'), 1);
verifie('booléen vrai', (new Capteur(array('type' => 'equal', 'critical' => '1')))->mesure(true), 2);

/* ------------------------------------------------------------------ 7 --- */
echo "7. Changement de capteur\n";
$c = new Capteur(array('type' => 'above', 'warning' => 10, 'cmd' => '#1#'));
verifie('en alerte sur #1#', $c->mesure(11), 1);
$c->regle['cmd'] = '#2#';
$c->regle = alertebeEngine::cleanRule($c->regle);
verifie('nouveau capteur, 5 : normal', $c->mesure(5), 0);
verifie('état repart de zéro', $c->etat['since'][1], null);

/* ------------------------------------------------------------------ 8 --- */
echo "8. Surveillance : actions, rappels, acquittement\n";
$t = strtotime('2026-07-15 12:00:00');
$reglages = array('reminder_interval' => 15, 'reminder_max' => 2);
$types = function ($_r) { return implode(',', array_column($_r['events'], 'type')); };
$s = alertebeEngine::aggregate(array(0, 0), array(), $reglages, $t);
verifie('tout normal : rien', $types($s), '');
$s = alertebeEngine::aggregate(array(1, 0), $s['state'], $reglages, $t);
verifie('une règle en avertissement', $types($s), 'warning');
$s = alertebeEngine::aggregate(array(1, 0), $s['state'], $reglages, $t + 600);
verifie('10 min : pas encore de rappel', $types($s), '');
$s = alertebeEngine::aggregate(array(1, 1), $s['state'], $reglages, $t + 900);
verifie('15 min : rappel', $types($s), 'reminder');
$s = alertebeEngine::aggregate(array(1, 0), $s['state'], $reglages, $t + 1800);
verifie('30 min : second rappel', $types($s), 'reminder');
$s = alertebeEngine::aggregate(array(1, 0), $s['state'], $reglages, $t + 2700);
verifie('45 min : plafond de 2 rappels atteint', $types($s), '');
$s['state']['ack'] = 1;
$s = alertebeEngine::aggregate(array(2, 0), $s['state'], $reglages, $t + 3000);
verifie('escalade vers critique', $types($s), 'critical');
verifie('… l\'acquittement tombe', $s['state']['ack'], 0);
$s['state']['ack'] = 1;
$s = alertebeEngine::aggregate(array(2, 0), $s['state'], $reglages, $t + 5000);
verifie('acquittée : plus de rappel', $types($s), '');
$s = alertebeEngine::aggregate(array(1, 0), $s['state'], $reglages, $t + 5100);
verifie('critique → avertissement : noté seulement', $types($s), 'lowered');
$s = alertebeEngine::aggregate(array(0, 0), $s['state'], $reglages, $t + 5400);
verifie('retour à la normale', $types($s), 'recovery');
verifie('durée de l\'alerte', $s['events'][0]['duration'], 5400);
verifie('pire niveau atteint', $s['events'][0]['worst'], 2);
verifie('état remis à zéro', $s['state']['since'], null);
$sans = alertebeEngine::aggregate(array(1), array(), array('reminder_interval' => 0), $t);
$sans = alertebeEngine::aggregate(array(1), $sans['state'], array('reminder_interval' => 0), $t + 86400);
verifie('sans rappel réglé : silence', $types($sans), '');

/* ------------------------------------------------------------------ 9 --- */
echo "9. Textes\n";
verifie('nombre à la française', alertebeEngine::formatNumber(7.50), '7,5');
verifie('nombre entier', alertebeEngine::formatNumber(-12.0), '-12');
verifie('durée en minutes', alertebeEngine::formatDuration(1260), '21 min');
verifie('durée en heures', alertebeEngine::formatDuration(3 * 3600 + 5 * 60), '3 h 05');
verifie('durée en jours', alertebeEngine::formatDuration(3 * 86400), '3 jours');
verifie('seuil bas', alertebeEngine::thresholdText(alertebeEngine::cleanRule(alertebeEngine::PROFILES['frost']), 2, '°C'), '≤ 3 °C');
$fr = new Capteur(alertebeEngine::PROFILES['freezer']);
$fr->mesure(-10);
$fr->avance(30);
$fr->mesure(-10);
verifie('message congélateur', alertebeEngine::describe($fr->regle, $fr->dernier, 'Congélateur', -10, '°C'),
        'Congélateur : -10 °C (≥ -12 °C)');

echo "\n" . ($ok + $ko) . " vérifications, " . $ko . " échec(s).\n";
exit($ko > 0 ? 1 : 0);
