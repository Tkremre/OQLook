<p align="center">
  <img src="../../docs/assets/oqlook-mark.svg" alt="OQLook" width="64" />
</p>

# OQLook Install Scripts

---

## Table of Contents

- [🇬🇧 English](#-english)
- [🇫🇷 Français](#-français)

---

## 🇬🇧 English

> [!NOTE]
> English section for install and hardening automation.

These scripts are designed to speed up setup and reduce manual errors on Linux and Windows.

### Which Script Should I Use?

- You want a quick install: use `bootstrap`.
- You prepare production: apply `hardening` after bootstrap.
- You need to rollback hardening changes: use rollback scripts.

### Linux

- Bootstrap: `scripts/install/linux/bootstrap.sh`
- Hardening: `scripts/install/linux/hardening.sh`
- Hardening rollback: `scripts/install/linux/hardening-rollback.sh`

Quick example:

```bash
chmod +x scripts/install/linux/bootstrap.sh
./scripts/install/linux/bootstrap.sh --install-deps
```

Useful flags:

- `--skip-build`
- `--skip-migrate`
- `--app-dir /opt/oqlook`
- `--node-major 22`
- `--web-user www-data`

Hardening example:

```bash
sudo ./scripts/install/linux/hardening.sh --app-dir /opt/oqlook --server-name oqlook.example.com --web nginx
sudo ./scripts/install/linux/hardening-rollback.sh --web auto
```

### Windows

- Bootstrap: `scripts/install/windows/bootstrap.ps1`
- Hardening: `scripts/install/windows/production-hardening.ps1`
- Worker loop: `scripts/install/windows/queue-worker-loop.ps1`
- Hardening rollback: `scripts/install/windows/production-hardening-remove.ps1`

Quick example:

```powershell
powershell -ExecutionPolicy Bypass -File .\scripts\install\windows\bootstrap.ps1 -InstallDeps -InstallPostgres
```

Useful flags:

- `-SkipBuild`
- `-SkipMigrate`
- `-AppDir C:\inetpub\wwwroot\OQLook`
- `-PhpBin C:\php\php.exe`
- `-ComposerBin composer`
- `-NpmBin npm`

Notes:

- `-InstallDeps` uses `winget`.
- Redis on Windows uses Memurai (Redis-compatible) with `-InstallRedis`.

Hardening example:

```powershell
powershell -ExecutionPolicy Bypass -File .\scripts\install\windows\production-hardening.ps1 -AppDir C:\inetpub\wwwroot\OQLook -PhpBin C:\php\php.exe
powershell -ExecutionPolicy Bypass -File .\scripts\install\windows\production-hardening-remove.ps1 -TaskPrefix OQLook
```

### Typical Scenarios

#### Local development

- run bootstrap,
- keep debug enabled,
- run `npm run dev` for frontend hot reload.

#### Pre-production / staging

- run bootstrap,
- run migrations,
- run `npm run build`,
- run queue worker and test a full scan.

#### Production

- bootstrap,
- hardening,
- `APP_DEBUG=false`,
- queue worker as service/scheduled task,
- backup database + logs retention.

### Post-install Checklist

- `php artisan about` works
- DB connection OK
- queue worker started (if enabled)
- metamodel discovery works
- full scan completes
- PDF export opens correctly

### Troubleshooting

- bootstrap fails on dependencies: install tools manually then rerun with `--skip-*` or `-Skip*` options.
- permissions issue on Linux: check web user/group ownership.
- scheduled worker not running on Windows: verify task registration and execution policy.

---

## 🇫🇷 Français

> [!TIP]
> Section française pour l’automatisation d’installation et de durcissement.

Ces scripts sont faits pour accélérer l’installation et limiter les erreurs manuelles sur Linux et Windows.

### Quel Script Utiliser ?

- Installation rapide: utiliser `bootstrap`.
- Préparation prod: appliquer `hardening` après bootstrap.
- Retour arrière sur durcissement: utiliser les scripts rollback.

### Linux

- Bootstrap: `scripts/install/linux/bootstrap.sh`
- Durcissement: `scripts/install/linux/hardening.sh`
- Rollback durcissement: `scripts/install/linux/hardening-rollback.sh`

Exemple rapide:

```bash
chmod +x scripts/install/linux/bootstrap.sh
./scripts/install/linux/bootstrap.sh --install-deps
```

Options utiles:

- `--skip-build`
- `--skip-migrate`
- `--app-dir /opt/oqlook`
- `--node-major 22`
- `--web-user www-data`

Exemple durcissement:

```bash
sudo ./scripts/install/linux/hardening.sh --app-dir /opt/oqlook --server-name oqlook.example.com --web nginx
sudo ./scripts/install/linux/hardening-rollback.sh --web auto
```

### Windows

- Bootstrap: `scripts/install/windows/bootstrap.ps1`
- Durcissement: `scripts/install/windows/production-hardening.ps1`
- Boucle worker: `scripts/install/windows/queue-worker-loop.ps1`
- Rollback durcissement: `scripts/install/windows/production-hardening-remove.ps1`

Exemple rapide:

```powershell
powershell -ExecutionPolicy Bypass -File .\scripts\install\windows\bootstrap.ps1 -InstallDeps -InstallPostgres
```

Options utiles:

- `-SkipBuild`
- `-SkipMigrate`
- `-AppDir C:\inetpub\wwwroot\OQLook`
- `-PhpBin C:\php\php.exe`
- `-ComposerBin composer`
- `-NpmBin npm`

Notes:

- `-InstallDeps` utilise `winget`.
- Redis sur Windows utilise Memurai (compatible Redis) avec `-InstallRedis`.

Exemple durcissement:

```powershell
powershell -ExecutionPolicy Bypass -File .\scripts\install\windows\production-hardening.ps1 -AppDir C:\inetpub\wwwroot\OQLook -PhpBin C:\php\php.exe
powershell -ExecutionPolicy Bypass -File .\scripts\install\windows\production-hardening-remove.ps1 -TaskPrefix OQLook
```

### Scénarios Types

#### Développement local

- lancer bootstrap,
- garder debug activé,
- lancer `npm run dev` pour le hot reload frontend.

#### Préproduction / recette

- lancer bootstrap,
- lancer les migrations,
- lancer `npm run build`,
- lancer le worker et tester un scan full.

#### Production

- bootstrap,
- durcissement,
- `APP_DEBUG=false`,
- worker queue en service/tâche planifiée,
- sauvegarde base + rétention logs.

### Checklist Post-install

- `php artisan about` répond
- connexion DB OK
- worker queue démarré (si activé)
- découverte métamodèle OK
- scan full terminé
- export PDF lisible

### Dépannage

- échec dépendances bootstrap: installer les outils manuellement puis relancer avec options `--skip-*` ou `-Skip*`.
- permissions Linux: vérifier propriétaire/groupe utilisateur web.
- worker planifié Windows KO: vérifier tâche planifiée + execution policy.
