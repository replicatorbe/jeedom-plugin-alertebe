<?php
/* This file is part of Jeedom.
 *
 * Jeedom is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * Jeedom is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with Jeedom. If not, see <http://www.gnu.org/licenses/>.
 */

require_once __DIR__ . '/../../../../core/php/core.inc.php';
require_once __DIR__ . '/alertebeEngine.class.php';

/*
 * Un équipement = une surveillance : « Frigo cuisine », « Incendie garage ».
 * Elle a ses règles — un capteur, un type, deux seuils — et ses actions, jouées
 * quand son niveau change.
 *
 * Cette classe fait le lien avec Jeedom et rien d'autre : elle lit les
 * capteurs, appelle le moteur (alertebeEngine), joue les actions et tient les
 * commandes à jour. Toute la décision est dans le moteur, et c'est voulu : elle
 * s'éprouve hors ligne, pas ici.
 *
 * Aucun démon : un écouteur sur les capteurs fait réagir la surveillance à la
 * seconde — c'est capital pour un incendie — et le cron du cœur passe chaque
 * minute pour ce qui dépend du temps seul : durées de confirmation, rappels,
 * capteurs muets.
 */
class alertebe extends eqLogic {

    /* Ce qui déclenche des actions, dans l'ordre de l'onglet « Actions ». Un
     * rappel rejoue les actions du niveau en cours. */
    const TRIGGERS = array('warning', 'critical', 'recovery');

    /*
     * Les blocs du sélecteur d'actions refusés ici. Les actions sont jouées
     * dans le cron du cœur ou dans l'écouteur d'un capteur, partagés par tous
     * les plugins : « Attendre », « Pause », « Faire une demande » et les
     * rapports y retiendraient tout le monde. Les autres n'ont de sens que dans
     * un scénario. Pour une séquence, l'action « Scénario » est faite pour ça.
     */
    const REFUSED_BLOCKS = array('wait', 'sleep', 'ask', 'report', 'exportHistory',
                                 'stop', 'log', 'scenario_return', 'icon', 'tag');

    const JOURNAL_SIZE = 50;

    /* Un rappel toutes les 30 minutes, trois au plus : assez pour qu'une
     * alerte de nuit ne passe pas inaperçue, pas assez pour qu'on coupe les
     * notifications du téléphone. */
    const DEFAULT_REMINDER_INTERVAL = 30;
    const DEFAULT_REMINDER_MAX = 3;

    /* ==================================================================== CRON */

    public static function cron() {
        foreach (self::byType(__CLASS__, true) as $eqLogic) {
            try {
                $eqLogic->evaluate('cron');
            } catch (Throwable $e) {
                log::add(__CLASS__, 'error', $eqLogic->getHumanName() . ' : ' . $e->getMessage());
            }
        }
    }

    /* L'écouteur : un capteur surveillé a publié une valeur. */
    public static function pull($_options) {
        $eqLogic = self::byId($_options['eqLogic_id']);
        if (!is_object($eqLogic) || $eqLogic->getIsEnable() != 1) {
            return;
        }
        try {
            $eqLogic->evaluate('event');
        } catch (Throwable $e) {
            log::add(__CLASS__, 'error', $eqLogic->getHumanName() . ' : ' . $e->getMessage());
        }
    }

    /* ===================================================== CYCLE DE VIE eqLogic */

    public function preSave() {
        $this->setConfiguration('rules', alertebeEngine::cleanRules($this->getConfiguration('rules', array())));
        $this->setConfiguration('actions', self::cleanActions($this->getConfiguration('actions', array())));
        $interval = alertebeEngine::number($this->getConfiguration('reminder_interval', ''));
        $this->setConfiguration('reminder_interval',
            ($interval === null) ? self::DEFAULT_REMINDER_INTERVAL : max(0, min(1440, (int) $interval)));
        $max = alertebeEngine::number($this->getConfiguration('reminder_max', ''));
        $this->setConfiguration('reminder_max', ($max === null) ? self::DEFAULT_REMINDER_MAX : max(0, min(100, (int) $max)));
        if ($this->getConfiguration('message_center', '') === '') {
            $this->setConfiguration('message_center', 1);
        }
    }

    public function postSave() {
        $this->createCommands();
        $this->updateListener();
        if ($this->getIsEnable() != 1) {
            /* Désactivé, l'équipement ne reçoit plus rien : ses commandes
             * resteraient figées sur « Critique ». L'alerte en cours est oubliée,
             * comme pour une surveillance désactivée ; la réactivation repart de
             * zéro, durées de confirmation comprises. */
            if ($this->loadState()['eq']['level'] != alertebeEngine::NORMAL) {
                $this->silence();
                $this->journal(__('Équipement désactivé : alerte en cours oubliée', __FILE__), 'user');
            }
            return;
        }
        try {
            $this->evaluate('save');
        } catch (Throwable $e) {
            log::add(__CLASS__, 'error', $this->getHumanName() . ' : ' . $e->getMessage());
        }
    }

    public function preRemove() {
        $listener = listener::byClassAndFunction(__CLASS__, 'pull', array('eqLogic_id' => intval($this->getId())));
        if (is_object($listener)) {
            $listener->remove();
        }
        foreach (array('state', 'details', 'journal') as $name) {
            cache::delete($this->rtKey($name));
        }
        foreach (array('state', 'journal', 'disarmed', 'suspended_until') as $name) {
            config::remove($this->rtKey($name), __CLASS__);
        }
        $this->removeMessages();
    }

