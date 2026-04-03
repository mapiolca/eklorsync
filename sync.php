<?php
/* Copyright (C) 2024 - Module PowrSync
 * Page de synchronisation des prix POwR Connect
 */

require '../../main.inc.php';
require_once DOL_DOCUMENT_ROOT.'/core/class/html.form.class.php';
require_once DOL_DOCUMENT_ROOT.'/core/lib/product.lib.php';
require_once DOL_DOCUMENT_ROOT.'/core/lib/tax.lib.php';
require_once DOL_DOCUMENT_ROOT.'/product/class/product.class.php';
require_once DOL_DOCUMENT_ROOT.'/fourn/class/fournisseur.product.class.php';
require_once dol_buildpath('/powrsync/class/powrsync.class.php', 0);
require_once dol_buildpath('/powrsync/class/powrconnectscraper.class.php', 0);

$langs->loadLangs(array('products', 'suppliers', 'powrsync@powrsync'));

if (!isModEnabled('powrsync')) {
	accessforbidden('Module PowrSync non activé');
}

if (!$user->hasRight('powrsync', 'synclog', 'read')) {
	accessforbidden();
}

$action              = GETPOST('action', 'aZ09');
$supplierPriceLineId = GETPOST('lineid', 'int');
$confirm             = GETPOST('confirm', 'alpha');
$sortfield           = GETPOST('sortfield', 'aZ09comma') ? GETPOST('sortfield', 'aZ09comma') : 'p.ref';
$sortorder           = GETPOST('sortorder', 'aZ09comma') ? GETPOST('sortorder', 'aZ09comma') : 'ASC';
$search_ref_product  = trim(GETPOST('search_ref_product', 'alphanohtml'));
$search_ref_fourn    = trim(GETPOST('search_ref_fourn', 'alphanohtml'));

$tempDir = !empty($conf->powrsync->dir_temp) ? $conf->powrsync->dir_temp : sys_get_temp_dir();
$scraper = new PowrConnectScraper($tempDir);

$fkSoc = getDolGlobalInt('POWRSYNC_SUPPLIER_ID');
$email = getDolGlobalString('POWRSYNC_LOGIN');
$pwd   = getDolGlobalString('POWRSYNC_PASSWORD');

$form = new Form($db);

$allowedSortFields = array('p.ref', 'p.label', 'pfp.ref_fourn', 'pfp.unitprice', 'lastlog.new_price', 'lastlog.datec', 'lastlog.status');
if (!in_array($sortfield, $allowedSortFields, true)) {
	$sortfield = 'p.ref';
}
$sortorder = (strtoupper($sortorder) === 'DESC') ? 'DESC' : 'ASC';

$param = '';
if ($search_ref_product !== '') {
	$param .= '&search_ref_product='.urlencode($search_ref_product);
}
if ($search_ref_fourn !== '') {
	$param .= '&search_ref_fourn='.urlencode($search_ref_fourn);
}

// =========================================================================
// ACTIONS
// =========================================================================

