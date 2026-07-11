# Deploying the Checkoutly licensing server to production

Target: a single Ubuntu 24.04 VPS serving `https://checkoutly.app` (portal + API
gateway + Stripe + license signing), with auto-HTTPS via FrankenPHP and
push-to-deploy via GitHub Actions.

Everything on the apex:

| URL | What |
|-----|------|
| `https://checkoutly.app/` | merchant portal / dashboard |
| `https://checkoutly.app/api/v1/*` | API gateway (the module points here) |
| `https://checkoutly.app/webhook/stripe` | Stripe webhook |
| `https://checkoutly.app/connect/{google,facebook}/check` | OAuth callbacks |

Do the phases in order. `$` = run on the VPS as your sudo user unless noted.

---

## ⚠️ Three rules that will save you an afternoon

These caused every 500 during the first deploy — read before Phase 3.

1. **Create every mounted file as a real FILE _before_ the first `docker compose up`.**
   The app bind-mounts `.env.local` and `secrets/license-private.pem`. If either
   path doesn't exist when the container is created, Docker silently makes it an
   **empty directory**, and you get "no DATABASE_URL / signing key not configured"
   with no obvious cause. If it happens: `docker compose ... down`,
   `rm -rf <the-bogus-dir>`, create the real file, `up -d`. A plain `up -d` will
   **not** fix it — an unchanged container isn't recreated, so the stale
   directory-mount persists; you need `down` then `up`.

2. **Paths inside `.env.local` are CONTAINER paths, not host paths.** The signing
   key lives on the host at `/opt/checkoutly-licensing/secrets/license-private.pem`
   but is mounted into the container at `/etc/checkoutly/license-private.pem` — so
   `LICENSE_PRIVATE_KEY_PATH=/etc/checkoutly/license-private.pem` (the default in
   `.env.dist`). Using the host path fails: the container has no `/opt/...`.

3. **Use hex-only DB passwords** (`openssl rand -hex 24`). A `@ : / ? #` or `$` in
   the password breaks `DATABASE_URL` parsing (`Malformed parameter "url"`). The
   value in `.env` (DB_PASSWORD) and the one embedded in `DATABASE_URL` in
   `.env.local` must match; changing it after first boot needs the `db_data` volume
   recreated (MariaDB only reads the password on first init).

Diagnose any of these from inside the container:
`docker compose -f docker-compose.prod.yml exec app php bin/console debug:dotenv <VAR>`.

---

## Phase 0 — DNS (do this first, propagation takes time)

At your domain registrar, point the domain at the VPS public IP (`x.x.x.x`):

```
A     @      x.x.x.x
A     www    x.x.x.x
```

Verify before continuing (must return the VPS IP):

```
$ dig +short checkoutly.app
$ dig +short www.checkoutly.app
```

> `.app` is HSTS-preloaded — HTTPS is mandatory. Caddy needs 80/443 reachable
> and DNS resolving to issue the certificate, so DNS has to be live first.

---

## Phase 1 — VPS bootstrap

```bash
$ sudo apt update && sudo apt -y upgrade

# Docker Engine + compose plugin (official convenience script)
$ curl -fsSL https://get.docker.com | sudo sh
$ sudo usermod -aG docker "$USER"      # then log out / back in

# Firewall
$ sudo apt -y install ufw
$ sudo ufw allow OpenSSH
$ sudo ufw allow 80/tcp
$ sudo ufw allow 443/tcp
$ sudo ufw --force enable

$ docker --version && docker compose version
```

---

## Phase 2 — Let the VPS pull the private repo (deploy key)

Generate a keypair on the VPS and add the **public** half to the repo:

```bash
$ ssh-keygen -t ed25519 -C "checkoutly-vps-deploy" -f ~/.ssh/checkoutly_deploy -N ""
$ cat ~/.ssh/checkoutly_deploy.pub
```

GitHub → repo **checkoutly-licensing-server** → Settings → Deploy keys → Add:
paste that public key, title `vps`, leave "Allow write access" **off**.

Tell SSH to use it for GitHub:

```bash
$ cat >> ~/.ssh/config <<'EOF'
Host github.com
  IdentityFile ~/.ssh/checkoutly_deploy
  IdentitiesOnly yes
EOF
$ ssh -T git@github.com   # accept the fingerprint; "successfully authenticated" is expected
```

---

## Phase 3 — Clone + secrets

```bash
$ sudo mkdir -p /opt/checkoutly-licensing && sudo chown "$USER" /opt/checkoutly-licensing
$ git clone git@github.com:codingpandaren/checkoutly-licensing-server.git /opt/checkoutly-licensing
$ cd /opt/checkoutly-licensing
```

**3a. Compose env** (DB creds + ACME email):

