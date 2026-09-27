/* This file is part of Jeedom.
 *
 * Jeedom is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * Jeedom is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with Jeedom. If not, see <http://www.gnu.org/licenses/>.
 */

/* ================================================================== OUTILS */

function alertebeMarkModified() {
  if (typeof jeeFrontEnd !== 'undefined') { jeeFrontEnd.modifyWithoutSave = true }
  window.modifyWithoutSave = true
}

function alertebeCurrentId() {
  var field = document.querySelector('.eqLogicAttr[data-l1key="id"]')
  return (field && field.value !== '') ? field.value : null
}

/* Construit en DOM, sans HTML concaténé : noms de capteurs et messages viennent
   des équipements de l'utilisateur, et une balise dans un nom ne doit pas
   s'exécuter dans la page. */
function alertebeText(_tag, _text, _className) {
  var element = document.createElement(_tag)
  element.textContent = (_text === null || _text === undefined) ? '' : _text
  if (_className) { element.className = _className }
  return element
}

/* _anyPage : la réponse vaut quelle que soit la surveillance ouverte — la vue
   d'ensemble agit sur des surveillances qui ne sont pas à l'écran. */
function alertebeRequest(_data, _success, _failure, _anyPage) {
  var id = _data.id
  domUtils.ajax({
    type: 'POST',
    url: 'plugins/alertebe/core/ajax/alertebe.ajax.php',
    data: _data,
    dataType: 'json',
    noDisplayError: true,
    error: function (request, status, error) {
      domUtils.handleAjaxError(request, status, error)
      if (_failure) { _failure() }
    },
    success: function (data) {
      if (data.state != 'ok') {
        jeedomUtils.showAlert({ message: data.result, level: 'danger' })
        if (_failure) { _failure() }
        return
      }
      /* La page a pu changer de surveillance pendant l'aller-retour. */
      if (!_anyPage && id !== undefined && alertebeCurrentId() !== id) { return }
      _success(data.result)
    }
  })
}

var alertebeLevelClass = { 0: 'label-success', 1: 'label-warning', 2: 'label-danger' }
var alertebeJournalClass = { critical: 'text-danger', warning: 'text-warning', recovery: 'text-success', user: 'text-primary', info: 'text-muted' }

function alertebeLabel(_text, _className) {
  var label = alertebeText('span', _text, 'label ' + _className)
  label.style.fontSize = '12px'
  return label
}

/* L'étiquette d'état d'une surveillance : coupée, suspendue, ou son niveau. */
function alertebeStateLabel(_status) {
  if (_status.mode == 'disarmed') { return alertebeLabel('{{Désactivée}}', 'label-default') }
  if (_status.mode == 'suspended') { return alertebeLabel('{{Suspendue jusqu\'à}} ' + _status.suspended_until, 'label-default') }
  return alertebeLabel(_status.label, alertebeLevelClass[_status.level] || 'label-default')
}

function alertebeJournalTable(_entries, _withEq) {
  var table = document.createElement('table')
  table.className = 'table table-condensed'
  table.style.margin = '0'
  _entries.forEach(function (entry) {
    var tr = document.createElement('tr')
    var when = alertebeText('td', entry.when, 'text-muted')
    when.style.whiteSpace = 'nowrap'
    when.style.width = '1%'
    tr.appendChild(when)
    if (_withEq) {
      var eq = alertebeText('td', entry.eq)
      eq.style.whiteSpace = 'nowrap'
      eq.style.width = '1%'
      tr.appendChild(eq)
    }
    tr.appendChild(alertebeText('td', entry.text, alertebeJournalClass[entry.kind] || ''))
    table.appendChild(tr)
  })
  return table
}

/* ========================================================= VUE D'ENSEMBLE */