if ($user->hasRight('powrsync', 'synclog', 'write')) {

	// --- Synchronisation par batch avec barre de progression (1 produit par rechargement) ---

	if ($action == 'syncall_batch') {
		$batchSize    = 1;
		$offset       = max(0, GETPOST('offset', 'int'));
		$totalUpdated = max(0, GETPOST('updated', 'int'));
		$totalErrors  = max(0, GETPOST('errors', 'int'));
		$totalCount   = max(0, GETPOST('total', 'int'));
		$startTs      = GETPOST('start_ts', 'int');

		// Premier batch : initialisation du timestamp de session
		if ($offset === 0 || $startTs <= 0) {
			$startTs = dol_now();
		}

		// Premier batch : COUNT léger pour calibrer la barre + purge des cookies périmés
		if ($offset === 0 || $totalCount === 0) {
			$sqlCount  = "SELECT COUNT(pfp.rowid) AS nb";
			$sqlCount .= " FROM ".MAIN_DB_PREFIX."product_fournisseur_price AS pfp";
			$sqlCount .= " LEFT JOIN ".MAIN_DB_PREFIX."product_fournisseur_price_extrafields AS ef ON ef.fk_object = pfp.rowid";
			$sqlCount .= " WHERE pfp.fk_soc = ".((int) $fkSoc);
			$sqlCount .= " AND pfp.entity IN (".getEntity('product').")";
			$sqlCount .= " AND pfp.status = 1";
			$sqlCount .= " AND ef.supplier_url IS NOT NULL AND TRIM(ef.supplier_url) <> ''";
			$resCount   = $db->query($sqlCount);
			$totalCount = ($resCount && ($objCount = $db->fetch_object($resCount))) ? (int) $objCount->nb : 0;

			// Purge des cookies de session précédente pour forcer un login frais
			array_map('unlink', glob($tempDir.'/powrsync_*.txt'));
		}

		// Récupère uniquement le produit courant via LIMIT/OFFSET
		$batch = getProductsWithPowrRef($db, $fkSoc, 'p.ref', 'ASC', '', '', true, $batchSize, $offset);
		if ($batch === false) {
			$batch = array();
		}

		foreach ($batch as $productToSync) {
			$ret = syncOneSupplierProductPrice(
				$db, $scraper, $productToSync, $fkSoc, $user,
				getDolGlobalString('POWRSYNC_LOGIN'),
				dol_decode(getDolGlobalString('POWRSYNC_PASSWORD'))
			);

			// Premier produit : si échec, on retente une fois —
			// le 302 du login a pu consommer la première tentative
			if ($ret < 0 && $offset === 0) {
				sleep(1);
				$ret = syncOneSupplierProductPrice(
					$db, $scraper, $productToSync, $fkSoc, $user,
					getDolGlobalString('POWRSYNC_LOGIN'),
					dol_decode(getDolGlobalString('POWRSYNC_PASSWORD'))
				);
			}

			if ($ret > 0) {
				$totalUpdated++;
			} elseif ($ret < 0) {
				$totalErrors++;
			}
		}
		$scraper->close();

		$newOffset = $offset + count($batch);
		$isDone    = ($newOffset >= $totalCount || $totalCount === 0);
		$percent   = $totalCount > 0 ? min(100, (int) round($newOffset / $totalCount * 100)) : 100;

		// --- Rendu de la page de progression ---
		llxHeader('', $langs->trans('PowrSyncTitle'));

		print '<div style="max-width:720px; margin:50px auto; padding:0 20px;">';

		print '<h3 style="text-align:center; margin-bottom:24px;">';
		print img_picto('', 'refresh', 'class="pictofixedwidth"');
		print ' '.$langs->trans('SyncAllPrices');
		print '</h3>';

		// Barre de progression
		print '<div style="background:#dde1e7; border-radius:8px; overflow:hidden; height:30px; margin-bottom:12px; box-shadow:inset 0 1px 3px rgba(0,0,0,.15);">';
		print '<div style="background:#4a90d9; height:100%; width:'.$percent.'%; display:flex; align-items:center; justify-content:center; transition:width .3s ease;">';
		if ($percent >= 10) {
			print '<span style="color:#fff; font-weight:bold; font-size:13px; text-shadow:0 1px 2px rgba(0,0,0,.3);">'.$percent.'%</span>';
		}
		print '</div>';
		print '</div>';

		print '<p style="text-align:center; color:#666; margin-top:8px; margin-bottom:20px;">';
		print min($newOffset, $totalCount).' / '.$totalCount.' produit(s) traité(s)';
		print '</p>';

		// --- Logs de la session en cours (du plus récent au plus ancien) ---
		$sqlLogs  = "SELECT l.ref_product, l.ref_fourn, l.old_price, l.new_price, l.status, l.message, l.datec";
		$sqlLogs .= " FROM ".MAIN_DB_PREFIX."powrsync_log AS l";
		$sqlLogs .= " WHERE l.datec >= '".$db->idate($startTs)."'";
		$sqlLogs .= " ORDER BY l.datec DESC, l.rowid DESC";

		$resLogs = $db->query($sqlLogs);

		if ($resLogs && $db->num_rows($resLogs) > 0) {
			print '<table class="tagtable noborder liste" style="font-size:13px;">';
			print '<tr class="liste_titre">';
			print '<td>'.$langs->trans('ProductRef').'</td>';
			print '<td>'.$langs->trans('PowrRef').'</td>';
			print '<td class="right">'.$langs->trans('OldPrice').'</td>';
			print '<td class="right">'.$langs->trans('NewPrice').'</td>';
			print '<td class="center">'.$langs->trans('Status').'</td>';
			print '</tr>';

			while ($logObj = $db->fetch_object($resLogs)) {
				$status = (int) $logObj->status;

				if ($status == PowrConnectScraper::LOG_OK) {
					$badgeClass = 'badge badge-status4';
					$badgeLabel = $langs->trans('PowrLogUpdated');
				} elseif ($status == PowrConnectScraper::LOG_UPTODATE) {
					$badgeClass = 'badge badge-status1';
					$badgeLabel = $langs->trans('PowrLogUpToDate');
				} else {
					$badgeClass = 'badge badge-status8';
					$badgeLabel = $langs->trans('PowrLogError');
				}

				$oldPriceHtml = $logObj->old_price !== null ? price($logObj->old_price).' €' : '-';
				$newPriceHtml = $logObj->new_price !== null ? price($logObj->new_price).' €' : '-';

				$diffHtml = '';
				if ($logObj->old_price !== null && $logObj->new_price !== null) {
					$diff = (float) $logObj->new_price - (float) $logObj->old_price;
					if (abs($diff) > 0.001) {
						$arrow    = $diff > 0 ? '▲' : '▼';
						$diffCss  = $diff > 0 ? 'color:red' : 'color:green';
						$diffHtml = ' <span style="'.$diffCss.';font-size:11px;">'.$arrow.' '.price(abs($diff)).' €</span>';
					}
				}

				print '<tr class="oddeven">';
				print '<td>'.dol_escape_htmltag($logObj->ref_product).'</td>';
				print '<td>'.dol_escape_htmltag($logObj->ref_fourn).'</td>';
				print '<td class="right">'.$oldPriceHtml.'</td>';
				print '<td class="right">'.$newPriceHtml.$diffHtml.'</td>';
				print '<td class="center"><span class="'.$badgeClass.'">'.$badgeLabel.'</span>';
				if ($status < 0 && !empty($logObj->message)) {
					print '<br><small class="opacitymedium">'.dol_escape_htmltag(dol_trunc($logObj->message, 60)).'</small>';
				}
				print '</td>';
				print '</tr>';
			}

			print '</table>';
		}

		// Notification finale ou redirection — toujours après le tableau
		if ($isDone) {
			if ($totalErrors > 0) {
				print '<div class="warning" style="margin-top:20px; padding:12px 16px;">';
				print img_picto('', 'warning', 'class="pictofixedwidth"');
				print ' '.$langs->trans('PowrSyncDone', $totalUpdated);
				print ' &mdash; '.$totalErrors.' erreur(s) rencontrée(s).';
				print '</div>';
			} else {
				print '<div class="ok" style="margin-top:20px; padding:12px 16px;">';
				print img_picto('', 'tick', 'class="pictofixedwidth"');
				print ' '.$langs->trans('PowrSyncDone', $totalUpdated);
				print ' &mdash; Tous les prix sont à jour.';
				print '</div>';
			}

			print '<div style="text-align:center; margin-top:20px; margin-bottom:28px;">';
			print '<a class="butAction" href="'.dol_escape_htmltag($_SERVER['PHP_SELF']).'">';
			print $langs->trans('Back');
			print '</a>';
			print '</div>';

			// Retour auto après 5s — laisse le temps de lire le tableau complet
			print '<script>setTimeout(function(){ window.location='.json_encode($_SERVER['PHP_SELF']).'; }, 5000);</script>';
		} else {
			$nextUrl = $_SERVER['PHP_SELF']
				.'?action=syncall_batch'
				.'&offset='.(int) $newOffset
				.'&total='.(int) $totalCount
				.'&updated='.(int) $totalUpdated
				.'&errors='.(int) $totalErrors
				.'&start_ts='.(int) $startTs
				.'&token='.newToken();

			// 800ms : assez long pour voir la ligne s'ajouter, assez court pour rester fluide
			print '<script>setTimeout(function(){ window.location='.json_encode($nextUrl).'; }, 800);</script>';
			// Fallback si JS désactivé
			print '<p style="text-align:center; margin-top:20px;">';
			print '<a class="butAction" href="'.dol_escape_htmltag($nextUrl).'">'.$langs->trans('Next').'</a>';
			print '</p>';
		}

		print '</div>';

		llxFooter();
		$db->close();
		exit;
	}

	// --- Synchronisation d'UN seul produit (bouton individuel) ---

	if ($action == 'syncone' && $supplierPriceLineId > 0) {
		$found = getProductWithPowrRefByLineId($db, $fkSoc, $supplierPriceLineId);

		if ($found === false) {
			setEventMessages($langs->trans('PowrSyncDbError').': '.$db->lasterror(), null, 'errors');
		} elseif (empty($found)) {
			setEventMessages($langs->trans('PowrSyncProductNotFound'), null, 'errors');
		} else {
			$ret2 = syncOneSupplierProductPrice($db, $scraper, $found, $fkSoc, $user, getDolGlobalString('POWRSYNC_LOGIN'), dol_decode(getDolGlobalString('POWRSYNC_PASSWORD')));

			if ($ret2 == 1) {
				setEventMessages($langs->trans('PowrSyncUpdated', $found['ref_product'], $found['ref_fourn']), null, 'mesgs');
			} elseif ($ret2 == 2) {
				setEventMessages($langs->trans('PowrSyncUpToDate', $found['ref_product']), null, 'warnings');
			} else {
				setEventMessages($scraper->error, null, 'errors');
			}
		}

		$scraper->close();
		header('Location: '.$_SERVER['PHP_SELF']);
		exit;
	}
}

