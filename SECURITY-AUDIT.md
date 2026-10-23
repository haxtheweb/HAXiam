# Security Audit Playbook — HAXiam

Entry point for recurring security audits of this repo. One of five HAX repos audited on a regular cycle (`create`, `haxcms-nodejs`, `webcomponents`, `haxcms-php`, `HAXiam`) — see the project-specific rule in the PRAW rule registry (`haxtheweb/praw` `RULES.md`).

## Read order (do this first)

1. `README.md` — what HAXiam is (HAXcms multi-site packaging + Azure Entra ID OIDC bridge), install and config layout
2. `security_best_practices_report.md` — canonical current state (findings carry `**Status (implemented YYYY-MM-DD):**` annotations instead of separate round files)

## Scope

- PHP: `system/lib/AzureOIDC.php` (OIDC flow: `state`, `nonce`, PKCE, JWKS), `oauth-callback.php`, `login.php`, `system/boilerplate/systemsetup/iamConfig.php`
- Identity mapping: `sanitizeUserName` email-to-local-part collision guard; `users/<name>` binding via `_iamConfig/identities/<name>.json` (vhost-denied + git-ignored)
- Apache/permissions: `_iamConfig` deny block present in BOTH `*:80` and `*:443` vhosts; `azure.json` 0600 + www-data; `_iamConfig/` 0750, `config.cfg` 0640
- Install/upgrade shell: `scripts/install/haxiam-install.sh`, `scripts/upgrade/haxiam-bash-upgrade.sh`, `haxiam-jps/scripts/deploy-app.sh`, `haxiam-jps/scripts/setup-user.sh` — `set -euo pipefail`, secrets via env (never argv/echo), clones pinned to tag/SHA + digest
- Supply chain: `haxiam-jps/manifest.jps` `baseUrl` must point at a release tag/commit, never a mutable branch
- Harness: `testing/` (`azureOIDCTest.php`, `Dockerfile.ubuntu24`)
- Boundary: HAXiam installs on top of the `haxcms-php` core — core auth/password/cookie behavior is audited in `haxcms-php`; verify the bridge here, don't fork it

## Commands

```bash
php testing/azureOIDCTest.php                    # OIDC contract tests
php -l <changed file>
docker build -f testing/Dockerfile.ubuntu24 testing/   # container harness
```

## Regression guards (must stay green)

- `testing/azureOIDCTest.php` — state mismatch, missing code, token-exchange failure, wrong `aud`, expired `exp`, disabled provider, secret-leak assertions
- Identity-collision guard: `oauth-callback.php` + `AzureOIDC::resolveIdentityConflict()` / `bindNewUserIdentity()` (8 dedicated assertions)

## Reporting convention

- `security_best_practices_report.md` is canonical — update findings in place with status annotations rather than creating dated round files
- Cite `file:line`; findings use `H#/M#/L#` IDs
- Disclosures: HAXiam has no `SECURITY.md` of its own — follow the org policy used by `haxcms-php/SECURITY.md` (private advisory at `haxtheweb/issues`), never public issues