function alertebeLoadOverview() {
  var root = document.getElementById('div_alertebeOverview')
  if (!root) { return }
  alertebeRequest({ action: 'overview' }, function (result) {
    root.innerHTML = ''
    var table = document.createElement('table')
    table.className = 'table table-condensed table-bordered'
    var head = document.createElement('tr')
    ;['{{Surveillance}}', '{{État}}', '{{Depuis}}', '{{Détail}}', ''].forEach(function (title) {
      head.appendChild(alertebeText('th', title))
    })
    var thead = document.createElement('thead')
    thead.appendChild(head)
    table.appendChild(thead)
    var tbody = document.createElement('tbody')
    result.rows.forEach(function (row) {
      var tr = document.createElement('tr')
      var name = alertebeText('td', '')
      var link = alertebeText('a', row.name, 'cursor')
      link.setAttribute('data-eqLogic_id', row.id)
      link.classList.add('abOverviewOpen')
      name.appendChild(link)
      tr.appendChild(name)
      var state = document.createElement('td')
      if (!row.enabled) {
        state.appendChild(alertebeLabel('{{Équipement désactivé}}', 'label-default'))
      } else {
        state.appendChild(alertebeStateLabel(row))
        if (row.ack) { state.appendChild(alertebeText('span', ' {{acquittée}}', 'text-muted')) }
      }
      tr.appendChild(state)
      tr.appendChild(alertebeText('td', row.since ? row.since + ' (' + row.duration + ')' : '', 'text-muted'))
      tr.appendChild(alertebeText('td', row.rules == 0 ? '{{aucune règle}}' : row.message))
      var actions = document.createElement('td')
      actions.style.width = '1%'
      actions.style.whiteSpace = 'nowrap'
      if (row.enabled && row.level > 0 && !row.ack && row.mode == 'on') {
        var ack = alertebeText('a', ' {{Acquitter}}', 'btn btn-warning btn-xs abOverviewAck')
        ack.insertBefore(document.createElement('i'), ack.firstChild).className = 'fas fa-check'
        ack.setAttribute('data-id', row.id)
        actions.appendChild(ack)
      }
      tr.appendChild(actions)
      tbody.appendChild(tr)
    })
    table.appendChild(tbody)
    root.appendChild(table)
    alertebeMarkCards(result.rows)
    if (result.events.length > 0) {
      root.appendChild(alertebeText('div', '{{Derniers événements}}', 'text-muted')).style.margin = '5px 0'
      root.appendChild(alertebeJournalTable(result.events, true))
    }
  })
}

/* L'état sur chaque tuile de la liste : une surveillance en alerte se voit
   sans lire le tableau. */
function alertebeMarkCards(_rows) {
  _rows.forEach(function (row) {
    var card = document.querySelector('.eqLogicDisplayCard[data-eqLogic_id="' + row.id + '"]')
    if (!card) { return }
    card.querySelectorAll('.abCardState').forEach(function (old) { old.remove() })
    if (!row.enabled) { return }
    var label = alertebeStateLabel(row)
    label.classList.add('abCardState')
    label.style.display = 'inline-block'
    label.style.marginTop = '4px'
    card.appendChild(document.createElement('br')).className = 'abCardState'
    card.appendChild(label)
  })
}

/* ============================================================ EN CE MOMENT */