// =========================================================================
// VUE
// =========================================================================

$page  = 0;
$limit = 0;
$total = 0;

llxHeader('', $langs->trans('PowrSyncTitle'));

$object = new PowrSync($db);

print_barre_liste($langs->trans('PowrSyncTitle'), $page, $_SERVER['PHP_SELF'], $param, $sortfield, $sortorder, '', $total, $limit, $object->picto, 0, '', '', $limit);

// Vérification de la configuration
$configOk = ($fkSoc > 0 && !empty($email) && !empty($pwd));

if (!$configOk) {
	print '<div class="error">';
	print img_picto('', 'warning', 'class="pictofixedwidth"');
	print ' '.$langs->trans('PowrSyncNotConfigured');
	print ' <a href="'.dol_buildpath('/powrsync/admin/setup.php', 1).'">'.$langs->trans('GoToSetup').'</a>';
	print '</div>';
	llxFooter();
	$db->close();
	exit;
}

// Confirmation avant sync global
if ($user->hasRight('powrsync', 'synclog', 'write')) {
	$syncUrl = $_SERVER['PHP_SELF'].'?action=syncall_batch&offset=0&total=0&updated=0&errors=0&start_ts=0&token='.newToken();

	print '<div class="tabsAction">';
	print '<a class="butAction" id="btn-powrsync-all" href="#">';
	print img_picto('', 'refresh', 'class="pictofixedwidth"');
	print ' '.$langs->trans('SyncAllPrices');
	print '</a>';
	print '</div>';

	// Modal de confirmation
	print '<div id="powrsync-confirm-modal" style="display:none;" title="'.$langs->trans('SyncAllPrices').'">';
	print '<p>';
	print img_picto('', 'warning', 'class="pictofixedwidth"');
	print ' '.$langs->trans('PowrSyncConfirmAll');
	print '</p>';
	print '</div>';

	if (!empty($conf->use_javascript_ajax)) {
		print '<script>';
		print 'jQuery(function($) {';
		print '  var confirmUrl = '.json_encode($syncUrl).';';
		print '  $("#powrsync-confirm-modal").dialog({';
		print '    autoOpen: false,';
		print '    modal: true,';
		print '    width: 460,';
		print '    resizable: false,';
		print '    buttons: {';
		print '      '.json_encode($langs->trans('Confirm')).': function() {';
		print '        window.location = confirmUrl;';
		print '      },';
		print '      '.json_encode($langs->trans('Cancel')).': function() {';
		print '        $(this).dialog("close");';
		print '      }';
		print '    }';
		print '  });';
		print '  $("#btn-powrsync-all").on("click", function(e) {';
		print '    e.preventDefault();';
		print '    $("#powrsync-confirm-modal").dialog("open");';
		print '  });';
		print '});';
		print '</script>';
	}
}

