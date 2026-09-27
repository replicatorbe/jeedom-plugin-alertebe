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

/*
 * Le moteur des alertes : ce qu'une mesure vaut comme niveau d'alerte, et ce
 * que le changement de niveau d'une surveillance doit déclencher.
 *
 * Il ne connaît pas Jeedom, et c'est voulu : une alerte se trompe en silence.
 * Un frigo qui sonne à chaque ouverture de porte, une alerte qui clignote
 * autour du seuil, un incendie repéré trop tard : rien de cela ne lève
 * d'erreur. Chaque cas se rejoue hors ligne, minute par minute, dans
 * tests/run.php.
 *
 * Trois niveaux : 0 normal, 1 avertissement, 2 critique.
 */
class alertebeEngine {

    const NORMAL = 0;
    const WARNING = 1;
    const CRITICAL = 2;

    /*
     * Les types de règle, dans l'ordre de la liste déroulante.
     *
     * « rise » et « fall » ne regardent pas la valeur mais sa variation sur une
     * fenêtre de quelques minutes : c'est le critère d'un détecteur de chaleur
     * thermovélocimétrique. Une pièce qui prend 8 °C en deux minutes brûle,
     * bien avant d'atteindre 57 °C au plafond.
     */
    const TYPES = array('above', 'below', 'outside', 'equal', 'rise', 'fall');

    /*
     * Les échantillons d'une variation sont regroupés par tranches : au plus
     * SAMPLE_SLOTS tranches sur la fenêtre, de 10 secondes au moins. Chaque
     * tranche garde son extrême (le plus bas pour une hausse, le plus haut pour
     * une baisse) : un capteur qui publie chaque seconde ne fait ni grossir
     * l'état sans fin, ni oublier le point de départ d'une hausse.
     */
    const SAMPLE_SLOTS = 200;

    /*
     * Les profils : des règles préremplies, toutes modifiables ensuite.
     *
     * Les durées de confirmation sont le cœur de l'affaire. Un frigo ouvert
     * trente secondes prend 3 °C au capteur de la porte : sans 20 minutes de
     * patience, il sonnerait à chaque yaourt. Un incendie, lui, n'attend pas.
     */
    const PROFILES = array(
        'fridge'   => array('type' => 'above', 'warning' => 7, 'critical' => 10, 'delay' => 20, 'hysteresis' => 1, 'stale_after' => 180),
        'freezer'  => array('type' => 'above', 'warning' => -15, 'critical' => -12, 'delay' => 30, 'hysteresis' => 1, 'stale_after' => 180),
        'fire'     => array('type' => 'above', 'warning' => 50, 'critical' => 57, 'delay' => 0, 'hysteresis' => 2, 'stale_after' => 0),
        'fire_rate' => array('type' => 'rise', 'warning' => 5, 'critical' => 8, 'window' => 2, 'delay' => 0, 'hysteresis' => 1, 'stale_after' => 0),
        'frost'    => array('type' => 'below', 'warning' => 5, 'critical' => 3, 'delay' => 10, 'hysteresis' => 0.5, 'stale_after' => 180),
        'leak'     => array('type' => 'equal', 'warning' => '', 'critical' => '1', 'delay' => 0, 'hysteresis' => 0, 'stale_after' => 0),
        'humidity' => array('type' => 'outside', 'warning_low' => 40, 'warning_high' => 70, 'critical_low' => 30, 'critical_high' => 80,
                            'delay' => 60, 'hysteresis' => 2, 'stale_after' => 360),
        'co2'      => array('type' => 'above', 'warning' => 1000, 'critical' => 1500, 'delay' => 5, 'hysteresis' => 100, 'stale_after' => 60),
    );

    /* Les champs d'une règle qui portent un seuil. */
    const THRESHOLD_KEYS = array('warning', 'critical', 'warning_low', 'warning_high', 'critical_low', 'critical_high');

    /* ============================================================== OUTILS */

    private static function t($_text) {
        return function_exists('__') ? __($_text, __FILE__) : $_text;
    }

    /* Un nombre tapé à la française, « 20,5 », vaut 20.5. Tout le reste, vide
     * compris, vaut null : un seuil vide est un seuil absent, pas zéro. */
    public static function number($_value) {
        if (is_int($_value) || is_float($_value)) {
            return (float) $_value;
        }
        if (!is_string($_value)) {
            return null;
        }
        $text = str_replace(array(' ', "\u{a0}", ','), array('', '', '.'), trim($_value));
        if ($text === '' || !is_numeric($text)) {
            return null;
        }
        return (float) $text;
    }

