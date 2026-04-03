<?php
/* Copyright (C) 2024 Votre Société — Licence GNU GPL v3 */

require_once DOL_DOCUMENT_ROOT.'/core/class/commonobject.class.php';
require_once DOL_DOCUMENT_ROOT.'/custom/powrsync/class/powrconnectscraper.class.php';

/**
 * PowrSync
 *
 * Orchestrateur : parcourt les produits Dolibarr ayant une ref fournisseur
 * POwR Connect, récupère les prix via le scraper, compare et met à jour.
 */
class PowrSync extends CommonObject
{
	public $element       = 'powrsync';
	public $table_element = 'powrsync_log';
	public $picto         = 'fa-sun';

	/** Nom du fournisseur tel qu'enregistré dans Dolibarr (llx_societe.nom) */
	private $supplierName = 'POwR Connect';

	public $error  = '';
	public $errors = array();
	private $logColumns = null;

	// Identifiant interne du fournisseur POwR Connect
	private $supplierId = 0;

	public function __construct(DoliDB $db)
	{
		$this->db = $db;
	}

	// ─── Point d'entrée principal ───────────────────────────────────────────

	/**
	 * Synchronise tous les produits ayant une référence POwR Connect.
	 * Appelé par le cron ou manuellement.
	 *
	 * @param  string $email    Login POwR Connect (si vide, lit la constante)
	 * @param  string $password Mot de passe (si vide, lit la constante)
	 * @return int Nombre de prix mis à jour, ou -1 en cas d'erreur
	 */
	public function syncAllProducts($email = '', $password = '')
	{
		global $conf;

		// Récupération des identifiants depuis la config si non fournis
		if (empty($email)) {
			$email    = getDolGlobalString('POWRSYNC_LOGIN');
		}
		if (empty($password)) {
			$password = getDolGlobalString('POWRSYNC_PASSWORD');
		}

		if (empty($email) || empty($password)) {
			$this->error = 'Identifiants POwR Connect non configurés (Menu : Config > POwR Sync)';
			dol_syslog('PowrSync: '.$this->error, LOG_WARNING);
			return 1;
		}

		// Use configured supplier first to mirror sync.php behavior
		$this->supplierId = (int) getDolGlobalInt('POWRSYNC_SUPPLIER_ID');
		if ($this->supplierId <= 0) {
			$this->supplierId = $this->findSupplierId();
		}
		if ($this->supplierId <= 0) {
			$this->error = 'Fournisseur "'.$this->supplierName.'" introuvable dans Dolibarr. Le créer d\'abord.';
			dol_syslog('PowrSync: '.$this->error, LOG_WARNING);
			return 1;
		}

		// Récupérer les produits avec une ref POwR Connect
		$products = $this->getProductsWithPowrRef();
		if (empty($products)) {
			$this->output = "PowrSync success: 0 product checked, 0 updated, 0 error";
			dol_syslog($this->output , LOG_INFO);
			
			return 0;
		}

		// Connexion au site POwR Connect
		$tempDir = $conf->powrsync->dir_temp ?: sys_get_temp_dir();
		$scraper = new PowrConnectScraper($tempDir);

		if ($scraper->login($email, $password) < 0) {
			$this->error = 'Connexion POwR Connect échouée : '.$scraper->error;
			dol_syslog('PowrSync: '.$this->error, LOG_WARNING);
			return 1;
		}

		// Synchronisation produit par produit
		$updatedCount = 0;
		$checkedCount = count($products);
		foreach ($products as $product) {
			$result = $this->syncOneProduct($scraper, $product);
			if ($result > 0) {
				$updatedCount++;
			}
		}

		$scraper->close();

		$errorCount = count($this->errors);
		$this->output = 'PowrSync success: '.$checkedCount.' products checked, '.$updatedCount.' updated, '.$errorCount.' error';
		dol_syslog($this->output, LOG_INFO);
		return 0;
	}

	// ─── Synchronisation d'un produit ──────────────────────────────────────

