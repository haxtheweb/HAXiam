# Contract: `scripts/install/haxiam-install.sh` flag surface (locked before child-agent launch)

The unified installer invoked by `scripts/install/ubuntu-{20,22,24,26}.sh` wrappers and by `haxiam-jps/manifest.jps`'s `deployApp` action. This contract is committed FIRST so all three child agents implement/talk to the same installer interface.

## Invocation patterns

### Human/IT-user install (interactive or scripted)
```bash
sudo bash scripts/install/haxiam-install.sh \
  [--distro ubuntu-20.04|ubuntu-22.04|ubuntu-24.04|ubuntu-26.04|auto] \
  [--domain example.org] \
  [--le | --skip-le] \
  [--cert /path/to/fullchain.pem --key /path/to/privkey.key] \
  [--azure-tenant <tenantId> --azure-client <clientId> --azure-secret <secret>] \
  [--azure-redirect-base https://example.org] \
  [--azure-scopes "openid profile email"] \
  [--ha iam-install-dir] \
  [--non-interactive]
```

### JPS /Reclaim Cloud install (Reclaim Cloud manages SSL)
```bash
sudo bash scripts/install/haxiam-install.sh --distro auto --skip-le --non-interactive
```
…plus optional `--azure-tenant/...secret/--azure-redirect-base` when the JPS form's `configureAzure` checkbox was checked.

## Flag semantics (locked)

| Flag                    | Default         | Means                                                                                                                       |
| ----------------------- | --------------- | --------------------------------------------------------------------------------------------------------------------------- |
| `--distro`              | `auto` (from `/etc/os-release`) | `auto`; if distro can't be matched the installer MUST exit non-zero with a remediation message naming supported distros. |
| `--domain`              | unset           | The IAM domain. When set + `--le` → certbot acquires LE for it. When set + `--cert/--key` → those paths are written into the Apache vhost template. |
| `--le`                  | `--skip-le`     | Run certbot for `--domain`; fail the install if certbot can't acquire (network/DNS). Honours cron auto-renew setup.         |
| `--skip-le`             | (default)       | Skip certbot. Used by JPS where Jelastic manages SSL; also valid for institutional installs with existing certs.             |
| `--cert` / `--key`      | unset           | Pre-existing certificate paths. Mutually exclusive with `--le`. Written into the vhost template + chown/chmod 0644/0640.    |
| `--azure-tenant`        | unset           | Azure AD tenant GUID. Combined with client/secret below, triggers Azure config phase. With all three present, `_iamConfig/azure.json` is written with `enabled: true` and `check-azure-sso.sh` is run. |
| `--azure-client`        | unset           | Azure app registration client ID. OK with `--azure-tenant` alone? NO — all three or none.                                  |
| `--azure-secret`        | unset           | Client secret. NEVER echoed. The installer MUST read it from the flag value but NEVER `echo`/`cat`/log the actual value; only print `<set>`/`len-N`. |
| `--azure-redirect-base` | unset           | Base URL for `redirectUri` (e.g. `https://example.org` or `https://${env.domain}` from JPS). Computed `redirectUri = ${base}/oauth-callback.php` and printed back. If unset with Azure flags, installer attempts to derive from Apache config / hostname (`hostname -f`, `https://$(hostname)/oauth-callback.php`) and WARNs it's a guess. |
| `--azure-scopes`        | `openid profile email` | Space-delimited OIDC scopes.                                                                                           |
| `--ha`                  | `/var/www/iam`  | Override the HAxiam install directory. The current legacy scripts assume `/var/www/iam`; this flag exists for non-standard installs. |
| `--non-interactive`     | unset           | Disables `[y/N]` confirmations and any `read` prompts. JPS mode MUST pass this.                                            |

## Behavioural invariants (every consumer depends on these)
1. Every file/dir creation is guarded (`if [ ! -f ]`/`if [ ! -d ]`). Re-running on an existing install must NEVER overwrite a non-placeholder file. Re-running on `cores/HAXcms-1.x.x` MUST NOT re-clone if git working tree is clean.
2. On existing installs (detected via existing `_iamConfig/config.cfg` having a non-empty `haxiam=` line), the installer MUST read those variables instead of `whateveryousayiam.sh`'s defaults. New writes are limited to:
   - `_iamConfig/azure.json` (if not present, with `enabled:false`)
   - `vendor/` (via `composer install` if absent)
   - `_iamConfig/SYSTEM_VERSION.txt` (only advanced if it equals the new code version)
3. Exit codes are stable:
   - `0` success
   - `1` pre-flight failure
   - `2` package install failure
   - `3` HAXiam bootstrap failure
   - `4` composer install failure
   - `5` Azure config failure (`check-azure-sso.sh` non-zero)
   - `6` LE/cert failure
   JPS expects these so it can surface actionable errors to the operator.
4. `_iamConfig/azure.json` writing follows the `azure_json_schema.md` contract exactly. JSON is pretty-printed; file is chmod 0600; the `clientSecret` field is taken verbatim from the `--azure-secret` flag, then never re-echoed.
5. `--azure-redirect-base` produces a `redirectUri` printed in green (the success colour convention) for the admin to copy into Azure. The contract format is `${base}/oauth-callback.php` with no trailing slash.
6. On completion, the installer prints:
   - summary of what was written/skipped
   - admin URL + the install state, and **when Azure was configured**: the exact `redirectUri` line in a separate green block.
7. The legacy thin wrappers `scripts/install/ubuntu{20,22,24,26}.sh` MUST keep working; they parse argv tail and pass through to `haxiam-install.sh --distro ubuntu-NN.YY`.
8. The installer MUST NOT run `chown -R` / `chmod -R` against `/var/www` or anything outside `_iamConfig/users`/`users_sites` (replaces the blanket pattern from the current `harden-security.sh`).

## Owner responsibilities

- **azure-oidc** owns: PHP path (AzureOIDC class + oauth-callback.php + iamConfig.php/login.php bridge + boilerplate azure.json). MUST consume the flag shape above when reading the installer-written file.
- **installer-upgrade** owns: `haxiam-install.sh` and the four ubuntu wrappers. MUST implement the flags and invariants above exactly.
- **haxiam-jps** owns: the JPS manifest. MUST invoke the installer with `--non-interactive --skip-le` (Reclaim Cloud / Jelastic manages SSL) and pass through `--azure-*` flags when the JPS `configureAzure` checkbox is checked. MUST print the installer's `redirectUri` line in the JPS `success` message.
