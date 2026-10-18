# Module licensing (signed licenses)

Replaces "trust the `security_modules.active` DB flag" with a cryptographically
signed license. A module is available only if a valid Ed25519-signed license —
which the client cannot forge — names it. Editing the database or the license
file no longer grants a module.

**Fail mode:** hard-stop. An unlicensed/expired/mismatched module is blocked at
the route (`EnsureModuleIsActive`) and hidden from the navigation.

**Dormant by default:** with no `LICENSE_PUBLIC_KEY` set, licensing is OFF and
the app behaves exactly as before. Turn it on by configuring a public key.

## One-time setup (vendor)

```bash
php artisan license:keygen
```

Outputs a PUBLIC and a PRIVATE key.

- **Public key** → set `LICENSE_PUBLIC_KEY=...` in every install's `.env`.
- **Private key** → keep OFFLINE, secret. Never commit, never deploy. It is the
  only thing that can issue licenses. Store it in your password manager / HSM.

## Per-client issuance

1. On the client's server, capture its binding values:

   ```bash
   php artisan license:fingerprint      # prints domain + server fingerprint
   ```

2. On YOUR machine (with the private key), issue a license bound to them:

   ```bash
   LICENSE_SIGNING_KEY=<private-key> php artisan license:issue \
     --client="Acme Corp" \
     --domain=hub.acme.co.za \
     --fingerprint=<their fingerprint> \
     --modules=crm --modules=board_committee \
     --days=365 --max-users=50 \
     --out=acme-license.json
   ```

   Use `--modules='*'` to grant everything.

3. Deliver `acme-license.json` and place it at `storage/app/license.json` on
   their install (path configurable via `LICENSE_PATH`).

4. Confirm before it bites users:

   ```bash
   php artisan license:status           # should report VALID
   ```

## What the license binds to

`domain` + server `fingerprint` + `expires_at` + allowed `modules`. A license
copied to another server (different fingerprint/domain) or kept past expiry is
rejected. Module `code_name`s match the values used by the `module.active:<code>`
route middleware.

## Important limitation

This is the cryptographic **foundation**, not the whole wall. Because the gate
is plaintext PHP on the client's server, a determined client could still edit
the code to bypass the check. To make it tamper-proof, the next layers are:

- **Encrypt the gate + premium modules** with ionCube / SourceGuardian so the
  check can't be edited out (purchased tooling, separate build step).
- **Move the highest-value modules off the client's server** to a vendor-hosted
  API — code they never receive can't be cracked.

Signed licensing makes those layers enforceable; on its own it raises the bar
from "edit one DB boolean" to "find, patch, and re-patch obfuscated PHP on every
update," and every install is traceable.
