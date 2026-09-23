# Contract: `_iamConfig/azure.json` schema (locked before child-agent launch)

This is the **locked interface** between `azure-oidc` (owner: creates `system/lib/AzureOIDC.php`, `oauth-callback.php`, the boilerplate `azure.json` template, and the az-aware `iamConfig.php`/`login.php` modifications) and the consumers (`installer-upgrade`: writes values into this file at install time via flags; `haxiam-jps`: passes user-supplied tenant/clientId/clientSecret values into this file via the JPS template step and prints `redirectUri` back).

This contract is committed FIRST so every child agent implements against the same shape.

## File location
- Per install: `_iamConfig/azure.json`
- Boilerplate template shipped from HAxiam repo: `system/boilerplate/systemsetup/azure.json`
- Git-ignored at install time (already covered by the existing `_iamConfig/` ignore rule)

## Schema (canonical)
```json
{
  "enabled": false,
  "tenantId": "",
  "clientId": "",
  "clientSecret": "",
  "redirectUri": "",
  "issuer": "",
  "scopes": "openid profile email",
  "providerClass": "AzureOIDC"
}
```

| Field            | Type    | Required when enabled | Notes                                                                                                                                                                          |
| ---------------- | ------- | --------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ |
| `enabled`        | boolean | n/a                   | Master kill switch. Installer writes `false` when no Azure flags are passed; JPS writes `true` when `configureAzure` was checked and secrets were supplied.                |
| `tenantId`       | string  | yes                   | Azure AD tenant GUID (or verified domain). Issuer is derived from this by default.                                                                                             |
| `clientId`       | string  | yes                   | Azure app registration's Application (client) ID.                                                                                                                              |
| `clientSecret`   | string  | yes                   | Azure app registration's client secret value. Treated as secret — file permissions `0600`, never echoed by the installer.                                                     |
| `redirectUri`    | string  | yes                   | Absolute URL `https://{domain}/oauth-callback.php`. Computed by the installer from `--azure-redirect-base`/`--azure-domain` (or auto-detected); printed back in JPS success.   |
| `issuer`         | string  | optional              | Defaults to `https://login.microsoftonline.com/{tenantId}/v2.0`. Allow override for sovereign-cloud or v1 endpoints.                                                            |
| `scopes`         | string  | optional              | Space-separated. Default `openid profile email`. Installer / JPS MUST NOT modify unless the user passes `--azure-scopes`.                                                     |
| `providerClass`  | string  | optional              | Defaults to `AzureOIDC`. Allows future providers (e.g. generic OIDC) without changing the schema. The PHP class named lives in `system/lib/`.                                  |

## Publisher-side invariants (azure-oidc + installer-upgrade + haxiam-jps all depend on these)
1. `AzureOIDC::load(): self` MUST accept a string path (default `__DIR__ . '/../../_iamConfig/azure.json'`), read-and-decode it, and return an instance. Missing/unreadable file throws `\RuntimeException` with NO credentials in the message.
2. `AzureOIDC::isEnabled(): bool` returns `$this->config->enabled === true` AND all four of `tenantId`/`clientId`/`clientSecret`/`redirectUri` are non-empty strings.
3. The redirect URI registered in Azure MUST be byte-identical to the `redirectUri` field at validation time. The installer and the JPS MUST compute it deterministically.
4. Validation utility `scripts/utilities/check-azure-sso.sh` reads `tenantId`+`clientId`, hits `https://login.microsoftonline.com/{tenantId}/v2.0/.well-known/openid-configuration`, and confirms the JSON parses + `issuer` matches. Exit 0 on success; non-zero with a one-line remediation otherwise.
5. The file is written via `file_put_contents(..., json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), LOCK_EX)` and immediately `@chmod 0600`.
6. `iamConfig.php` (real, post-bridge) decides auth source as follows, top-to-bottom:
   - if `_iamConfig/azure.json` exists AND `AzureOIDC::isEnabled()` returns true: prefer `$_SESSION['HAXIAM_USER']` set by `oauth-callback.php`; ignore `$_SERVER['REMOTE_USER']`.
   - else: keep the existing `REMOTE_USER`/`PHP_AUTH_USER` fallback block unchanged.
7. `login.php` (post-bridge) redirects to `AzureOIDC::buildAuthorizationUrl($_SESSION['oauth_state'])` when `isEnabled()` is true; otherwise the existing legacy redirect stays.
8. `oauth-callback.php` validates the ID token (signature via JWKS + `iss`/`aud`/`exp` claims) before setting `$_SESSION['HAXIAM_USER']`. On any failure it redirects to `login.php?sso_error=<reason>` and does NOT issue a refresh token.
