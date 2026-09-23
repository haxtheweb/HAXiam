# haxiam-jps

Jelastic JPS manifest and tiny helper scripts for one-shot HAXiam installs on
**Reclaim Cloud / Jelastic** (or any single `apache2` `cp` node group).

The manifest is a thin driver: it asks the operator only the questions that
*cannot* live in the installer's flag surface (`_contracts/installer_flag_surface.md`),
and then hands off to the unified installer
`scripts/install/haxiam-install.sh` for everything else.

## What it installs

| Step | Action            | Notes                                                                                          |
| ---- | ----------------- | ---------------------------------------------------------------------------------------------- |
| 1    | `configSystem`    | `apt-get install php8.3-{fpm,zip,gd,dom,mbstring,yaml} apache2 brotli git` + a2enmod/a2enconf. |
| 2    | `deployApp`       | Clones HAXiam into the cp webroot (`$gh` / `/home/jelastic/webapp/ROOT/web` / `/var/www/iam`). Then runs `haxiam-install.sh --distro auto --skip-le --non-interactive`. Composes `--azure-*` flags when the SSO checkbox is on. Falls back to `composer install` only if the installer hasn't already produced `vendor/`. |
| 3    | `setupUser`       | Drops a fresh GUID token to `/var/www/iam/_installtoken.txt` (chmod 0600), POSTs `user + passphrase + install_token` to `install.php?op=advance&toStep=4`, then removes the file. The admin password never persists past this step — it's only available via the JPS global `HAX_ADMIN_PASS` for the success message. |
| 4    | `installLE`       | Only when *Skip Let's Encrypt* was unchecked. Calls Jelastic's `installAddon` for the community `lets-encrypt` JPS targeting `env.domain`. |
| 5    | `configureAzure`  | Only when *Configure Azure AD SSO* was checked. Writes `_iamConfig/azure.json` per `_contracts/azure_json_schema.md` (chmod 0600, `clientSecret` never echoed) and runs the validation utility `scripts/utilities/check-azure-sso.sh`. |

## Filesystem layout

```
haxiam-jps/
├── README.md              this file
├── manifest.jps           the JPS itself (YAML, Jelastic-recognised)
└── scripts/
    ├── deploy-app.sh       clone + run the unified installer
    └── install-token.sh    mint GUID, write _installtoken.txt, print to stdout
```

Nothing else in the HAXiam tree is owned or modified by this child.

## Settings form

At install time the form looks like this:

```
┌─ HAXiam install ────────────────────────────────────────────┐
│                                                              │
│  [ ]  Skip Let's Encrypt     (Reclaim Cloud/Jelastic handles │
│                              SSL for you)                    │
│                                                              │
│  [ ]  Configure Azure AD SSO                                 │
│         └ when checked, four extra fields appear below:     │
│              Tenant ID          [_______________________]    │
│              Client ID          [_______________________]    │
│              Client Secret      [•••••••••• (password)]      │
│              Redirect Domain    [_______________________]    │
│                                                              │
│  Local storage limit (GB)   [   20  ]                        │
│                                                              │
│  [  Install HAXiam  ]                                        │
└──────────────────────────────────────────────────────────────┘
```

- `Skip Let's Encrypt` defaults to **unchecked** (we DO install the LE addon).
- `Configure Azure AD SSO` defaults to **unchecked**. None of the four SSO
  sub-fields are visible until it's checked; the three required ones
  (`Tenant ID`, `Client ID`, `Client Secret`) are independently required
  when visible. The secret is typed as `password` and is stored encrypted at
  rest by Jelastic's settings storage.
- `Local storage limit` is an integer, default `20` GB.

## After install — Azure redirect URI registration

When the Azure AD SSO checkbox was ticked at install time, JPS prints the
**exact** `redirectUri` to register in the Azure app registration:

```
Azure Redirect URI  (paste into your app registration):

    https://example-use-the-DNS-name-from-Reclaim-Cloud.jelastic.dns/reclaim.cloud/oauth-callback.php
```

That string is computed **deterministically** as
`https://${redirectBase}/oauth-callback.php`, where `redirectBase` is:

- the value you typed into the *Redirect Domain* field, **or**
- `${env.domain}` if you left the field blank.

It must match byte-for-byte in the Azure portal; noise around it (trailing
slash, scheme difference, whitespace) will fail the SSO callback.

`AzureOIDC::isEnabled()` returns `true` only when the file is written AND
all four required fields are non-empty, so leaving the SSO checkbox unchecked
in JPS is intentionally a no-op — the installer still drops a default
`_iamConfig/azure.json` with `enabled: false`.

## Re-runs

The underlying installer is idempotent:

- An existing `_iamConfig/config.cfg` is honored — config values aren't
  rewritten.
- An existing `cores/HAXcms-1.x.x` checkout is left alone.
- `vendor/` is rebuilt only if missing; otherwise the call is skipped.

Re-running this JPS won't rotate the admin password or wipe generated
content. If you need to reset credentials, run the HAXiam bash menu
instead:

```bash
sudo bash /var/www/iam/scripts/haxiam.sh
```

## Reference

- Install flag surface contract: [`_contracts/installer_flag_surface.md`](../_contracts/installer_flag_surface.md)
- Azure config schema:         [`_contracts/azure_json_schema.md`](../_contracts/azure_json_schema.md)
- Reclaim Cloud JPS reference: <https://github.com/reclaimhosting/haxcms-jps>
- Community addon JPS:         <https://github.com/jelastic-public/lets-encrypt>

## Implementation notes

- `manifest.jps` is pure YAML (`yaml.safe_load` validated). No embedded
  inline JSON; the Azure config file is written by an in-manifest bash
  step, not via `writeFile`, to keep secrets behind `set -eu` and a real
  `chmod 0600`.
- `scripts/install-token.sh` prints only the bare GUID to stdout; all
  chatter goes to stderr so the next JPS action captures a clean token.
- `scripts/deploy-app.sh` mirrors the colour idiom of `scripts/haxiam.sh`
  (`txtbld`/`bldgrn`/`bldred`/`txtreset` + `haxecho`/`haxwarn`) but sends
  to stderr so Jelastic's install-log capture isn't polluted.
