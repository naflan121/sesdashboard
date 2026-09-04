# Deploying SesDashboard with Docker

Setup guide for a Linux Docker host. Written to be handed to an agent or followed by
hand — every step has a verification command and the output you should see.

**Placeholders to replace:** `<DB_HOST>`, `<DB_NAME>`, `<DB_USER>`, `<DB_PASS>`,
`<ADMIN_EMAIL>`, `<PUBLIC_HOSTNAME>`. Never commit real values — `.env.local` is
gitignored and is the only place they belong.

---

## What this app is

It receives Amazon SES event notifications pushed via SNS over HTTP and renders a
dashboard, an activity log and CSV/XLSX exports. It never calls AWS and needs no AWS
credentials to monitor. The one hard requirement is that **its webhook must be reachable
from the public internet**, because AWS does the calling.

See `docs/configuration.rst` for the AWS side. This document covers only the deployment.

---

## Prerequisites

| Requirement | Check | Notes |
|---|---|---|
| Docker Engine | `docker --version` | |
| Compose **v2** | `docker compose version` | The Makefile calls `docker compose`, not `docker-compose`. v1 will not work |
| `make` | `make --version` | Optional — every target is expanded below |
| `git` | `git --version` | |
| MySQL 8 | see step 3 | Either the bundled container or an existing server |

Node and Yarn are **not** needed. Frontend assets are pre-built and committed under
`public/build/` (34 tracked files), so there is nothing to compile.

---

## Step 1 — Get the code

```bash
git clone https://github.com/naflan121/sesdashboard.git
cd sesdashboard
git checkout fork-improvements
```

`fork-improvements` is the branch to deploy. It carries fixes not present upstream:
support for both SNS delivery modes, SNS signature verification, SES tag capture,
recording of rendering failures, and database indexes.

Verify:

```bash
git log --oneline -1        # expect: fix(login): add autocomplete attributes...
ls public/build/entrypoints.json
```

---

## Step 2 — Create `.env.local`

**Do this before `docker compose up`.** The `mysql` service declares
`env_file: ./.env.local`, so Compose fails immediately if the file is absent.

```bash
cp .env .env.local
```

Now replace the two placeholders. If `xxd` is available (`make .env.local` does this):

```bash
sed -i "s/%CHANGE_ME_DB_PASSWORD%/$(openssl rand -hex 10)/g" .env.local
sed -i "s/%CHANGE_ME_APP_SECRET%/$(openssl rand -hex 16)/g" .env.local
```

`openssl` is used here rather than the Makefile's `xxd`, which ships with `vim-common`
and is missing on minimal images.

Then edit `.env.local` and set:

```dotenv
APP_ENV=prod
APP_SECRET=<already generated above>

# Point at whichever database you chose in step 3
DATABASE_URL=mysql://<DB_USER>:<DB_PASS>@<DB_HOST>:3306/<DB_NAME>?serverVersion=8.0.42

# Optional — see step 7
SNS_VERIFY_SIGNATURE=false
```

Verify no placeholders survive:

```bash
grep -c 'CHANGE_ME' .env.local     # expect: 0
```

### Getting `serverVersion` right

This is not cosmetic — it decides which SQL dialect Doctrine emits. Check the server:

```bash
mysql -h <DB_HOST> -u <DB_USER> -p -e "SELECT VERSION();"
```

| Server | Value |
|---|---|
| MySQL 8.0.42 | `serverVersion=8.0.42` |
| MariaDB 10.4.27 | `serverVersion=mariadb-10.4.27` |

Declaring MySQL 8 against a MariaDB server makes `doctrine:schema:validate` report the
schema out of sync, because MariaDB's `JSON` type is an alias for `LONGTEXT`.

---

## Step 3 — Choose a database

### Option A — bundled MySQL container

Nothing more to do. Use the container name as the host, since services share a network:

```dotenv
MYSQL_HOST=sesdashboard-mysql
DATABASE_URL=mysql://sesdashuser:<DB_PASS>@sesdashboard-mysql:3306/sesdashdb?serverVersion=8.0.42
```