    private static function bounded($_value, $_min, $_max, $_default) {
        $number = self::number($_value);
        if ($number === null) {
            return $_default;
        }
        return max($_min, min($_max, $number));
    }

    public static function formatNumber($_value) {
        if ($_value === null || $_value === '') {
            return '';
        }
        if (!is_numeric($_value)) {
            return (string) $_value;
        }
        $text = number_format((float) $_value, 2, ',', '');
        $text = rtrim(rtrim($text, '0'), ',');
        return ($text === '-0') ? '0' : $text;
    }

    public static function formatDuration($_seconds) {
        $seconds = max(0, (int) round($_seconds));
        if ($seconds < 60) {
            return $seconds . ' s';
        }
        $minutes = (int) floor($seconds / 60);
        if ($minutes < 60) {
            return $minutes . ' min';
        }
        $hours = (int) floor($minutes / 60);
        if ($hours < 48) {
            $rest = $minutes % 60;
            return $hours . ' h' . (($rest > 0) ? ' ' . str_pad((string) $rest, 2, '0', STR_PAD_LEFT) : '');
        }
        return (int) floor($hours / 24) . ' ' . self::t('jours');
    }

    public static function levelLabel($_level) {
        switch ((int) $_level) {
            case self::WARNING:  return self::t('Avertissement');
            case self::CRITICAL: return self::t('Critique');
        }
        return self::t('Normal');
    }

    public static function typeLabel($_type) {
        switch ($_type) {
            case 'above':   return self::t('Au-dessus d\'un seuil');
            case 'below':   return self::t('En dessous d\'un seuil');
            case 'outside': return self::t('Hors d\'une plage');
            case 'equal':   return self::t('Égal à (binaire, texte)');
            case 'rise':    return self::t('Hausse rapide');
            case 'fall':    return self::t('Baisse rapide');
        }
        return $_type;
    }

    public static function profileLabel($_profile) {
        switch ($_profile) {
            case 'fridge':    return self::t('Frigo');
            case 'freezer':   return self::t('Congélateur');
            case 'fire':      return self::t('Incendie — température');
            case 'fire_rate': return self::t('Incendie — hausse rapide');
            case 'frost':     return self::t('Hors-gel');
            case 'leak':      return self::t('Fuite d\'eau / inondation');
            case 'humidity':  return self::t('Humidité hors plage (cave)');
            case 'co2':       return self::t('Qualité de l\'air (CO2)');
        }
        return $_profile;
    }

    /* =============================================================== RÈGLES */

    /*
     * Remet une règle en forme : défauts, bornes, nombres relus. Ce qui entre
     * ici vient d'un formulaire, et ce qui en sort est ce que le moteur lit.
     *
     * L'identifiant est posé une fois et ne change plus : l'état de chaque
     * règle y est rangé, si bien qu'ajouter ou retirer une autre règle ne fait
     * pas repartir de zéro une alerte en cours.
     */
    public static function cleanRule($_raw) {
        $raw = is_array($_raw) ? $_raw : array();
        $type = (isset($raw['type']) && in_array($raw['type'], self::TYPES, true)) ? $raw['type'] : 'above';
        $id = isset($raw['id']) ? (string) $raw['id'] : '';
        if (!preg_match('/^r[0-9a-f]{8}$/', $id)) {
            $id = 'r' . bin2hex(random_bytes(4));
        }
        $rule = array(
            'id'          => $id,
            'enable'      => (!isset($raw['enable']) || $raw['enable'] == 1) ? 1 : 0,
            'name'        => isset($raw['name']) ? trim((string) $raw['name']) : '',
            'cmd'         => isset($raw['cmd']) ? trim((string) $raw['cmd']) : '',
            'type'        => $type,
            'delay'       => self::bounded(isset($raw['delay']) ? $raw['delay'] : null, 0, 1440, 0),
            'hysteresis'  => self::bounded(isset($raw['hysteresis']) ? $raw['hysteresis'] : null, 0, 1000000, 0),
            'window'      => self::bounded(isset($raw['window']) ? $raw['window'] : null, 1, 1440, 5),
            'stale_after' => self::bounded(isset($raw['stale_after']) ? $raw['stale_after'] : null, 0, 10080, 0),
        );
        foreach (self::THRESHOLD_KEYS as $key) {
            $value = isset($raw[$key]) ? $raw[$key] : '';
            if ($type === 'equal') {
                /* Une valeur à reconnaître, pas un nombre : « 1 », « on »,
                 * « open », ou plusieurs séparées par | : « open|ouvert|1 ».
                 * Seuls warning et critical ont un sens ici. */
                $rule[$key] = in_array($key, array('warning', 'critical'), true)
                    ? implode('|', self::equalValues($value)) : '';
            } else {
                $number = self::number($value);
                $rule[$key] = ($number === null) ? '' : $number;
            }
        }
        return $rule;
    }