// Liste des produits avec ref POwR Connect
$products = getProductsWithPowrRef($db, $fkSoc, $sortfield, $sortorder, $search_ref_product, $search_ref_fourn);

if ($products === false) {
	print '<div class="error">'.$langs->trans('PowrSyncDbError').': '.dol_escape_htmltag($db->lasterror()).'</div>';
	llxFooter();
	$db->close();
	exit;
}

print '<form method="GET" action="'.$_SERVER['PHP_SELF'].'">';
print '<div class="div-table-responsive">';
print '<table class="tagtable nobottomiftotal noborder liste">';

print '<tr class="liste_titre_filter">';
print '<td class="liste_titre center maxwidthsearch">'.$form->showFilterButtons('left').'</td>';
print '<td><input type="text" class="flat minwidth100" name="search_ref_product" value="'.dol_escape_htmltag($search_ref_product).'"></td>';
print '<td></td>';
print '<td><input type="text" class="flat minwidth100" name="search_ref_fourn" value="'.dol_escape_htmltag($search_ref_fourn).'"></td>';
print '<td></td>';
print '<td></td>';
print '<td></td>';
print '<td></td>';
print '<td></td>';
print '</tr>';

print '<tr class="liste_titre">';
print '<td></td>';
print getTitleFieldOfList($langs->trans('ProductRef'), 0, $_SERVER['PHP_SELF'], 'p.ref', '', $param, '', $sortfield, $sortorder);
print getTitleFieldOfList($langs->trans('ProductLabel'), 0, $_SERVER['PHP_SELF'], 'p.label', '', $param, '', $sortfield, $sortorder);
print getTitleFieldOfList($langs->trans('PowrRef'), 0, $_SERVER['PHP_SELF'], 'pfp.ref_fourn', '', $param, '', $sortfield, $sortorder);
print '<td class="right">'.$langs->trans('CurrentBuyPrice').'</td>';
print getTitleFieldOfList($langs->trans('LastSyncPrice'), 0, $_SERVER['PHP_SELF'], 'lastlog.new_price', '', $param, 'class="right"', $sortfield, $sortorder);
print getTitleFieldOfList($langs->trans('LastSync'), 0, $_SERVER['PHP_SELF'], 'lastlog.datec', '', $param, 'class="center"', $sortfield, $sortorder);
print getTitleFieldOfList($langs->trans('Status'), 0, $_SERVER['PHP_SELF'], 'lastlog.status', '', $param, 'class="center"', $sortfield, $sortorder);
print '<td class="center">'.$langs->trans('Action').'</td>';
print '</tr>';

