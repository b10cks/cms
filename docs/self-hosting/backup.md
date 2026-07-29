---
description: "Back up and restore a self-hosted b10cks instance: databases, storage, .env, and why losing APP_KEY is unrecoverable."
---

# Backup & restore

A complete backup of a b10cks instance is three things:

| What | Contains |
| --- | --- |
| **Databases** | The management database (users, teams, spaces, billing) **and every per-space database** — on the standard profile each space has its own; on the shared profile spaces live as prefixed tables in the main database, or as SQLite files under `storage/app/spaces/` |
| **`storage/`** | Uploaded assets (on the `local` disk), transfer packages, SQLite space databases, and — in Docker — the generated `APP_KEY` at `storage/app/setup/app.key` |
| **`.env`** | Your configuration, including `APP_KEY` |

::: danger APP_KEY loss is unrecoverable
`APP_KEY` encrypts secrets at rest — API tokens, integration credentials, anything the app stores encrypted. A database restore **without the matching key** leaves that data permanently unreadable; no amount of support can bring it back. Keep a copy of `APP_KEY` somewhere safe, outside the server. In the Docker setup, if you never set `APP_KEY` in `.env`, the container generated one on first boot and persisted it at `storage/app/setup/app.key` on the storage volume — copy it into your `.env` (and your password manager) now.
:::

If assets live on S3 or GCS instead of the `local` disk, the bucket is outside the instance — use your provider's versioning or replication for it.

## Docker Compose

### Databases

The bundled MariaDB holds the management database and, on the standard profile, one database per space — so dump **all** databases, not just `b10cks`:

```bash
docker compose exec db mariadb-dump -uroot -p"$DB_ROOT_PASSWORD" \
  --all-databases --single-transaction | gzip > b10cks-db-$(date +%F).sql.gz
```

`--single-transaction` takes a consistent snapshot without locking writers. `$DB_ROOT_PASSWORD` is the value from your `.env`.

### Storage and configuration

```bash
docker compose exec app tar -czf - -C /app storage | cat > b10cks-storage-$(date +%F).tar.gz
cp .env b10cks-env-$(date +%F)
```

Also keep a copy of `docker-compose.yml` if you modified it.

### What the volumes do and don't survive

The named volumes (`app-storage`, `db-data`) persist across `docker compose down`, restarts, and image upgrades. They do **not** survive `docker compose down -v` — the `-v` flag deletes them, databases and uploads included. Volumes are not backups: they live on the same disk as everything else, so still take the dumps above and store them off the machine.

### Restore

1. Put `docker-compose.yml` and your backed-up `.env` in place — with the original `APP_KEY`.
2. Start only the database and wait for it to become healthy: `docker compose up -d db`
3. Import the dump:

```bash
gunzip -c b10cks-db-2026-07-29.sql.gz | \
  docker compose exec -T db mariadb -uroot -p"$DB_ROOT_PASSWORD"
```

4. Restore storage into the volume **before** the app's first boot — the archive contains `storage/app/setup/install-state.json`, and without it the entrypoint would run the installer against your restored database:

```bash
docker compose run --rm -T --no-deps --entrypoint tar app -xzf - -C /app \
  < b10cks-storage-2026-07-29.tar.gz
```

5. Start everything: `docker compose up -d`

The restored instance boots as the instance it was — same key, same data, installer disarmed.

## Manual and webhost installs

The same three pieces, with your own tooling:

```bash
mariadb-dump -u… -p… --all-databases --single-transaction | gzip > db.sql.gz
tar -czf storage.tar.gz -C /path/to/cms storage
cp /path/to/cms/.env env-backup
```

On the shared profile with a single database, `--databases yourdb` is enough — the per-space tables are prefixed inside it. With `B10CKS_SPACE_DB_DRIVER=sqlite`, the space databases are files under `storage/app/spaces/` and travel with the storage archive; make sure the dump and the archive are taken close together so content and assets stay consistent.

Restore is the reverse: put the code in place, restore `.env` and `storage/`, import the dump, and run `php artisan migrate` if the backup predates your current version.

## What a backup does not need

- **Transformed images** — computed per request and cached at the CDN, never written back to storage.
- **The queue** — pending jobs are in the database (`database` driver) and thus in your dump; Redis queues are transient and re-derivable.
- **The application code** — it's this repository (or the release archive); only your data is unique.

Test the restore path once before you need it. A backup you have never restored is a hope, not a backup.