Keep `MYSQL_USER`, `MYSQL_PASSWORD`, `MYSQL_DATABASE` and `MYSQL_ROOT_PASSWORD` in
`.env.local` — the container reads them on first boot to create the user and database.
They take effect **only** on an empty data volume; changing them later has no effect
unless you drop `sesdashboard-mysql-datavolume`.

### Option B — existing MySQL server

Point `DATABASE_URL` at it and **skip the mysql container** so it does not sit idle:

```bash
docker compose up -d webserver php-fpm
```

Create the database and a scoped user first. Do not reuse a root account:

```sql
CREATE DATABASE <DB_NAME> CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER '<DB_USER>'@'%' IDENTIFIED BY '<DB_PASS>';
GRANT ALL PRIVILEGES ON <DB_NAME>.* TO '<DB_USER>'@'%';
FLUSH PRIVILEGES;
```

`GRANT` is scoped to one schema deliberately — the app runs migrations, so it needs DDL
on its own database and nothing beyond it.

---

## Step 4 — Start the containers

```bash
docker compose up -d
docker compose ps          # all services should be Up
```

Three services: `webserver` (nginx:alpine, publishes port 80), `php-fpm` (built from
`phpdocker/php-fpm`, PHP 8.1) and `mysql` (skip it for Option B).

To publish on a port other than 80:

```bash
NGINX_HOST_PORT=8080 docker compose up -d
```

Compose interpolates `${NGINX_HOST_PORT:-80}` from the shell or from `.env` — **not**
from `.env.local`. Exporting it in the shell, as above, is the reliable way.

---

## Step 5 — Install and migrate

```bash
docker compose exec php-fpm composer install --no-dev --optimize-autoloader
docker compose exec php-fpm bin/console doctrine:migrations:migrate -n
docker compose exec php-fpm bin/console cache:clear
docker compose exec php-fpm bin/console cache:warmup
```

`composer.json` pins `config.platform.php` to 8.1.0 to match the image, so resolution is
identical wherever you run it.

Verify — both lines must say OK:

```bash
docker compose exec php-fpm bin/console doctrine:schema:validate
```

Expect 9 migrations applied and five tables: `email`, `email_event`, `project`, `user`,
`migration_versions`.

---

## Step 6 — Create the admin user and project

```bash
docker compose exec php-fpm bin/console app:create-user admin <ADMIN_EMAIL> '<STRONG_PASSWORD>' --admin
docker compose exec php-fpm bin/console app:create-project <ADMIN_EMAIL> "Production"
```

The second command prints the webhook path — **record it**, it is what AWS needs:

```
Webhook path: /webhook/<86-character-token>
```

Sign in with the **email address**, not the username. The firewall authenticates on
`email` (`config/packages/security.yaml`), so entering `admin` fails.

---

## Step 7 — Expose it over HTTPS

The nginx container serves plain HTTP on port 80. Put a TLS-terminating reverse proxy in
front (nginx, Traefik, Caddy, an ALB, or Cloudflare) and forward to it.

The URL to give AWS is:

```
https://<PUBLIC_HOSTNAME>/webhook/<token-from-step-6>
```

Requirements and cautions:

- **A publicly trusted certificate.** SNS rejects self-signed certificates on HTTPS
  subscriptions.
- **The token is the credential.** Anyone holding that URL can post fabricated events.
  Treat it as a secret; it is not in the repo.
- **Nothing else needs to be public.** Only `POST /webhook/{token}` is reachable
  anonymously; every other route requires a login. Restricting the dashboard to your VPN
  or office IP while leaving `/webhook/` open is a sound arrangement.

### Optional — verify SNS signatures

Closes the hole where a leaked URL is enough to inject events:

```dotenv
SNS_VERIFY_SIGNATURE=true
```

then `docker compose exec php-fpm bin/console cache:clear`.

**This only works if the SNS subscription has raw message delivery turned OFF.** The
signature travels in the SNS envelope; raw deliveries have no envelope, so there is
nothing to check and the request is passed through. Upstream's own instructions tell you
to enable raw delivery, so switching the subscription is a prerequisite, not an
afterthought. The app accepts both delivery modes either way.

---

## Step 8 — Schedule retention

Every SES event stores its full JSON payload. One send with a delivery and three opens is
five rows. Without pruning the tables grow without bound.

```cron
0 3 * * * cd /path/to/sesdashboard && docker compose exec -T php-fpm bin/console app:emails:cleanup --days=90
```