if (empty($products)) {
	print '<tr><td colspan="9" class="center opacitymedium">'.$langs->trans('PowrSyncNoProducts').'</td></tr>';
} else {
	foreach ($products as $prod) {
		$pid = (int) $prod['fk_product'];

		$log = (!empty($prod['last_datec']) || $prod['last_status'] !== null) ? array(
			'datec'     => $prod['last_datec'],
			'old_price' => $prod['last_old_price'],
			'new_price' => $prod['last_new_price'],
			'status'    => $prod['last_status'],
			'message'   => $prod['last_message'],
		) : null;

		$statusClass = '';
		$statusLabel = '';
		$statusIcon  = '';

		if ($log) {
			if ($log['status'] == PowrConnectScraper::LOG_OK) {
				$statusClass = 'badge badge-status4';
				$statusLabel = $langs->trans('PowrLogUpdated');
				$statusIcon  = 'tick';
			} elseif ($log['status'] == PowrConnectScraper::LOG_UPTODATE) {
				$statusClass = 'badge badge-status1';
				$statusLabel = $langs->trans('PowrLogUpToDate');
				$statusIcon  = 'check';
			} else {
				$statusClass = 'badge badge-status8';
				$statusLabel = $langs->trans('PowrLogError');
				$statusIcon  = 'warning';
			}
		}

		print '<tr class="oddeven">';
		print '<td></td>';

		// Ref produit (lien fiche)
		print '<td>';
		print '<a href="'.DOL_URL_ROOT.'/product/card.php?id='.$pid.'">'.dol_escape_htmltag($prod['ref_product']).'</a>';
		print '</td>';

		// Label
		print '<td>'.dol_escape_htmltag(dol_trunc($prod['label_product'], 50)).'</td>';

		// Ref POwR
		print '<td>';
		if (!empty($prod['supplier_url'])) {
			print '<a href="'.dol_escape_htmltag($prod['supplier_url']).'" target="_blank" rel="noopener">';
			print dol_escape_htmltag($prod['ref_fourn']);
			print ' '.img_picto('', 'external-link-alt', 'class="opacitymedium"');
			print '</a>';
		} else {
			print dol_escape_htmltag($prod['ref_fourn']);
		}
		print '</td>';

		// Prix actuel Dolibarr
		print '<td class="right"><b>'.price($prod['unitprice']).' €</b></td>';

		// Dernier prix scrapé
		print '<td class="right">';
		if ($log && $log['new_price'] !== null) {
			$diff = $log['new_price'] - $log['old_price'];
			print price($log['new_price']).' €';
			if (abs($diff) > 0.001) {
				$arrow = $diff > 0 ? '▲' : '▼';
				$cls   = $diff > 0 ? 'color:red' : 'color:green';
				print ' <span style="'.$cls.'">'.$arrow.' '.price(abs($diff)).' €</span>';
			}
		} else {
			print '<span class="opacitymedium">-</span>';
		}
		print '</td>';

		// Date dernière synchro
		print '<td class="center">';
		if ($log) {
			print dol_print_date($db->jdate($log['datec']), 'dayhour');
		} else {
			print '<span class="opacitymedium">'.$langs->trans('Never').'</span>';
		}
		print '</td>';

		// Statut badge
		print '<td class="center">';
		if ($log) {
			print '<span class="'.$statusClass.'">';
			print img_picto('', $statusIcon, 'class="pictofixedwidth"');
			print $statusLabel.'</span>';
			if ($log['status'] < 0 && !empty($log['message'])) {
				print '<br><small class="opacitymedium">'.dol_escape_htmltag(dol_trunc($log['message'], 60)).'</small>';
			}
		} else {
			print '<span class="opacitymedium">-</span>';
		}
		print '</td>';

		// Bouton sync individuel
		print '<td class="center">';
		if ($user->hasRight('powrsync', 'synclog', 'write')) {
			print '<a class="reposition butActionSmall" href="'.$_SERVER['PHP_SELF'].'?action=syncone&lineid='.(int) $prod['pfp_rowid'].'&token='.newToken().'">';
			print img_picto($langs->trans('Sync'), 'refresh');
			print '</a>';
		}
		print '</td>';

		print '</tr>';
	}
}

print '</table>';
print '</div>';
print '</form>';

print '<br><p class="opacitymedium center">';
print $langs->trans('PowrSyncProductCount', count($products));
print '</p>';

llxFooter();
$db->close();

// =========================================================================
// HELPER FUNCTION: syncable products
// =========================================================================

/**
 * Returns supplier product rows for POwR Connect sync.
 *
 * @param DoliDB $db
 * @param int    $fkSoc
 * @param string $sortfield
 * @param string $sortorder
 * @param string $searchRefProduct
 * @param string $searchRefFourn
 * @param bool   $requireSupplierUrl
 * @param int    $limit              0 = pas de limite
 * @param int    $offset             0 = depuis le début
 * @return array|false
 */