function alertebeShowStatus(_status) {
  var root = document.getElementById('div_alertebeStatus')
  if (!root) { return }
  root.innerHTML = ''
  document.getElementById('span_alertebeAt').textContent = (_status && _status.at) ? '{{évaluée à}} ' + _status.at : ''
  alertebeShowJournal(_status ? _status.journal : [])
  var buttons = document.getElementById('div_alertebeButtons')
  if (!_status) {
    root.appendChild(alertebeText('div', '{{Sauvegardez la surveillance pour la voir fonctionner.}}', 'text-muted'))
    buttons.style.display = 'none'
    return
  }
  buttons.style.display = ''

  var head = document.createElement('div')
  head.style.fontSize = '15px'
  head.style.marginBottom = '8px'
  head.appendChild(alertebeStateLabel(_status))
  if (_status.since) {
    head.appendChild(alertebeText('span', ' {{depuis}} ' + _status.since + ' (' + _status.duration + ')', 'text-muted'))
  }
  if (_status.ack) {
    head.appendChild(alertebeText('span', ' — {{acquittée}}' + (_status.ack_by ? ' {{par}} ' + _status.ack_by : ''), 'text-muted'))
  }
  root.appendChild(head)

  if (_status.rules.length === 0) {
    root.appendChild(alertebeText('div', '{{Aucune règle : ajoutez-en une dans l\'onglet « Règles ».}}', 'text-muted'))
  } else {
    var table = document.createElement('table')
    table.className = 'table table-condensed table-bordered'
    var header = document.createElement('tr')
    ;['{{Règle}}', '{{Mesure}}', '{{Seuils}}', '{{Niveau}}'].forEach(function (title) {
      header.appendChild(alertebeText('th', title))
    })
    table.appendChild(header)
    _status.rules.forEach(function (rule) {
      var tr = document.createElement('tr')
      tr.appendChild(alertebeText('td', rule.name))
      if (!rule.enabled || rule.no_sensor) {
        var off = alertebeText('td', rule.enabled ? '{{aucun capteur choisi : la règle ne surveille rien}}' : '{{règle désactivée}}',
          rule.enabled ? 'text-warning' : 'text-muted')
        off.colSpan = 3
        tr.appendChild(off)
        table.appendChild(tr)
        return
      }
      var value = document.createElement('td')
      if (!rule.found) {
        value.appendChild(alertebeText('span', rule.problem || '{{capteur introuvable}}', 'text-danger'))
      } else if (rule.value === null) {
        value.appendChild(alertebeText('span', '{{aucune valeur lisible}}', 'text-warning'))
      } else {
        value.appendChild(alertebeText('span', rule.value))
      }
      if (rule.age !== null) {
        value.appendChild(alertebeText('div', '{{il y a}} ' + rule.age, rule.stale ? 'text-warning' : 'text-muted')).style.fontSize = '11px'
      }
      tr.appendChild(value)
      var thresholds = alertebeText('td', rule.thresholds, 'text-muted')
      /* Ce qui cloche dans le réglage : la règle tourne, mais pas comme on
         croit. Dit ici, là où l'on regarde si tout va bien. */
      ;(rule.problems || []).forEach(function (problem) {
        thresholds.appendChild(alertebeText('div', '⚠ ' + problem, 'text-warning')).style.fontSize = '11px'
      })
      tr.appendChild(thresholds)
      var level = document.createElement('td')
      level.appendChild(alertebeLabel(rule.label, alertebeLevelClass[rule.level] || 'label-default'))
      if (rule.stale) {
        level.appendChild(alertebeText('div', '{{capteur muet}}', 'text-warning')).style.fontSize = '11px'
      }
      if (rule.pending !== '') {
        level.appendChild(alertebeText('div', '{{en attente :}} ' + rule.pending, 'text-muted')).style.fontSize = '11px'
      }
      tr.appendChild(level)
      table.appendChild(tr)
    })
    root.appendChild(table)
  }

  document.getElementById('bt_alertebeAck').style.display = (_status.level > 0 && !_status.ack && _status.mode == 'on') ? '' : 'none'
  document.getElementById('bt_alertebeSuspend').closest('.input-group').style.display = (_status.mode == 'on') ? 'inline-flex' : 'none'
  document.getElementById('bt_alertebeDisarm').style.display = (_status.mode == 'disarmed') ? 'none' : ''
  document.getElementById('bt_alertebeResume').style.display = (_status.mode == 'on') ? 'none' : ''
}

function alertebeShowJournal(_entries) {
  var root = document.getElementById('div_alertebeJournal')
  if (!root) { return }
  root.innerHTML = ''
  if (!_entries || _entries.length === 0) {
    root.appendChild(alertebeText('span', '{{Rien pour l\'instant.}}', 'text-muted'))
    return
  }
  root.appendChild(alertebeJournalTable(_entries, false))
}

function alertebeLoadStatus(_refresh) {
  var id = alertebeCurrentId()
  if (id === null) {
    alertebeShowStatus(null)
    return
  }
  alertebeRequest({ action: 'status', id: id, refresh: _refresh ? 1 : 0 }, alertebeShowStatus)
}

function alertebeStatusAction(_data) {
  var id = alertebeCurrentId()
  if (id === null) { return }
  _data.id = id
  alertebeRequest(_data, alertebeShowStatus)
}

/* ================================================================ RÈGLES */