    /* L'écouteur suit la liste des capteurs : reconstruit à chaque
     * enregistrement, il ne garde pas un capteur retiré. */
    private function updateListener() {
        $listener = listener::byClassAndFunction(__CLASS__, 'pull', array('eqLogic_id' => intval($this->getId())));
        $ids = array();
        foreach ($this->getConfiguration('rules', array()) as $rule) {
            $id = self::cmdIdOf($rule['cmd']);
            if ($rule['enable'] == 1 && $id !== null) {
                $ids[] = $id;
            }
        }
        $ids = array_values(array_unique($ids));
        if ($this->getIsEnable() != 1 || count($ids) == 0) {
            if (is_object($listener)) {
                $listener->remove();
            }
            return;
        }
        if (!is_object($listener)) {
            $listener = new listener();
            $listener->setClass(__CLASS__);
            $listener->setFunction('pull');
            $listener->setOption(array('eqLogic_id' => intval($this->getId())));
        }
        $listener->emptyEvent();
        foreach ($ids as $id) {
            $listener->addEvent($id);
        }
        $listener->save();
    }

    /* Le cœur enregistre une commande choisie sous la forme « #123# »
     * (jeedom::fromHumanReadable, à l'enregistrement de l'équipement). */
    public static function cmdIdOf($_expression) {
        return preg_match('/^#(\d+)#$/', trim((string) $_expression), $matches) ? (int) $matches[1] : null;
    }

    /* Les listes d'actions, au format du sélecteur des scénarios. Une ligne
     * sans commande est retirée : elle ne ferait rien, sinon encombrer. */
    public static function cleanActions($_actions) {
        $clean = array();
        foreach (self::TRIGGERS as $trigger) {
            $clean[$trigger] = array();
            if (!is_array($_actions) || !isset($_actions[$trigger]) || !is_array($_actions[$trigger])) {
                continue;
            }
            foreach ($_actions[$trigger] as $action) {
                if (!is_array($action) || !isset($action['cmd']) || trim((string) $action['cmd']) === '') {
                    continue;
                }
                $clean[$trigger][] = array(
                    'cmd'     => trim((string) $action['cmd']),
                    'options' => (isset($action['options']) && is_array($action['options'])) ? $action['options'] : array(),
                );
            }
        }
        return $clean;
    }

    /* ============================================================== COMMANDES */

    public function createCommands() {
        $definitions = array(
            array('logicalId' => 'state', 'name' => __('État', __FILE__),
                  'type' => 'info', 'subType' => 'string', 'visible' => 1),
            array('logicalId' => 'message', 'name' => __('Message', __FILE__),
                  'type' => 'info', 'subType' => 'string', 'visible' => 1),
            array('logicalId' => 'level', 'name' => __('Niveau', __FILE__),
                  'type' => 'info', 'subType' => 'numeric', 'visible' => 0, 'historized' => 1, 'min' => 0, 'max' => 2),
            array('logicalId' => 'alert', 'name' => __('En alerte', __FILE__),
                  'type' => 'info', 'subType' => 'binary', 'visible' => 0, 'historized' => 1, 'generic' => 'ALARM_STATE'),
            array('logicalId' => 'since', 'name' => __('Depuis', __FILE__),
                  'type' => 'info', 'subType' => 'string', 'visible' => 0),
            array('logicalId' => 'acknowledged', 'name' => __('Acquittée', __FILE__),
                  'type' => 'info', 'subType' => 'binary', 'visible' => 0),
            array('logicalId' => 'monitoring', 'name' => __('Surveillance active', __FILE__),
                  'type' => 'info', 'subType' => 'binary', 'visible' => 0, 'generic' => 'ALARM_ENABLE_STATE'),
            array('logicalId' => 'ack', 'name' => __('Acquitter', __FILE__),
                  'type' => 'action', 'subType' => 'other', 'visible' => 1),
            array('logicalId' => 'arm', 'name' => __('Activer la surveillance', __FILE__),
                  'type' => 'action', 'subType' => 'other', 'visible' => 0, 'value' => 'monitoring', 'generic' => 'ALARM_ARMED'),
            array('logicalId' => 'disarm', 'name' => __('Désactiver la surveillance', __FILE__),
                  'type' => 'action', 'subType' => 'other', 'visible' => 0, 'value' => 'monitoring', 'generic' => 'ALARM_RELEASED'),
            array('logicalId' => 'suspend', 'name' => __('Suspendre (minutes)', __FILE__),
                  'type' => 'action', 'subType' => 'slider', 'visible' => 0, 'min' => 5, 'max' => 1440, 'step' => 5),
            array('logicalId' => 'resume', 'name' => __('Reprendre', __FILE__),
                  'type' => 'action', 'subType' => 'other', 'visible' => 0),
            array('logicalId' => 'refresh', 'name' => __('Rafraîchir', __FILE__),
                  'type' => 'action', 'subType' => 'other', 'visible' => 1),
        );

        $order = 0;
        $created = array();
        foreach ($definitions as $definition) {
            $cmd = $this->getCmd(null, $definition['logicalId']);
            if (!is_object($cmd)) {
                /* Créée une fois : ensuite, nom, affichage et historique sont
                 * à l'utilisateur, une mise à jour du plugin n'y touche pas. */
                $cmd = new alertebeCmd();
                $cmd->setLogicalId($definition['logicalId']);
                $cmd->setEqLogic_id($this->getId());
                $cmd->setName($definition['name']);
                $cmd->setType($definition['type']);
                $cmd->setSubType($definition['subType']);
                $cmd->setIsVisible($definition['visible']);
                if (isset($definition['historized'])) {
                    $cmd->setIsHistorized($definition['historized']);
                }
                if (isset($definition['min'])) {
                    $cmd->setConfiguration('minValue', $definition['min']);
                    $cmd->setConfiguration('maxValue', $definition['max']);
                }
                if (isset($definition['step'])) {
                    $cmd->setDisplay('parameters', array('step' => $definition['step']));
                }
                $cmd->setOrder($order);
                $cmd->save();
            }
            /* Le type générique fait d'une surveillance une alarme pour l'appli
             * mobile et les ponts (Homebridge, Google) : état, activation,
             * désactivation. Posé aussi sur les commandes d'avant la 0.3, mais
             * jamais par-dessus un choix de l'utilisateur. */
            if (isset($definition['generic']) && $cmd->getGeneric_type() == '') {
                $cmd->setGeneric_type($definition['generic']);
                $cmd->save();
            }
            $created[$definition['logicalId']] = $cmd;
            $order++;
        }
        /* Les actions liées à leur info, pour les widgets et l'appli mobile. */
        foreach (array('arm', 'disarm') as $logicalId) {
            if ($created[$logicalId]->getValue() != $created['monitoring']->getId()) {
                $created[$logicalId]->setValue($created['monitoring']->getId());
                $created[$logicalId]->save();
            }
        }
    }

