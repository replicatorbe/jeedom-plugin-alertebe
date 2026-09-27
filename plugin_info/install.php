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

require_once __DIR__ . '/../../../core/php/core.inc.php';
/* La désinstallation passe ici alors que le plugin peut déjà être désactivé :
 * l'autoload ne chargerait alors plus sa classe. */
require_once __DIR__ . '/../core/class/alertebe.class.php';

function alertebe_install() {
}

function alertebe_update() {
    /* Les surveillances déjà créées n'ont pas les commandes ajoutées depuis.
     * Une à la fois, sous son propre try : une surveillance en défaut ne doit
     * pas priver les autres de leur mise à jour. */
    foreach (eqLogic::byType('alertebe') as $eqLogic) {
        try {
            $eqLogic->createCommands();
        } catch (Throwable $e) {
            log::add('alertebe', 'error', $eqLogic->getHumanName() . ' : ' . $e->getMessage());
        }
    }
}

function alertebe_remove() {
    foreach (eqLogic::byType('alertebe') as $eqLogic) {
        $listener = listener::byClassAndFunction('alertebe', 'pull', array('eqLogic_id' => intval($eqLogic->getId())));
        if (is_object($listener)) {
            $listener->remove();
        }
    }
    message::removeAll('alertebe');
}
