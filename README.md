# Module PowrSync — Dolibarr

Synchronisation automatique des prix d'achat POwR Connect dans Dolibarr.

## Installation

1. Copier le dossier `powrsync/` dans `htdocs/custom/` de votre Dolibarr
2. Activer le module dans **Configuration → Modules**
3. Configurer le module : **Produits → POwR Connect - Prix → (menu admin)**

## Configuration (admin/setup.php)

| Paramètre | Description |
|---|---|
| Email | Votre email de connexion à powr-connect.shop |
| Mot de passe | Votre mot de passe (stocké chiffré via `dol_encode`) |
| Fournisseur Dolibarr | La fiche fournisseur "POwR Connect" dans Dolibarr |
| Délai entre requêtes | Minimum 500ms (recommandé : 1000-2000ms) |

## Pré-requis côté Dolibarr

Pour que la synchro fonctionne, chaque produit doit avoir :
- **Un prix fournisseur POwR Connect** avec la **référence POwR** renseignée dans `ref_fourn`
  (ex: `OND0791`, `AR0794`, etc.)

Chemin dans Dolibarr : Fiche produit → Onglet "Fournisseurs" → Prix fournisseur → Référence fournisseur

## ⚠️ Calibration obligatoire des sélecteurs HTML

Le fichier `class/powrconnectscraper.class.php` contient des constantes à adapter
**après avoir inspecté le vrai HTML du site POwR Connect** une fois connecté :

```php
// Ligne ~30 dans la classe
const PRICE_XPATH          = '//span[contains(@class,"product-price")]';
const PRODUCT_URL_PATTERN  = '/produit/%s';  // URL de la fiche produit
```

### Procédure de calibration

1. Connectez-vous manuellement sur https://powr-connect.shop
2. Ouvrez une fiche produit (ex: https://powr-connect.shop/produit/OND0791)
3. Clic droit sur le prix → "Inspecter"
4. Notez la classe CSS ou la structure HTML du prix
5. Adapter `PRICE_XPATH` et `PRODUCT_URL_PATTERN` en conséquence

### Exemple si le prix est dans :
```html
<span class="price-ht">123,45 € HT</span>
```
→ Changer en :
```php
const PRICE_XPATH = '//span[contains(@class,"price-ht")]';
```

### Si le site est une SPA (Vue.js / React)
Si le prix est chargé dynamiquement en JavaScript, `cURL` seul ne suffira pas.
Dans ce cas, deux options :
- **Option A** : Intercepter les appels API XHR/Fetch (F12 → Network → XHR)
  et appeler directement l'API JSON (plus propre et robuste)
- **Option B** : Utiliser Puppeteer/Playwright via un script Node.js appelé en `shell_exec()`

## Utilisation

### Manuelle
Produits → POwR Connect - Prix → Bouton "Synchroniser tous les prix"
ou bouton individuel par ligne produit.

### Automatique (cron)
Le module enregistre une tâche cron dans Dolibarr (quotidienne par défaut).
Activer via : Configuration → Tâches planifiées.

## Structure des fichiers

```
powrsync/
├── admin/setup.php                       ← Réglages
├── admin/about.php                       ← À propos
├── core/modules/modPowrSync.class.php    ← Descripteur module
├── class/
│   └── powrconnectscraper.class.php      ← Scraper + logique métier
├── sql/
│   ├── llx_powrsync_log.sql              ← Table historique
│   └── llx_powrsync_log.key.sql          ← Index
├── admin/
│   └── setup.php                         ← Page de configuration
├── sync.php                              ← Page principale (liste + actions)
├── sync_history.php                               ← Historique des synchros
└── langs/
    ├── fr_FR/powrsync.lang
    └── en_US/powrsync.lang
    └── es_ES/powrsync.lang
    └── it_IT/powrsync.lang
    └── de_DE/powrsync.lang
```

## Journalisation

Chaque synchronisation est enregistrée dans `llx_powrsync_log` avec :
- Ancien prix / nouveau prix
- Statut : mis à jour (1), déjà à jour (2), erreur (-1), introuvable (-2)
- Message d'erreur si applicable

Consultable via : Produits → POwR Connect - Prix → Historique