/* Des aides propres à chaque type, sous la règle. */
var alertebeTypeHints = {
  above: '{{Alerte quand la valeur atteint ou dépasse le seuil. Laissez un niveau vide pour ne pas l\'utiliser.}}',
  below: '{{Alerte quand la valeur descend au seuil ou en dessous : hors-gel, congélateur éteint, batterie faible.}}',
  outside: '{{Alerte quand la valeur sort de la plage. Une borne vide n\'est pas contrôlée.}}',
  equal: '{{Alerte quand le capteur vaut exactement cette valeur : 1 pour un détecteur de fuite ou de fumée, « open » pour un contact. Plusieurs valeurs se séparent par | : open|ouvert. Sans tenir compte des majuscules.}}',
  rise: '{{Alerte quand la valeur a monté d\'au moins ce seuil sur la fenêtre, depuis son point le plus bas. La règle se tait quand la hausse s\'arrête : doublez-la d\'une règle de seuil.}}',
  fall: '{{Alerte quand la valeur a baissé d\'au moins ce seuil sur la fenêtre, depuis son point le plus haut.}}'
}

/* Deux champs portent la même clé (le seuil numérique et la valeur d'égalité) :
   seuls ceux du type choisi sont visibles, et seuls les visibles sont lus. */
function alertebeRuleFields(_block) {
  var type = _block.querySelector('.abRuleAttr[data-key="type"]').value
  _block.querySelectorAll('.abShow').forEach(function (element) {
    var types = element.getAttribute('data-types').split(',')
    element.style.display = (types.indexOf(type) !== -1) ? '' : 'none'
  })
  _block.querySelector('.abRuleHint').textContent = alertebeTypeHints[type] || ''
}

function alertebeAddRule(_rule) {
  var template = document.getElementById('tpl_alertebeRule')
  var container = document.getElementById('div_alertebeRules')
  if (!template || !container) { return null }
  var block = template.content.firstElementChild.cloneNode(true)
  var rule = _rule || {}
  var type = rule.type || 'above'
  block.querySelector('.abRuleAttr[data-key="type"]').value = type
  block.querySelectorAll('.abRuleAttr').forEach(function (field) {
    var key = field.getAttribute('data-key')
    if (key == 'type') { return }
    /* Seuil numérique et valeur d'égalité partagent leur clé : seul le champ
       du type reçoit la valeur, l'autre ne doit pas afficher « 7 » le jour où
       l'on change de type. */
    var shown = field.closest('.abShow')
    if (shown && shown.getAttribute('data-types').split(',').indexOf(type) === -1) { return }
    if (field.type == 'checkbox') {
      field.checked = !isset(rule.enable) || rule.enable == 1
    } else if (isset(rule[key]) && rule[key] !== null) {
      field.value = rule[key]
    }
  })
  container.appendChild(block)
  alertebeRuleFields(block)
  return block
}

function alertebeReadRules() {
  var rules = []
  document.querySelectorAll('#div_alertebeRules .alertebeRule').forEach(function (block) {
    var rule = {}
    block.querySelectorAll('.abRuleAttr').forEach(function (field) {
      var shown = field.closest('.abShow')
      if (shown && shown.style.display == 'none') { return }
      rule[field.getAttribute('data-key')] = (field.type == 'checkbox') ? (field.checked ? 1 : 0) : field.value
    })
    rules.push(rule)
  })
  return rules
}

/* =============================================================== ACTIONS */

/*
 * Une ligne d'action, calquée sur le sélecteur d'action des scénarios. L'ordre
 * html() → setJeeValues → appendChild → replaceWith est celui du cœur : le HTML
 * des options contient des <script> que seul Element.prototype.html() exécute.
 */