    /* ================================================================== ÉTAT */

    public function rtKey($_name) {
        return 'eq' . $this->getId() . '::' . $_name;
    }

    /*
     * L'état des règles et de la surveillance : en cache à chaque passage, en
     * base quand un niveau change. Un redémarrage de Jeedom retrouve ainsi une
     * alerte en cours, et ne rejoue pas ses actions.
     *
     * Dans la configuration du plugin et non dans celle de l'équipement : le
     * cron travaille sur un équipement chargé au début de son passage, et
     * l'enregistrer effacerait un réglage sauvegardé entre-temps depuis la page.
     */
    private function loadState() {
        $state = cache::byKey($this->rtKey('state'))->getValue(null);
        if (!is_array($state)) {
            $state = json_decode((string) config::byKey($this->rtKey('state'), __CLASS__, ''), true);
        }
        if (!is_array($state)) {
            $state = array();
        }
        return array_merge(array('eq' => alertebeEngine::defaultEqState(), 'rules' => array()), $state);
    }

    private function storeState($_state, $_persist) {
        cache::set($this->rtKey('state'), $_state);
        if ($_persist) {
            config::save($this->rtKey('state'), json_encode($_state), __CLASS__);
        }
    }

    /* Surveillance désactivée (jusqu'à nouvel ordre) ou suspendue (pour un
     * temps : « je nettoie le frigo »). Rend 'on', 'disarmed' ou 'suspended'. */
    public function monitoringMode() {
        if (config::byKey($this->rtKey('disarmed'), __CLASS__, 0) == 1) {
            return 'disarmed';
        }
        $until = (int) config::byKey($this->rtKey('suspended_until'), __CLASS__, 0);
        return ($until > time()) ? 'suspended' : 'on';
    }

    public function suspendedUntil() {
        return (int) config::byKey($this->rtKey('suspended_until'), __CLASS__, 0);
    }

    /*
     * Couper la surveillance remet tout à zéro, sans jouer d'action de fin
     * d'alerte : personne n'a réparé le frigo, on a seulement décidé de ne plus
     * le regarder. À la reprise, une alerte toujours là repasse par sa durée de
     * confirmation et redéclenche ses actions.
     */
    private function silence() {
        $this->storeState(array('eq' => alertebeEngine::defaultEqState(), 'rules' => array()), true);
        cache::delete($this->rtKey('details'));
        $this->removeMessages();
    }

    /*
     * Un message par alerte et par niveau : « alert12::1758964800::critical ».
     * Le cœur ne recrée pas un message dont l'identifiant existe déjà, il en
     * change seulement la date : avec un identifiant par surveillance, le
     * passage en critique et les alertes suivantes gardaient le texte de la
     * première, et l'action globale « sur nouveau message » ne partait plus.
     */
    private function messageId($_since, $_type) {
        return 'alert' . $this->getId() . '::' . (int) $_since . '::' . $_type;
    }

    /* Les messages de la surveillance, et celui d'avant la 0.3 qui n'avait pas
     * de suffixe. */
    private function removeMessages() {
        message::removeAll(__CLASS__, 'alert' . $this->getId());
        message::removeAll(__CLASS__, 'alert' . $this->getId() . '::', true);
    }

    public function disarm($_by = '') {
        $this->withLock(function () use ($_by) {
            config::save($this->rtKey('disarmed'), 1, __CLASS__);
            config::remove($this->rtKey('suspended_until'), __CLASS__);
            $this->silence();
            $this->journal(__('Surveillance désactivée', __FILE__) . self::byText($_by), 'user');
            $this->refreshCommands();
        });
    }

    public function suspend($_minutes, $_by = '') {
        $minutes = alertebeEngine::number($_minutes);
        if ($minutes === null || $minutes <= 0) {
            throw new Exception(__('Durée de suspension invalide', __FILE__));
        }
        $minutes = min(10080, $minutes);
        $this->withLock(function () use ($minutes, $_by) {
            $until = time() + (int) round($minutes * 60);
            config::save($this->rtKey('suspended_until'), $until, __CLASS__);
            $this->silence();
            $this->journal(__('Surveillance suspendue jusqu\'à', __FILE__) . ' ' . self::whenText($until) . self::byText($_by), 'user');
            $this->refreshCommands();
        });
    }

    /* Activer comme reprendre : les deux rendent la main aux règles. */
    public function resume($_by = '') {
        $this->withLock(function () use ($_by) {
            $wasOff = $this->monitoringMode() != 'on';
            config::remove($this->rtKey('disarmed'), __CLASS__);
            config::remove($this->rtKey('suspended_until'), __CLASS__);
            if ($wasOff) {
                $this->journal(__('Surveillance reprise', __FILE__) . self::byText($_by), 'user');
            }
        });
        $this->evaluate('resume');
    }

