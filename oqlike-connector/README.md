<p align="center">
  <img src="../docs/assets/oqlook-mark.svg" alt="OQLook" width="72" />
</p>

# OQLook Connector (Standalone PHP)

---

## Table of Contents

- [🇬🇧 English](#-english)
- [🇫🇷 Français](#-français)

---

## 🇬🇧 English

> [!NOTE]
> English section for connector deployment and operations.

### Why This Connector Exists

Use the connector when direct iTop REST discovery is too slow, unstable, or limited for large CMDBs.

Connector value:

- faster metamodel extraction near iTop,
- safer and more predictable payloads,
- reduced latency between iTop internals and discovery logic.

### Endpoints

- `GET /ping`
- `GET /classes?filter=persistent`
- `GET /class/{ClassName}`
- `GET /class/{ClassName}/relations`

### Deployment Pattern (Recommended)

1. Deploy connector on the same network segment as iTop.
2. Protect it with HTTPS.
3. Protect access with bearer token.
4. Configure OQLook to use the connector URL.

### Installation

1. Copy connector folder to iTop host.
2. Copy `config.sample.php` to `config.php`.
3. Edit `config.php`:
   - `bearer_token`
   - `itop_bootstrap` absolute path to `.../application/startup.inc.php`
   - runtime limits (`max_execution_seconds`, memory guard ratios) if needed.
4. Expose `public/` with Nginx or Apache.

#### Apache example

```apache
Alias /oqlike-connector "/var/www/oqlike-connector/public"
<Directory "/var/www/oqlike-connector/public">
    AllowOverride All
    Require all granted
</Directory>

# If Authorization is not forwarded:
SetEnvIf Authorization "(.*)" HTTP_AUTHORIZATION=$1
```

#### Nginx example

```nginx
location /oqlike-connector/ {
    alias /var/www/oqlike-connector/public/;
    index index.php;
    try_files $uri $uri/ /index.php?$query_string;
}

location ~ ^/oqlike-connector/(.+\.php)$ {
    alias /var/www/oqlike-connector/public/$1;
    include fastcgi_params;
    fastcgi_param SCRIPT_FILENAME /var/www/oqlike-connector/public/$1;
    fastcgi_pass unix:/run/php/php-fpm.sock;
}
```

### Security Checklist

- Required header: `Authorization: Bearer <token>`
- Missing/invalid token returns `401`
- Restrict CORS (`cors_allowed_origins`)
- Use HTTPS only in production
- Keep `config.php` out of version control

### Smoke Tests

```bash
curl -k -H "Authorization: Bearer <TOKEN>" "https://<host>/oqlike-connector/ping"
curl -k -H "Authorization: Bearer <TOKEN>" "https://<host>/oqlike-connector/classes?filter=persistent"
curl -k -H "Authorization: Bearer <TOKEN>" "https://<host>/oqlike-connector/classes?filter=persistent&include_hash=0"
```

Expected `/ping`:

- `ok: true`
- `metamodel_available: true`

### Connect OQLook to Connector

In OQLook connection settings:

- Connector URL: `https://<host>/oqlike-connector`
- Bearer token: same value as `config.php`

Then run discovery from UI or CLI:

```bash
php artisan oqlike:discover <connection_id>
```

### Performance Tips

- Large CMDBs can produce heavy payloads.
- Tune OQLook first:
  - `OQLIKE_MAX_CONNECTOR_CLASSES`
  - `OQLIKE_CONNECTOR_MEMORY_GUARD_RATIO`
  - `OQLIKE_CONNECTOR_MEMORY_HARD_STOP_RATIO`
  - `OQLIKE_DISCOVERY_SCAN_LIMIT`

### Common Issues

- `Connection refused`: network path/firewall/reverse proxy issue.
- `401 unauthorized`: token mismatch.
- timeouts: increase runtime and review class cap.
- partial class list: inspect connector logs and memory guard settings.

---

## 🇫🇷 Français

> [!TIP]
> Section française pour le déploiement et l'exploitation du connecteur.

### Pourquoi Ce Connecteur Existe

Utilise le connecteur quand la découverte REST iTop directe est trop lente, instable ou limitée sur de grosses CMDB.

Ce qu'il apporte:

- extraction métamodèle plus rapide au plus près d'iTop,
- payloads plus stables/prévisibles,
- latence réduite entre iTop et la logique de découverte.

### Endpoints

- `GET /ping`
- `GET /classes?filter=persistent`
- `GET /class/{ClassName}`
- `GET /class/{ClassName}/relations`

### Pattern de Déploiement (Recommandé)

1. Déployer le connecteur sur le même segment réseau qu'iTop.
2. Le protéger en HTTPS.
3. Contrôler l'accès via bearer token.
4. Configurer OQLook avec l'URL du connecteur.

### Installation

1. Copier le dossier connecteur sur l'hôte iTop.
2. Copier `config.sample.php` vers `config.php`.
3. Modifier `config.php`:
   - `bearer_token`
   - chemin absolu `itop_bootstrap` vers `.../application/startup.inc.php`
   - limites runtime (`max_execution_seconds`, ratios garde mémoire) si besoin.
4. Exposer `public/` via Nginx ou Apache.

#### Exemple Apache

```apache
Alias /oqlike-connector "/var/www/oqlike-connector/public"
<Directory "/var/www/oqlike-connector/public">
    AllowOverride All
    Require all granted
</Directory>

# Si Authorization n'est pas transmise:
SetEnvIf Authorization "(.*)" HTTP_AUTHORIZATION=$1
```

#### Exemple Nginx

```nginx
location /oqlike-connector/ {
    alias /var/www/oqlike-connector/public/;
    index index.php;
    try_files $uri $uri/ /index.php?$query_string;
}

location ~ ^/oqlike-connector/(.+\.php)$ {
    alias /var/www/oqlike-connector/public/$1;
    include fastcgi_params;
    fastcgi_param SCRIPT_FILENAME /var/www/oqlike-connector/public/$1;
    fastcgi_pass unix:/run/php/php-fpm.sock;
}
```

### Checklist Sécurité

- Header requis: `Authorization: Bearer <token>`
- Token invalide/absent: `401`
- Restreindre CORS (`cors_allowed_origins`)
- HTTPS uniquement en production
- Garder `config.php` hors versioning

### Smoke Tests

```bash
curl -k -H "Authorization: Bearer <TOKEN>" "https://<host>/oqlike-connector/ping"
curl -k -H "Authorization: Bearer <TOKEN>" "https://<host>/oqlike-connector/classes?filter=persistent"
curl -k -H "Authorization: Bearer <TOKEN>" "https://<host>/oqlike-connector/classes?filter=persistent&include_hash=0"
```

Attendu sur `/ping`:

- `ok: true`
- `metamodel_available: true`

### Connecter OQLook Au Connecteur

Dans les paramètres de connexion OQLook:

- URL du connecteur: `https://<host>/oqlike-connector`
- Bearer token: même valeur que dans `config.php`

Puis lancer la découverte (UI ou CLI):

```bash
php artisan oqlike:discover <connection_id>
```

### Conseils Performance

- Les grosses CMDB peuvent générer des payloads lourds.
- Ajuster d'abord OQLook:
  - `OQLIKE_MAX_CONNECTOR_CLASSES`
  - `OQLIKE_CONNECTOR_MEMORY_GUARD_RATIO`
  - `OQLIKE_CONNECTOR_MEMORY_HARD_STOP_RATIO`
  - `OQLIKE_DISCOVERY_SCAN_LIMIT`

### Problèmes Fréquents

- `Connection refused`: souci réseau/firewall/reverse proxy.
- `401 unauthorized`: token incorrect.
- timeouts: augmenter les limites runtime et revoir les caps classes.
- liste classes incomplète: vérifier logs connecteur + garde mémoire.
