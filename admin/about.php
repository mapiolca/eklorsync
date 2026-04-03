<?php
/**
 * Page À propos pour le module PowrSync.
 */

// EN: Attempt to load Dolibarr bootstrap from common locations.
// FR: Tente de charger l'amorçage Dolibarr depuis les emplacements courants.
$res = 0;
if (!$res && file_exists(__DIR__.'/../main.inc.php')) {
        $res = require_once __DIR__.'/../main.inc.php';
}
if (!$res && file_exists(__DIR__.'/../../main.inc.php')) {
        $res = require_once __DIR__.'/../../main.inc.php';
}
if (!$res && file_exists(__DIR__.'/../../../main.inc.php')) {
        $res = require_once __DIR__.'/../../../main.inc.php';
}
if (!$res) {
        die('Include of main fails');
}

require_once DOL_DOCUMENT_ROOT.'/core/lib/admin.lib.php';
dol_include_once('/powrsync/lib/powrsync.lib.php');
dol_include_once('/powrsync/core/modules/modPowrSync.class.php');

// EN: Load admin and module translations for the about page.
// FR: Charge les traductions d'administration et du module pour la page À propos.
$langs->loadLangs(array('admin', 'powrsync@powrsync'));

// EN: Only Dolibarr administrators can display the about page.
// FR: Seuls les administrateurs Dolibarr peuvent afficher la page À propos.
if (empty($user->admin)) {
        accessforbidden();
}

$moduleDescriptor = new modPowrSync($db);
$title = $langs->trans('PowrSyncAbout');
$helpurl = '';

llxHeader('', $title, $helpurl);

print load_fiche_titre($langs->trans('PowrSyncAbout'), '', 'info');
$head = powrsyncAdminPrepareHead();
// EN: Render the admin tabs with the bookcal pictogram to stay consistent with the setup header.
// FR: Affiche les onglets d'administration avec le pictogramme bookcal pour rester cohérent avec la configuration.
print dol_get_fiche_head($head, 'about', $title, -1, 'bookcal@powrsync');

print '<div class="underbanner opacitymedium">'.$langs->trans('PowrSyncAboutPage').'</div>';
print '<br>';

print '<div class="fichecenter">';

// EN: Present core module information in a dedicated summary table.
// FR: Présente les informations principales du module dans un tableau récapitulatif.
print '<div class="fichehalfleft">';
print '<div class="div-table-responsive-no-min">';
print '<table class="noborder centpercent">';
print '<tr class="liste_titre"><th colspan="2">'.$langs->trans('PowrSyncAboutGeneral').'</th></tr>';
print '<tr class="oddeven"><td class="titlefield">'.$langs->trans('PowrSyncAboutVersion').'</td><td>'.dol_escape_htmltag($moduleDescriptor->version).'</td></tr>';
print '<tr class="oddeven"><td class="titlefield">'.$langs->trans('PowrSyncAboutFamily').'</td><td>'.dol_escape_htmltag($moduleDescriptor->family).'</td></tr>';
print '<tr class="oddeven"><td class="titlefield">'.$langs->trans('PowrSyncAboutDescription').'</td><td>'.dol_escape_htmltag($langs->trans($moduleDescriptor->description)).'</td></tr>';
print '<tr class="oddeven"><td class="titlefield">'.$langs->trans('PowrSyncAboutMaintainer').'</td><td>'.dol_escape_htmltag($moduleDescriptor->editor_name).'</td></tr>';
print '</table>';
print '</div>';
print '</div>';

// EN: List documentation and support resources with direct links.
// FR: Liste les ressources de documentation et de support avec des liens directs.
print '<div class="fichehalfright">';
print '<div class="div-table-responsive-no-min">';
print '<table class="noborder centpercent">';
print '<tr class="liste_titre"><th colspan="2">'.$langs->trans('PowrSyncAboutResources').'</th></tr>';
print '<tr class="oddeven"><td class="titlefield">'.$langs->trans('PowrSyncAboutDocumentation').'</td><td><a href="'.dol_buildpath('/powrsync/README.md', 1).'" target="_blank" rel="noopener">'.$langs->trans('PowrSyncAboutDocumentationLink').'</a></td></tr>';
print '<tr class="oddeven"><td class="titlefield">'.$langs->trans('PowrSyncAboutSupport').'</td><td>'.dol_escape_htmltag($langs->trans('PowrSyncAboutSupportValue')).'</td></tr>';
print '<tr class="oddeven"><td class="titlefield">'.$langs->trans('PowrSyncAboutContact').'</td><td><a href="https://'.$moduleDescriptor->editor_url.'" target="_blank" rel="noopener">'.dol_escape_htmltag($moduleDescriptor->editor_url).'</a></td></tr>';
print '</table>';
print '</div>';
print '</div>';

print '</div>';

print dol_get_fiche_end();

llxFooter();
$db->close();
