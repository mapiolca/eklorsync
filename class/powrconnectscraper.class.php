<?php
/* Copyright (C) 2024 Votre Société — Licence GNU GPL v3 */

/**
 * PowrConnectScraper
 *
 * Authentification sur powr-connect.shop + extraction des prix via cURL/DOM.
 * Vérifier les CGU avant usage. Respecter le délai entre requêtes.
 */
class PowrConnectScraper
{
	public const LOG_ERROR = -1;
	public const LOG_OK = 1;
	public const LOG_UPTODATE = 2;

	private $baseUrl      = 'https://powr-connect.shop';
	private $cookieFile   = '';
	private $ch           = null;
	private $loggedIn     = false;
	private $requestDelay = 800000; // µs entre requêtes (0.8 s)
	private $userAgent    = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/120.0.0.0 Safari/537.36';
	private $lastCsrfRaw  = '';
	private $loginState   = 'none';

	public $error = '';

	/**
	 * Émet un message de debug via dol_syslog si disponible, sinon error_log
	 */
	private function debug($msg)
	{
		if (function_exists('dol_syslog')) {
			dol_syslog('[PowrConnectScraper] '.$msg, LOG_DEBUG);
		} else {
			error_log('[PowrConnectScraper] '.$msg);
		}
	}

	public function __construct($tempDir = '/tmp')
	{
		$this->cookieFile = $tempDir.'/powrsync_'.md5(__FILE__).'.txt';
	}

	private function initCurl()
	{
		if ($this->ch) {
			curl_close($this->ch);
		}
		$this->ch = curl_init();
		curl_setopt_array($this->ch, array(
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_FOLLOWLOCATION => true,
			CURLOPT_MAXREDIRS      => 5,
			CURLOPT_COOKIEJAR      => $this->cookieFile,
			CURLOPT_COOKIEFILE     => $this->cookieFile,
			CURLOPT_USERAGENT      => $this->userAgent,
			CURLOPT_TIMEOUT        => 30,
			CURLOPT_SSL_VERIFYPEER => true,
		));
	}

	/**
	 * Vérifie si une session active existe déjà via le cookie jar.
	 * Tente de charger une page et vérifie qu'on n'est pas redirigé vers /connexion.
	 */
	public function isSessionActive()
	{
		$this->debug('isSessionActive() — vérification du cookie jar : '.$this->cookieFile);

		if (!file_exists($this->cookieFile)) {
			$this->debug('Pas de fichier cookie — session inactive');
			return false;
		}

		// Vérifier que le cookie __session existe et n'est pas vide/expiré
		$cookies = file_get_contents($this->cookieFile);
		if (strpos($cookies, '__session') === false) {
			$this->debug('Cookie __session absent du fichier — session inactive');
			return false;
		}
		$this->debug('Cookie __session trouvé, test GET sur le site...');

		$this->initCurl();
		// Désactiver le suivi de redirection pour détecter un 302 vers /connexion
		curl_setopt($this->ch, CURLOPT_FOLLOWLOCATION, false);
		curl_setopt($this->ch, CURLOPT_URL, $this->baseUrl.'/account/profile');
		curl_setopt($this->ch, CURLOPT_HTTPGET, true);

		curl_exec($this->ch);
		$httpCode = curl_getinfo($this->ch, CURLINFO_HTTP_CODE);
		$this->debug('GET /account/profile → HTTP '.$httpCode);

		// Restaurer le suivi de redirection
		curl_setopt($this->ch, CURLOPT_FOLLOWLOCATION, true);

		// 200 = connecté, 302/301 = redirigé vers login
		if ($httpCode === 200) {
			$this->loggedIn = true;
			$this->debug('Session active — pas besoin de se reconnecter');
			return true;
		}

		$this->debug('Session expirée ou invalide (HTTP '.$httpCode.')');
		return false;
	}