function alertebeAddAction(_trigger, _action) {
  var container = document.querySelector('.alertebeActions[data-trigger="' + _trigger + '"]')
  if (container === null) { return null }
  var action = _action || {}
  if (!isset(action.options)) { action.options = {} }

  var div = '<div class="alertebeAction expression" style="margin-bottom:4px;">'
  div += '<input class="expressionAttr" data-l1key="type" style="display:none;" value="action">'
  div += '<div class="form-group" style="margin:0;">'
  div += '<div class="col-sm-1">'
  div += '<input type="checkbox" class="expressionAttr" data-l1key="options" data-l2key="enable" checked title="{{Décocher pour désactiver cette action sans la supprimer}}">'
  div += '<input type="checkbox" class="expressionAttr" data-l1key="options" data-l2key="background" title="{{Exécuter en parallèle des autres actions}}">'
  div += '</div>'
  div += '<div class="col-sm-5">'
  div += '<div class="input-group">'
  div += '<span class="input-group-btn">'
  div += '<a class="btn btn-default btn-sm abRemoveAction roundedLeft"><i class="fas fa-minus-circle"></i></a>'
  div += '</span>'
  div += '<input class="expressionAttr form-control input-sm cmdAction" data-l1key="cmd" placeholder="{{Commande à déclencher}}">'
  div += '<span class="input-group-btn">'
  div += '<a class="btn btn-default btn-sm abListAction" title="{{Choisir un bloc (message, scénario, variable...)}}"><i class="fas fa-tasks"></i></a>'
  div += '<a class="btn btn-default btn-sm abListCmd roundedRight" title="{{Choisir une commande}}"><i class="fas fa-list-alt"></i></a>'
  div += '</span>'
  div += '</div>'
  div += '</div>'
  div += '<div class="col-sm-6 actionOptions"></div>'
  div += '</div>'
  div += '</div>'

  var wrapper = document.createElement('div')
  wrapper.html(div)
  wrapper.setJeeValues(action, '.expressionAttr')
  container.appendChild(wrapper)
  var nodes = Array.prototype.slice.call(wrapper.childNodes)
  wrapper.replaceWith(...nodes)

  if (nodes.length > 0) {
    alertebeRefreshActionOptions(nodes[0], init(action.cmd, ''), action.options)
  }
  return nodes[0]
}

/* Les options connues d'une ligne dont le cœur n'a pas (encore) dessiné les
   champs. Rendues au relevé, pour ne pas enregistrer du vide à leur place. */
function alertebePendingOptions(_line) {
  return (isset(_line.alertebePending) && _line.alertebePending !== null) ? _line.alertebePending : null
}

/* Réaffiche les options — titre, message, curseur — après un changement de
   commande. C'est le cœur qui les dessine, d'après la commande visée.

   La variante synchrone de displayActionOption fige l'onglet le temps d'un
   aller-retour PAR action : on passe par la variante asynchrone. */
function alertebeRefreshActionOptions(_line, _expression, _options) {
  var expression = String(init(_expression, ''))

  /* Le rendu détruit et reconstruit le champ Message : le rejouer à chaque
     perte de focus effacerait ce que l'utilisateur est en train d'y taper. */
  if (_line.alertebeExpression === expression) { return }
  _line.alertebeExpression = expression

  /* Titre et message n'existent que dans le HTML renvoyé par le cœur. Tant
     qu'il n'est pas arrivé, les options connues restent sur la ligne : sinon
     l'enregistrement remplacerait par du vide un message rédigé de longue date. */
  _line.alertebePending = _options || {}

  jeedom.cmd.displayActionOption(expression, _options, function (html) {
    var target = _line.querySelector('.actionOptions')
    if (target === null) { return }
    /* Le cœur répond ce mot, tel quel, pour un bloc réservé aux scénarios. */
    if (html === 'Unsupported') {
      target.textContent = '{{Ce bloc n\'est utilisable que dans un scénario.}}'
      return
    }
    if (html === '' && expression !== '') {
      target.textContent = '{{Options indisponibles : la commande visée a peut-être été supprimée. Le message enregistré est conservé.}}'
      return
    }
    target.html(html)
    jeedomUtils.taAutosize()
    _line.alertebePending = null
  })
}

function alertebeReadActions() {
  var actions = {}
  document.querySelectorAll('.alertebeActions').forEach(function (container) {
    var list = []
    container.querySelectorAll('.alertebeAction').forEach(function (line) {
      var action = line.getJeeValues('.expressionAttr')[0]
      var pending = alertebePendingOptions(line)
      if (pending !== null) {
        action.options = Object.assign({}, pending, action.options)
      }
      list.push(action)
    })
    actions[container.getAttribute('data-trigger')] = list
  })
  return actions
}

/* ================================================== CYCLE DE VIE DE LA PAGE */