`-T` disables TTY allocation, which cron requires.

---

## Verifying the deployment

```bash
# 1. app responds
curl -s -o /dev/null -w '%{http_code}\n' http://localhost/login          # 200

# 2. static assets are served as files, not HTML
curl -s -I http://localhost/site.webmanifest | grep -i content-type      # application/manifest+json

# 3. webhook accepts a synthetic SES event
curl -s -X POST "http://localhost/webhook/<token>" \
  -H 'Content-Type: application/json' \
  -d '{"eventType":"Send","mail":{"timestamp":"2026-01-01T00:00:00.000Z","messageId":"deploy-test-1","source":"s@example.com","destination":["d@example.com"],"commonHeaders":{"subject":"Deploy test"}},"send":{}}'
# expect: Ok

# 4. it landed
docker compose exec php-fpm bin/console dbal:run-sql \
  "SELECT message_id, status FROM email WHERE message_id='deploy-test-1'"

# 5. remove the probe
docker compose exec php-fpm bin/console dbal:run-sql \
  "DELETE e FROM email_event e JOIN email m ON m.id=e.email_id WHERE m.message_id='deploy-test-1'"
docker compose exec php-fpm bin/console dbal:run-sql \
  "DELETE FROM email WHERE message_id='deploy-test-1'"
```

Webhook response codes: `200` accepted · `400` malformed body or unsupported event type ·
`403` invalid SNS signature · `404` unknown project token.

---

## Updating

```bash
git pull
docker compose exec php-fpm composer install --no-dev --optimize-autoloader
docker compose exec php-fpm bin/console doctrine:migrations:migrate -n
docker compose exec php-fpm bin/console cache:clear
docker compose exec php-fpm bin/console cache:warmup
docker compose restart php-fpm
```

`make upgrade` does the same, minus the cache steps. Take a database backup first —
migrations are not reversed automatically.

---

## Troubleshooting

| Symptom | Cause and fix |
|---|---|
| `docker compose up` fails on the mysql service | `.env.local` does not exist. Step 2 comes first |
| `'compose' is not a docker command` | Compose v1. Install the v2 plugin |
| CSS/JS load as HTML, or a manifest parse error | Only affects the PHP built-in server. nginx serves `public/` directly and is unaffected |
| `The parameter "CHANGE_ME_DB_PASSWORD" must be defined` | Placeholders still in `.env.local`. See step 2 |
| Schema "not in sync" but all columns exist | Wrong `serverVersion` — MariaDB declared as MySQL 8 |
| `Unable to write in the cache directory` | `var/` not writable. `docker compose exec php-fpm chown -R www-data:www-data var` |
| SNS subscription stuck *Pending confirmation* | Webhook unreachable from the internet, or TLS not publicly trusted. The app auto-confirms, so there is nothing to click |
| Events accepted but absent from the dashboard | Check `SELECT event, COUNT(*) FROM email_event GROUP BY event`. Only `send`, `delivery`, `reject`, `bounce`, `complaint`, `failure`, `open`, `click` are recognised |
| Code changed but nothing happens | `APP_ENV=prod` caches everything. Run `cache:clear` |

Logs:

```bash
docker compose logs -f php-fpm
docker compose exec php-fpm tail -50 var/log/prod.log
```

---

## Known limitations

Deploy with these in mind; none are blockers for single-team internal use.

- **No idempotency.** SNS delivers *at-least-once*, and a retry re-applies the event —
  replaying one `Open` three times moved the counter from 1 to 4. Open and click figures
  are a lower bound until this is fixed.
- **One project per user**, enforced in `ProjectController::add`.
- **The dashboard is not scoped by project.** `DashboardStatsHelper` counts every
  `email_event` row in the range regardless of owner. Fine for one user; a data leak with
  two.
- **`DeliveryDelay` and `Subscription` SES events are unsupported** and answered with 400.
  Leave them unticked in the event destination.
- **Dependencies are stale.** Symfony is pinned around 5.4.10–5.4.16 while 5.4.53 exists;
  `composer audit` reports advisories including three rated high, all fixed in 5.4.52+.
  Run `composer update` within the existing `5.4.*` constraints before going live — it
  needs no code changes.