	/**
	 * Synchronise le prix d'un produit
	 *
	 * @param  PowrConnectScraper $scraper
	 * @param  array              $product  Données du produit (rowid, ref_fourn, buyprice, qty_min...)
	 * @return int  1=mis à jour, 0=inchangé, -1=erreur
	 */
	private function syncOneProduct(PowrConnectScraper $scraper, array $product)
	{
		global $user;

		$powrRef    = $product['ref_fourn'];
		$productId  = $product['product_id'];
		$currentPrice = (float) $product['unitprice'];
		$powrUrl    = !empty($product['supplier_url']) ? $product['supplier_url'] : '';

		if (empty($powrUrl)) {
			$this->addError('Ref '.$powrRef.' : URL fournisseur non renseignée (extrafield supplier_url)');
			$this->logSync($productId, $powrRef, $currentPrice, null, 'error', 'URL fournisseur manquante');
			return -1;
		}

		// Récupération du prix sur le site via l'URL directe
		$newPrice = $scraper->getPrice($powrRef, $powrUrl);

		if ($newPrice === false) {
			$this->addError('Ref '.$powrRef.' : '.$scraper->error);
			$this->logSync($productId, $powrRef, $currentPrice, null, 'error', $scraper->error);
			return -1;
		}

		// Comparaison avec tolérance de 0.001€ pour éviter les micro-diffs flottants
		$hasChanged = (abs($newPrice - $currentPrice) > 0.001);

		if (!$hasChanged) {
			$this->logSync($productId, $powrRef, $currentPrice, $newPrice, 'unchanged', '');
			return 0;
		}

		// Mise à jour du prix fournisseur dans Dolibarr
		$result = $this->updateSupplierPrice(
			$productId,
			$this->supplierId,
			$powrRef,
			(float) $product['qty_min_to_buy'],
			$newPrice,
			(int) $product['pfp_id']
		);

		if ($result < 0) {
			$this->logSync($productId, $powrRef, $currentPrice, $newPrice, 'error', $this->error);
			return -1;
		}

		$this->logSync($productId, $powrRef, $currentPrice, $newPrice, 'updated', '');
		return 1;
	}

	// ─── Requêtes Dolibarr ─────────────────────────────────────────────────

	/**
	 * Retourne l'ID du fournisseur POwR Connect dans llx_societe
	 */
	private function findSupplierId()
	{
		$nameLike = $this->db->escape($this->supplierName);
		$sql = "SELECT s.rowid FROM ".MAIN_DB_PREFIX."societe s"
			." WHERE s.fournisseur = 1"
			." AND s.nom LIKE '%".$nameLike."%'"
			." AND s.entity IN (".getEntity('societe').")"
			." LIMIT 1";

		$res = $this->db->query($sql);
		if (!$res) {
			$this->error = 'SQL findSupplierId : '.$this->db->lasterror();
			return -1;
		}
		$obj = $this->db->fetch_object($res);
		return $obj ? (int) $obj->rowid : 0;
	}

	/**
	 * Retourne la liste des produits ayant une ref fournisseur POwR Connect
	 * sous forme de tableau : product_id, ref_fourn, unit_price, qty_min_to_buy
	 */
	private function getProductsWithPowrRef()
	{
		$sql = "SELECT pfp.fk_product AS product_id,"
			." pfp.ref_fourn,"
			." pfp.unitprice,"
			." pfp.quantity AS qty_min_to_buy,"
			." pfp.rowid AS pfp_id,"
			." extra.supplier_url"
			." FROM ".MAIN_DB_PREFIX."product_fournisseur_price pfp"
			." INNER JOIN ".MAIN_DB_PREFIX."product p ON p.rowid = pfp.fk_product"
			." LEFT JOIN ".MAIN_DB_PREFIX."product_fournisseur_price_extrafields extra ON extra.fk_object = pfp.rowid"
			." WHERE pfp.fk_soc = ".((int) $this->supplierId)
			." AND pfp.entity IN (".getEntity('product').")"
			." AND pfp.status = 1"
			." AND extra.supplier_url IS NOT NULL"
			." AND TRIM(extra.supplier_url) <> ''"
			." ORDER BY pfp.fk_product ASC";

		$res = $this->db->query($sql);
		if (!$res) {
			$this->error = 'SQL getProductsWithPowrRef : '.$this->db->lasterror();
			return array();
		}

		$list = array();
		while ($obj = $this->db->fetch_object($res)) {
			$list[] = array(
				'product_id'    => (int) $obj->product_id,
				'ref_fourn'     => $obj->ref_fourn,
				'unitprice'     => (float) $obj->unitprice,
				'qty_min_to_buy' => (float) $obj->qty_min_to_buy,
				'pfp_id'        => (int) $obj->pfp_id,
				'supplier_url'  => !empty($obj->supplier_url) ? $obj->supplier_url : '',
			);
		}
		return $list;
	}