	/**
	 * Authentification via l'endpoint Remix de /connexion
	 * Le CSRF est géré par cookie (posé par le GET, renvoyé automatiquement par le POST).
	 * Retourne 1 si succès, -1 si échec
	 */
	public function login($email, $password)
	{
		$this->debug('login() — début, user='.$email);
		$this->loginState = 'none';

		// Vérifie si on est déjà connecté via les cookies existants
		if ($this->isSessionActive()) {
			$this->loginState = 'already_connected';
			return 1;
		}

		$this->initCurl();

		// Étape 1 : GET /connexion pour récupérer le cookie csrf + __session initiale
		$loginUrl = $this->baseUrl.'/connexion';
		$this->debug('GET '.$loginUrl.' (pour cookies csrf + __session)');
		curl_setopt($this->ch, CURLOPT_URL, $loginUrl);
		curl_setopt($this->ch, CURLOPT_HTTPGET, true);
		$html = curl_exec($this->ch);

		if (curl_errno($this->ch)) {
			$this->error = 'Connexion impossible : '.curl_error($this->ch);
			$this->debug('ERREUR cURL GET /connexion : '.$this->error);
			return -1;
		}
		$this->debug('GET /connexion OK, HTML length='.strlen($html).' bytes');

		// Extraire la valeur du cookie csrf depuis le cookie jar
		// Le CSRF utilise un double-submit pattern : la valeur doit être dans le cookie ET dans le body
		$csrfValue = $this->extractCsrfFromCookieJar();
		if (file_exists($this->cookieFile)) {
			$cookieContent = file_get_contents($this->cookieFile);
			$hasCsrf = strpos($cookieContent, 'csrf') !== false ? 'oui' : 'non';
			$hasSession = strpos($cookieContent, '__session') !== false ? 'oui' : 'non';
			$this->debug('Cookies après GET : csrf='.$hasCsrf.', __session='.$hasSession);
		}
		$this->debug('CSRF cookie value : '.($csrfValue ? substr($csrfValue, 0, 30).'…' : '(non trouvé)'));

		// EN: Step 2, discover login form action + hidden fields to avoid hardcoded Remix route.
		// FR: Étape 2, détecter l'action du formulaire + champs cachés pour éviter une route Remix figée.
		$formConfig = $this->extractLoginFormConfig($html);
		$formNotFound = empty($formConfig['action']) && empty($formConfig['hidden']);
		if ($formNotFound) {
			// EN: If no login form is present, session may already be authenticated.
			// FR: Si le formulaire de connexion est absent, la session peut déjà être authentifiée.
			$this->debug('No login form detected on /connexion, re-check active session before POST login');
			if ($this->isSessionActive()) {
				$this->debug('Active session confirmed after /connexion GET, skip login POST');
				$this->loginState = 'already_connected';
				return 1;
			}
			// EN: If login form is absent, do not force a POST login (can trigger HTTP 500 upstream).
			// FR: Si le formulaire est absent, ne pas forcer un POST login (peut déclencher un HTTP 500 côté fournisseur).
			// EN: Continue flow and let product URL access confirm whether session is usable.
			// FR: Continuer le flux et laisser l'accès URL produit confirmer si la session est exploitable.
			$this->loggedIn = true;
			$this->loginState = 'already_connected';
			$this->debug('No login form and inactive /account/profile check: skip login POST, continue with product URL test');
			return 1;
		}

		$postUrl = $formConfig['action'];
		if (empty($postUrl)) {
			$postUrl = $this->baseUrl.'/connexion';
		}

		$postFields = $formConfig['hidden'];
		if (!is_array($postFields)) {
			$postFields = array();
		}

		// EN: Always enforce required credentials fields.
		// FR: Toujours forcer les champs d'identifiants requis.
		$postFields['username'] = $email;
		$postFields['password'] = $password;
		if (!isset($postFields['stayConnected'])) {
			$postFields['stayConnected'] = 'on';
		}
		if (!isset($postFields['_action']) || $postFields['_action'] === '') {
			$postFields['_action'] = 'login';
		}

		// EN: If csrf is absent from form, fallback to cookie-derived value.
		// FR: Si csrf est absent du formulaire, fallback via la valeur issue du cookie.
		if (empty($postFields['csrf']) && !empty($csrfValue)) {
			$postFields['csrf'] = $csrfValue;
		}

		usleep($this->requestDelay);
		$this->debug('POST '.$postUrl.' avec username='.$email);

		// Ne pas suivre les redirections pour capturer la réponse 204
		curl_setopt($this->ch, CURLOPT_FOLLOWLOCATION, false);
		curl_setopt($this->ch, CURLOPT_HEADER, true);
		curl_setopt($this->ch, CURLOPT_HTTPHEADER, array(
			'Content-Type: application/x-www-form-urlencoded',
			'Origin: '.$this->baseUrl,
			'Referer: '.$loginUrl,
			'X-Requested-With: XMLHttpRequest',
		));
		curl_setopt_array($this->ch, array(
			CURLOPT_URL        => $postUrl,
			CURLOPT_POST       => true,
			CURLOPT_POSTFIELDS => http_build_query($postFields),
		));

		$response = curl_exec($this->ch);
		$httpCode = curl_getinfo($this->ch, CURLINFO_HTTP_CODE);
		$headerSize = curl_getinfo($this->ch, CURLINFO_HEADER_SIZE);
		$headers = substr($response, 0, $headerSize);

		$this->debug('POST login → HTTP '.$httpCode);
		$this->debug('Headers réponse (premières lignes) : '.substr(str_replace("\r\n", ' | ', $headers), 0, 500));

		// EN: Retry once with raw csrf cookie value when server returns HTTP 500.
		// FR: Refaire 1 tentative avec la valeur brute du cookie csrf si le serveur renvoie HTTP 500.
		if ($httpCode >= 500 && !empty($this->lastCsrfRaw) && (!isset($postFields['csrf']) || $postFields['csrf'] !== $this->lastCsrfRaw)) {
			$this->debug('Retry login POST with raw csrf cookie value');
			$postFields['csrf'] = $this->lastCsrfRaw;
			usleep($this->requestDelay);
			curl_setopt($this->ch, CURLOPT_POSTFIELDS, http_build_query($postFields));
			$response = curl_exec($this->ch);
			$httpCode = curl_getinfo($this->ch, CURLINFO_HTTP_CODE);
			$headerSize = curl_getinfo($this->ch, CURLINFO_HEADER_SIZE);
			$headers = substr($response, 0, $headerSize);
			$this->debug('POST login retry (csrf raw) → HTTP '.$httpCode);
			$this->debug('Headers retry (premières lignes) : '.substr(str_replace("\r\n", ' | ', $headers), 0, 500));
		}

		// Restaurer les options par défaut
		curl_setopt($this->ch, CURLOPT_FOLLOWLOCATION, true);
		curl_setopt($this->ch, CURLOPT_HEADER, false);
		curl_setopt($this->ch, CURLOPT_HTTPHEADER, array());

		if (curl_errno($this->ch)) {
			$this->error = 'Erreur cURL login : '.curl_error($this->ch);
			$this->debug('ERREUR cURL POST : '.$this->error);
			return -1;
		}

		// Remix renvoie 204 No Content avec x-remix-redirect en cas de succès
		if ($httpCode === 204 || ($httpCode >= 200 && $httpCode < 400)) {
			// Vérifier que le cookie __session a été mis à jour avec des données réelles
			if (file_exists($this->cookieFile)) {
				$cookieContent = file_get_contents($this->cookieFile);
				$hasAuth = strpos($cookieContent, 'auth_token_sso') !== false;
				$this->debug('Cookie auth_token_sso après login : '.($hasAuth ? 'oui' : 'non'));
				if ($hasAuth || strpos($headers, 'x-remix-redirect') !== false) {
					$this->loggedIn = true;
					$this->loginState = 'login_ok';
					$this->debug('Login réussi — session active');
					return 1;
				}
			}
		}

		// Échec
		$this->loginState = 'login_failed';
		$this->error = 'Échec login HTTP '.$httpCode.' — vérifier les identifiants';
		$this->debug('Échec login : HTTP '.$httpCode);
		return -1;
	}

