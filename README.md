<p align="center">
  <img src="docs/assets/oqlook-mark.svg" alt="OQLook" width="84" />
</p>

<h1 align="center">OQLook</h1>

<p align="center">
  Adaptive CMDB quality scanner for iTop (self-hosted)
</p>

---

## Contents

- [🇬🇧 English](#-english)
- [🇫🇷 Français](#-français)

---

## 🇬🇧 English

> [!NOTE]
> Full English section. French version is below.

### What Is OQLook?

OQLook helps CMDB teams detect data-quality issues in iTop, prioritize what matters, and track remediation progress over time.

It combines:

- adaptive checks,
- domain scoring,
- issue triage,
- object/rule acknowledgements,
- exportable reporting.

### Who Is It For?

- CMDB administrators
- Process owners (incident/change/problem)
- IT governance and quality teams
- Ops teams that need an actionable backlog instead of raw CMDB noise

### What You Can Do With It

- Run **full scans** for baseline quality assessment.
- Run **delta scans** for day-to-day monitoring.
- Focus on high-impact issues first (`affected`, `severity`, domain).
- Acknowledge accepted exceptions at rule or object level.
- Export scan context and issue evidence to PDF.

### Main Features

- Multi-domain scoring: `completeness`, `consistency`, `relations`, `obsolescence`, `hygiene`
- Full and delta scans
- Rule-level and object-level acknowledgements
- Configurable compliance rules in UI (enable/disable + severity override)
- Drilldown of impacted objects with filtering/sorting
- PDF export (scan context, KPIs, issue details)
- iTop metamodel discovery via REST and optional connector
- UI preferences: language, theme, density, layout

### Quick Architecture

1. OQLook app runs scans and stores findings.
2. OQLook queries iTop directly (REST) or via the optional connector.
3. Findings are normalized and scored.
4. UI exposes triage + acknowledgements + exports.

### Quick Start (Linux/Windows)

#### Prerequisites

- PHP `8.2+` with extensions (`curl`, `mbstring`, `intl`, `pdo_pgsql` or `pdo_mysql`, `zip`, `gd`; `ldap` optional)
- Composer `2+`
- Node.js `20+` (LTS recommended)
- PostgreSQL or MySQL/MariaDB
- Web server (Nginx or Apache) pointing to `public/`
- Redis optional (recommended if using queue)

#### Install scripts

| OS | Bootstrap script | Production hardening |
|---|---|---|
| Linux | `scripts/install/linux/bootstrap.sh` | `scripts/install/linux/hardening.sh` |
| Windows | `scripts/install/windows/bootstrap.ps1` | `scripts/install/windows/production-hardening.ps1` |

Clone:

```bash
git clone https://github.com/Tkremre/OQLook.git
cd OQLook
```

Linux example:

```bash
chmod +x scripts/install/linux/bootstrap.sh
./scripts/install/linux/bootstrap.sh --install-deps
```

Windows example:

```powershell
powershell -ExecutionPolicy Bypass -File .\scripts\install\windows\bootstrap.ps1 -InstallDeps -InstallPostgres
```

Script reference: `scripts/install/README.md`

### Manual Installation

```bash
cp .env.example .env
composer install --no-interaction --prefer-dist
npm install
npm run build
php artisan key:generate --force
php artisan migrate --force
php artisan optimize:clear
```

### First 15 Minutes (Recommended Path)

1. Open OQLook and create your iTop connection.
2. Run metamodel discovery.
3. Launch a **full scan** (first baseline).
4. Open Issues and sort by `affected desc`.
5. Triage top critical/warning findings.
6. Apply acknowledgements for known/accepted exceptions.
7. Switch to **delta mode** for recurring runs.

> [!TIP]
> First scan is for baseline. Daily operations should usually be delta + periodic full scan.

### Scan Modes Explained

| Mode | Goal | Typical usage |
|---|---|---|
| `full` | Analyze complete target scope | Initial baseline, weekly/monthly audits |
| `delta` | Focus on recent changes | Daily operations, faster feedback |

### How To Read the Score

- `100` means no active issue weighted in current context.
- Lower score means heavier penalty from issue count/severity/impact.
- Score by domain helps decide who should act first:
  - `completeness`: required fields, classification quality
  - `consistency`: duplicates, naming, business coherence
  - `relations`: missing/invalid references
  - `obsolescence`: stale/unused data
  - `hygiene`: structural quality checks

### Typical CMDB Routine

- **Daily**: run delta, handle high-impact new items.
- **Weekly**: review trends and acknowledgements.
- **Monthly**: run full scan and compare baseline drift.

### Configuration

Minimum `.env`:

```env
APP_ENV=production
APP_DEBUG=false
APP_URL=https://your-hostname-or-subpath
ASSET_URL=https://your-hostname-or-subpath

DB_CONNECTION=pgsql
DB_HOST=127.0.0.1
DB_PORT=5432
DB_DATABASE=oqlook
DB_USERNAME=oqlook
DB_PASSWORD=change_me
```

If served from subpath (`/oqlook`):

```env
APP_URL=https://mydomain/oqlook
ASSET_URL=https://mydomain/oqlook
```

Then:

```bash
npm run build
php artisan optimize:clear
```

#### Key tuning variables

- Scan limits:
  - `OQLIKE_MAX_FULL_RECORDS_PER_CLASS`
  - `OQLIKE_MAX_FULL_RECORDS_WITHOUT_DELTA`
  - `OQLIKE_DELTA_STRICT_MODE`
  - `OQLIKE_MAX_DUPLICATE_SCAN_RECORDS`
- Metamodel/connector:
  - `OQLIKE_MAX_CONNECTOR_CLASSES`
  - `OQLIKE_CONNECTOR_MEMORY_GUARD_RATIO`
  - `OQLIKE_CONNECTOR_MEMORY_HARD_STOP_RATIO`
  - `OQLIKE_DISCOVERY_SCAN_LIMIT`
- Object acknowledgements:
  - `OQLIKE_OBJECT_ACK_ENABLED=true`
  - `OQLIKE_OBJECT_ACK_MAX_VERIFICATIONS_PER_ISSUE=250`
  - `OQLIKE_ISSUE_OBJECTS_MAX_FETCH=5000`

### Operations & CLI

Queue worker (recommended):

```bash
php artisan queue:work --queue=default --tries=1
```

Discovery only:

```bash
php artisan oqlike:discover <connection_id>
```

Run scan:

```bash
php artisan oqlike:scan <connection_id> --mode=delta
php artisan oqlike:scan <connection_id> --mode=full --classes=Server,Person
```

### Troubleshooting

#### Dotenv parse error

`Failed to parse dotenv file. Encountered unexpected whitespace`

Use comma-separated values without spaces:

```env
OQLIKE_ADMIN_PACK_PLACEHOLDER_TERMS=test,tmp,todo,tbd,sample,dummy,unknown,n/a,na,xxx,to_define
```

#### `Could not open input file: artisan`

Run commands from project root:

```bash
cd /path/to/OQLook
php artisan ...
```

#### Missing assets (`/build/assets/...`)

- Check `APP_URL` and `ASSET_URL`
- Rebuild frontend assets
- Clear Laravel caches

#### Scan seems stuck

- Inspect `storage/logs/laravel.log`
- Reduce caps (`OQLIKE_MAX_FULL_RECORDS_PER_CLASS`, `OQLIKE_MAX_DUPLICATE_SCAN_RECORDS`)
- Use queue worker and watchdog in production

### Security Notes

- Never commit `.env`.
- Rotate leaked secrets immediately (`APP_KEY`, API tokens, DB credentials).
- Keep `APP_DEBUG=false` in production.

### Additional Documentation

- Classic install: `docs/INSTALL_CLASSIC.md`
- Docker install: `docs/INSTALL_DOCKER.md`
- Production hardening: `docs/PRODUCTION_HARDENING.md`
- Connector deployment: `oqlike-connector/README.md`
- Install scripts: `scripts/install/README.md`

---

## 🇫🇷 Français

> [!TIP]
> Section complète en français. La version anglaise est au-dessus.

### Qu'est-ce qu'OQLook ?

OQLook aide les équipes CMDB à détecter les anomalies de qualité dans iTop, prioriser ce qui compte, et suivre la remédiation dans le temps.

L'outil combine:

- des contrôles adaptatifs,
- un scoring par domaine,
- du triage d'anomalies,
- des acquittements règle/objet,
- des exports de reporting.

### Pour Qui ?

- Administrateurs CMDB
- Owners de processus (incident/changement/problème)
- Équipes gouvernance/qualité IT
- Équipes ops qui veulent un backlog actionnable plutôt qu'un bruit CMDB brut

### Ce Que Tu Peux Faire

- Lancer des **scans complets** pour établir un état de référence.
- Lancer des **scans delta** pour la surveillance quotidienne.
- Prioriser les anomalies à fort impact (`affected`, `severity`, domaine).
- Acquitter les exceptions connues au niveau règle ou objet.
- Exporter le contexte du scan et les preuves en PDF.

### Fonctionnalités Principales

- Scoring multi-domaines: `completeness`, `consistency`, `relations`, `obsolescence`, `hygiene`
- Scans complets et delta
- Acquittements au niveau règle et objet
- Règles de conformité configurables dans l'UI (activer/désactiver + override de sévérité)
- Drilldown des objets impactés avec tri/filtres
- Export PDF (contexte de scan, KPIs, détails anomalies)
- Découverte du métamodèle iTop via REST et connecteur optionnel
- Préférences UI: langue, thème, densité, disposition

### Architecture Rapide

1. L'app OQLook exécute les scans et stocke les résultats.
2. OQLook interroge iTop directement (REST) ou via le connecteur optionnel.
3. Les résultats sont normalisés et scorés.
4. L'UI expose triage + acquittements + exports.

### Démarrage Rapide (Linux/Windows)

#### Prérequis

- PHP `8.2+` avec extensions (`curl`, `mbstring`, `intl`, `pdo_pgsql` ou `pdo_mysql`, `zip`, `gd`; `ldap` optionnel)
- Composer `2+`
- Node.js `20+` (LTS recommandé)
- PostgreSQL ou MySQL/MariaDB
- Serveur web (Nginx ou Apache) pointant vers `public/`
- Redis optionnel (recommandé si queue)

#### Scripts d'installation

| OS | Script bootstrap | Durcissement production |
|---|---|---|
| Linux | `scripts/install/linux/bootstrap.sh` | `scripts/install/linux/hardening.sh` |
| Windows | `scripts/install/windows/bootstrap.ps1` | `scripts/install/windows/production-hardening.ps1` |

Clone:

```bash
git clone https://github.com/Tkremre/OQLook.git
cd OQLook
```

Linux:

```bash
chmod +x scripts/install/linux/bootstrap.sh
./scripts/install/linux/bootstrap.sh --install-deps
```

Windows:

```powershell
powershell -ExecutionPolicy Bypass -File .\scripts\install\windows\bootstrap.ps1 -InstallDeps -InstallPostgres
```

Référence scripts: `scripts/install/README.md`

### Installation Manuelle

```bash
cp .env.example .env
composer install --no-interaction --prefer-dist
npm install
npm run build
php artisan key:generate --force
php artisan migrate --force
php artisan optimize:clear
```

### Prise En Main En 15 Minutes (Parcours conseillé)

1. Crée ta connexion iTop dans OQLook.
2. Lance la découverte du métamodèle.
3. Lance un **scan full** (baseline).
4. Ouvre Anomalies et trie par `affecté décroissant`.
5. Traite d'abord les critiques/avertissements à fort impact.
6. Acquitte les exceptions connues.
7. Passe ensuite en **mode delta** pour les runs récurrents.

> [!TIP]
> Le premier scan sert de baseline. Ensuite, le plus efficace est souvent delta au quotidien + full périodique.

### Comprendre Les Modes De Scan

| Mode | Objectif | Usage typique |
|---|---|---|
| `full` | Analyser tout le scope cible | Baseline initiale, audit hebdo/mensuel |
| `delta` | Cibler les changements récents | Pilotage quotidien, feedback rapide |

### Lire Le Score Correctement

- `100` = aucune anomalie active pondérée dans le contexte courant.
- Plus le score baisse, plus la pénalité issue du volume/sévérité/impact est forte.
- Le score par domaine guide la priorisation:
  - `completeness`: champs obligatoires, qualité de classification
  - `consistency`: doublons, nommage, cohérence métier
  - `relations`: références manquantes/invalides
  - `obsolescence`: données anciennes/inutilisées
  - `hygiene`: contrôles structurels

### Routine CMDB Type

- **Quotidien**: scan delta + traitement des nouveautés à fort impact.
- **Hebdo**: revue des tendances et des acquittements.
- **Mensuel**: scan full et comparaison de dérive.

### Configuration

`.env` minimal:

```env
APP_ENV=production
APP_DEBUG=false
APP_URL=https://your-hostname-or-subpath
ASSET_URL=https://your-hostname-or-subpath

DB_CONNECTION=pgsql
DB_HOST=127.0.0.1
DB_PORT=5432
DB_DATABASE=oqlook
DB_USERNAME=oqlook
DB_PASSWORD=change_me
```

Si servi en sous-chemin (`/oqlook`):

```env
APP_URL=https://mydomain/oqlook
ASSET_URL=https://mydomain/oqlook
```

Puis:

```bash
npm run build
php artisan optimize:clear
```

#### Variables de tuning clés

- Limites de scan:
  - `OQLIKE_MAX_FULL_RECORDS_PER_CLASS`
  - `OQLIKE_MAX_FULL_RECORDS_WITHOUT_DELTA`
  - `OQLIKE_DELTA_STRICT_MODE`
  - `OQLIKE_MAX_DUPLICATE_SCAN_RECORDS`
- Métamodèle/connecteur:
  - `OQLIKE_MAX_CONNECTOR_CLASSES`
  - `OQLIKE_CONNECTOR_MEMORY_GUARD_RATIO`
  - `OQLIKE_CONNECTOR_MEMORY_HARD_STOP_RATIO`
  - `OQLIKE_DISCOVERY_SCAN_LIMIT`
- Acquittements objet:
  - `OQLIKE_OBJECT_ACK_ENABLED=true`
  - `OQLIKE_OBJECT_ACK_MAX_VERIFICATIONS_PER_ISSUE=250`
  - `OQLIKE_ISSUE_OBJECTS_MAX_FETCH=5000`

### Exploitation & CLI

Worker queue (recommandé):

```bash
php artisan queue:work --queue=default --tries=1
```

Découverte seule:

```bash
php artisan oqlike:discover <connection_id>
```

Lancer un scan:

```bash
php artisan oqlike:scan <connection_id> --mode=delta
php artisan oqlike:scan <connection_id> --mode=full --classes=Server,Person
```

### Dépannage

#### Erreur parse dotenv

`Failed to parse dotenv file. Encountered unexpected whitespace`

Ne mets pas d'espaces dans les listes CSV:

```env
OQLIKE_ADMIN_PACK_PLACEHOLDER_TERMS=test,tmp,todo,tbd,sample,dummy,unknown,n/a,na,xxx,to_define
```

#### `Could not open input file: artisan`

Lance les commandes depuis la racine du projet:

```bash
cd /path/to/OQLook
php artisan ...
```

#### Assets manquants (`/build/assets/...`)

- Vérifier `APP_URL` et `ASSET_URL`
- Rebuilder les assets frontend
- Vider les caches Laravel

#### Scan bloqué

- Consulter `storage/logs/laravel.log`
- Réduire les caps (`OQLIKE_MAX_FULL_RECORDS_PER_CLASS`, `OQLIKE_MAX_DUPLICATE_SCAN_RECORDS`)
- Utiliser queue worker + watchdog en production

### Notes Sécurité

- Ne jamais commit `.env`.
- Tourner immédiatement les secrets exposés (`APP_KEY`, tokens API, creds DB).
- Garder `APP_DEBUG=false` en production.

### Documentation Complémentaire

- Installation classique: `docs/INSTALL_CLASSIC.md`
- Installation Docker: `docs/INSTALL_DOCKER.md`
- Durcissement production: `docs/PRODUCTION_HARDENING.md`
- Déploiement connecteur: `oqlike-connector/README.md`
- Scripts d'installation: `scripts/install/README.md`