    public static function cleanRules($_rules) {
        if (!is_array($_rules)) {
            return array();
        }
        $clean = array();
        $seen = array();
        foreach ($_rules as $raw) {
            $rule = self::cleanRule($raw);
            /* Deux règles copiées l'une de l'autre dans la page partageraient
             * leur état : la seconde reçoit un identifiant neuf. */
            while (isset($seen[$rule['id']])) {
                $rule['id'] = 'r' . bin2hex(random_bytes(4));
            }
            $seen[$rule['id']] = true;
            $clean[] = $rule;
        }
        return $clean;
    }

    /* Une règle qui ne peut rien déclencher : aucun seuil renseigné. Elle
     * reste enregistrée, mais la page le signale. */
    public static function ruleHasThreshold($_rule) {
        foreach (self::thresholdKeys($_rule['type']) as $key) {
            if ($_rule[$key] !== '' && $_rule[$key] !== null) {
                return true;
            }
        }
        return self::number($_rule['stale_after']) > 0;
    }

    /*
     * Ce qui cloche dans une règle, en clair, pour la page : elle s'enregistre
     * quand même, mais ne fera pas ce qu'on croit.
     */
    public static function ruleProblems($_rule) {
        $problems = array();
        if (!self::ruleHasThreshold($_rule)) {
            $problems[] = self::t('aucun seuil : la règle ne peut rien déclencher');
            return $problems;
        }
        $warning = self::threshold($_rule, 'warning');
        $critical = self::threshold($_rule, 'critical');
        switch ($_rule['type']) {
            case 'above':
            case 'rise':
            case 'fall':
                if ($warning !== null && $critical !== null && $critical < $warning) {
                    $problems[] = self::t('le seuil critique est plus bas que l\'avertissement : l\'avertissement ne servira jamais');
                }
                break;
            case 'below':
                if ($warning !== null && $critical !== null && $critical > $warning) {
                    $problems[] = self::t('le seuil critique est plus haut que l\'avertissement : l\'avertissement ne servira jamais');
                }
                break;
            case 'outside':
                foreach (array('warning', 'critical') as $prefix) {
                    $low = self::threshold($_rule, $prefix . '_low');
                    $high = self::threshold($_rule, $prefix . '_high');
                    if ($low !== null && $high !== null && $low >= $high) {
                        $problems[] = self::t('plage inversée') . ' (' . self::levelLabel(($prefix == 'critical') ? self::CRITICAL : self::WARNING) . ')';
                    }
                }
                break;
            case 'equal':
                if ($warning !== null && $critical !== null) {
                    $lower = function ($_values) { return array_map('mb_strtolower', $_values); };
                    if (count(array_intersect($lower(self::equalValues($warning)), $lower(self::equalValues($critical)))) > 0) {
                        $problems[] = self::t('même valeur pour les deux niveaux : seul le critique servira');
                    }
                }
                break;
        }
        if ($_rule['type'] !== 'equal' && $_rule['hysteresis'] > 0 && $warning !== null && $critical !== null
            && $_rule['hysteresis'] >= abs($critical - $warning) && $_rule['type'] !== 'outside') {
            $problems[] = self::t('hystérésis plus large que l\'écart entre les deux seuils');
        }
        return $problems;
    }

    /* Les valeurs d'une règle « égal à » : « open | ouvert » en donne deux. */
    public static function equalValues($_text) {
        $values = array();
        foreach (explode('|', (string) $_text) as $value) {
            if (trim($value) !== '') {
                $values[] = trim($value);
            }
        }
        return $values;
    }

