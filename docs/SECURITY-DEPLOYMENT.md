# Security / licensing — go-live runbook

Run this for **every install that goes live** — each client deployment, staging,
or your own instances. Do the steps in order. Commands run on the server (SSH)
from the project root unless it says "on your machine".

Conventions used below:
- `<DOMAIN>` — the install's domain, e.g. `hub.example.co.za`.
- `<CLIENT>` — a label for the install, e.g. the customer name.
- "on your machine" — the vendor's offline machine that holds the private key.

> Read this first: until you do **Step 5 (ionCube)**, a *developer* who can edit
> the PHP can still bypass the gate. Steps 1–4 stop a *client* and bind each
> install to its domain+server; Step 5 is what makes it hold against someone who
> knows the code.

---

## 0. Rotate the seeded admin passwords (every new install)

The product seeds admin accounts (e.g. `francois@…`, `support@…`). The original
plaintext passwords were committed historically, so treat every freshly-seeded
install as compromised until rotated:

1. Log in and change every seeded admin account's password, **or**
2. Set strong values in the install's `.env` *before* seeding:

```
SEED_MASTER_ADMIN_PASSWORD=<new strong value>
SEED_SUPPORT_PASSWORD=<new strong value>
```

Never commit real values — `.env` is git-ignored; keep them in your secrets
manager.

---

## 1. Generate ONE signing keypair (once, ever — on your machine)

This is done a single time for the whole product, not per install.

```bash
php artisan license:keygen
```

- **PUBLIC** key → goes in every install's `.env` (`LICENSE_PUBLIC_KEY`).
- **PRIVATE** key → store in your password manager / HSM. Never commit or deploy
  it. It is the only thing that can issue licenses; if it leaks, anyone can forge
  them. Reuse the same keypair for all installs.

## 2. Get the install's binding values (per install)

On the target server:

```bash
php artisan license:fingerprint
# prints:  domain = <DOMAIN>   fingerprint = <FINGERPRINT>
```

Record the `domain` + `fingerprint`.

## 3. Issue a license for that install (on your machine, with the private key)

```bash
LICENSE_SIGNING_KEY=<private-key> php artisan license:issue \
  --client="<CLIENT>" \
  --domain=<DOMAIN> \
  --fingerprint=<FINGERPRINT> \
  --modules='*' \
  --days=365 \
  --out=<CLIENT>-license.json
```

- `--modules='*'` grants everything. To sell specific modules, repeat the flag:
  `--modules=crm --modules=board_committee`.
- `--days` sets the validity window (renewal = issue a new file).

## 4. Deploy the license + turn enforcement on (per install)

Upload the file to the server as `storage/app/license.json`, then in that
install's `.env`:

```
LICENSE_PUBLIC_KEY=<public key from step 1>
LICENSE_ENFORCE=true
```

Apply and verify:

```bash
php artisan config:clear
php artisan license:status      # must say "License VALID"
```

If it says VALID, the gate is live: the install only runs modules its signed
license names, and the license won't validate if copied to another
domain/server or after it expires.

> Rollback / panic switch: set `LICENSE_ENFORCE=false` (or blank
> `LICENSE_PUBLIC_KEY`) and `php artisan config:clear` — licensing goes dormant
> and everything is allowed again.

---

## 5. ⚠️ Encrypt the gate with ionCube (the step that makes 1–4 hold)

Steps 1–4 are plaintext PHP. A developer can edit `allows()` to `return true`.
Encrypt the gate so it can't be edited. **This requires buying ionCube Encoder
(or SourceGuardian)** — a purchase + build step, done once in your pipeline and
applied to every install's artifact.

Encrypt at minimum these files (the whole enforcement surface):

```
app/Core/License/LicenseService.php          <- the verification + allows()
app/Http/Middleware/EnsureModuleIsActive.php <- the route gate
app/Http/Middleware/WatermarkInstance.php
app/Shared/Traits/BuildsNavigation.php       <- the menu filter
```

Workflow once you have the Encoder:

1. Install the **ionCube Loader** extension on each server (`php -m | grep
   ionCube` to confirm) — required to run encrypted files.
2. In your deploy pipeline, after `composer install`, run the Encoder over the
   files above (and any premium modules you want protected), replacing the
   plaintext copies in the deployed artifact only. Keep your repo plaintext for
   development.