	/**
	 * Met à jour le prix fournisseur dans llx_product_fournisseur_price
	 *
	 * @param  int    $productId
	 * @param  int    $supplierId
	 * @param  string $refFourn
	 * @param  float  $qty
	 * @param  float  $price  Prix HT
	 * @param  int    $priceLineId  ID llx_product_fournisseur_price.rowid
	 * @return int  >= 0 succès, < 0 erreur
	 */
	private function updateSupplierPrice($productId, $supplierId, $refFourn, $qty, $price, $priceLineId = 0)
	{
		global $langs;

		require_once DOL_DOCUMENT_ROOT.'/product/class/productfournisseur.class.php';

		$productFournisseur = new ProductFournisseur($this->db);
		$qty = max(1, $qty);
		$configuredVatRaw = getDolGlobalString('POWRSYNC_DEFAULT_VAT_RATE');
		if (trim((string) $configuredVatRaw) === '') {
			if (is_object($langs)) {
				$langs->load('powrsync@powrsync');
				$this->error = $langs->trans('PowrSyncDefaultVatRateRequired');
			} else {
				$this->error = 'POWRSYNC_DEFAULT_VAT_RATE is required';
			}
			return -1;
		}
		$vatTx = (float) price2num($configuredVatRaw);

		// EN: Always initialize object context ids before update to prevent insert with fk_product/fk_soc at 0.
		// FR: Toujours initialiser les IDs de contexte de l'objet avant update pour éviter un insert avec fk_product/fk_soc à 0.
		$productFournisseur->id = (int) $productId;
		$productFournisseur->fk_product = (int) $productId;
		$productFournisseur->fourn_id = (int) $supplierId;
		$productFournisseur->ref_fourn = $refFourn;
		$productFournisseur->fourn_qty = $qty;
		if ((int) $priceLineId > 0) {
			$productFournisseur->product_fourn_price_id = (int) $priceLineId;
		}

		// Chercher si une ligne existe déjà
		$result = $productFournisseur->fetch_product_fournisseur_price(
			$productId,
			$supplierId,
			$refFourn,
			$qty
		);

		if ($result < 0) {
			// Pas de ligne existante — on en crée une
			$productFournisseur->fk_product = $productId;
			$productFournisseur->fourn_id   = $supplierId;
			$productFournisseur->ref_fourn  = $refFourn;
			$productFournisseur->fourn_qty  = $qty;
			$productFournisseur->fourn_price = $price;
			$productFournisseur->fourn_tva_tx = $vatTx;
			$productFournisseur->fourn_price_expression = '';
		}

		// update_buyprice : met à jour le prix dans la table
		$res = $productFournisseur->update_buyprice(
			$qty,
			$price,
			$GLOBALS['user'],
			'HT',
			$supplierId,
			$vatTx,
			$refFourn,
			0, // charges
			0, // discount
			0, // remise_percent
			0  // npr
		);

		if ($res < 0) {
			$this->error = 'update_buyprice échoué pour '.$refFourn.' : '.$productFournisseur->error;
			return -1;
		}

		$forceVatResult = $this->forceSupplierPriceVatRate((int) $priceLineId, (int) $productId, (int) $supplierId, $refFourn, (float) $qty, (float) $vatTx);
		if ($forceVatResult < 0) {
			$this->error = 'VAT update failed for '.$refFourn.' : '.$this->db->lasterror();
			return -1;
		}

		return 1;
	}

	/**
	 * Force VAT rate value on supplier price row after update.
	 *
	 * @param	int		$priceLineId
	 * @param	int		$productId
	 * @param	int		$supplierId
	 * @param	string	$refFourn
	 * @param	float	$qty
	 * @param	float	$vatTx
	 * @return	int
	 */
	private function forceSupplierPriceVatRate($priceLineId, $productId, $supplierId, $refFourn, $qty, $vatTx)
	{
		$priceLineId = (int) $priceLineId;
		if ($priceLineId <= 0) {
			$sqlFind = "SELECT pfp.rowid";
			$sqlFind .= " FROM ".MAIN_DB_PREFIX."product_fournisseur_price AS pfp";
			$sqlFind .= " WHERE pfp.fk_product = ".((int) $productId);
			$sqlFind .= " AND pfp.fk_soc = ".((int) $supplierId);
			$sqlFind .= " AND pfp.ref_fourn = '".$this->db->escape($refFourn)."'";
			$sqlFind .= " AND pfp.quantity = ".price2num($qty);
			$sqlFind .= " ORDER BY pfp.rowid DESC";
			$sqlFind .= " LIMIT 1";

			$resqlFind = $this->db->query($sqlFind);
			if (!$resqlFind) {
				return -1;
			}
			$objFind = $this->db->fetch_object($resqlFind);
			$priceLineId = !empty($objFind) ? (int) $objFind->rowid : 0;
			if ($resqlFind) {
				$this->db->free($resqlFind);
			}
		}

		if ($priceLineId <= 0) {
			return -1;
		}

		$sqlUpdate = "UPDATE ".MAIN_DB_PREFIX."product_fournisseur_price";
		$sqlUpdate .= " SET tva_tx = ".price2num($vatTx);
		$sqlUpdate .= " WHERE rowid = ".$priceLineId;

		return $this->db->query($sqlUpdate) ? 1 : -1;
	}