function getProductsWithPowrRef($db, $fkSoc, $sortfield = 'p.ref', $sortorder = 'ASC',
	$searchRefProduct = '', $searchRefFourn = '', $requireSupplierUrl = false,
	$limit = 0, $offset = 0)
{
	$sortFieldMap = array(
		'p.ref'             => 'p.ref',
		'p.label'           => 'p.label',
		'pfp.ref_fourn'     => 'pfp.ref_fourn',
		'pfp.unitprice'     => 'pfp.unitprice',
		'lastlog.new_price' => 'lastlog.new_price',
		'lastlog.datec'     => 'lastlog.datec',
		'lastlog.status'    => 'lastlog.status',
	);
	$sortfield = !empty($sortFieldMap[$sortfield]) ? $sortFieldMap[$sortfield] : 'p.ref';
	$sortorder = (strtoupper($sortorder) === 'DESC') ? 'DESC' : 'ASC';

	$sql  = "SELECT pfp.rowid AS pfp_rowid, pfp.fk_product, p.ref AS ref_product, p.label AS label_product, pfp.ref_fourn, pfp.unitprice AS unitprice, pfp.quantity, ef.supplier_url";
	$sql .= ", lastlog.datec AS last_datec, lastlog.old_price AS last_old_price, lastlog.new_price AS last_new_price, lastlog.status AS last_status, lastlog.message AS last_message";
	$sql .= " FROM ".MAIN_DB_PREFIX."product_fournisseur_price AS pfp";
	$sql .= " INNER JOIN ".MAIN_DB_PREFIX."product AS p ON p.rowid = pfp.fk_product";
	$sql .= " LEFT JOIN ".MAIN_DB_PREFIX."product_fournisseur_price_extrafields AS ef ON ef.fk_object = pfp.rowid";
	$sql .= " LEFT JOIN (";
	$sql .= " SELECT l1.fk_product, l1.datec, l1.old_price, l1.new_price, l1.status, l1.message";
	$sql .= " FROM ".MAIN_DB_PREFIX."powrsync_log AS l1";
	$sql .= " INNER JOIN (SELECT fk_product, MAX(datec) AS maxdate FROM ".MAIN_DB_PREFIX."powrsync_log GROUP BY fk_product) AS l2 ON l2.fk_product = l1.fk_product AND l2.maxdate = l1.datec";
	$sql .= " ) AS lastlog ON lastlog.fk_product = pfp.fk_product";
	$sql .= " WHERE pfp.fk_soc = ".((int) $fkSoc);
	$sql .= " AND pfp.entity IN (".getEntity('product').")";
	$sql .= " AND pfp.status = 1";

	if ($requireSupplierUrl) {
		$sql .= " AND ef.supplier_url IS NOT NULL";
		$sql .= " AND TRIM(ef.supplier_url) <> ''";
	}

	if ($searchRefProduct !== '') {
		$sql .= " AND p.ref LIKE '%".$db->escape($searchRefProduct)."%'";
	}
	if ($searchRefFourn !== '') {
		$sql .= " AND pfp.ref_fourn LIKE '%".$db->escape($searchRefFourn)."%'";
	}

	$sql .= " ORDER BY ".$sortfield." ".$sortorder.", pfp.rowid ASC";

	if ($limit > 0) {
		$sql .= $db->plimit($limit, $offset);
	}

	$resql = $db->query($sql);
	if (!$resql) {
		return false;
	}

	$result = array();
	while ($obj = $db->fetch_object($resql)) {
		$result[] = array(
			'pfp_rowid'      => (int) $obj->pfp_rowid,
			'fk_product'     => (int) $obj->fk_product,
			'ref_product'    => $obj->ref_product,
			'label_product'  => $obj->label_product,
			'ref_fourn'      => $obj->ref_fourn,
			'unitprice'      => (float) $obj->unitprice,
			'quantity'       => (float) $obj->quantity,
			'supplier_url'   => $obj->supplier_url,
			'last_datec'     => $obj->last_datec,
			'last_old_price' => ($obj->last_old_price !== null ? (float) $obj->last_old_price : null),
			'last_new_price' => ($obj->last_new_price !== null ? (float) $obj->last_new_price : null),
			'last_status'    => ($obj->last_status !== null ? (int) $obj->last_status : null),
			'last_message'   => $obj->last_message,
		);
	}

	return $result;
}

/**
 * Returns one supplier product row for POwR Connect sync.
 *
 * @param DoliDB $db
 * @param int    $fkSoc
 * @param int    $lineId
 * @return array|false
 */
function getProductWithPowrRefByLineId($db, $fkSoc, $lineId)
{
	$sql  = "SELECT pfp.rowid AS pfp_rowid, pfp.fk_product, p.ref AS ref_product, p.label AS label_product, pfp.ref_fourn, pfp.unitprice AS unitprice, pfp.quantity, ef.supplier_url";
	$sql .= " FROM ".MAIN_DB_PREFIX."product_fournisseur_price AS pfp";
	$sql .= " INNER JOIN ".MAIN_DB_PREFIX."product AS p ON p.rowid = pfp.fk_product";
	$sql .= " LEFT JOIN ".MAIN_DB_PREFIX."product_fournisseur_price_extrafields AS ef ON ef.fk_object = pfp.rowid";
	$sql .= " WHERE pfp.rowid = ".((int) $lineId);
	$sql .= " AND pfp.fk_soc = ".((int) $fkSoc);
	$sql .= " AND pfp.entity IN (".getEntity('product').")";
	$sql .= " AND pfp.status = 1";

	$resql = $db->query($sql);
	if (!$resql) {
		return false;
	}

	$obj = $db->fetch_object($resql);
	if (empty($obj)) {
		return array();
	}

	return array(
		'pfp_rowid'     => (int) $obj->pfp_rowid,
		'fk_product'    => (int) $obj->fk_product,
		'ref_product'   => $obj->ref_product,
		'label_product' => $obj->label_product,
		'ref_fourn'     => $obj->ref_fourn,
		'unitprice'     => (float) $obj->unitprice,
		'quantity'      => (float) $obj->quantity,
		'supplier_url'  => $obj->supplier_url,
	);
}

// =========================================================================
// FONCTION UTILITAIRE : derniers logs par produit
// =========================================================================

/**
 * Retourne le dernier log de synchro pour chaque fk_product
 *
 * @param DoliDB $db
 * @param int    $fkSoc
 * @return array [fk_product => log_row]
 */