	/**
	 * EN: Return latest login state (none|already_connected|login_ok|login_failed).
	 * FR: Retourne l'état du dernier login (none|already_connected|login_ok|login_failed).
	 *
	 * @return string
	 */
	public function getLoginState()
	{
		return $this->loginState;
	}

	/**
	 * Test supplier connection and read one product price.
	 *
	 * @param	string	$login
	 * @param	string	$password
	 * @param	string	$url
	 * @param	string	$powrRef
	 * @return	float|false
	 */
	public function testConnectionAndGetPrice($login, $password, $url, $powrRef = 'TEST')
	{
		$ret = $this->login($login, $password);
		if ($ret < 0) {
			return false;
		}

		return $this->getPrice($powrRef, $url);
	}

	/**
	 * EN: Extract login endpoint and hidden fields from the /connexion HTML form.
	 * FR: Extrait l'endpoint de login et les champs cachés du formulaire HTML /connexion.
	 *
	 * @param	string $html Login page HTML
	 * @return	array{action:string,hidden:array<string,string>}
	 */
	private function extractLoginFormConfig($html)
	{
		$result = array(
			'action' => '',
			'hidden' => array(),
		);

		if (empty($html)) {
			return $result;
		}

		libxml_use_internal_errors(true);
		$dom = new DOMDocument();
		$loaded = $dom->loadHTML($html, LIBXML_NOWARNING | LIBXML_NOERROR);
		libxml_clear_errors();
		if (!$loaded) {
			$this->debug('extractLoginFormConfig : DOM load failed, fallback to hardcoded route');
			return $result;
		}

		$xpath = new DOMXPath($dom);
		$forms = $xpath->query('//form[.//input[@name="username"] and .//input[@name="password"]]');
		if (!$forms || !$forms->length) {
			$this->debug('extractLoginFormConfig : login form not found, fallback to hardcoded route');
			return $result;
		}

		$form = $forms->item(0);
		$action = trim((string) $form->getAttribute('action'));
		if (!empty($action)) {
			if (strpos($action, 'http://') === 0 || strpos($action, 'https://') === 0) {
				$result['action'] = $action;
			} elseif (strpos($action, '/') === 0) {
				$result['action'] = $this->baseUrl.$action;
			} else {
				$result['action'] = $this->baseUrl.'/'.$action;
			}
		}

		foreach ($xpath->query('.//input[@type="hidden"]', $form) as $hiddenInput) {
			$name = (string) $hiddenInput->getAttribute('name');
			if ($name === '') {
				continue;
			}
			$result['hidden'][$name] = (string) $hiddenInput->getAttribute('value');
		}

		$this->debug('extractLoginFormConfig : action='.(empty($result['action']) ? '(vide)' : $result['action']).', hidden='.count($result['hidden']));
		return $result;
	}