function printEqLogic(_eqLogic) {
  var configuration = (isset(_eqLogic) && isset(_eqLogic.configuration)) ? _eqLogic.configuration : {}

  var rules = document.getElementById('div_alertebeRules')
  if (rules) { rules.innerHTML = '' }
  ;(Array.isArray(configuration.rules) ? configuration.rules : []).forEach(function (rule) { alertebeAddRule(rule) })

  document.querySelectorAll('.alertebeActions').forEach(function (container) { container.innerHTML = '' })
  var actions = (isset(configuration.actions) && typeof configuration.actions === 'object') ? configuration.actions : {}
  Object.keys(actions).forEach(function (trigger) {
    if (Array.isArray(actions[trigger])) {
      actions[trigger].forEach(function (action) { alertebeAddAction(trigger, action) })
    }
  })

  alertebeShowStatus(null)
  if (isset(_eqLogic.id) && _eqLogic.id != '') {
    alertebeLoadStatus(false)
  }
}

/* Appelée par plugin.template.js avant l'enregistrement : règles et actions
   sont des listes, que data-lXkey ne sait pas ramasser. */
function saveEqLogic(_eqLogic) {
  if (!isset(_eqLogic.configuration)) { _eqLogic.configuration = {} }
  _eqLogic.configuration.rules = alertebeReadRules()
  _eqLogic.configuration.actions = alertebeReadActions()
  return _eqLogic
}

/* ================================================================ COMMANDES */

function addCmdToTable(_cmd) {
  if (!isset(_cmd)) {
    var _cmd = { configuration: {} }
  }
  if (!isset(_cmd.configuration)) {
    _cmd.configuration = {}
  }

  /* Le champ caché « id » n'est pas décoratif : sans lui, chaque sauvegarde
     détruit et recrée les commandes — historique perdu, scénarios cassés. */
  var tr = '<td>'
  tr += '<span class="cmdAttr" data-l1key="id" style="display:none;"></span>'
  tr += '<div class="input-group">'
  tr += '<input class="cmdAttr form-control input-sm roundedLeft" data-l1key="name" placeholder="{{Nom}}">'
  tr += '<span class="input-group-btn">'
  tr += '<a class="cmdAction btn btn-sm btn-default" data-l1key="chooseIcon" title="{{Choisir une icône}}"><i class="fas fa-icons"></i></a>'
  tr += '</span>'
  tr += '<span class="cmdAttr input-group-addon roundedRight" data-l1key="display" data-l2key="icon" style="font-size:19px;padding:0 5px 0 0!important;"></span>'
  tr += '</div>'
  tr += '</td>'
  tr += '<td>'
  tr += '<span class="type" type="' + init(_cmd.type) + '">' + jeedom.cmd.availableType() + '</span>'
  tr += '<span class="subType" subType="' + init(_cmd.subType) + '"></span>'
  tr += '</td>'
  tr += '<td>'
  tr += '<label class="checkbox-inline"><input type="checkbox" class="cmdAttr" data-l1key="isVisible" checked>{{Afficher}}</label>'
  if (_cmd.type == 'info') {
    tr += '<label class="checkbox-inline"><input type="checkbox" class="cmdAttr" data-l1key="isHistorized">{{Historiser}}</label>'
  }
  tr += '<span class="cmdAttr" data-l1key="htmlstate" style="display:inline-block;margin-left:5px;"></span>'
  tr += '</td>'
  tr += '<td>'
  if (is_numeric(_cmd.id)) {
    tr += '<a class="btn btn-default btn-xs cmdAction" data-action="configure"><i class="fas fa-cogs"></i></a> '
    tr += '<a class="btn btn-default btn-xs cmdAction" data-action="test"><i class="fas fa-rss"></i> {{Tester}}</a> '
  }
  tr += '</td>'

  /* Une ligne créée en DOM : insertAdjacentHTML sur la table génère un <tbody>
     par insertion. */
  var newRow = document.createElement('tr')
  newRow.innerHTML = tr
  newRow.classList.add('cmd')
  newRow.setAttribute('data-cmd_id', init(_cmd.id))
  newRow.setAttribute('title', '{{Identifiant interne}} : ' + init(_cmd.logicalId))
  document.getElementById('table_cmd').querySelector('tbody').appendChild(newRow)
  newRow.setJeeValues(_cmd, '.cmdAttr')
  jeedom.cmd.changeType(newRow, init(_cmd.subType))
}

/* ================================================================ ÉCOUTEURS */

