# OQLook Install Scripts

These scripts automate dependency installation and first-time application setup.

## Linux

Script:
- `scripts/install/linux/bootstrap.sh`

Example:

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

Production hardening:
- Script: `scripts/install/linux/hardening.sh`
- Installs templates for Nginx/Apache, systemd worker/watchdog, and logrotate.
- Rollback script: `scripts/install/linux/hardening-rollback.sh`

Example:

```bash
sudo ./scripts/install/linux/hardening.sh --app-dir /opt/oqlook --server-name oqlook.example.com --web nginx
sudo ./scripts/install/linux/hardening-rollback.sh --web auto
```

## Windows

Script:
- `scripts/install/windows/bootstrap.ps1`

Example:

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
- Redis on Windows uses Memurai (Redis-compatible) when `-InstallRedis` is provided.

Production hardening:
- Scripts:
  - `scripts/install/windows/production-hardening.ps1`
  - `scripts/install/windows/queue-worker-loop.ps1`
  - `scripts/install/windows/production-hardening-remove.ps1`
- Installs scheduled tasks for queue worker and watchdog.

Example:

```powershell
powershell -ExecutionPolicy Bypass -File .\scripts\install\windows\production-hardening.ps1 -AppDir C:\inetpub\wwwroot\OQLook -PhpBin C:\php\php.exe
powershell -ExecutionPolicy Bypass -File .\scripts\install\windows\production-hardening-remove.ps1 -TaskPrefix OQLook
```