    private static function thresholdKeys($_type) {
        return ($_type === 'outside') ? array('warning_low', 'warning_high', 'critical_low', 'critical_high')
                                      : array('warning', 'critical');
    }

    public static function profileRule($_profile) {
        if (!isset(self::PROFILES[$_profile])) {
            return null;
        }
        $rule = self::cleanRule(array_merge(self::PROFILES[$_profile], array('name' => self::profileLabel($_profile))));
        unset($rule['id']);
        return $rule;
    }

    /* ============================================================ ÉVALUATION */

    private static function threshold($_rule, $_key) {
        if ($_rule['type'] === 'equal') {
            return ($_rule[$_key] === '') ? null : (string) $_rule[$_key];
        }
        return self::number($_rule[$_key]);
    }

    /*
     * La grandeur que la règle compare à ses seuils : la valeur elle-même, ou
     * sa variation sur la fenêtre pour une hausse ou une baisse rapide.
     *
     * La variation se mesure contre le point le plus bas (hausse) ou le plus
     * haut (baisse) de la fenêtre, et non contre le premier : une température
     * qui baisse d'un degré puis monte de huit a bien monté de huit.
     */
    private static function metric($_rule, $_value, &$_state, $_now) {
        if ($_rule['type'] === 'equal') {
            if ($_value === null || (is_string($_value) && trim($_value) === '')) {
                return null;
            }
            return is_bool($_value) ? ($_value ? '1' : '0') : trim((string) $_value);
        }
        $value = self::number($_value);
        if ($value === null) {
            return null;
        }
        if ($_rule['type'] !== 'rise' && $_rule['type'] !== 'fall') {
            return $value;
        }
        $rise = ($_rule['type'] === 'rise');
        $window = $_rule['window'] * 60;
        $slot = max(10, (int) ceil($window / self::SAMPLE_SLOTS));
        $bucket = (int) (floor($_now / $slot) * $slot);
        $samples = array();
        foreach ($_state['samples'] as $sample) {
            if (is_array($sample) && count($sample) == 2 && $sample[0] >= $_now - $window && $sample[0] <= $_now) {
                $samples[] = $sample;
            }
        }
        $last = count($samples) - 1;
        if ($last >= 0 && $samples[$last][0] == $bucket) {
            $samples[$last][1] = $rise ? min($samples[$last][1], $value) : max($samples[$last][1], $value);
        } else {
            $samples[] = array($bucket, $value);
        }
        $_state['samples'] = $samples;
        $values = array_column($samples, 1);
        return $rise ? $value - min($values) : max($values) - $value;
    }

    /*
     * La grandeur est-elle au niveau demandé ?
     *
     * $_relaxed : on y est déjà, et on n'en sort qu'une fois l'hystérésis
     * franchie. Un frigo en alerte à 7 °C avec 1 °C d'hystérésis ne revient à
     * la normale qu'à 6 °C : sans cela, une valeur qui danse entre 6,9 et 7,1
     * enverrait une alerte et une fin d'alerte à chaque mesure.
     */
    public static function inLevel($_rule, $_level, $_metric, $_relaxed) {
        $prefix = ($_level == self::CRITICAL) ? 'critical' : 'warning';
        $margin = $_relaxed ? (float) $_rule['hysteresis'] : 0.0;
        switch ($_rule['type']) {
            case 'equal':
                $expected = self::threshold($_rule, $prefix);
                if ($expected === null) {
                    return false;
                }
                foreach (self::equalValues($expected) as $value) {
                    if (is_numeric($value) && is_numeric($_metric)) {
                        if ((float) $value == (float) $_metric) {
                            return true;
                        }
                    } elseif (mb_strtolower($value) === mb_strtolower((string) $_metric)) {
                        return true;
                    }
                }
                return false;
            case 'outside':
                $low = self::threshold($_rule, $prefix . '_low');
                $high = self::threshold($_rule, $prefix . '_high');
                return ($low !== null && $_metric < $low + $margin) || ($high !== null && $_metric > $high - $margin);
            case 'below':
                $limit = self::threshold($_rule, $prefix);
                return $limit !== null && $_metric <= $limit + $margin;
            default:
                /* above, rise, fall : plus la grandeur est haute, pire c'est. */
                $limit = self::threshold($_rule, $prefix);
                return $limit !== null && $_metric >= $limit - $margin;
        }
    }

