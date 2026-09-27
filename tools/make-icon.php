<?php
/* Fabrique l'icône du plugin.
 *
 *   php tools/make-icon.php
 *
 * Dessinée ici plutôt que déposée en binaire opaque : on peut la relire et la
 * refaire. Le dessin est fait en 1024 puis réduit en 256, ce qui donne les
 * bords lissés que GD ne produit pas sur un remplissage direct.
 *
 * Un disque rouge et une cloche blanche barrée d'un point d'exclamation : une
 * alerte, sans rien dire du capteur, puisque le plugin les prend tous.
 */

const TAILLE = 256;
const ECHELLE = 4;

$grand = imagecreatetruecolor(TAILLE * ECHELLE, TAILLE * ECHELLE);
imagesavealpha($grand, true);
imagealphablending($grand, false);
imagefill($grand, 0, 0, imagecolorallocatealpha($grand, 0, 0, 0, 127));
imagealphablending($grand, true);

$rouge  = imagecolorallocate($grand, 0xD6, 0x33, 0x2B);
$orange = imagecolorallocate($grand, 0xF2, 0x9A, 0x1A);
$blanc  = imagecolorallocate($grand, 0xFF, 0xFF, 0xFF);

$e = function ($_valeur) { return (int) round($_valeur * ECHELLE); };

/* Le disque, cerclé d'orange. */
imagefilledellipse($grand, $e(128), $e(128), $e(240), $e(240), $orange);
imagefilledellipse($grand, $e(128), $e(128), $e(216), $e(216), $rouge);

/* La cloche : le dôme, le corps qui s'évase, le rebord, le battant. */
imagefilledellipse($grand, $e(128), $e(98), $e(96), $e(96), $blanc);
imagefilledpolygon($grand, array($e(80), $e(98), $e(176), $e(98), $e(190), $e(170), $e(66), $e(170)), $blanc);
imagefilledrectangle($grand, $e(58), $e(166), $e(198), $e(180), $blanc);
imagefilledellipse($grand, $e(128), $e(194), $e(30), $e(30), $blanc);
imagefilledellipse($grand, $e(128), $e(48), $e(18), $e(18), $blanc);

/* Le point d'exclamation, en rouge dans la cloche. */
imagefilledrectangle($grand, $e(121), $e(82), $e(135), $e(136), $rouge);
imagefilledellipse($grand, $e(128), $e(152), $e(16), $e(16), $rouge);

$icone = imagecreatetruecolor(TAILLE, TAILLE);
imagesavealpha($icone, true);
imagealphablending($icone, false);
imagefill($icone, 0, 0, imagecolorallocatealpha($icone, 0, 0, 0, 127));
imagecopyresampled($icone, $grand, 0, 0, 0, 0, TAILLE, TAILLE, TAILLE * ECHELLE, TAILLE * ECHELLE);

$cible = __DIR__ . '/../plugin_info/alertebe_icon.png';
imagepng($icone, $cible);
echo "Écrit : $cible\n";
