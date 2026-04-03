<?php
/* Copyright (C) 2024 Votre Société — Licence GNU GPL v3 */

/**
 * EklorScraper
 *
 * Authentification sur EKLOR.shop + extraction des prix via cURL/DOM.
 * Vérifier les CGU avant usage. Respecter le délai entre requêtes.
 */
class EklorScraper
{
	public const LOG_ERROR = -1;
	public const LOG_OK = 1;
	public const LOG_UPTODATE = 2;

	private $baseUrl      = 'https://EKLOR.shop';
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
			dol_syslog('[EklorScraper] '.$msg, LOG_DEBUG);
		} else {
			error_log('[EklorScraper] '.$msg);
		}
	}

	public function __construct($tempDir = '/tmp')
	{
		$this->cookieFile = $tempDir.'/eklorsync_'.md5(__FILE__).'.txt';
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
	 * Tente de charger la page profil WooCommerce et vérifie qu'on n'est pas redirigé vers la page login.
	 */
	public function isSessionActive()
	{
		$this->debug('isSessionActive() — vérification du cookie jar : '.$this->cookieFile);

		if (!file_exists($this->cookieFile)) {
			$this->debug('Pas de fichier cookie — session inactive');
			return false;
		}

		// Vérifier qu'un cookie WordPress de session existe
		$cookies = file_get_contents($this->cookieFile);
		if (strpos($cookies, 'wordpress_logged_in_') === false) {
			$this->debug('Cookie wordpress_logged_in_ absent du fichier — session inactive');
			return false;
		}
		$this->debug('Cookie wordpress_logged_in_ trouvé, test GET sur le site...');

		$this->initCurl();
		// Désactiver le suivi de redirection pour détecter un 302 vers la page login
		curl_setopt($this->ch, CURLOPT_FOLLOWLOCATION, false);
		curl_setopt($this->ch, CURLOPT_URL, $this->baseUrl.'/mon-compte/profil/');
		curl_setopt($this->ch, CURLOPT_HTTPGET, true);

		curl_exec($this->ch);
		$httpCode = curl_getinfo($this->ch, CURLINFO_HTTP_CODE);
		$this->debug('GET /mon-compte/profil/ → HTTP '.$httpCode);

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
	 * Authentification WordPress via /wp-login.php
	 * Contourne le reCAPTCHA iThemes Security présent sur le formulaire WooCommerce /mon-compte/.
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

		// Étape 1 : GET /wp-login.php pour poser le cookie wordpress_test_cookie
		$wpLoginUrl = $this->baseUrl.'/wp-login.php';
		$this->debug('GET '.$wpLoginUrl.' (pour poser le test cookie WordPress)');
		curl_setopt($this->ch, CURLOPT_URL, $wpLoginUrl);
		curl_setopt($this->ch, CURLOPT_HTTPGET, true);
		curl_exec($this->ch);

		if (curl_errno($this->ch)) {
			$this->error = 'Connexion impossible : '.curl_error($this->ch);
			$this->debug('ERREUR cURL GET /wp-login.php : '.$this->error);
			return -1;
		}
		$this->debug('GET /wp-login.php OK');

		// Étape 2 : POST /wp-login.php avec les champs natifs WordPress
		// (pas de reCAPTCHA sur cet endpoint contrairement au formulaire WooCommerce)
		usleep($this->requestDelay);

		$postFields = array(
			'log'         => $email,
			'pwd'         => $password,
			'wp-submit'   => 'Se connecter',
			'redirect_to' => $this->baseUrl.'/mon-compte/',
			'testcookie'  => '1',
			'rememberme'  => 'forever',
		);

		$this->debug('POST '.$wpLoginUrl.' avec log='.$email);

		curl_setopt($this->ch, CURLOPT_FOLLOWLOCATION, false);
		curl_setopt($this->ch, CURLOPT_HEADER, true);
		curl_setopt($this->ch, CURLOPT_HTTPHEADER, array(
			'Content-Type: application/x-www-form-urlencoded',
			'Origin: '.$this->baseUrl,
			'Referer: '.$wpLoginUrl,
		));
		curl_setopt_array($this->ch, array(
			CURLOPT_URL        => $wpLoginUrl,
			CURLOPT_POST       => true,
			CURLOPT_POSTFIELDS => http_build_query($postFields),
		));

		$response = curl_exec($this->ch);
		$httpCode = curl_getinfo($this->ch, CURLINFO_HTTP_CODE);
		$headerSize = curl_getinfo($this->ch, CURLINFO_HEADER_SIZE);
		$headers = substr($response, 0, $headerSize);

		$this->debug('POST login → HTTP '.$httpCode);
		$this->debug('Headers réponse (premières lignes) : '.substr(str_replace("\r\n", ' | ', $headers), 0, 500));

		// Restaurer les options par défaut
		curl_setopt($this->ch, CURLOPT_FOLLOWLOCATION, true);
		curl_setopt($this->ch, CURLOPT_HEADER, false);
		curl_setopt($this->ch, CURLOPT_HTTPHEADER, array());

		if (curl_errno($this->ch)) {
			$this->error = 'Erreur cURL login : '.curl_error($this->ch);
			$this->debug('ERREUR cURL POST : '.$this->error);
			return -1;
		}

		// WordPress renvoie 302 vers redirect_to en cas de succès
		// Vérifier la présence du cookie wordpress_logged_in_*
		if ($httpCode === 302 || ($httpCode >= 200 && $httpCode < 400)) {
			if (file_exists($this->cookieFile)) {
				$cookieContent = file_get_contents($this->cookieFile);
				$hasAuth = strpos($cookieContent, 'wordpress_logged_in_') !== false;
				$this->debug('Cookie wordpress_logged_in_ après login : '.($hasAuth ? 'oui' : 'non'));
				if ($hasAuth) {
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
	 * @param	string	$eklorRef
	 * @return	float|false
	 */
	public function testConnectionAndGetPrice($login, $password, $url, $eklorRef = 'TEST')
	{
		$ret = $this->login($login, $password);
		if ($ret < 0) {
			return false;
		}

		return $this->getPrice($eklorRef, $url);
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
	 * @param  string $eklorRef  Référence fournisseur (pour les messages d'erreur)
	 * @param  string $url      URL complète de la fiche produit sur le site fournisseur
	 * @return float|false      Prix HT ou false si non trouvé
	 */
	public function getPrice($eklorRef, $url = '')
	{
		$this->debug('getPrice() — ref='.$eklorRef.', url='.$url);

		if (!$this->loggedIn) {
			$this->error = 'Non authentifié';
			$this->debug('ERREUR : non authentifié');
			return false;
		}

		if (empty($url)) {
			$this->error = 'URL produit non renseignée pour '.$eklorRef;
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
			$this->error = 'cURL erreur ref '.$eklorRef.' : '.curl_error($this->ch);
			$this->debug('ERREUR cURL : '.$this->error);
			return false;
		}
		if ($httpCode === 404) {
			$this->error = 'Référence '.$eklorRef.' introuvable (404)';
			$this->debug('ERREUR 404 pour '.$eklorRef);
			return false;
		}
		if ($httpCode === 302 || strpos($finalUrl, '/mon-compte/profil') !== false) {
			$this->error = 'Session expirée ou non connecté — redirigé vers '.$finalUrl;
			$this->debug('ERREUR : redirection vers la page de connexion, session perdue');
			return false;
		}

		return $this->parsePrice($html, $eklorRef);
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
	 * Parse le prix HT dans le HTML de la fiche produit EKLOR
	 *
	 * Nouvelle structure (WooCommerce) :
	 * <span class="woocommerce-Price-amount amount"><bdi>3 651,89&nbsp;<span class="woocommerce-Price-currencySymbol">€</span></bdi></span>
	 * Le prix est aussi disponible dans la meta og : <meta property="product:price:amount" content="3651.894925">
	 * Et dans le JSON-LD @graph : offers[].priceSpecification[].price
	 */
	private function parsePrice($html, $eklorRef)
	{
		$this->debug('parsePrice() — ref='.$eklorRef.', HTML length='.strlen($html).' bytes');

		// Stratégie 1 : meta tag product:price:amount (source la plus fiable)
		$this->debug('Stratégie 1 : meta property="product:price:amount"');
		if (preg_match('/<meta[^>]+property=["\']product:price:amount["\'][^>]+content=["\']([0-9.]+)["\']/', $html, $m)) {
			$price = (float) $m[1];
			$this->debug('Regex S1 : valeur brute="'.$m[1].'"');
			if ($price > 0) {
				$this->debug('Stratégie 1 réussie : prix='.$price);
				return $price;
			}
		} else {
			$this->debug('Stratégie 1 : aucun match');
		}

		// Stratégie 2 : DOM/XPath sur la section summary WooCommerce
		$this->debug('Stratégie 2 : DOM/XPath sur woocommerce-Price-amount dans .entry-summary');
		libxml_use_internal_errors(true);
		$dom = new DOMDocument();
		$dom->loadHTML($html, LIBXML_NOWARNING | LIBXML_NOERROR);
		libxml_clear_errors();

		$xpath = new DOMXPath($dom);

		// Cherche le prix dans la section résumé produit (évite les prix des upsells)
		$priceNodes = $xpath->query(
			'//*[contains(@class,"entry-summary")]' .
			'//*[contains(@class,"woocommerce-Price-amount")]//bdi' .
			'|' .
			'//*[contains(@class,"entry-summary")]' .
			'//*[contains(@class,"woocommerce-Price-amount")]'
		);
		$nodeCount = $priceNodes ? $priceNodes->length : 0;
		$this->debug('XPath S2 : '.$nodeCount.' nœud(s) trouvé(s)');
		if ($priceNodes) {
			foreach ($priceNodes as $node) {
				$text = trim($node->textContent);
				$this->debug('  nœud texte="'.$text.'"');
				if (preg_match('/[0-9]/', $text)) {
					$price = $this->cleanPrice($text.'€');
					$this->debug('  → cleanPrice = '.var_export($price, true));
					if ($price !== false && $price > 0) {
						$this->debug('Stratégie 2 réussie : prix='.$price);
						return $price;
					}
				}
			}
		}

		// Stratégie 3 : JSON-LD schema.org (format @graph ou direct)
		$this->debug('Stratégie 3 : JSON-LD schema.org');
		foreach ($xpath->query('//script[@type="application/ld+json"]') as $script) {
			$json = json_decode(trim($script->textContent), true);
			if (!is_array($json)) {
				continue;
			}
			// Format nouveau : {"@graph": [..., {"@type":"Product", "offers":[...]}]}
			$nodes = !empty($json['@graph']) ? $json['@graph'] : [$json];
			foreach ($nodes as $node) {
				if (($node['@type'] ?? '') !== 'Product') {
					continue;
				}
				$this->debug('  JSON-LD Product trouvé');
				$offers = $node['offers'] ?? [];
				// offers peut être un tableau ou un objet
				if (isset($offers['@type'])) {
					$offers = [$offers];
				}
				foreach ($offers as $offer) {
					// Format nouveau : priceSpecification[0].price
					if (!empty($offer['priceSpecification'])) {
						foreach ((array) $offer['priceSpecification'] as $spec) {
							if (!empty($spec['price'])) {
								$price = (float) $spec['price'];
								if ($price > 0) {
									$this->debug('Stratégie 3 réussie (priceSpecification) : prix='.$price);
									return $price;
								}
							}
						}
					}
					// Format ancien : offers.price
					if (!empty($offer['price'])) {
						$price = (float) $offer['price'];
						if ($price > 0) {
							$this->debug('Stratégie 3 réussie (offers.price) : prix='.$price);
							return $price;
						}
					}
				}
			}
		}

		$this->error = 'Prix non trouvé pour '.$eklorRef.' — vérifier la structure HTML de la page';
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