    private static function ruleState($_state, $_rule) {
        $state = is_array($_state) ? $_state : array();
        /* Une règle qui change de capteur ou de type repart de zéro : les
         * échantillons et les délais de l'ancien capteur ne disent rien du
         * nouveau. */
        $signature = $_rule['cmd'] . '|' . $_rule['type'];
        if (!isset($state['signature']) || $state['signature'] !== $signature) {
            $state = array();
        }
        return array_merge(array(
            'signature' => $signature,
            'level'     => self::NORMAL,
            'since'     => array(self::WARNING => null, self::CRITICAL => null),
            'samples'   => array(),
            'peak_min'  => null,
            'peak_max'  => null,
        ), $state);
    }

    /*
     * Une règle devant une mesure.
     *
     *   $_value     la valeur du capteur, telle que Jeedom la rend ;
     *   $_collected l'horodatage de cette valeur (null si inconnu) ;
     *   $_state     l'état rendu au passage précédent (array() la première fois).
     *
     * La durée de confirmation se compte par niveau : le frigo doit rester
     * au-dessus de 7 °C vingt minutes d'affilée pour passer en avertissement,
     * et au-dessus de 10 °C vingt minutes pour passer en critique. Une seule
     * mesure revenue sous le seuil remet le compteur de ce niveau à zéro.
     *
     * La descente, elle, est immédiate — l'hystérésis suffit à la stabiliser :
     * retenir une fin d'alerte ne protège de rien.
     *
     * Une valeur absente ou illisible ne change rien : on garde le niveau
     * connu. Un capteur qui se tait est l'affaire de « stale_after ».
     */
    public static function evaluateRule($_rule, $_value, $_collected, $_state, $_now) {
        $state = self::ruleState($_state, $_rule);
        $result = array(
            'level'   => self::NORMAL,
            'raw'     => self::NORMAL,
            'metric'  => null,
            'stale'   => false,
            'age'     => null,
            'missing' => false,
            'pending' => null,
        );

        if ($_collected !== null && $_collected > 0) {
            $result['age'] = max(0, $_now - (int) $_collected);
        }
        $staleAfter = (float) $_rule['stale_after'];
        if ($staleAfter > 0 && $result['age'] !== null && $result['age'] >= $staleAfter * 60) {
            $result['stale'] = true;
        }

        $metric = self::metric($_rule, $_value, $state, $_now);
        if ($metric === null) {
            $result['missing'] = true;
            $confirmed = (int) $state['level'];
        } else {
            $result['metric'] = $metric;
            $raw = self::NORMAL;
            foreach (array(self::CRITICAL, self::WARNING) as $level) {
                if (self::inLevel($_rule, $level, $metric, false)
                    || ($level <= $state['level'] && self::inLevel($_rule, $level, $metric, true))) {
                    $raw = $level;
                    break;
                }
            }
            $result['raw'] = $raw;
            foreach (array(self::WARNING, self::CRITICAL) as $level) {
                if ($raw >= $level) {
                    if ($state['since'][$level] === null) {
                        $state['since'][$level] = $_now;
                    }
                } else {
                    $state['since'][$level] = null;
                }
            }
            $delay = (float) $_rule['delay'] * 60;
            $confirmed = self::NORMAL;
            foreach (array(self::CRITICAL, self::WARNING) as $level) {
                if ($state['since'][$level] !== null && $_now - $state['since'][$level] >= $delay) {
                    $confirmed = $level;
                    break;
                }
            }
            if ($raw > $confirmed) {
                $result['pending'] = array('level' => $raw, 'at' => (int) ceil($state['since'][$raw] + $delay));
            }
            /* Le pire atteint pendant l'alerte, pour le message de fin. Remis
             * à zéro quand une alerte commence, et non quand elle finit : le
             * message de retour à la normale en a encore besoin. */
            if ($confirmed > self::NORMAL && (int) $state['level'] == self::NORMAL) {
                $state['peak_min'] = null;
                $state['peak_max'] = null;
            }
            if ($confirmed > self::NORMAL && is_float($metric)) {
                $state['peak_min'] = ($state['peak_min'] === null) ? $metric : min($state['peak_min'], $metric);
                $state['peak_max'] = ($state['peak_max'] === null) ? $metric : max($state['peak_max'], $metric);
            }
        }

        $state['level'] = $confirmed;
        $result['level'] = max($confirmed, $result['stale'] ? self::WARNING : self::NORMAL);
        $result['state'] = $state;
        return $result;
    }

