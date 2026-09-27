<?php
if (!isConnect('admin')) {
	throw new Exception('{{401 - Accès non autorisé}}');
}
?>
<form class="form-horizontal">
	<fieldset>
		<div class="alert alert-info" style="margin:5px;">
			{{Le plugin n'a pas de réglage global : chaque surveillance a ses règles, ses actions et ses rappels, sur sa propre page. Aucun démon ni dépendance : un écouteur réagit à chaque nouvelle valeur d'un capteur, et le cron de Jeedom passe chaque minute pour les durées de confirmation, les rappels et les capteurs muets.}}
		</div>
	</fieldset>
</form>
