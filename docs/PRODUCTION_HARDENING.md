# Production Hardening

## EN

This guide installs production-ready runtime pieces for OQLook:

- web server vhost (Nginx or Apache, Linux)
- queue worker daemon
- watchdog scheduler (`oqlike:watchdog`)
- Laravel log rotation

### Linux (systemd + nginx/apache + logrotate)

Templates:

- `deploy/linux/nginx/oqlook.conf`
- `deploy/linux/apache/oqlook.conf`
- `deploy/linux/systemd/oqlook-queue-worker.service`
- `deploy/linux/systemd/oqlook-watchdog.service`
- `deploy/linux/systemd/oqlook-watchdog.timer`
- `deploy/linux/logrotate/oqlook`

Installer script:

- `scripts/install/linux/hardening.sh`

Example:

```bash
chmod +x scripts/install/linux/hardening.sh
sudo ./scripts/install/linux/hardening.sh \
  --app-dir /opt/oqlook \
  --server-name oqlook.example.com \
  --web nginx \
  --php-bin /usr/bin/php \
  --php-fpm-socket unix:/run/php/php8.2-fpm.sock \
  --web-user www-data
```

Dry run:

```bash
./scripts/install/linux/hardening.sh --dry-run --web nginx
```

Useful flags:

- `--web nginx|apache|none`
- `--skip-systemd`
- `--skip-logrotate`
- `--web-user` and `--web-group`

Validation:

```bash
systemctl status oqlook-queue-worker.service
systemctl status oqlook-watchdog.timer
journalctl -u oqlook-queue-worker.service -f
nginx -t
```

Rollback:

```bash
chmod +x scripts/install/linux/hardening-rollback.sh
sudo ./scripts/install/linux/hardening-rollback.sh --web auto
```

### Windows (Task Scheduler)

Scripts:

- `scripts/install/windows/production-hardening.ps1`
- `scripts/install/windows/queue-worker-loop.ps1`

The script creates scheduled tasks (running as `SYSTEM` by default):

- `<TaskPrefix>-QueueWorker`
- `<TaskPrefix>-Watchdog`

Example:

```powershell
powershell -ExecutionPolicy Bypass -File .\scripts\install\windows\production-hardening.ps1 `
  -AppDir C:\inetpub\wwwroot\OQLook `
  -PhpBin C:\php\php.exe `
  -TaskPrefix OQLook `
  -WatchdogEveryMinutes 2
```

Validation:

```powershell
Get-ScheduledTask -TaskName "OQLook-*"
Start-ScheduledTask -TaskName "OQLook-Watchdog"
```

Rollback:

```powershell
powershell -ExecutionPolicy Bypass -File .\scripts\install\windows\production-hardening-remove.ps1 -TaskPrefix OQLook
```

## FR

Ce guide installe les briques runtime de production pour OQLook:

- vhost web (Nginx ou Apache, Linux)
- worker de queue en continu
- planification watchdog (`oqlike:watchdog`)
- rotation des logs Laravel

### Linux (systemd + nginx/apache + logrotate)

Modeles:

- `deploy/linux/nginx/oqlook.conf`
- `deploy/linux/apache/oqlook.conf`
- `deploy/linux/systemd/oqlook-queue-worker.service`
- `deploy/linux/systemd/oqlook-watchdog.service`
- `deploy/linux/systemd/oqlook-watchdog.timer`
- `deploy/linux/logrotate/oqlook`

Script d'installation:

- `scripts/install/linux/hardening.sh`

Exemple:

```bash
chmod +x scripts/install/linux/hardening.sh
sudo ./scripts/install/linux/hardening.sh \
  --app-dir /opt/oqlook \
  --server-name oqlook.example.com \
  --web nginx \
  --php-bin /usr/bin/php \
  --php-fpm-socket unix:/run/php/php8.2-fpm.sock \
  --web-user www-data
```

Mode simulation:

```bash
./scripts/install/linux/hardening.sh --dry-run --web nginx
```

Options utiles:

- `--web nginx|apache|none`
- `--skip-systemd`
- `--skip-logrotate`
- `--web-user` et `--web-group`

Verification:

```bash
systemctl status oqlook-queue-worker.service
systemctl status oqlook-watchdog.timer
journalctl -u oqlook-queue-worker.service -f
nginx -t
```

Rollback:

```bash
chmod +x scripts/install/linux/hardening-rollback.sh
sudo ./scripts/install/linux/hardening-rollback.sh --web auto
```

### Windows (Planificateur de taches)

Scripts:

- `scripts/install/windows/production-hardening.ps1`
- `scripts/install/windows/queue-worker-loop.ps1`

Le script cree des taches planifiees (compte `SYSTEM` par defaut):

- `<TaskPrefix>-QueueWorker`
- `<TaskPrefix>-Watchdog`

Exemple:

```powershell
powershell -ExecutionPolicy Bypass -File .\scripts\install\windows\production-hardening.ps1 `
  -AppDir C:\inetpub\wwwroot\OQLook `
  -PhpBin C:\php\php.exe `
  -TaskPrefix OQLook `
  -WatchdogEveryMinutes 2
```

Verification:

```powershell
Get-ScheduledTask -TaskName "OQLook-*"
Start-ScheduledTask -TaskName "OQLook-Watchdog"
```

Rollback:

```powershell
powershell -ExecutionPolicy Bypass -File .\scripts\install\windows\production-hardening-remove.ps1 -TaskPrefix OQLook
```