    /* ======================================================== SURVEILLANCE */

    public static function defaultEqState() {
        return array(
            'level'          => self::NORMAL,
            'since'          => null,
            'worst'          => self::NORMAL,
            'ack'            => 0,
            'ack_by'         => '',
            'ack_at'         => null,
            'reminder_last'  => null,
            'reminder_count' => 0,
        );
    }

    /*
     * Le niveau d'une surveillance est le pire de ses règles. Ce sont ses
     * changements qui déclenchent quelque chose :
     *
     *   warning / critical  le niveau monte : on joue les actions du niveau ;
     *   lowered             de critique à avertissement : noté au journal,
     *                       aucune action — l'alerte n'est pas finie ;
     *   recovery            retour à la normale : actions de fin d'alerte ;
     *   reminder            l'alerte dure, personne ne l'a acquittée : les
     *                       actions du niveau en cours sont rejouées.
     *
     * Une montée vers critique efface l'acquittement : on a acquitté un frigo
     * à 8 °C, pas un frigo à 12 °C.
     */
    public static function aggregate($_levels, $_eqState, $_settings, $_now) {
        $state = array_merge(self::defaultEqState(), is_array($_eqState) ? $_eqState : array());
        $level = self::NORMAL;
        foreach ($_levels as $ruleLevel) {
            $level = max($level, (int) $ruleLevel);
        }
        $previous = (int) $state['level'];
        $events = array();

        if ($level > $previous) {
            if ($previous == self::NORMAL) {
                $state['since'] = $_now;
                $state['worst'] = $level;
            }
            $state['worst'] = max((int) $state['worst'], $level);
            $state['ack'] = 0;
            $state['ack_by'] = '';
            $state['ack_at'] = null;
            $state['reminder_last'] = $_now;
            $state['reminder_count'] = 0;
            $events[] = array('type' => ($level == self::CRITICAL) ? 'critical' : 'warning', 'level' => $level, 'from' => $previous);
        } elseif ($level < $previous && $level > self::NORMAL) {
            $events[] = array('type' => 'lowered', 'level' => $level, 'from' => $previous);
        } elseif ($level < $previous) {
            $events[] = array('type' => 'recovery', 'level' => $level, 'from' => $previous,
                              'duration' => ($state['since'] === null) ? 0 : $_now - $state['since'],
                              'worst' => (int) $state['worst']);
            $state = self::defaultEqState();
        } elseif ($level > self::NORMAL && $state['ack'] != 1) {
            $interval = (float) (isset($_settings['reminder_interval']) ? $_settings['reminder_interval'] : 0) * 60;
            $max = (int) (isset($_settings['reminder_max']) ? $_settings['reminder_max'] : 0);
            $last = ($state['reminder_last'] === null) ? $state['since'] : $state['reminder_last'];
            if ($interval > 0 && $last !== null && $_now - $last >= $interval
                && ($max == 0 || $state['reminder_count'] < $max)) {
                $state['reminder_last'] = $_now;
                $state['reminder_count']++;
                $events[] = array('type' => 'reminder', 'level' => $level, 'from' => $previous,
                                  'count' => $state['reminder_count']);
            }
        }
        $state['level'] = $level;
        return array('state' => $state, 'events' => $events);
    }

    /* =============================================================== TEXTES */

    /* Le seuil franchi, en clair : « ≥ 10 °C », « hors 40–70 % ». */
    public static function thresholdText($_rule, $_level, $_unit = '') {
        $prefix = ($_level == self::CRITICAL) ? 'critical' : 'warning';
        $unit = ($_unit === '') ? '' : ' ' . $_unit;
        switch ($_rule['type']) {
            case 'equal':
                $value = self::threshold($_rule, $prefix);
                return ($value === null) ? '' : '= ' . implode(' ' . self::t('ou') . ' ', self::equalValues($value));
            case 'outside':
                $low = self::threshold($_rule, $prefix . '_low');
                $high = self::threshold($_rule, $prefix . '_high');
                if ($low !== null && $high !== null) {
                    return self::t('hors') . ' ' . self::formatNumber($low) . '–' . self::formatNumber($high) . $unit;
                }
                if ($low !== null) {
                    return '< ' . self::formatNumber($low) . $unit;
                }
                return ($high === null) ? '' : '> ' . self::formatNumber($high) . $unit;
            case 'below':
                $limit = self::threshold($_rule, $prefix);
                return ($limit === null) ? '' : '≤ ' . self::formatNumber($limit) . $unit;
            case 'rise':
            case 'fall':
                $limit = self::threshold($_rule, $prefix);
                return ($limit === null) ? '' : (($_rule['type'] === 'rise') ? '+' : '−') . self::formatNumber($limit) . $unit
                    . ' ' . self::t('en') . ' ' . self::formatNumber($_rule['window']) . ' min';
            default:
                $limit = self::threshold($_rule, $prefix);
                return ($limit === null) ? '' : '≥ ' . self::formatNumber($limit) . $unit;
        }
    }