function getLastLogsByProduct($db, $fkSoc)
{
	$sql  = "SELECT l.fk_product, l.datec, l.old_price, l.new_price, l.status, l.message";
	$sql .= " FROM ".MAIN_DB_PREFIX."powrsync_log l";
	$sql .= " INNER JOIN (";
	$sql .= " SELECT fk_product, MAX(datec) AS maxdate FROM ".MAIN_DB_PREFIX."powrsync_log GROUP BY fk_product";
	$sql .= " ) last ON l.fk_product = last.fk_product AND l.datec = last.maxdate";

	$resql  = $db->query($sql);
	$result = array();
	if ($resql) {
		while ($obj = $db->fetch_object($resql)) {
			$result[(int) $obj->fk_product] = array(
				'datec'     => $obj->datec,
				'old_price' => $obj->old_price,
				'new_price' => $obj->new_price,
				'status'    => (int) $obj->status,
				'message'   => $obj->message,
			);
		}
	}

	return $result;
}

/**
 * Returns the default VAT rate for a supplier according to its country.
 *
 * @param DoliDB $db
 * @param int    $supplierId
 * @return float|null
 */
function getSupplierDefaultVatRate($db, $supplierId)
{
	static $vatCache = array();

	$supplierId = (int) $supplierId;
	if ($supplierId <= 0) {
		return 0.0;
	}

	if (isset($vatCache[$supplierId])) {
		return (float) $vatCache[$supplierId];
	}

	$configuredVatRaw = getDolGlobalString('POWRSYNC_DEFAULT_VAT_RATE');
	if (trim((string) $configuredVatRaw) === '') {
		return null;
	}

	$vatCache[$supplierId] = (float) price2num($configuredVatRaw);
	return (float) $vatCache[$supplierId];
}

/**
 * Synchronize one supplier product price with POwR Connect.
 *
 * @param DoliDB             $db
 * @param PowrConnectScraper $scraper
 * @param array              $productRow
 * @param int                $fkSoc
 * @param User               $user
 * @param string             $login
 * @param string             $password
 * @return int  1=mis à jour, 2=déjà à jour, -1=erreur
 */
function syncOneSupplierProductPrice($db, $scraper, $productRow, $fkSoc, $user, $login, $password)
{
	global $langs;

	$powrRef      = $productRow['ref_fourn'];
	$url          = !empty($productRow['supplier_url']) ? $productRow['supplier_url'] : '';
	$productId    = (int) $productRow['fk_product'];
	$currentPrice = isset($productRow['unitprice']) ? (float) $productRow['unitprice'] : null;

	if (empty($url) || !filter_var($url, FILTER_VALIDATE_URL)) {
		$scraper->error = 'URL fournisseur non valide pour '.$powrRef;
		insertPowrSyncLog($db, $productRow, $user, PowrConnectScraper::LOG_ERROR, $currentPrice, null, $scraper->error);
		return -1;
	}

	$newPrice = $scraper->testConnectionAndGetPrice($login, $password, $url, $powrRef);

	if ($newPrice === false) {
		insertPowrSyncLog($db, $productRow, $user, PowrConnectScraper::LOG_ERROR, $currentPrice, null, $scraper->error);
		return -1;
	}

	if (abs($newPrice - $currentPrice) <= 0.001) {
		insertPowrSyncLog($db, $productRow, $user, PowrConnectScraper::LOG_UPTODATE, $currentPrice, $newPrice, '');
		return 2;
	}

	$productFournisseur = new ProductFournisseur($db);
	$priceLineId        = !empty($productRow['pfp_rowid']) ? (int) $productRow['pfp_rowid'] : 0;
	$qty                = max(1, (float) $productRow['quantity']);
	$vatTx              = getSupplierDefaultVatRate($db, $fkSoc);

	if ($vatTx === null) {
		$scraper->error = $langs->trans('PowrSyncDefaultVatRateRequired');
		insertPowrSyncLog($db, $productRow, $user, PowrConnectScraper::LOG_ERROR, $currentPrice, null, $scraper->error);
		return -1;
	}

	// Toujours renseigner les IDs de contexte avant update pour éviter le fallback delete/insert avec fk_product=0/fk_soc=0.
	$productFournisseur->id         = $productId;
	$productFournisseur->fk_product = $productId;
	$productFournisseur->fourn_id   = (int) $fkSoc;
	$productFournisseur->ref_fourn  = $powrRef;
	$productFournisseur->fourn_qty  = $qty;

	if ($priceLineId > 0) {
		$productFournisseur->product_fourn_price_id = $priceLineId;
	}

	$fetchResult = -1;
	if ($priceLineId > 0) {
		$fetchResult = $productFournisseur->fetch_product_fournisseur_price($priceLineId);
	}

	if ($fetchResult < 0) {
		$productFournisseur->fk_product = $productId;
		$productFournisseur->fourn_id   = (int) $fkSoc;
		$productFournisseur->ref_fourn  = $powrRef;
		$productFournisseur->fourn_qty  = $qty;
	}

	$res = $productFournisseur->update_buyprice(
		$qty,
		(float) $newPrice,
		$user,
		'HT',
		(int) $fkSoc,
		$vatTx,
		$powrRef,
		0,
		0,
		0,
		0
	);

	if ($res < 0) {
		$scraper->error = !empty($productFournisseur->error) ? $productFournisseur->error : $db->lasterror();
		insertPowrSyncLog($db, $productRow, $user, PowrConnectScraper::LOG_ERROR, $currentPrice, $newPrice, $scraper->error);
		return -1;
	}

	$forceVatResult = forceSupplierPriceVatRate($db, $priceLineId, $productId, $fkSoc, $powrRef, $qty, $vatTx);
	if ($forceVatResult < 0) {
		$scraper->error = $db->lasterror();
		insertPowrSyncLog($db, $productRow, $user, PowrConnectScraper::LOG_ERROR, $currentPrice, $newPrice, $scraper->error);
		return -1;
	}

	insertPowrSyncLog($db, $productRow, $user, PowrConnectScraper::LOG_OK, $currentPrice, $newPrice, '');
	return 1;
}