/* Les pages sont chargées en AJAX : les écouteurs sont posés sur le conteneur
   de page, qui est remplacé à chaque navigation et les emporte avec lui. */
var alertebeContainer = document.getElementById('div_pageContainer') || document.body

alertebeContainer.addEventListener('click', function (event) {
  var target

  /* ---- vue d'ensemble */
  if (event.target.closest('#bt_alertebeOverviewRefresh')) {
    alertebeLoadOverview()
    return
  }
  if (target = event.target.closest('.abOverviewAck')) {
    alertebeRequest({ action: 'ack', id: target.getAttribute('data-id') }, function () { alertebeLoadOverview() }, null, true)
    return
  }
  /* De retour sur la liste, la vue d'ensemble a pu vieillir. */
  if (event.target.closest('.eqLogicAction[data-action="returnToThumbnailDisplay"]')) {
    setTimeout(alertebeLoadOverview, 100)
  }
  if (target = event.target.closest('.abOverviewOpen')) {
    var card = document.querySelector('.eqLogicDisplayCard[data-eqLogic_id="' + target.getAttribute('data-eqLogic_id') + '"]')
    if (card) { card.click() }
    return
  }

  /* ---- en ce moment */
  if (event.target.closest('#bt_alertebeRefresh')) {
    alertebeLoadStatus(true)
    return
  }
  if (event.target.closest('#bt_alertebeAck')) {
    alertebeStatusAction({ action: 'ack' })
    return
  }
  if (event.target.closest('#bt_alertebeSuspend')) {
    alertebeStatusAction({ action: 'suspend', minutes: document.getElementById('sel_alertebeSuspend').value })
    return
  }
  if (event.target.closest('#bt_alertebeDisarm')) {
    /* Une surveillance désactivée ne reprend pas seule : on demande. */
    jeeDialog.confirm('{{Désactiver la surveillance jusqu\'à ce que quelqu\'un la réactive ? Une alerte en cours sera oubliée. Pour une interruption temporaire, préférez « Suspendre ».}}', function (result) {
      if (result) { alertebeStatusAction({ action: 'disarm' }) }
    })
    return
  }
  if (event.target.closest('#bt_alertebeResume')) {
    alertebeStatusAction({ action: 'resume' })
    return
  }

  /* ---- règles */
  if (event.target.closest('#bt_alertebeAddRule')) {
    var profile = document.getElementById('sel_alertebeProfile').value
    var rule = (profile !== '' && alertebeProfiles[profile]) ? Object.assign({}, alertebeProfiles[profile].rule) : { type: 'above' }
    var block = alertebeAddRule(rule)
    if (block) { block.scrollIntoView({ behavior: 'smooth', block: 'nearest' }) }
    alertebeMarkModified()
    return
  }
  if (target = event.target.closest('.abRuleRemove')) {
    target.closest('.alertebeRule').remove()
    alertebeMarkModified()
    return
  }
  if (target = event.target.closest('.abRulePick')) {
    var field = target.closest('.alertebeRule').querySelector('.abRuleAttr[data-key="cmd"]')
    jeedom.cmd.getSelectModal({ cmd: { type: 'info' } }, function (result) {
      if (!result || !result.human) { return }
      field.value = result.human
      alertebeMarkModified()
    })
    return
  }
  if (target = event.target.closest('.abRuleClear')) {
    var cleared = target.closest('.alertebeRule').querySelector('.abRuleAttr[data-key="cmd"]')
    if (cleared.value !== '') {
      cleared.value = ''
      alertebeMarkModified()
    }
    return
  }

  /* ---- actions */
  if (target = event.target.closest('.abAddAction')) {
    alertebeAddAction(target.getAttribute('data-trigger'), null)
    alertebeMarkModified()
    return
  }
  if (target = event.target.closest('.abRemoveAction')) {
    target.closest('.alertebeAction').remove()
    alertebeMarkModified()
    return
  }
  if (target = event.target.closest('.abListCmd')) {
    var cmdLine = target.closest('.alertebeAction')
    /* Le sélecteur rappelle avec { human: '#[Objet][Équipement][Commande]#' } :
       le cœur la convertit en identifiant à l'enregistrement, si bien qu'un
       renommage ultérieur ne casse pas l'action. */
    jeedom.cmd.getSelectModal({ cmd: { type: 'action' } }, function (result) {
      cmdLine.querySelector('.expressionAttr[data-l1key="cmd"]').jeeValue(result.human)
      alertebeRefreshActionOptions(cmdLine, result.human, '')
      alertebeMarkModified()
    })
    return
  }
  if (target = event.target.closest('.abListAction')) {
    var blockLine = target.closest('.alertebeAction')
    jeedom.getSelectActionModal({}, function (result) {
      if (alertebeRefusedBlocks.indexOf(result.human) !== -1) {
        jeedomUtils.showAlert({
          message: '{{Ce bloc n\'est pas utilisable ici : il retiendrait le cron de Jeedom ou n\'a de sens que dans un scénario. Passez par l\'action « Scénario ».}}',
          level: 'warning',
          timeOut: 10000
        })
        return
      }
      blockLine.querySelector('.expressionAttr[data-l1key="cmd"]').jeeValue(result.human)
      alertebeRefreshActionOptions(blockLine, result.human, '')
      alertebeMarkModified()
    })
    return
  }
  if (target = event.target.closest('.abTestActions')) {
    var testId = alertebeCurrentId()
    if (testId === null) { return }
    if ((typeof jeeFrontEnd !== 'undefined' && jeeFrontEnd.modifyWithoutSave) || window.modifyWithoutSave) {
      jeedomUtils.showAlert({ message: '{{Sauvegardez d\'abord : le test joue les actions enregistrées.}}', level: 'warning' })
      return
    }
    alertebeRequest({ action: 'testActions', id: testId, trigger: target.getAttribute('data-trigger') }, function (result) {
      if (result.count === 0) {
        jeedomUtils.showAlert({ message: '{{Aucune action enregistrée pour ce niveau.}}', level: 'warning' })
      } else if (result.errors.length > 0) {
        jeedomUtils.showAlert({ message: '{{Échec :}} ' + result.errors.join(' ; '), level: 'danger' })
      } else {
        jeedomUtils.showAlert({
          message: result.count + ' {{action(s) jouée(s).}}' + (result.fallback ? ' {{Aucune action critique n\'est réglée : ce sont celles de l\'avertissement.}}' : ''),
          level: 'success',
          timeOut: 6000
        })
      }
      alertebeLoadStatus(false)
    })
    return
  }
})

