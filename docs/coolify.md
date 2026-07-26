# Coolify On Hetzner

Coolify is the open-source, self-hosted comparison. The platform gives a
Vercel/Railway-like Git deployment UI, but server security, availability,
database operations, and disaster recovery remain the operator's
responsibility.

`compose.coolify.yaml` runs:

- the TYPO3 application
- MariaDB 10.11
- a TYPO3 Scheduler worker
- durable database and `fileadmin` volumes

Redis and Solr are deliberately excluded from the baseline so the speed and
price comparison measures the same basic CMS workload as Railway and
Sliplane. The existing `compose.hetzner.yaml` remains the larger
MariaDB/Redis/Solr reference stack.

## Host And Price

For the baseline, use a Hetzner `CX33` in Germany:

- 4 shared vCPU
- 8 GB RAM
- 80 GB NVMe
- €8.49/month excluding VAT after the June 2026 price change
- €0.50/month for IPv4
- €1.70/month for Hetzner backups at 20%
- **€10.69/month excluding VAT**

Self-hosted Coolify is free. Coolify Cloud can operate the control plane for
$5/month while the applications remain on the Hetzner server.

## Prepare Coolify

Create an Ubuntu 24.04 LTS server with an SSH key. Permit SSH only from the
administrative network and expose TCP 80/443. Do not expose MariaDB.

Install self-hosted Coolify using the command from the official installation
page:

```bash
curl -fsSL https://cdn.coollabs.io/coolify/install.sh | sudo bash
```

Alternatively, create a Coolify Cloud account and connect the new Hetzner
server. Coolify Cloud manages only the Coolify control plane; the server and
deployed services still belong to the operator.

## Deploy TYPO3

1. Create a Coolify project and production environment.
2. Add a Docker Compose resource connected to this GitHub repository.
3. Set the Compose file to `/compose.coolify.yaml`.
4. Copy `.env.coolify.example` into Coolify's environment editor.
5. Replace every `change-me` value. Generate the encryption key with:

   ```bash
   openssl rand -hex 48
   ```

6. Assign the production domain to service `app`, port `80`.
7. Confirm that `db` and `scheduler` have no public domains.
8. Deploy the stack.

For the first deployment only, use:

```dotenv
TYPO3_AUTO_SETUP=1
TYPO3_BOOTSTRAP_EMPTY_DATABASE=1
```

After the frontend and `/typo3/` work, change both flags to `0` and redeploy.

## Local Installation Test

The exact Coolify stack can be exercised without Coolify:

```bash
cp .env.coolify.example .env.coolify
# Replace every change-me value.
docker compose \
  --env-file .env.coolify \
  -f compose.coolify.yaml \
  up --build -d
```

Inspect it with:

```bash
docker compose --env-file .env.coolify -f compose.coolify.yaml ps
docker compose --env-file .env.coolify -f compose.coolify.yaml logs app
docker compose --env-file .env.coolify -f compose.coolify.yaml logs scheduler
```

## Operations

- Enable Hetzner backups and configure an independent off-server SQL and
  `fileadmin` export.
- Keep Ubuntu, Docker, Coolify, MariaDB, and TYPO3 patched.
- Monitor the external URL, disk consumption, container restarts, and backup
  jobs.
- A single CX33 is not highly available. Plan a maintenance window for host
  and database work.
- Move `fileadmin` to the S3-compatible driver before horizontally scaling the
  application.

Sources: [Coolify installation](https://coolify.io/docs/get-started/installation),
[Docker Compose](https://coolify.io/docs/knowledge-base/docker/compose),
[Coolify pricing](https://coolify.io/pricing), and
[Hetzner's June 2026 prices](https://docs.hetzner.com/general/infrastructure-and-availability/price-adjustment/).
