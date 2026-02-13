<p align="center">
  <img src="../../docs/assets/oqlook-mark.svg" alt="OQLook" width="64" />
</p>

# OQLook Install Scripts

EN: Bootstrap and hardening helpers to install OQLook on Linux/Windows.

FR: Scripts de bootstrap et de durcissement pour installer OQLook sur Linux/Windows.

---

## Linux

- Bootstrap: `scripts/install/linux/bootstrap.sh`
- Hardening: `scripts/install/linux/hardening.sh`
- Hardening rollback: `scripts/install/linux/hardening-rollback.sh`

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

Hardening example:

```bash
sudo ./scripts/install/linux/hardening.sh --app-dir /opt/oqlook --server-name oqlook.example.com --web nginx
sudo ./scripts/install/linux/hardening-rollback.sh --web auto
```

## Windows

- Bootstrap: `scripts/install/windows/bootstrap.ps1`
- Hardening: `scripts/install/windows/production-hardening.ps1`
- Worker loop: `scripts/install/windows/queue-worker-loop.ps1`
- Hardening rollback: `scripts/install/windows/production-hardening-remove.ps1`

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

Hardening example:

```powershell
powershell -ExecutionPolicy Bypass -File .\scripts\install\windows\production-hardening.ps1 -AppDir C:\inetpub\wwwroot\OQLook -PhpBin C:\php\php.exe
powershell -ExecutionPolicy Bypass -File .\scripts\install\windows\production-hardening-remove.ps1 -TaskPrefix OQLook
```
