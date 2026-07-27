# Self-hosting OpenFunnels

OpenFunnels can run as a standalone Laravel application on a VPS. The application owns funnel editing, publishing, lead capture, contacts, DNS verification, and custom-domain routing. Your hosting platform remains responsible for PHP, the database, HTTPS certificates, the reverse proxy, backups, and process supervision.

## Production services

- PHP 8.3 or newer with the extensions required by Laravel, PDO, and `dns_get_record()` support.
- A supported web server or application platform with the document root set to `public/`.
- PostgreSQL or MySQL for normal production installations. SQLite is suitable for small single-node installations when the database file is backed up carefully.
- A process supervisor for `php artisan queue:work`.
- Cron running `php artisan schedule:run` every minute.
- SMTP or another Laravel mail transport for lead notifications and account email.
- Outbound HTTPS access for Automation Studio webhook actions. Private-network webhook targets are blocked by default.
- Node.js and pnpm during deployment to build frontend assets; Node.js is not required by the running PHP application after the build.

Laravel exposes `GET /up` for load-balancer and uptime checks.

## Installation

```bash
git clone https://github.com/aialvi/openfunnels.git
cd openfunnels
composer install --no-dev --classmap-authoritative
pnpm install --frozen-lockfile
pnpm run build
cp .env.example .env
php artisan key:generate
php artisan migrate --force
php artisan storage:link
php artisan optimize
```

Set `APP_ENV=production`, `APP_DEBUG=false`, and an HTTPS `APP_URL`. Configure a production database, session/cache/queue stores, mail delivery, and a unique `APP_KEY` before accepting traffic. Never share an `APP_KEY` between installations.

The web process must be able to write to `storage/` and `bootstrap/cache/`. Do not expose the repository root as the web root.

## Public funnel URLs

Without a custom domain, published funnels use:

```text
https://your-openfunnels-host/f/{slug}
```

The Share action always uses a backend-generated public URL. Draft funnels do not have a shareable URL.

## Custom domains

Configure the public DNS destination shown by the domain wizard:

```env
DOMAIN_MAPPING_CNAME_TARGET=funnels.example.com
DOMAIN_MAPPING_A_RECORD_IP=203.0.113.10
FUNNEL_CUSTOM_DOMAIN_SCHEME=https
```

Subdomains use a CNAME record and root domains use an A record. Laravel verifies DNS with `dns_get_record()` and serves only verified domains belonging to published funnels.

Your reverse proxy or hosting platform must accept those hostnames and provision their certificates. OpenFunnels intentionally does not invoke Certbot, edit proxy configuration, or provision certificates from PHP.

## Long-running processes

Run a supervised worker and restart it after each release:

```bash
php artisan queue:work --sleep=3 --tries=3 --max-time=3600
```

Automation Studio uses the queue for event matching, email, webhooks, waits, and CRM actions. It also relies on the scheduler to recover a pending outbox event or delayed run when a queue job is lost. Do not deploy automations without both the worker and the minute-level scheduler.

Useful automation settings include:

```env
AUTOMATION_QUEUE=default
AUTOMATION_RUN_RETENTION_DAYS=90
AUTOMATION_WEBHOOK_ALLOW_PRIVATE_NETWORKS=false
AUTOMATION_WEBHOOK_ALLOW_HTTP=false
```

Marketing workflow email includes an account-scoped unsubscribe link and suppresses unsubscribed or bounced contacts. Operators remain responsible for collecting appropriate consent, configuring a valid sender identity, and following the laws that apply to their recipients.

Add this cron entry:

```cron
* * * * * cd /path/to/openfunnels && php artisan schedule:run >/dev/null 2>&1
```

## Deployment sequence

For updates, enable maintenance mode when required, pull the desired release, install locked dependencies, build assets, migrate, rebuild Laravel caches, restart queue workers, and then disable maintenance mode.

Back up the database and persistent storage before migrations. Test restoration periodically; a backup that has never been restored is not a recovery plan.