	// ─── Journal de synchronisation ────────────────────────────────────────

	/**
	 * Enregistre une ligne dans llx_powrsync_log
	 *
	 * @param  int    $productId
	 * @param  string $powrRef
	 * @param  float  $oldPrice
	 * @param  float|null $newPrice
	 * @param  string $status   'updated'|'unchanged'|'error'
	 * @param  string $message
	 */
	private function logSync($productId, $powrRef, $oldPrice, $newPrice, $status, $message)
	{
		$columns = $this->getLogColumns();
		if (empty($columns)) {
			return;
		}

		$fields = array();
		$values = array();
		$statusMap = array(
			'updated' => PowrConnectScraper::LOG_OK,
			'unchanged' => PowrConnectScraper::LOG_UPTODATE,
			'error' => PowrConnectScraper::LOG_ERROR,
		);
		$numericStatus = isset($statusMap[$status]) ? (int) $statusMap[$status] : PowrConnectScraper::LOG_ERROR;

		if (!empty($columns['fk_product'])) {
			$fields[] = 'fk_product';
			$values[] = (int) $productId;
		}
		if (!empty($columns['ref_fourn'])) {
			$fields[] = 'ref_fourn';
			$values[] = "'".$this->db->escape($powrRef)."'";
		}
		if (!empty($columns['old_price'])) {
			$fields[] = 'old_price';
			$values[] = ($oldPrice !== null ? (float) price2num($oldPrice) : 'NULL');
		}
		if (!empty($columns['new_price'])) {
			$fields[] = 'new_price';
			$values[] = ($newPrice !== null ? (float) price2num($newPrice) : 'NULL');
		}
		if (!empty($columns['status'])) {
			$fields[] = 'status';
			$values[] = $numericStatus;
		}
		if (!empty($columns['sync_status'])) {
			$fields[] = 'sync_status';
			$values[] = "'".$this->db->escape($status)."'";
		}
		if (!empty($columns['message'])) {
			$fields[] = 'message';
			$values[] = "'".$this->db->escape($message)."'";
		}
		if (!empty($columns['datec'])) {
			$fields[] = 'datec';
			$values[] = "'".$this->db->idate(dol_now())."'";
		}
		if (!empty($columns['entity'])) {
			$fields[] = 'entity';
			$values[] = (int) $GLOBALS['conf']->entity;
		}

		if (empty($fields)) {
			return;
		}

		$sql = "INSERT INTO ".MAIN_DB_PREFIX."powrsync_log (".implode(', ', $fields).")";
		$sql .= " VALUES (".implode(', ', $values).")";

		$this->db->query($sql);
	}

	/**
	 * Retourne les N dernières lignes du journal
	 *
	 * @param  int $limit
	 * @return array
	 */
	public function getLogs($limit = 100)
	{
		$columns = $this->getLogColumns();
		$statusField = !empty($columns['sync_status']) ? 'l.sync_status' : 'l.status';

		$sql = "SELECT l.rowid, l.fk_product, l.ref_fourn,"
			." l.old_price, l.new_price, ".$statusField." AS sync_status, l.message, l.datec,"
			." p.ref AS product_ref, p.label AS product_label"
			." FROM ".MAIN_DB_PREFIX."powrsync_log l"
			." LEFT JOIN ".MAIN_DB_PREFIX."product p ON p.rowid = l.fk_product"
			." WHERE l.entity IN (".getEntity('product').")"
			." ORDER BY l.datec DESC"
			." LIMIT ".((int) $limit);

		$res = $this->db->query($sql);
		if (!$res) {
			$this->error = $this->db->lasterror();
			return array();
		}

		$rows = array();
		while ($obj = $this->db->fetch_object($res)) {
			$rows[] = $obj;
		}
		return $rows;
	}

	// ─── Utilitaire ────────────────────────────────────────────────────────

	private function addError($msg)
	{
		$this->errors[] = $msg;
		dol_syslog('PowrSync ERROR: '.$msg, LOG_ERR);
	}

	/**
	 * Returns available columns for powrsync_log table.
	 *
	 * @return array
	 */
	private function getLogColumns()
	{
		if (is_array($this->logColumns)) {
			return $this->logColumns;
		}

		$this->logColumns = array();
		$resql = $this->db->query("SHOW COLUMNS FROM ".MAIN_DB_PREFIX."powrsync_log");
		if (!$resql) {
			return $this->logColumns;
		}

		while ($obj = $this->db->fetch_object($resql)) {
			$this->logColumns[$obj->Field] = true;
		}
		$this->db->free($resql);

		return $this->logColumns;
	}
}