3. Deploy the encrypted artifact. `php artisan license:status` should still work.

Encrypt **only** the gate + premium modules; leave the rest readable so support
and on-site fixes stay possible. Trade-off: you'll need the Loader on every
server and encrypted files are harder for you to debug.

---

## 6. (Optional) Remote kill-switch — revoke an install remotely

Useful for paying clients you may need to cut off (non-payment / abuse / theft).
Skip for installs you fully control.

**Stand up ONE authority instance you host** (only you control it). On it:

```
LICENSE_AUTHORITY=true
LICENSE_REMOTE_SECRET=<long random shared secret>
```

On **each client install**:

```
LICENSE_REMOTE=true
LICENSE_SERVER_URL=https://<your-authority-domain>
LICENSE_REMOTE_SECRET=<same secret>
LICENSE_GRACE_HOURS=72
```

Make sure the scheduler runs (`* * * * * php artisan schedule:run`) — the hourly
`license:heartbeat` checks in. Then on the authority:

```bash
php artisan license:registry list                        # installs (auto-registered on first heartbeat)
php artisan license:registry revoke --fingerprint=<FINGERPRINT> --reason="non-payment"
php artisan license:registry restore --fingerprint=<FINGERPRINT>
```

A revoke hard-stops that install on its next heartbeat. If the authority is
unreachable, clients keep working for `LICENSE_GRACE_HOURS` then stop — so your
outage won't instantly down every client, but a client that blocks the heartbeat
forever still gets cut off after the grace window.

---

## 7. Verify the watermark (leak tracing)

Every response carries an `X-Instance` header derived from the license + server,
unique per install:

```bash
curl -sI https://<DOMAIN> | grep -i x-instance
```

If the product ever turns up running somewhere unauthorized, that header ties it
back to the install it derives from.

---

## Upgrades, renewals & module changes

A license is signed, so you can't hand-edit it — you **re-issue and replace** it.
Same keypair, same domain + fingerprint; only the payload changes.

When a client **buys another module**, **renews**, or **downgrades**:

```bash
# on your machine, with the private key — list the FULL module set they should
# now have (existing + new), not just the new one:
LICENSE_SIGNING_KEY=<private-key> php artisan license:issue \
  --client="<CLIENT>" \
  --domain=<DOMAIN> \
  --fingerprint=<FINGERPRINT> \
  --modules=crm --modules=board_committee --modules=<new-module> \
  --days=<remaining or renewed term> \
  --out=<CLIENT>-license.json
```

Then on their server: upload over `storage/app/license.json`, then
`php artisan config:clear` and `php artisan license:status` (the new module
should appear under `modules`). Takes effect on the next request.

Notes:
- The `--modules` list **replaces** the old one — omit a module and they lose it.
  Use `--modules='*'` for "everything".
- A module is visible only if it's both **licensed** here **and** `active=1` in
  `security_modules` (modules seed active, so normally you only touch the
  license).
- Renewal = re-issue with a later `--days`. Downgrade = re-issue with fewer
  `--modules`. No keypair change, ever.

---

## Per-install checklist

For each new go-live, tick:

- [ ] 0. Seeded admin passwords rotated / set via env
- [ ] 2. Captured the install's domain + fingerprint
- [ ] 3. Issued a license bound to them (offline, private key)
- [ ] 4. Deployed `storage/app/license.json`, set `LICENSE_PUBLIC_KEY`, `license:status` = VALID
- [ ] 5. Gate files encrypted (ionCube) in the deployed artifact
- [ ] 6. (If billable) registered on the authority + heartbeat confirmed
- [ ] 7. `X-Instance` watermark present

(Step 1 is one-time for the whole product, not per install.)

---

## What this does and does NOT stop

- ✅ Copying the install to another domain/server — license won't validate.
- ✅ A client editing the DB `active` flag — the signed license still gates.
- ✅ Revoking a live install remotely (step 6).
- ✅ Tracing a leaked running copy (step 7).
- ⚠️ A developer copying the *source* — **cannot be stopped by code.** Mitigate
  with: signed legal agreements (IP assignment + NDA), least-privilege repo
  access, immediate access revocation on offboarding, and ionCube (step 5) so a
  stolen copy can't activate modules without your private key.