```bash
$ cp .env.deploy.example .env
$ nano .env        # set DB_PASSWORD, DB_ROOT_PASSWORD, ACME_EMAIL
```

**3b. App secrets:**

```bash
$ cp .env.prod.example .env.local
$ nano .env.local  # APP_SECRET, DATABASE_URL (user/pass must match .env),
                   # Stripe LIVE keys, GOOGLE_PLACES_API_KEY, OAuth creds
```

Generate `APP_SECRET`: `openssl rand -hex 16`.

**3c. License signing key** (the private key that signs license keys — never commit it):

```bash
$ mkdir -p secrets
# copy your checkoutly-license-private.pem here as secrets/license-private.pem
$ chmod 600 secrets/license-private.pem
```

> The matching public key is embedded in the module's `LicenseValidator`. Reuse
> the SAME private key you already sign dev licenses with, or every existing key
> stops validating.

---

## Phase 4 — First deploy (manual)

```bash
$ docker compose -f docker-compose.prod.yml up -d --build
$ docker compose -f docker-compose.prod.yml logs -f app   # watch for the cert + "serving"
```

Run migrations, then make yourself admin (log in via Google at
`https://checkoutly.app/login` once first, so your user row exists):

```bash
$ docker compose -f docker-compose.prod.yml exec -T app \
    php bin/console doctrine:migrations:migrate --no-interaction
# after logging in via the browser once:
$ docker compose -f docker-compose.prod.yml exec app \
    php bin/console app:user:promote you@youremail.com
```

Check:

```bash
$ curl -s -o /dev/null -w '%{http_code}\n' https://checkoutly.app/            # 200/302
$ curl -s -X POST https://checkoutly.app/api/v1/vat/validate -d '{}'          # {"ok":false,"error":"unauthorized"}
```

---

## Phase 5 — Push-to-deploy (GitHub Actions)

The workflow (`.github/workflows/deploy.yml`) SSHes into the VPS on every push to
`main`. Give Actions its own key:

```bash
# On the VPS: a key Actions will use to log in as this user.
$ ssh-keygen -t ed25519 -C "gh-actions" -f ~/.ssh/gh_actions -N ""
$ cat ~/.ssh/gh_actions.pub >> ~/.ssh/authorized_keys
$ cat ~/.ssh/gh_actions          # the PRIVATE key → GitHub secret below
```

GitHub → repo → Settings → Secrets and variables → Actions → New secret:

| Secret | Value |
|--------|-------|
| `VPS_HOST` | VPS IP or hostname |
| `VPS_USER` | your sudo user |
| `VPS_PORT` | `22` (or your SSH port) |
| `VPS_SSH_KEY` | contents of `~/.ssh/gh_actions` (the private key) |

Now `git push origin main` → the Actions tab runs: pull, build, up, migrate,
cache clear. Trigger a no-op run any time from Actions → *Deploy to production*
→ Run workflow.

---

## Phase 6 — External services

- **Stripe** (live mode) → Developers → Webhooks → add endpoint
  `https://checkoutly.app/webhook/stripe`; copy the signing secret into
  `STRIPE_WEBHOOK_SECRET` in `.env.local`, then `docker compose ... up -d` to reload.
- **Google OAuth** console → Authorized redirect URI
  `https://checkoutly.app/connect/google/check`.
- **Facebook Login** → Valid OAuth redirect URI
  `https://checkoutly.app/connect/facebook/check`.

---

## Phase 7 — Backups

```bash
$ chmod +x deploy/backup.sh
$ ( crontab -l 2>/dev/null; echo '15 3 * * * /opt/checkoutly-licensing/deploy/backup.sh >> /var/log/checkoutly-backup.log 2>&1' ) | crontab -
$ ./deploy/backup.sh    # test once → backups/licensing_*.sql.gz
```

---

## Phase 8 — Point the module at production

The module reaches the gateway via `CHECKOUTLY_GATEWAY_URL` (and the revocation
heartbeat / portal URLs). For the shipped build, set the default to
`https://checkoutly.app` in the module code (`GatewayClient::GATEWAY_URL`,
`LicenseService::API_URL` → `/api/license/status`, `LicenseService::PORTAL_URL`)
so it works with no merchant config. Until then, set the config key on a shop:
`CHECKOUTLY_GATEWAY_URL = https://checkoutly.app`.

---

## Ops cheatsheet

```bash
$ docker compose -f docker-compose.prod.yml logs -f app
$ docker compose -f docker-compose.prod.yml restart app
$ docker compose -f docker-compose.prod.yml exec app php bin/console <cmd>
# rollback: check out the previous commit and redeploy
$ git checkout <sha> && docker compose -f docker-compose.prod.yml up -d --build
```
