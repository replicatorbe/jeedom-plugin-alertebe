<?php
if (!isConnect('admin')) {
	throw new Exception('{{401 - Accès non autorisé}}');
}
/* L'autoload du cœur ne connaît que la classe qui porte le nom du plugin : le
 * moteur se charge par elle. */
require_once __DIR__ . '/../../core/class/alertebe.class.php';
$plugin = plugin::byId('alertebe');
sendVarToJS('eqType', $plugin->getId());
$eqLogics = eqLogic::byType($plugin->getId());

/* Les profils, pour préremplir une règle ; les blocs refusés, pour le dire au
 * moment du choix plutôt qu'à la première alerte. */
$profiles = array();
foreach (array_keys(alertebeEngine::PROFILES) as $profile) {
	$profiles[$profile] = array('label' => alertebeEngine::profileLabel($profile), 'rule' => alertebeEngine::profileRule($profile));
}
sendVarToJS('alertebeProfiles', $profiles);
sendVarToJS('alertebeRefusedBlocks', alertebe::REFUSED_BLOCKS);

/* Un champ numérique d'une règle. En texte et non en « number » : un
 * navigateur en français vide en silence « 7,5 » dans un champ numérique. Le
 * serveur relit la virgule. */
function alertebeRuleNumber($_key, $_label, $_unit, $_placeholder, $_types, $_help = '') {
	?>
	<div class="col-sm-3 abShow" data-types="<?php echo $_types; ?>" style="margin-bottom:6px;">
		<label style="font-weight:normal;margin-bottom:2px;"><?php echo $_label; ?>
			<?php if ($_help != '') { ?>
				<sup><i class="fas fa-question-circle" title="<?php echo $_help; ?>"></i></sup>
			<?php } ?>
		</label>
		<div class="input-group input-group-sm">
			<input type="text" class="form-control abRuleAttr" data-key="<?php echo $_key; ?>" placeholder="<?php echo $_placeholder; ?>">
			<?php if ($_unit != '') { ?>
				<span class="input-group-addon"><?php echo $_unit; ?></span>
			<?php } ?>
		</div>
	</div>
	<?php
}
?>
<div class="row row-overflow">
	<div class="col-xs-12 eqLogicThumbnailDisplay">
		<legend><i class="fas fa-cog"></i> {{Gestion}}</legend>
		<div class="eqLogicThumbnailContainer">
			<div class="cursor eqLogicAction logoPrimary" data-action="add">
				<i class="fas fa-plus-circle"></i>
				<br>
				<span>{{Ajouter une surveillance}}</span>
			</div>
			<div class="cursor eqLogicAction logoSecondary" data-action="gotoPluginConf">
				<i class="fas fa-wrench"></i>
				<br>
				<span>{{Configuration}}</span>
			</div>
		</div>

		<?php if (count($eqLogics) == 0) { ?>
			<div class="alert alert-info" style="margin:5px;">
				<b>{{Aucune surveillance pour le moment. Pour démarrer :}}</b>
				<ol style="margin:5px 0 0 0;padding-left:20px;">
					<li>{{Cliquez sur « Ajouter une surveillance » et nommez-la, par exemple « Frigo cuisine ».}}</li>
					<li>{{Onglet « Règles » : choisissez un profil (Frigo, Incendie, Fuite d'eau…) puis le capteur à surveiller. Les seuils proposés conviennent pour commencer.}}</li>
					<li>{{Onglet « Actions » : ce qui doit se passer en avertissement, en critique et au retour à la normale — une notification, une sirène, une lampe, un scénario.}}</li>
					<li>{{Sauvegardez, puis testez les actions depuis leur onglet.}}</li>
				</ol>
			</div>
		<?php } else { ?>
			<legend>
				<i class="fas fa-traffic-light"></i> {{Vue d'ensemble}}
				<a class="btn btn-default btn-xs pull-right" id="bt_alertebeOverviewRefresh" style="margin-top:5px;"><i class="fas fa-sync"></i> {{Actualiser}}</a>
			</legend>
			<div id="div_alertebeOverview" style="margin:0 5px 10px 5px;"></div>
		<?php } ?>

		<legend><i class="fas fa-bell"></i> {{Mes surveillances}}</legend>
		<div class="eqLogicThumbnailContainer">
			<?php
			foreach ($eqLogics as $eqLogic) {
				$opacity = ($eqLogic->getIsEnable()) ? '' : 'disableCard';
				echo '<div class="eqLogicDisplayCard cursor ' . $opacity . '" data-eqLogic_id="' . $eqLogic->getId() . '">';
				echo '<img src="' . $plugin->getPathImgIcon() . '">';
				echo '<br>';
				echo '<span class="name">' . $eqLogic->getHumanName(true, true) . '</span>';
				echo '</div>';
			}
			?>
		</div>
	</div>

	<div class="col-xs-12 eqLogic" style="display: none;">
		<div class="input-group pull-right" style="display:inline-flex">
			<span class="input-group-btn">
				<a class="btn btn-default btn-sm eqLogicAction roundedLeft" data-action="configure"><i class="fas fa-cogs"></i><span class="hidden-xs"> {{Configuration avancée}}</span></a>
				<a class="btn btn-sm btn-success eqLogicAction" data-action="save"><i class="fas fa-check-circle"></i> {{Sauvegarder}}</a>
				<a class="btn btn-sm btn-danger eqLogicAction roundedRight" data-action="remove"><i class="fas fa-minus-circle"></i> {{Supprimer}}</a>
			</span>
		</div>
		<ul class="nav nav-tabs" role="tablist">
			<li role="presentation"><a href="#" class="eqLogicAction" aria-controls="home" role="tab" data-toggle="tab" data-action="returnToThumbnailDisplay"><i class="fas fa-arrow-circle-left"></i></a></li>
			<li role="presentation" class="active"><a href="#eqlogictab" aria-controls="home" role="tab" data-toggle="tab"><i class="fas fa-bell"></i><span class="hidden-xs"> {{Surveillance}}</span></a></li>
			<li role="presentation"><a href="#ruletab" aria-controls="home" role="tab" data-toggle="tab"><i class="fas fa-ruler-vertical"></i><span class="hidden-xs"> {{Règles}}</span></a></li>
			<li role="presentation"><a href="#actiontab" aria-controls="home" role="tab" data-toggle="tab"><i class="fas fa-bolt"></i><span class="hidden-xs"> {{Actions}}</span></a></li>
			<li role="presentation"><a href="#journaltab" aria-controls="home" role="tab" data-toggle="tab"><i class="fas fa-history"></i><span class="hidden-xs"> {{Journal}}</span></a></li>
			<li role="presentation"><a href="#commandtab" aria-controls="home" role="tab" data-toggle="tab"><i class="fas fa-list"></i><span class="hidden-xs"> {{Commandes}}</span></a></li>
		</ul>

		<div class="tab-content">
			<!-- ========================================== SURVEILLANCE ========================================== -->
			<div role="tabpanel" class="tab-pane active" id="eqlogictab">
				<br>
				<div class="col-lg-6">
					<form class="form-horizontal">
						<fieldset>
							<legend><i class="fas fa-tag"></i> {{Général}}</legend>
							<div class="form-group">
								<label class="col-sm-4 control-label">{{Nom de la surveillance}}</label>
								<div class="col-sm-7">
									<input type="text" class="eqLogicAttr form-control" data-l1key="id" style="display:none;">
									<input type="text" class="eqLogicAttr form-control" data-l1key="name" placeholder="{{Frigo cuisine}}">
								</div>
							</div>
							<div class="form-group">
								<label class="col-sm-4 control-label">{{Objet parent}}</label>
								<div class="col-sm-7">
									<select class="eqLogicAttr form-control" data-l1key="object_id">
										<option value="">{{Aucun}}</option>
										<?php
										$options = '';
										foreach ((jeeObject::buildTree(null, false)) as $object) {
											$options .= '<option value="' . $object->getId() . '">' . str_repeat('&nbsp;&nbsp;', $object->getConfiguration('parentNumber')) . $object->getName() . '</option>';
										}
										echo $options;
										?>
									</select>
								</div>
							</div>
							<div class="form-group">
								<label class="col-sm-4 control-label">{{Catégorie}}</label>
								<div class="col-sm-7">
									<?php
									foreach (jeedom::getConfiguration('eqLogic:category') as $key => $value) {
										echo '<label class="checkbox-inline">';
										echo '<input type="checkbox" class="eqLogicAttr" data-l1key="category" data-l2key="' . $key . '">' . $value['name'];
										echo '</label>';
									}
									?>
								</div>
							</div>
							<div class="form-group">
								<label class="col-sm-4 control-label">{{Options}}</label>
								<div class="col-sm-7">
									<label class="checkbox-inline"><input type="checkbox" class="eqLogicAttr" data-l1key="isEnable" checked>{{Activer}}</label>
									<label class="checkbox-inline"><input type="checkbox" class="eqLogicAttr" data-l1key="isVisible" checked>{{Visible}}</label>
								</div>
							</div>
						</fieldset>
						<fieldset>
							<legend><i class="fas fa-redo"></i> {{Rappels}}</legend>
							<div class="form-group">
								<label class="col-sm-4 control-label">{{Rappeler toutes les}}
									<sup><i class="fas fa-question-circle" title="{{Tant que l'alerte dure et que personne ne l'a acquittée, les actions de son niveau sont rejouées à cet intervalle. 0 : jamais de rappel.}}"></i></sup>
								</label>
								<div class="col-sm-3">
									<div class="input-group">
										<input type="text" class="eqLogicAttr form-control roundedLeft" data-l1key="configuration" data-l2key="reminder_interval" placeholder="30">
										<span class="input-group-addon roundedRight">{{min}}</span>
									</div>
								</div>
							</div>
							<div class="form-group">
								<label class="col-sm-4 control-label">{{Rappels au plus}}
									<sup><i class="fas fa-question-circle" title="{{Nombre de rappels pour une même alerte. Une montée en critique recommence le compte. 0 : sans limite.}}"></i></sup>
								</label>
								<div class="col-sm-3">
									<input type="text" class="eqLogicAttr form-control" data-l1key="configuration" data-l2key="reminder_max" placeholder="3">
								</div>
							</div>
							<div class="form-group">
								<label class="col-sm-4 control-label">{{Centre de messages}}</label>
								<div class="col-sm-7">
									<label class="checkbox-inline"><input type="checkbox" class="eqLogicAttr" data-l1key="configuration" data-l2key="message_center" checked>{{Y inscrire chaque alerte}}</label>
									<span class="help-block" style="margin:0;">{{Une trace dans Jeedom même si aucune action n'est réglée, ou si la notification n'est pas partie.}}</span>
								</div>
							</div>
						</fieldset>
					</form>
				</div>

				<div class="col-lg-6">
					<legend>
						<i class="fas fa-heartbeat"></i> {{En ce moment}}
						<span id="span_alertebeAt" class="text-muted" style="font-size:12px;margin-left:8px;"></span>
					</legend>
					<div id="div_alertebeStatus"></div>
					<div id="div_alertebeButtons" style="margin-top:10px;">
						<a class="btn btn-default btn-sm" id="bt_alertebeRefresh"><i class="fas fa-sync"></i> {{Évaluer maintenant}}</a>
						<a class="btn btn-warning btn-sm" id="bt_alertebeAck"><i class="fas fa-check"></i> {{Acquitter}}</a>
						<div class="input-group input-group-sm" style="display:inline-flex;vertical-align:middle;width:auto;">
							<select class="form-control roundedLeft" id="sel_alertebeSuspend" style="width:auto;">
								<option value="15">15 min</option>
								<option value="30">30 min</option>
								<option value="60" selected>1 h</option>
								<option value="120">2 h</option>
								<option value="240">4 h</option>
								<option value="480">8 h</option>
								<option value="1440">24 h</option>
							</select>
							<span class="input-group-btn"><a class="btn btn-default roundedRight" id="bt_alertebeSuspend"><i class="fas fa-pause"></i> {{Suspendre}}</a></span>
						</div>
						<a class="btn btn-default btn-sm" id="bt_alertebeDisarm"><i class="fas fa-power-off"></i> {{Désactiver}}</a>
						<a class="btn btn-success btn-sm" id="bt_alertebeResume"><i class="fas fa-play"></i> {{Reprendre}}</a>
					</div>
					<div class="help-block">{{Suspendre coupe la surveillance le temps de nettoyer un frigo ou de dégivrer un congélateur ; elle reprend seule. Désactiver la coupe jusqu'à nouvel ordre. Dans les deux cas, une alerte en cours est oubliée sans jouer les actions de retour à la normale.}}</div>
				</div>
			</div>

			<!-- ========================================== RÈGLES ========================================== -->
			<div role="tabpanel" class="tab-pane" id="ruletab">
				<br>
				<div class="alert alert-info" style="margin:5px 5px 10px 5px;">
					{{Chaque règle surveille un capteur. Le niveau de la surveillance est le pire de ses règles.}}
					<ul style="margin:5px 0 0 0;padding-left:20px;">
						<li>{{<b>Confirmation</b> : la valeur doit rester au-delà du seuil tout ce temps avant l'alerte. C'est ce qui empêche une porte de frigo ouverte de déclencher quoi que ce soit.}}</li>
						<li>{{<b>Hystérésis</b> : l'alerte ne s'arrête qu'une fois la valeur revenue de cet écart en deçà du seuil. Un frigo en alerte à 7 °C avec 1 °C d'hystérésis ne revient à la normale qu'à 6 °C.}}</li>
						<li>{{<b>Muet après</b> : un capteur sans nouvelle valeur depuis ce temps passe en avertissement. Jeedom garde la dernière valeur d'un capteur à la pile vide : sans ce contrôle, il dirait « tout va bien » indéfiniment. Beaucoup de capteurs ne publient qu'au changement : ne descendez pas trop bas.}}</li>
						<li>{{<b>Hausse rapide</b> : la variation sur la fenêtre, mesurée depuis le point le plus bas. Pour un incendie, combinez-la avec une règle de seuil sur le même capteur.}}</li>
					</ul>
				</div>
				<div style="margin:0 0 10px 5px;">
					<div class="input-group input-group-sm" style="display:inline-flex;width:auto;">
						<select class="form-control roundedLeft" id="sel_alertebeProfile" style="width:auto;">
							<option value="">{{Règle vierge}}</option>
							<?php foreach ($profiles as $key => $profile) { ?>
								<option value="<?php echo $key; ?>"><?php echo $profile['label']; ?></option>
							<?php } ?>
						</select>
						<span class="input-group-btn"><a class="btn btn-default roundedRight" id="bt_alertebeAddRule"><i class="fas fa-plus-circle"></i> {{Ajouter une règle}}</a></span>
					</div>
				</div>
				<div id="div_alertebeRules"></div>

				<!-- Le modèle d'une règle : copié par le JS, jamais envoyé tel quel. -->
				<template id="tpl_alertebeRule">
					<div class="alertebeRule panel panel-default" style="margin:0 5px 10px 5px;">
						<input type="hidden" class="abRuleAttr" data-key="id">
						<div class="panel-heading" style="padding:6px 10px;">
							<div class="row">
								<div class="col-sm-1" style="padding-top:6px;">
									<input type="checkbox" class="abRuleAttr" data-key="enable" checked title="{{Décocher pour suspendre cette règle sans la supprimer}}">
								</div>
								<div class="col-sm-4">
									<input type="text" class="form-control input-sm abRuleAttr" data-key="name" placeholder="{{Nom (par défaut : celui du capteur)}}">
								</div>
								<div class="col-sm-4">
									<select class="form-control input-sm abRuleAttr" data-key="type">
										<?php foreach (alertebeEngine::TYPES as $type) { ?>
											<option value="<?php echo $type; ?>"><?php echo alertebeEngine::typeLabel($type); ?></option>
										<?php } ?>
									</select>
								</div>
								<div class="col-sm-3 text-right">
									<a class="btn btn-danger btn-sm abRuleRemove" title="{{Supprimer la règle}}"><i class="fas fa-minus-circle"></i></a>
								</div>
							</div>
						</div>
						<div class="panel-body" style="padding:10px;">
							<div class="row" style="margin-bottom:6px;">
								<div class="col-sm-9">
									<label style="font-weight:normal;margin-bottom:2px;">{{Capteur}}</label>
									<div class="input-group input-group-sm">
										<input type="text" class="form-control roundedLeft abRuleAttr" data-key="cmd" placeholder="#[Cuisine][Frigo][Température]#">
										<span class="input-group-btn">
											<a class="btn btn-default abRulePick" title="{{Choisir une commande info}}"><i class="fas fa-list-alt"></i></a>
											<a class="btn btn-default roundedRight abRuleClear" title="{{Vider}}"><i class="fas fa-times"></i></a>
										</span>
									</div>
								</div>
							</div>
							<div class="row">
								<?php
								alertebeRuleNumber('warning', '<i class="fas fa-exclamation-triangle text-warning"></i> {{Avertissement}}', '', '7', 'above,below,rise,fall');
								alertebeRuleNumber('critical', '<i class="fas fa-fire text-danger"></i> {{Critique}}', '', '10', 'above,below,rise,fall');
								alertebeRuleNumber('warning', '<i class="fas fa-exclamation-triangle text-warning"></i> {{Avertissement si égal à}}', '', '', 'equal');
								alertebeRuleNumber('critical', '<i class="fas fa-fire text-danger"></i> {{Critique si égal à}}', '', '1', 'equal');
								alertebeRuleNumber('warning_low', '<i class="fas fa-exclamation-triangle text-warning"></i> {{Avertissement sous}}', '', '40', 'outside');
								alertebeRuleNumber('warning_high', '{{… ou au-dessus de}}', '', '70', 'outside');
								alertebeRuleNumber('critical_low', '<i class="fas fa-fire text-danger"></i> {{Critique sous}}', '', '30', 'outside');
								alertebeRuleNumber('critical_high', '{{… ou au-dessus de}}', '', '80', 'outside');
								?>
							</div>
							<div class="row">
								<?php
								alertebeRuleNumber('window', '{{Fenêtre}}', '{{min}}', '5', 'rise,fall', '{{La variation se mesure sur ce temps.}}');
								alertebeRuleNumber('delay', '{{Confirmation}}', '{{min}}', '0', 'above,below,outside,equal,rise,fall', '{{Temps pendant lequel la valeur doit rester au-delà du seuil avant l\'alerte. 0 : immédiat.}}');
								alertebeRuleNumber('hysteresis', '{{Hystérésis}}', '', '0', 'above,below,outside,rise,fall', '{{Écart à regagner sous le seuil avant le retour à la normale.}}');
								alertebeRuleNumber('stale_after', '{{Muet après}}', '{{min}}', '0', 'above,below,outside,equal,rise,fall', '{{Avertissement si le capteur ne publie plus rien depuis ce temps. 0 : pas de contrôle.}}');
								?>
							</div>
							<div class="text-muted abRuleHint" style="font-size:12px;"></div>
						</div>
					</div>
				</template>
			</div>

			<!-- ========================================== ACTIONS ========================================== -->
			<div role="tabpanel" class="tab-pane" id="actiontab">
				<br>
				<div class="alert alert-info" style="margin:5px 5px 10px 5px;">
					{{Les actions se choisissent comme dans un scénario : une commande (notification, sirène, lampe) ou un bloc (message, scénario, variable). Dans les titres et messages, ces mots sont remplacés :}}
					<code>#equipement#</code> <code>#niveau#</code> <code>#message#</code> <code>#regle#</code> <code>#capteur#</code>
					<code>#valeur#</code> <code>#unite#</code> <code>#seuil#</code> <code>#pic#</code> <code>#depuis#</code> <code>#duree#</code> <code>#rappel#</code>.
					{{Exemple :}} <code>#equipement# — #niveau# : #message#</code>.
					{{Un rappel rejoue les actions du niveau en cours. Sauvegardez avant de tester : le test joue les actions enregistrées.}}
				</div>
				<?php
				$triggers = array(
					'warning'  => array('icon' => 'fas fa-exclamation-triangle text-warning', 'help' => '{{Quand la surveillance passe de normal à avertissement.}}'),
					'critical' => array('icon' => 'fas fa-fire text-danger', 'help' => '{{Quand elle passe en critique, depuis normal ou depuis avertissement.}}'),
					'recovery' => array('icon' => 'fas fa-check-circle text-success', 'help' => '{{Quand tout est revenu à la normale. #duree# donne alors la durée de l\'alerte.}}'),
				);
				foreach ($triggers as $trigger => $info) {
				?>
					<fieldset style="margin:0 5px 15px 5px;">
						<legend>
							<i class="<?php echo $info['icon']; ?>"></i> <?php echo alertebe::triggerLabel($trigger); ?>
							<span class="text-muted" style="font-size:12px;margin-left:8px;"><?php echo $info['help']; ?></span>
							<span class="pull-right">
								<a class="btn btn-default btn-xs abAddAction" data-trigger="<?php echo $trigger; ?>"><i class="fas fa-plus-circle"></i> {{Ajouter une action}}</a>
								<a class="btn btn-default btn-xs abTestActions" data-trigger="<?php echo $trigger; ?>"><i class="fas fa-vial"></i> {{Tester}}</a>
							</span>
						</legend>
						<div class="alertebeActions form-horizontal" data-trigger="<?php echo $trigger; ?>"></div>
					</fieldset>
				<?php } ?>
			</div>

			<!-- ========================================== JOURNAL ========================================== -->
			<div role="tabpanel" class="tab-pane" id="journaltab">
				<br>
				<div class="text-muted" style="margin:0 5px 10px 5px;">{{Les 50 derniers événements : alertes, rappels, retours à la normale, acquittements et suspensions.}}</div>
				<div id="div_alertebeJournal" style="margin:0 5px;"></div>
			</div>

			<!-- ========================================== COMMANDES ========================================== -->
			<div role="tabpanel" class="tab-pane" id="commandtab">
				<br>
				<table id="table_cmd" class="table table-bordered table-condensed">
					<thead>
						<tr>
							<th>{{Nom}}</th>
							<th>{{Type}}</th>
							<th>{{Options}}</th>
							<th>{{Action}}</th>
						</tr>
					</thead>
					<tbody></tbody>
				</table>
			</div>
		</div>
	</div>
</div>

<?php include_file('core', 'plugin.template', 'js'); ?>
<?php include_file('desktop', 'alertebe', 'js', 'alertebe'); ?>