	/**
	 * Récupère le prix HT d'un produit via son URL directe sur le site fournisseur
	 *
	 * @param  string $powrRef  Référence fournisseur (pour les messages d'erreur)
	 * @param  string $url      URL complète de la fiche produit sur le site fournisseur
	 * @return float|false      Prix HT ou false si non trouvé
	 */
	public function getPrice($powrRef, $url = '')
	{
		$this->debug('getPrice() — ref='.$powrRef.', url='.$url);

		if (!$this->loggedIn) {
			$this->error = 'Non authentifié';
			$this->debug('ERREUR : non authentifié');
			return false;
		}

		if (empty($url)) {
			$this->error = 'URL produit non renseignée pour '.$powrRef;
			$this->debug('ERREUR : URL manquante');
			return false;
		}

		usleep($this->requestDelay);

		$this->debug('GET '.$url);
		curl_setopt_array($this->ch, array(
			CURLOPT_URL     => $url,
			CURLOPT_HTTPGET => true,
		));

		$html     = curl_exec($this->ch);
		$httpCode = curl_getinfo($this->ch, CURLINFO_HTTP_CODE);
		$finalUrl = curl_getinfo($this->ch, CURLINFO_EFFECTIVE_URL);
		$this->debug('GET produit → HTTP '.$httpCode.' — URL finale : '.$finalUrl.' — HTML length='.strlen($html).' bytes');

		if (curl_errno($this->ch)) {
			$this->error = 'cURL erreur ref '.$powrRef.' : '.curl_error($this->ch);
			$this->debug('ERREUR cURL : '.$this->error);
			return false;
		}
		if ($httpCode === 404) {
			$this->error = 'Référence '.$powrRef.' introuvable (404)';
			$this->debug('ERREUR 404 pour '.$powrRef);
			return false;
		}
		if ($httpCode === 302 || strpos($finalUrl, '/connexion') !== false) {
			$this->error = 'Session expirée ou non connecté — redirigé vers '.$finalUrl;
			$this->debug('ERREUR : redirection vers /connexion, session perdue');
			return false;
		}

		return $this->parsePrice($html, $powrRef);
	}

