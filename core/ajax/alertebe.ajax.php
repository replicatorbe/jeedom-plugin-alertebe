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

try {
    require_once __DIR__ . '/../../../../core/php/core.inc.php';
    include_file('core', 'authentification', 'php');
    /* L'autoload du cœur ne connaît que la classe qui porte le nom du plugin :
     * le moteur se charge par elle. */
    require_once __DIR__ . '/../class/alertebe.class.php';

    if (!isConnect('admin')) {
        throw new Exception(__('401 - Accès non autorisé', __FILE__));
    }

    ajax::init();

    /* eqLogic::byId() charge n'importe quel équipement : sans ce contrôle, un
     * identifiant étranger ferait agir le plugin sur l'équipement d'un autre. */
    $getAlert = function ($_id) {
        $eqLogic = alertebe::byId($_id);
        if (!is_object($eqLogic) || $eqLogic->getEqType_name() != 'alertebe') {
            throw new Exception(__('Surveillance introuvable', __FILE__));
        }
        return $eqLogic;
    };

    /* L'état du moment, pour le panneau de la page. Avec refresh=1, la
     * surveillance repasse d'abord : le bouton « Évaluer maintenant ». */
    if (init('action') == 'status') {
        $eqLogic = $getAlert(init('id'));
        if (init('refresh') == 1) {
            $eqLogic->evaluate('page');
        }
        ajax::success($eqLogic->pageStatus());
    }

    if (init('action') == 'overview') {
        ajax::success(alertebe::overview());
    }

    $by = alertebe::currentUser();

    if (init('action') == 'ack') {
        $eqLogic = $getAlert(init('id'));
        $eqLogic->acknowledge($by);
        ajax::success(alertebe::byId($eqLogic->getId())->pageStatus());
    }

    if (init('action') == 'suspend') {
        $eqLogic = $getAlert(init('id'));
        $eqLogic->suspend(init('minutes'), $by);
        ajax::success(alertebe::byId($eqLogic->getId())->pageStatus());
    }

    if (init('action') == 'disarm') {
        $eqLogic = $getAlert(init('id'));
        $eqLogic->disarm($by);
        ajax::success(alertebe::byId($eqLogic->getId())->pageStatus());
    }

    if (init('action') == 'resume') {
        $eqLogic = $getAlert(init('id'));
        $eqLogic->resume($by);
        ajax::success(alertebe::byId($eqLogic->getId())->pageStatus());
    }

    /* Les actions enregistrées d'un déclencheur, jamais une liste envoyée par
     * le navigateur : il faut donc sauvegarder avant de tester. */
    if (init('action') == 'testActions') {
        $eqLogic = $getAlert(init('id'));
        ajax::success($eqLogic->testActions(init('trigger')));
    }

    throw new Exception(__('Aucune méthode correspondante à :', __FILE__) . ' ' . init('action'));
} catch (Exception $e) {
    ajax::error(displayException($e), $e->getCode());
}
