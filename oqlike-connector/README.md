# OQLook Connector (Standalone PHP)

EN: The connector is deployed close to iTop and exposes metamodel endpoints for OQLook.  
FR: Le connecteur se déploie au plus près d'iTop et expose des endpoints métamodèle pour OQLook.

## EN - Overview

Use this connector when:
- iTop REST discovery is too slow or limited.
- You want stable class/attribute discovery from the iTop server side.

Main endpoints:
- `GET /ping`
- `GET /classes?filter=persistent`
- `GET /class/{ClassName}`
- `GET /class/{ClassName}/relations`

## FR - Présentation

Utilisez ce connecteur si:
- la découverte REST iTop est trop lente ou limitée,
- vous voulez une découverte de classes/attributs plus stable côté serveur iTop.

Endpoints principaux:
- `GET /ping`
- `GET /classes?filter=persistent`
- `GET /class/{ClassName}`
- `GET /class/{ClassName}/relations`

## Installation (Generic)

1. Copy the connector directory to the iTop host.
2. Copy `config.sample.php` to `config.php`.
3. Edit `config.php`:
   - `bearer_token`
   - `itop_bootstrap` absolute path to `.../application/startup.inc.php`
   - optional limits (`max_execution_seconds`, memory settings)
4. Expose `public/` via Nginx or Apache.

### Apache example

```apache
Alias /oqlike-connector "/var/www/oqlike-connector/public"
<Directory "/var/www/oqlike-connector/public">
    AllowOverride All
    Require all granted
</Directory>

# If Authorization header is not forwarded:
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

## Security

- Required header: `Authorization: Bearer <token>`
- Invalid/missing token: `401`
- Keep CORS restricted (`cors_allowed_origins`)
- Serve over HTTPS

## Smoke Tests

```bash
curl -k -H "Authorization: Bearer <TOKEN>" "https://<host>/oqlike-connector/ping"
curl -k -H "Authorization: Bearer <TOKEN>" "https://<host>/oqlike-connector/classes?filter=persistent"
curl -k -H "Authorization: Bearer <TOKEN>" "https://<host>/oqlike-connector/classes?filter=persistent&include_hash=0"
```

Expected `/ping`:
- `ok: true`
- `metamodel_available: true`

## OQLook side configuration

Set connector URL and token in OQLook connection wizard or DB config:
- Connector URL: `https://<host>/oqlike-connector`
- Bearer token: same as `config.php`

## Performance Notes

- Connector responses can be large on big CMDBs.
- Tune OQLook limits first:
  - `OQLIKE_MAX_CONNECTOR_CLASSES`
  - `OQLIKE_CONNECTOR_MEMORY_GUARD_RATIO`
  - `OQLIKE_CONNECTOR_MEMORY_HARD_STOP_RATIO`
  - `OQLIKE_DISCOVERY_SCAN_LIMIT`
- Keep low latency between OQLook app server and iTop connector server.