	/**
	 * Extrait la valeur du cookie csrf depuis le fichier cookie jar de cURL.
	 * Le cookie est au format Netscape : domaine \t ... \t nom \t valeur
	 */
	private function extractCsrfFromCookieJar()
	{
		$this->lastCsrfRaw = '';

		if (!file_exists($this->cookieFile)) {
			$this->debug('extractCsrfFromCookieJar : fichier cookie absent');
			return '';
		}

		$lines = file($this->cookieFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
		$cookieNames = array();
		foreach ($lines as $line) {
			// Les cookies HttpOnly sont préfixés par "#HttpOnly_" — il faut les traiter
			if (strpos($line, '#HttpOnly_') === 0) {
				$line = substr($line, 10); // Retirer le préfixe "#HttpOnly_"
			} elseif (isset($line[0]) && $line[0] === '#') {
				continue; // Ligne de commentaire
			}
			$parts = explode("\t", $line);
			// Format Netscape : domain, flag, path, secure, expiry, name, value
			if (count($parts) >= 7) {
				$cookieNames[] = $parts[5];
				if ($parts[5] === 'csrf') {
					$rawValue = urldecode($parts[6]);
					$this->lastCsrfRaw = $rawValue;
					// Le cookie Remix est signé : base64(json_token).signature
					// Il faut décoder pour extraire le token brut
					$token = $this->decodeRemixSignedCookie($rawValue);
					$this->debug('extractCsrfFromCookieJar : trouvé, raw='.strlen($rawValue).' bytes, token décodé='.strlen($token).' bytes');
					return $token;
				}
			}
		}

		$this->debug('extractCsrfFromCookieJar : cookie csrf non trouvé. Cookies présents : '.implode(', ', $cookieNames));
		return '';
	}

	/**
	 * Décode un cookie signé Remix (format : base64(json_value).hmac_signature)
	 * Retourne la valeur brute du token (sans guillemets JSON, sans signature)
	 */
	private function decodeRemixSignedCookie($signedValue)
	{
		// Format : base64("token_value").43_chars_signature
		// Le dernier "." sépare la valeur base64 de la signature HMAC (43 chars base64url)
		$lastDot = strrpos($signedValue, '.');
		if ($lastDot === false) {
			$this->debug('decodeRemixSignedCookie : pas de "." — valeur brute utilisée');
			return $signedValue;
		}

		$base64Part = substr($signedValue, 0, $lastDot);
		$decoded = base64_decode($base64Part);

		if ($decoded === false) {
			$this->debug('decodeRemixSignedCookie : base64_decode échoué — valeur brute utilisée');
			return $signedValue;
		}

		// La valeur décodée est un JSON string entre guillemets : "token_value"
		$token = json_decode($decoded);
		if (is_string($token)) {
			$this->debug('decodeRemixSignedCookie : token JSON décodé OK, longueur='.strlen($token));
			return $token;
		}

		// Fallback : retourner la valeur décodée telle quelle
		$this->debug('decodeRemixSignedCookie : json_decode non-string, utilise valeur brute décodée');
		return $decoded;
	}

	/**
	 * Parse le prix HT dans le HTML de la fiche produit POwR Connect
	 *
	 * Le prix se trouve dans un bloc avec la classe "font-semibold leading-none"
	 * à l'intérieur d'un conteneur "bg-secondary-100" (promo) ou "bg-grey-50" (normal).
	 * Format typique : <p class="text-[15px] font-semibold leading-none">661,38&nbsp;€</p>
	 */
	private function parsePrice($html, $powrRef)
	{
		$this->debug('parsePrice() — ref='.$powrRef.', HTML length='.strlen($html).' bytes');

		// Stratégie 1 : regex directe sur le pattern de prix affiché
		// Cherche le prix dans le bloc "À l'unité" (premier prix font-semibold leading-none suivi de €)
		$this->debug('Stratégie 1 : regex font-semibold leading-none + €');
		if (preg_match_all('/font-semibold leading-none["\'][^>]*>([0-9\s\xc2\xa0.,]+)\s*(?:&nbsp;)?€/u', $html, $matches)) {
			$this->debug('Regex S1 : '.count($matches[1]).' candidat(s) trouvé(s) : '.implode(' | ', $matches[1]));
			foreach ($matches[1] as $rawPrice) {
				$price = $this->cleanPrice($rawPrice.'€');
				$this->debug('  → cleanPrice("'.trim($rawPrice).'") = '.var_export($price, true));
				if ($price !== false && $price > 0) {
					$this->debug('Stratégie 1 réussie : prix='.$price);
					return $price;
				}
			}
		} else {
			$this->debug('Stratégie 1 : aucun match (classe CSS absente ou HTML différent)');
		}

		// Stratégie 2 : DOM/XPath fallback
		$this->debug('Stratégie 2 : DOM/XPath sur font-semibold + leading-none');
		libxml_use_internal_errors(true);
		$dom = new DOMDocument();
		$dom->loadHTML($html, LIBXML_NOWARNING | LIBXML_NOERROR);
		libxml_clear_errors();

		$xpath = new DOMXPath($dom);

		// Cherche les éléments avec font-semibold et leading-none qui contiennent un prix
		$priceNodes = $xpath->query('//*[contains(@class,"font-semibold") and contains(@class,"leading-none")]');
		$nodeCount = $priceNodes ? $priceNodes->length : 0;
		$this->debug('XPath S2 : '.$nodeCount.' nœud(s) trouvé(s)');
		if ($priceNodes) {
			foreach ($priceNodes as $node) {
				$text = trim($node->textContent);
				$this->debug('  nœud texte="'.$text.'"');
				if (preg_match('/[0-9]/', $text) && mb_strpos($text, '€') !== false) {
					$price = $this->cleanPrice($text);
					$this->debug('  → cleanPrice = '.var_export($price, true));
					if ($price !== false && $price > 0) {
						$this->debug('Stratégie 2 réussie : prix='.$price);
						return $price;
					}
				}
			}
		}

		// Stratégie 3 : JSON-LD schema.org (si le prix y est ajouté un jour)
		$this->debug('Stratégie 3 : JSON-LD schema.org');
		foreach ($xpath->query('//script[@type="application/ld+json"]') as $script) {
			$json = json_decode(trim($script->textContent), true);
			$this->debug('  JSON-LD type='.($json['@type'] ?? '?').' — offers.price='.($json['offers']['price'] ?? 'absent'));
			if (!empty($json['offers']['price'])) {
				$price = (float) $json['offers']['price'];
				$this->debug('Stratégie 3 réussie : prix='.$price);
				return $price;
			}
		}

		$this->error = 'Prix non trouvé pour '.$powrRef.' — vérifier la structure HTML de la page';
		$this->debug('ERREUR : '.$this->error);
		return false;
	}

	/**
	 * Nettoie une chaîne prix (ex: "1 234,56 € HT" → 1234.56)
	 */
	private function cleanPrice($raw)
	{
		$v = preg_replace('/[€\s]|HT|TTC/ui', '', $raw);
		$v = str_replace(array("\xc2\xa0", ' '), '', $v); // espace insécable
		$v = str_replace(',', '.', $v);
		$v = preg_replace('/[^0-9.]/', '', $v);
		return is_numeric($v) ? (float) $v : false;
	}

	public function close()
	{
		if ($this->ch) {
			curl_close($this->ch);
			$this->ch = null;
		}
		$this->loggedIn = false;
	}

	public function __destruct()
	{
		$this->close();
	}
}
