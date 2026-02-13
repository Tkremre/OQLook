<p align="center">
  <img src="../docs/assets/oqlook-mark.svg" alt="OQLook" width="72" />
</p>

# OQLook Connector (Standalone PHP)

EN: Deploy this connector close to iTop to expose fast/stable metamodel endpoints for OQLook.

FR: Déployez ce connecteur au plus près d'iTop pour exposer des endpoints métamodèle rapides/stables vers OQLook.

---

## Table of Contents / Sommaire

- [Overview / Présentation](#overview--présentation)
- [Endpoints](#endpoints)
- [Installation](#installation)
- [Security / Sécurité](#security--sécurité)
- [Smoke tests](#smoke-tests)
- [OQLook configuration](#oqlook-configuration)
- [Performance notes](#performance-notes)

## Overview / Présentation

Use this connector when:

- iTop REST discovery is too slow or limited.
- You want class/attribute discovery executed server-side near iTop.

Utilisez ce connecteur si :

- la découverte REST iTop est trop lente ou limitée,
- vous voulez exécuter la découverte classes/attributs côté serveur iTop.

## Endpoints

- `GET /ping`
- `GET /classes?filter=persistent`
- `GET /class/{ClassName}`
- `GET /class/{ClassName}/relations`

## Installation

1. Copy this connector folder to the iTop host.
2. Copy `config.sample.php` to `config.php`.
3. Edit `config.php`:
   - `bearer_token`
   - `itop_bootstrap` absolute path to `.../application/startup.inc.php`
   - optional runtime limits (`max_execution_seconds`, memory guard)
4. Expose `public/` through Nginx or Apache.

### Apache example

```apache
Alias /oqlike-connector "/var/www/oqlike-connector/public"
<Directory "/var/www/oqlike-connector/public">
    AllowOverride All
    Require all granted
</Directory>

# If Authorization is not forwarded:
SetEnvIf Authorization "(.*)" HTTP_AUTHORIZATION=$1
```

### Nginx example

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

## Security / Sécurité

- Required header: `Authorization: Bearer <token>`
- Invalid/missing token returns `401`
- Restrict CORS (`cors_allowed_origins`)
- Use HTTPS in production

## Smoke tests

```bash
curl -k -H "Authorization: Bearer <TOKEN>" "https://<host>/oqlike-connector/ping"
curl -k -H "Authorization: Bearer <TOKEN>" "https://<host>/oqlike-connector/classes?filter=persistent"
curl -k -H "Authorization: Bearer <TOKEN>" "https://<host>/oqlike-connector/classes?filter=persistent&include_hash=0"
```

Expected `/ping`:

- `ok: true`
- `metamodel_available: true`

## OQLook configuration

In OQLook connection settings:

- Connector URL: `https://<host>/oqlike-connector`
- Bearer token: same value as `config.php`

## Performance notes

- Connector payload can be large on big CMDBs.
- Tune OQLook limits first:
  - `OQLIKE_MAX_CONNECTOR_CLASSES`
  - `OQLIKE_CONNECTOR_MEMORY_GUARD_RATIO`
  - `OQLIKE_CONNECTOR_MEMORY_HARD_STOP_RATIO`
  - `OQLIKE_DISCOVERY_SCAN_LIMIT`
- Keep low latency between OQLook app and connector host.