alertebeContainer.addEventListener('change', function (event) {
  var target
  if (target = event.target.closest('.abRuleAttr[data-key="type"]')) {
    alertebeRuleFields(target.closest('.alertebeRule'))
  }
  /* Règles et actions ne sont pas des .eqLogicAttr : le cœur ne les voit pas
     changer, et quitterait la page sans prévenir. */
  if (event.target.closest('.abRuleAttr') || event.target.closest('.alertebeAction')) {
    alertebeMarkModified()
  }
})

/* Une commande tapée ou collée à la main : ses options sont redessinées quand
   on quitte le champ. */
alertebeContainer.addEventListener('focusout', function (event) {
  var input = event.target.closest('.alertebeAction .cmdAction')
  if (input === null) { return }
  var line = input.closest('.alertebeAction')
  var current = line.getJeeValues('.expressionAttr')[0]
  alertebeRefreshActionOptions(line, input.jeeValue(), init(current.options))
})

alertebeLoadOverview()

/* L'état de la page se rafraîchit seul toutes les 30 secondes : on laisse
   souvent la page ouverte pour regarder un frigo remonter. L'intervalle
   survit à la navigation AJAX : il s'arrête de lui-même quand la page du
   plugin n'est plus là, et une seule instance tourne à la fois. */
if (window.alertebeTimer) { clearInterval(window.alertebeTimer) }
window.alertebeTimer = setInterval(function () {
  if (!document.getElementById('div_alertebeRules')) {
    clearInterval(window.alertebeTimer)
    window.alertebeTimer = null
    return
  }
  if (document.hidden) { return }
  var editor = document.querySelector('.eqLogic')
  if (editor && editor.style.display != 'none') {
    if (alertebeCurrentId() !== null) { alertebeLoadStatus(false) }
  } else {
    alertebeLoadOverview()
  }
}, 30000)