/**
 * Force VAT rate value on supplier price row after update.
 *
 * @param DoliDB $db
 * @param int    $priceLineId
 * @param int    $productId
 * @param int    $fkSoc
 * @param string $powrRef
 * @param float  $qty
 * @param float  $vatTx
 * @return int
 */
function forceSupplierPriceVatRate($db, $priceLineId, $productId, $fkSoc, $powrRef, $qty, $vatTx)
{
	$priceLineId = (int) $priceLineId;

	if ($priceLineId <= 0) {
		$sqlFind  = "SELECT pfp.rowid";
		$sqlFind .= " FROM ".MAIN_DB_PREFIX."product_fournisseur_price AS pfp";
		$sqlFind .= " WHERE pfp.fk_product = ".((int) $productId);
		$sqlFind .= " AND pfp.fk_soc = ".((int) $fkSoc);
		$sqlFind .= " AND pfp.ref_fourn = '".$db->escape($powrRef)."'";
		$sqlFind .= " AND pfp.quantity = ".price2num($qty);
		$sqlFind .= " ORDER BY pfp.rowid DESC";
		$sqlFind .= " LIMIT 1";

		$resqlFind = $db->query($sqlFind);
		if (!$resqlFind) {
			return -1;
		}

		$objFind     = $db->fetch_object($resqlFind);
		$priceLineId = !empty($objFind) ? (int) $objFind->rowid : 0;

		if ($resqlFind) {
			$db->free($resqlFind);
		}
	}

	if ($priceLineId <= 0) {
		return -1;
	}

	$sqlUpdate  = "UPDATE ".MAIN_DB_PREFIX."product_fournisseur_price";
	$sqlUpdate .= " SET tva_tx = ".price2num($vatTx);
	$sqlUpdate .= " WHERE rowid = ".$priceLineId;

	return $db->query($sqlUpdate) ? 1 : -1;
}

/**
 * Save one synchronization log row in llx_powrsync_log.
 *
 * @param DoliDB $db
 * @param array  $productRow
 * @param User   $user
 * @param int    $status
 * @param float  $oldPrice
 * @param float  $newPrice
 * @param string $message
 * @return void
 */
function insertPowrSyncLog($db, $productRow, $user, $status, $oldPrice, $newPrice, $message)
{
	static $availableColumns = null;

	if ($availableColumns === null) {
		$availableColumns = array();
		$resql = $db->query("SHOW COLUMNS FROM ".MAIN_DB_PREFIX."powrsync_log");
		if ($resql) {
			while ($obj = $db->fetch_object($resql)) {
				$availableColumns[$obj->Field] = true;
			}
			$db->free($resql);
		}
	}

	if (empty($availableColumns)) {
		return;
	}

	$fields = array();
	$values = array();

	if (!empty($availableColumns['fk_product'])) {
		$fields[] = 'fk_product';
		$values[] = (int) $productRow['fk_product'];
	}
	if (!empty($availableColumns['ref_product'])) {
		$fields[] = 'ref_product';
		$values[] = "'".$db->escape($productRow['ref_product'])."'";
	}
	if (!empty($availableColumns['ref_fourn'])) {
		$fields[] = 'ref_fourn';
		$values[] = "'".$db->escape($productRow['ref_fourn'])."'";
	}
	if (!empty($availableColumns['old_price'])) {
		$fields[] = 'old_price';
		$values[] = ($oldPrice !== null ? (float) price2num($oldPrice) : 'NULL');
	}
	if (!empty($availableColumns['new_price'])) {
		$fields[] = 'new_price';
		$values[] = ($newPrice !== null ? (float) price2num($newPrice) : 'NULL');
	}
	if (!empty($availableColumns['status'])) {
		$fields[] = 'status';
		$values[] = (int) $status;
	}
	if (!empty($availableColumns['sync_status'])) {
		$fields[] = 'sync_status';
		$values[] = "'".$db->escape((string) $status)."'";
	}
	if (!empty($availableColumns['message'])) {
		$fields[] = 'message';
		$values[] = "'".$db->escape($message)."'";
	}
	if (!empty($availableColumns['fk_user_creat'])) {
		$fields[] = 'fk_user_creat';
		$values[] = (int) $user->id;
	}
	if (!empty($availableColumns['entity'])) {
		$fields[] = 'entity';
		$values[] = (int) $GLOBALS['conf']->entity;
	}
	if (!empty($availableColumns['datec'])) {
		$fields[] = 'datec';
		$values[] = "'".$db->idate(dol_now())."'";
	}

	if (empty($fields)) {
		return;
	}

	$sql  = "INSERT INTO ".MAIN_DB_PREFIX."powrsync_log (".implode(', ', $fields).")";
	$sql .= " VALUES (".implode(', ', $values).")";

	$db->query($sql);
}