    /* La mesure en clair : la valeur, ou sa variation pour une hausse rapide. */
    public static function measureText($_rule, $_value, $_metric, $_unit = '') {
        $unit = ($_unit === '') ? '' : ' ' . $_unit;
        if ($_rule['type'] === 'rise' || $_rule['type'] === 'fall') {
            $sign = ($_rule['type'] === 'rise') ? '+' : '−';
            return self::formatNumber($_value) . $unit . ' (' . $sign . self::formatNumber($_metric) . $unit
                . ' ' . self::t('en') . ' ' . self::formatNumber($_rule['window']) . ' min)';
        }
        return self::formatNumber($_value) . (is_numeric($_value) ? $unit : '');
    }

    /* Le pire atteint pendant l'alerte, dans le sens où la règle regarde. */
    public static function peakText($_rule, $_state, $_unit = '') {
        $unit = ($_unit === '') ? '' : ' ' . $_unit;
        if (!is_array($_state) || !isset($_state['peak_max']) || $_state['peak_max'] === null) {
            return '';
        }
        switch ($_rule['type']) {
            case 'equal':
                return '';
            case 'below':
                return self::formatNumber($_state['peak_min']) . $unit;
            case 'outside':
                return self::formatNumber($_state['peak_min']) . ' / ' . self::formatNumber($_state['peak_max']) . $unit;
            case 'rise':
                return '+' . self::formatNumber($_state['peak_max']) . $unit;
            case 'fall':
                return '−' . self::formatNumber($_state['peak_max']) . $unit;
        }
        return self::formatNumber($_state['peak_max']) . $unit;
    }

    /* Une règle en alerte, en une phrase : « Frigo : 11,2 °C (≥ 10 °C) ». */
    public static function describe($_rule, $_result, $_name, $_value, $_unit = '') {
        if ($_result['level'] == self::NORMAL) {
            return '';
        }
        $valueLevel = (int) $_result['state']['level'];
        if ($_result['stale'] && $valueLevel == self::NORMAL) {
            return $_name . ' : ' . self::t('aucune nouvelle valeur depuis') . ' ' . self::formatDuration($_result['age']);
        }
        if ($_rule['type'] === 'rise' || $_rule['type'] === 'fall') {
            /* « Garage : +9 °C en 2 min (seuil 8 °C), 30 °C maintenant » : la
             * variation d'abord, c'est elle qui a déclenché. */
            $unit = ($_unit === '') ? '' : ' ' . $_unit;
            $prefix = ($valueLevel == self::CRITICAL) ? 'critical' : 'warning';
            $text = $_name . ' : ' . (($_rule['type'] === 'rise') ? '+' : '−') . self::formatNumber($_result['metric']) . $unit
                . ' ' . self::t('en') . ' ' . self::formatNumber($_rule['window']) . ' min';
            if (self::threshold($_rule, $prefix) !== null) {
                $text .= ' (' . self::t('seuil') . ' ' . self::formatNumber(self::threshold($_rule, $prefix)) . $unit . ')';
            }
            if ($_result['metric'] !== null) {
                $text .= ', ' . self::formatNumber($_value) . $unit . ' ' . self::t('maintenant');
            }
        } else {
            $text = $_name . ' : ' . self::measureText($_rule, $_value, $_result['metric'], $_unit);
            $threshold = self::thresholdText($_rule, $valueLevel, $_unit);
            if ($threshold !== '') {
                $text .= ' (' . $threshold . ')';
            }
        }
        if ($_result['stale']) {
            $text .= ', ' . self::t('capteur muet depuis') . ' ' . self::formatDuration($_result['age']);
        }
        return $text;
    }
}