    public function acknowledge($_by = '') {
        $this->withLock(function () use ($_by) {
            $state = $this->loadState();
            if ($state['eq']['level'] == alertebeEngine::NORMAL || $state['eq']['ack'] == 1) {
                return;
            }
            $state['eq']['ack'] = 1;
            $state['eq']['ack_by'] = (string) $_by;
            $state['eq']['ack_at'] = time();
            $this->storeState($state, true);
            $this->journal(__('Alerte acquittée', __FILE__) . self::byText($_by), 'user');
            $this->refreshCommands();
        });
    }

    private static function byText($_by) {
        return ($_by === '') ? '' : ' ' . __('par', __FILE__) . ' ' . $_by;
    }

    /* L'utilisateur derrière une commande : le tableau de bord le passe dans
     * les options, la page du plugin a la session. */
    public static function currentUser($_options = array()) {
        if (is_array($_options) && isset($_options['user_login']) && $_options['user_login'] !== '') {
            return (string) $_options['user_login'];
        }
        if (isset($_SESSION['user']) && is_object($_SESSION['user'])) {
            return (string) $_SESSION['user']->getLogin();
        }
        return '';
    }

    /* ============================================================ ÉVALUATION */

    /*
     * Protégé par un verrou de fichier : le cron et l'écouteur peuvent tomber
     * dans la même seconde, et deux passages simultanés joueraient deux fois
     * les actions d'une même alerte.
     */
    private function withLock($_callback) {
        /* Sans verrou, on passe quand même : un fichier de verrou illisible ne
         * doit pas faire taire une alerte incendie. */
        $lock = @fopen(jeedom::getTmpFolder(__CLASS__) . '/eq' . $this->getId() . '.lock', 'c');
        if ($lock === false || !flock($lock, LOCK_EX)) {
            log::add(__CLASS__, 'warning', $this->getHumanName() . ' : '
                . __('verrou indisponible, passage sans verrou', __FILE__));
            if ($lock !== false) {
                fclose($lock);
            }
            return $_callback();
        }
        try {
            return $_callback();
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    public function evaluate($_origin = 'cron') {
        if ($this->getIsEnable() != 1) {
            return;
        }
        $this->withLock(function () use ($_origin) {
            $this->evaluateLocked($_origin);
        });
    }

    /* Un capteur, tel que Jeedom le connaît maintenant. */
    public static function readSensor($_expression) {
        $sensor = array('found' => false, 'value' => null, 'collected' => null, 'name' => (string) $_expression, 'unit' => '',
                        'problem' => __('capteur introuvable', __FILE__));
        $id = self::cmdIdOf($_expression);
        if ($id === null) {
            return $sensor;
        }
        try {
            $cmd = cmd::byId($id);
            if (!is_object($cmd) || $cmd->getType() != 'info') {
                return $sensor;
            }
            $sensor['name'] = $cmd->getHumanName();
            /* Jeedom ignore les valeurs d'un équipement désactivé et n'en rend
             * plus qu'une vide : la règle serait aveugle sans le savoir. */
            $eqLogic = $cmd->getEqLogic();
            if (!is_object($eqLogic) || $eqLogic->getIsEnable() != 1) {
                $sensor['problem'] = __('l\'équipement du capteur est désactivé', __FILE__);
                return $sensor;
            }
            $sensor['found'] = true;
            $sensor['problem'] = '';
            $sensor['unit'] = (string) $cmd->getUnite();
            $sensor['value'] = $cmd->execCmd();
            $collected = strtotime((string) $cmd->getCollectDate());
            $sensor['collected'] = ($collected !== false && $collected > 0) ? $collected : null;
        } catch (Throwable $e) {
            $sensor['value'] = null;
        }
        return $sensor;
    }

    private function evaluateLocked($_origin) {
        $now = time();
        $mode = $this->monitoringMode();
        if ($mode == 'suspended') {
            $this->refreshCommands();
            return;
        }
        if ($mode == 'on' && $this->suspendedUntil() > 0) {
            /* La suspension vient d'expirer. */
            config::remove($this->rtKey('suspended_until'), __CLASS__);
            $this->journal(__('Fin de la suspension : surveillance reprise', __FILE__), 'user');
        }
        if ($mode == 'disarmed') {
            $this->refreshCommands();
            return;
        }

        $state = $this->loadState();
        $levels = array();
        $details = array();
        $ruleStates = array();
        $changed = false;
        foreach ($this->getConfiguration('rules', array()) as $rule) {
            $name = ($rule['name'] !== '') ? $rule['name'] : '';
            if ($rule['enable'] != 1) {
                $details[] = array('id' => $rule['id'], 'name' => $name, 'enabled' => false);
                continue;
            }
            if ($rule['cmd'] === '') {
                /* Une règle en cours de réglage : rien à surveiller encore. */
                $details[] = array('id' => $rule['id'], 'enabled' => true, 'no_sensor' => true,
                                   'name' => ($name === '') ? __('Règle sans capteur', __FILE__) : $name,
                                   'rule' => $rule, 'level' => 0, 'was' => 0);
                continue;
            }
            $sensor = self::readSensor($rule['cmd']);
            $previous = isset($state['rules'][$rule['id']]) ? $state['rules'][$rule['id']] : array();
            $result = alertebeEngine::evaluateRule($rule, $sensor['found'] ? $sensor['value'] : null,
                                                   $sensor['collected'], $previous, $now);
            if (!$sensor['found']) {
                /* Un capteur supprimé ou renommé hors de Jeedom : la règle ne
                 * voit plus rien. Se taire serait dire « tout va bien » sur un
                 * frigo qu'on ne regarde plus. */
                $result['level'] = max($result['level'], alertebeEngine::WARNING);
            }
            /* Le niveau affiché, capteur muet compris : le retour à la normale
             * nomme les règles qui étaient en alerte. */
            $result['state']['shown'] = $result['level'];
            $ruleStates[$rule['id']] = $result['state'];
            $levels[$rule['id']] = $result['level'];
            $previousLevel = isset($previous['level']) ? (int) $previous['level'] : 0;
            $previousShown = isset($previous['shown']) ? (int) $previous['shown'] : 0;
            if ($previousLevel != (int) $result['state']['level'] || $previousShown != $result['level']) {
                $changed = true;
            }
            if ($name === '') {
                $name = $sensor['name'];
            }
            $text = $sensor['found'] ? alertebeEngine::describe($rule, $result, $name, $sensor['value'], $sensor['unit'])
                                     : $name . ' : ' . $sensor['problem'];
            $details[] = array(
                'id'        => $rule['id'],
                'enabled'   => true,
                'name'      => $name,
                'sensor'    => $sensor['name'],
                'found'     => $sensor['found'],
                'problem'   => $sensor['problem'],
                'rule'      => $rule,
                'unit'      => $sensor['unit'],
                'value'     => $sensor['value'],
                'level'     => $result['level'],
                'was'       => $previousShown,
                'metric'    => $result['metric'],
                'stale'     => $result['stale'],
                'age'       => $result['age'],
                'missing'   => $result['missing'],
                'pending'   => $result['pending'],
                'text'      => $text,
                'peak'      => alertebeEngine::peakText($rule, $result['state'], $sensor['unit']),
            );
        }
        $state['rules'] = $ruleStates;

        $settings = array(
            'reminder_interval' => $this->getConfiguration('reminder_interval', self::DEFAULT_REMINDER_INTERVAL),
            'reminder_max'      => $this->getConfiguration('reminder_max', self::DEFAULT_REMINDER_MAX),
        );
        $aggregate = alertebeEngine::aggregate($levels, $state['eq'], $settings, $now);
        $before = $state['eq'];
        $state['eq'] = $aggregate['state'];
        if (count($aggregate['events']) > 0) {
            $changed = true;
        }
        $this->storeState($state, $changed);
        cache::set($this->rtKey('details'), array('at' => $now, 'rules' => $details));

        $context = $this->alertContext($details, $state['eq'], $now);
        foreach ($aggregate['events'] as $event) {
            $this->handleEvent($event, $context, $before);
        }
        $this->refreshCommands($context['message']);
    }

    /* Ce que les messages et les actions disent de l'alerte : les règles en
     * alerte, la pire d'abord. */
    private function alertContext($_details, $_eqState, $_now) {
        $active = array_values(array_filter($_details, function ($_detail) {
            return $_detail['enabled'] && $_detail['level'] > alertebeEngine::NORMAL;
        }));
        usort($active, function ($_a, $_b) {
            return $_b['level'] - $_a['level'];
        });
        $worst = (count($active) > 0) ? $active[0] : null;
        $since = $_eqState['since'];
        $object = $this->getObject();
        return array(
            'details' => $_details,
            'active'  => $active,
            'worst'   => $worst,
            'message' => implode(' ; ', array_column($active, 'text')),
            'since'   => ($since === null) ? $_now : $since,
            'tags'    => array(
                '#equipement#' => $this->getName(),
                '#objet#'      => is_object($object) ? $object->getName() : '',
                '#niveau#'     => alertebeEngine::levelLabel($_eqState['level']),
                '#message#'    => implode(' ; ', array_column($active, 'text')),
                '#regle#'      => ($worst === null) ? '' : $worst['name'],
                '#capteur#'    => ($worst === null) ? '' : $worst['sensor'],
                '#valeur#'     => ($worst === null) ? '' : alertebeEngine::formatNumber($worst['value']),
                '#unite#'      => ($worst === null) ? '' : $worst['unit'],
                '#seuil#'      => ($worst === null) ? '' : alertebeEngine::thresholdText($worst['rule'], max(1, $worst['level']), $worst['unit']),
                '#pic#'        => ($worst === null) ? '' : $worst['peak'],
                '#depuis#'     => ($since === null) ? '' : date('H:i', $since),
                '#duree#'      => ($since === null) ? '' : alertebeEngine::formatDuration($_now - $since),
                '#heure#'      => date('H:i', $_now),
                '#rappel#'     => '0',
                '#acquitte_par#' => '',
            ),
        );
    }

    private function handleEvent($_event, $_context, $_before) {
        $label = alertebeEngine::levelLabel($_event['level']);
        switch ($_event['type']) {
            case 'warning':
            case 'critical':
                $text = $label . ' : ' . $_context['message'];
                $this->journal($text, $_event['type']);
                /* Pas « error » : une alerte n'est pas une panne du plugin, et le
                 * cœur transforme les erreurs de plugin en messages à part. */
                log::add(__CLASS__, ($_event['type'] == 'critical') ? 'warning' : 'info', $this->getHumanName() . ' ' . $text);
                if ($this->getConfiguration('message_center', 1) == 1) {
                    message::add(__CLASS__, $this->getHumanName() . ' — ' . $text, '',
                                 $this->messageId($_context['since'], $_event['type']));
                }
                $this->runActions($_event['type'], $_context['tags']);
                return;
            case 'lowered':
                $this->journal(__('Redescend en avertissement', __FILE__) . ' : ' . $_context['message'], 'warning');
                return;
            case 'reminder':
                $this->journal(__('Rappel', __FILE__) . ' ' . $_event['count'] . ' — ' . $label . ' : ' . $_context['message'],
                               ($_event['level'] == alertebeEngine::CRITICAL) ? 'critical' : 'warning');
                $tags = $_context['tags'];
                $tags['#rappel#'] = (string) $_event['count'];
                $this->runActions(($_event['level'] == alertebeEngine::CRITICAL) ? 'critical' : 'warning', $tags);
                return;
            case 'recovery':
                $text = __('Retour à la normale après', __FILE__) . ' ' . alertebeEngine::formatDuration($_event['duration'])
                    . ' (' . __('pire niveau', __FILE__) . ' : ' . mb_strtolower(alertebeEngine::levelLabel($_event['worst'])) . ')';
                $this->journal($text, 'recovery');
                log::add(__CLASS__, 'info', $this->getHumanName() . ' ' . $text);
                $tags = $_context['tags'];
                $tags['#niveau#'] = alertebeEngine::levelLabel(alertebeEngine::NORMAL);
                $tags['#message#'] = $text;
                $tags['#duree#'] = alertebeEngine::formatDuration($_event['duration']);
                /* Les règles qui étaient en alerte, et le pire qu'elles ont
                 * atteint : « Frigo : 11,2 °C ». */
                $ended = array_values(array_filter($_context['details'], function ($_detail) {
                    return $_detail['enabled'] && $_detail['was'] > alertebeEngine::NORMAL;
                }));
                if (count($ended) > 0) {
                    $tags['#regle#'] = implode(', ', array_column($ended, 'name'));
                    $tags['#capteur#'] = implode(', ', array_column($ended, 'sensor'));
                    $tags['#valeur#'] = alertebeEngine::formatNumber($ended[0]['value']);
                    $tags['#unite#'] = $ended[0]['unit'];
                    $tags['#pic#'] = implode(', ', array_filter(array_map(function ($_detail) {
                        return ($_detail['peak'] === '') ? '' : $_detail['name'] . ' : ' . $_detail['peak'];
                    }, $ended)));
                }
                $tags['#depuis#'] = ($_before['since'] === null) ? '' : date('H:i', $_before['since']);
                /* Seul moment où il sert : une alerte en cours n'est jamais
                 * acquittée quand ses actions partent, sinon elles ne partiraient
                 * pas. */
                $tags['#acquitte_par#'] = ($_before['ack'] == 1) ? (string) $_before['ack_by'] : '';
                $this->runActions('recovery', $tags);
                return;
        }
    }

    /* ============================================================== ACTIONS */

    /*
     * Joue les actions d'un déclencheur. scenarioExpression::createAndExec est
     * le primitif du cœur pour une action configurée par l'utilisateur : il
     * accepte une commande comme un bloc (message, scénario, variable), honore
     * les cases « activée » et « en tâche de fond » posées par le sélecteur.
     *
     * Une action en échec ne retient pas les suivantes : une notification
     * cassée ne doit pas empêcher la sirène de sonner. Rend la liste des
     * échecs, pour le bouton « Tester ».
     */
    public function runActions($_trigger, $_tags) {
        $list = $this->actionsFor($_trigger);
        $errors = array();
        foreach ($list as $action) {
            $options = (isset($action['options']) && is_array($action['options'])) ? $action['options'] : array();
            if (isset($options['enable']) && $options['enable'] == 0) {
                continue;
            }
            /* Les jetons du cœur d'abord (variable(), une commande citée dans le
             * texte), les nôtres ensuite : dans l'autre sens, un message
             * contenant « (≥ 10 °C) » serait relu comme une expression. */
            $scenario = null;
            foreach ($options as $key => $value) {
                if (!is_string($value) || $key === 'enable' || $key === 'background') {
                    continue;
                }
                $value = scenarioExpression::setTags($value, $scenario);
                $options[$key] = str_replace(array_keys($_tags), array_values($_tags), $value);
            }
            try {
                $this->runAction($action['cmd'], $options);
            } catch (Throwable $e) {
                $errors[] = $action['cmd'] . ' — ' . $e->getMessage();
                log::add(__CLASS__, 'error', $this->getHumanName() . ' ' . __('action en échec :', __FILE__)
                    . ' ' . $action['cmd'] . ' — ' . $e->getMessage());
            }
        }
        return $errors;
    }

    /*
     * Les actions d'un déclencheur. Sans action critique réglée, le critique
     * joue celles de l'avertissement : qui n'a réglé qu'une notification
     * s'attend à la recevoir aussi quand le frigo passe à 12 °C, et une alerte
     * qui monte directement en critique ne doit pas rester muette.
     */
    public function actionsFor($_trigger, &$_fallback = false) {
        $actions = self::cleanActions($this->getConfiguration('actions', array()));
        $_fallback = false;
        if (!isset($actions[$_trigger])) {
            return array();
        }
        if ($_trigger == 'critical' && count($actions['critical']) == 0 && count($actions['warning']) > 0) {
            $_fallback = true;
            return $actions['warning'];
        }
        return $actions[$_trigger];
    }

    private function runAction($_expression, $_options) {
        $expression = trim((string) $_expression);
        if (in_array($expression, self::REFUSED_BLOCKS, true)) {
            throw new Exception(__('bloc inutilisable ici :', __FILE__) . ' ' . $expression);
        }
        $id = self::cmdIdOf($expression);
        if ($id === null) {
            /* Un bloc : message, scénario, variable… 'source' sert de libellé
             * d'origine au bloc « message », affiché au centre de messages. */
            $_options['source'] = $this->getHumanName();
            scenarioExpression::createAndExec('action', $expression, $_options);
            return;
        }
        $cmd = cmd::byId($id);
        if (!is_object($cmd)) {
            throw new Exception(__('commande introuvable :', __FILE__) . ' ' . $expression);
        }
        if ($cmd->getType() != 'action') {
            throw new Exception(__('ce n\'est pas une commande d\'action :', __FILE__) . ' ' . $cmd->getHumanName());
        }
        /* En tâche de fond, le cœur relance l'action dans un autre processus :
         * c'est ce que l'utilisateur a demandé en cochant la case. */
        if (isset($_options['background']) && $_options['background'] == 1) {
            scenarioExpression::createAndExec('action', $expression, $_options);
            return;
        }
        unset($_options['enable'], $_options['background']);
        log::add(__CLASS__, 'debug', $this->getHumanName() . ' → ' . $cmd->getHumanName());
        $cmd->execCmd($_options);
    }

    /* Le bouton « Tester » de la page : les actions d'un déclencheur, avec un
     * message d'essai reconnaissable, sans rien changer à l'état. */
    public function testActions($_trigger) {
        if (!in_array($_trigger, self::TRIGGERS, true)) {
            throw new Exception(__('Déclencheur inconnu :', __FILE__) . ' ' . $_trigger);
        }
        $level = ($_trigger == 'critical') ? alertebeEngine::CRITICAL
               : (($_trigger == 'warning') ? alertebeEngine::WARNING : alertebeEngine::NORMAL);
        $prefix = '[' . __('Essai', __FILE__) . '] ';
        $object = $this->getObject();
        $tags = array(
            '#equipement#' => $this->getName(),
            '#objet#'      => is_object($object) ? $object->getName() : '',
            '#niveau#'     => alertebeEngine::levelLabel($level),
            '#message#'    => $prefix . __('ceci est un essai des actions', __FILE__) . ' « '
                              . alertebeEngine::levelLabel($level) . ' » ' . __('de', __FILE__) . ' ' . $this->getName(),
            '#regle#'      => $prefix . __('règle', __FILE__),
            '#capteur#'    => $prefix . __('capteur', __FILE__),
            '#valeur#'     => '0',
            '#unite#'      => '',
            '#seuil#'      => '',
            '#pic#'        => '',
            '#depuis#'     => date('H:i'),
            '#duree#'      => '0 s',
            '#heure#'      => date('H:i'),
            '#rappel#'     => '0',
            '#acquitte_par#' => ($_trigger == 'recovery') ? $prefix . self::currentUser() : '',
        );
        $fallback = false;
        $count = count($this->actionsFor($_trigger, $fallback));
        $errors = $this->runActions($_trigger, $tags);
        $this->journal(__('Essai des actions', __FILE__) . ' « ' . self::triggerLabel($_trigger) . ' »'
            . ($fallback ? ' (' . __('celles de l\'avertissement, aucune n\'étant réglée pour le critique', __FILE__) . ')' : ''), 'user');
        return array('count' => $count, 'errors' => $errors, 'fallback' => $fallback);
    }

    public static function triggerLabel($_trigger) {
        switch ($_trigger) {
            case 'warning':  return __('Avertissement', __FILE__);
            case 'critical': return __('Critique', __FILE__);
            case 'recovery': return __('Retour à la normale', __FILE__);
        }
        return $_trigger;
    }

    /* ======================================================== AFFICHAGE */

    private function refreshCommands($_message = null) {
        $mode = $this->monitoringMode();
        $state = $this->loadState();
        $level = ($mode == 'on') ? (int) $state['eq']['level'] : alertebeEngine::NORMAL;
        if ($mode == 'disarmed') {
            $label = __('Désactivée', __FILE__);
        } elseif ($mode == 'suspended') {
            $label = __('Suspendue jusqu\'à', __FILE__) . ' ' . self::whenText($this->suspendedUntil());
        } else {
            $label = alertebeEngine::levelLabel($level);
        }
        if ($_message === null) {
            $details = cache::byKey($this->rtKey('details'))->getValue(array());
            $texts = array();
            if ($mode == 'on' && is_array($details) && isset($details['rules'])) {
                $active = array_filter($details['rules'], function ($_d) {
                    return $_d['enabled'] && $_d['level'] > 0;
                });
                usort($active, function ($_a, $_b) { return $_b['level'] - $_a['level']; });
                $texts = array_column($active, 'text');
            }
            $_message = implode(' ; ', $texts);
        }
        $this->checkAndUpdateCmd('state', $label);
        $this->checkAndUpdateCmd('level', $level);
        $this->checkAndUpdateCmd('alert', ($level > 0) ? 1 : 0);
        $this->checkAndUpdateCmd('message', ($level > 0) ? $_message : '');
        $this->checkAndUpdateCmd('since', ($level > 0 && $state['eq']['since'] !== null) ? date('Y-m-d H:i:s', $state['eq']['since']) : '');
        $this->checkAndUpdateCmd('acknowledged', ($level > 0 && $state['eq']['ack'] == 1) ? 1 : 0);
        $this->checkAndUpdateCmd('monitoring', ($mode == 'on') ? 1 : 0);
    }

    public function journal($_text, $_kind = 'info') {
        $entries = $this->journalEntries();
        array_unshift($entries, array('at' => time(), 'kind' => $_kind, 'text' => (string) $_text));
        $entries = array_slice($entries, 0, self::JOURNAL_SIZE);
        cache::set($this->rtKey('journal'), $entries);
        config::save($this->rtKey('journal'), json_encode($entries, JSON_UNESCAPED_UNICODE), __CLASS__);
    }

    public function journalEntries() {
        $entries = cache::byKey($this->rtKey('journal'))->getValue(null);
        if (!is_array($entries)) {
            $entries = json_decode((string) config::byKey($this->rtKey('journal'), __CLASS__, ''), true);
        }
        return is_array($entries) ? $entries : array();
    }

    /* « 14:02 », « hier 23:10 », « 21/09 06:30 » — et « 28/09 06:30 » pour
     * la fin d'une suspension qui dépasse minuit. */
    public static function whenText($_at) {
        $day = date('Y-m-d', $_at);
        if ($day == date('Y-m-d')) {
            return date('H:i', $_at);
        }
        if ($day == date('Y-m-d', strtotime('-1 day'))) {
            return __('hier', __FILE__) . ' ' . date('H:i', $_at);
        }
        return date('d/m H:i', $_at);
    }

    public function journalForDisplay() {
        return array_map(function ($_entry) {
            return array('when' => self::whenText($_entry['at']), 'kind' => $_entry['kind'], 'text' => $_entry['text']);
        }, $this->journalEntries());
    }

    /* L'état de la surveillance, pour la page de l'équipement. */
    public function pageStatus() {
        $mode = $this->monitoringMode();
        $state = $this->loadState();
        $details = cache::byKey($this->rtKey('details'))->getValue(array());
        $eq = $state['eq'];
        $level = ($mode == 'on') ? (int) $eq['level'] : 0;
        $rules = array();
        if (is_array($details) && isset($details['rules'])) {
            foreach ($details['rules'] as $detail) {
                if (!$detail['enabled']) {
                    $rules[] = array('name' => $detail['name'], 'enabled' => false);
                    continue;
                }
                $rule = $detail['rule'];
                if (!empty($detail['no_sensor'])) {
                    $rules[] = array('name' => $detail['name'], 'enabled' => true, 'no_sensor' => true,
                                     'problems' => alertebeEngine::ruleProblems($rule));
                    continue;
                }
                $pending = '';
                if ($detail['pending'] !== null) {
                    $pending = alertebeEngine::levelLabel($detail['pending']['level']) . ' ' . __('à', __FILE__)
                        . ' ' . date('H:i', $detail['pending']['at']);
                }
                $thresholds = array();
                foreach (array(alertebeEngine::WARNING, alertebeEngine::CRITICAL) as $l) {
                    $text = alertebeEngine::thresholdText($rule, $l, $detail['unit']);
                    if ($text !== '') {
                        $thresholds[] = alertebeEngine::levelLabel($l) . ' ' . $text;
                    }
                }
                $rules[] = array(
                    'name'       => $detail['name'],
                    'enabled'    => true,
                    'sensor'     => $detail['sensor'],
                    'found'      => $detail['found'],
                    'problem'    => isset($detail['problem']) ? $detail['problem'] : '',
                    'value'      => $detail['missing'] ? null
                                    : alertebeEngine::measureText($rule, $detail['value'], $detail['metric'], $detail['unit']),
                    'level'      => $detail['level'],
                    'text'       => $detail['text'],
                    'label'      => alertebeEngine::levelLabel($detail['level']),
                    'stale'      => $detail['stale'],
                    'age'        => ($detail['age'] === null) ? null : alertebeEngine::formatDuration($detail['age']),
                    'pending'    => $pending,
                    'thresholds' => implode(' · ', $thresholds),
                    'problems'   => alertebeEngine::ruleProblems($rule),
                );
            }
        }
        return array(
            'mode'      => $mode,
            'suspended_until' => ($mode == 'suspended') ? self::whenText($this->suspendedUntil()) : '',
            'level'     => $level,
            'label'     => alertebeEngine::levelLabel($level),
            'since'     => ($level > 0 && $eq['since'] !== null) ? self::whenText($eq['since']) : '',
            'duration'  => ($level > 0 && $eq['since'] !== null) ? alertebeEngine::formatDuration(time() - $eq['since']) : '',
            'ack'       => ($level > 0 && $eq['ack'] == 1),
            'ack_by'    => $eq['ack_by'],
            'at'        => (is_array($details) && isset($details['at'])) ? date('H:i:s', $details['at']) : '',
            'rules'     => $rules,
            'journal'   => $this->journalForDisplay(),
        );
    }

    /* La vue d'ensemble : toutes les surveillances, et les derniers événements
     * de toutes, du plus récent au plus ancien. */
    public static function overview() {
        $rows = array();
        $events = array();
        foreach (self::byType(__CLASS__) as $eqLogic) {
            $status = $eqLogic->pageStatus();
            $message = '';
            foreach ($status['rules'] as $rule) {
                if ($rule['enabled'] && !empty($rule['level'])) {
                    $message .= (($message === '') ? '' : ' ; ') . $rule['text'];
                }
            }
            $rows[] = array(
                'id'       => $eqLogic->getId(),
                'name'     => $eqLogic->getHumanName(),
                'enabled'  => $eqLogic->getIsEnable() == 1,
                'mode'     => $status['mode'],
                'suspended_until' => $status['suspended_until'],
                'level'    => $status['level'],
                'label'    => $status['label'],
                'since'    => $status['since'],
                'duration' => $status['duration'],
                'ack'      => $status['ack'],
                'message'  => $message,
                'rules'    => count($eqLogic->getConfiguration('rules', array())),
            );
            foreach ($eqLogic->journalEntries() as $entry) {
                $events[] = array('at' => $entry['at'], 'eq' => $eqLogic->getName(), 'kind' => $entry['kind'], 'text' => $entry['text']);
            }
        }
        usort($rows, function ($_a, $_b) {
            return ($_b['level'] - $_a['level']) ?: strcmp($_a['name'], $_b['name']);
        });
        usort($events, function ($_a, $_b) { return $_b['at'] - $_a['at']; });
        $events = array_map(function ($_e) {
            return array('when' => self::whenText($_e['at']), 'eq' => $_e['eq'], 'kind' => $_e['kind'], 'text' => $_e['text']);
        }, array_slice($events, 0, 20));
        return array('rows' => $rows, 'events' => $events);
    }
}

/*
 * La classe de commande est obligatoire, même réduite à son execute() : sans
 * elle, le cœur refuse de créer un équipement du plugin.
 */
class alertebeCmd extends cmd {

    public function execute($_options = array()) {
        $eqLogic = $this->getEqLogic();
        $by = alertebe::currentUser($_options);
        switch ($this->getLogicalId()) {
            case 'ack':
                $eqLogic->acknowledge($by);
                return;
            case 'arm':
            case 'resume':
                $eqLogic->resume($by);
                return;
            case 'disarm':
                $eqLogic->disarm($by);
                return;
            case 'suspend':
                $eqLogic->suspend(isset($_options['slider']) ? $_options['slider'] : 60, $by);
                return;
            case 'refresh':
                $eqLogic->evaluate('command');
                return;
        }
    }
}
