[![#HAXTheWeb](https://img.shields.io/badge/-HAXTheWeb-999999FF?style=flat&logo=data:image/svg%2bxml;base64,PHN2ZyBpZD0iZmVhMTExZTAtMjEwZC00Y2QwLWJhMWQtZGZmOTQyODc0Njg1IiBkYXRhLW5hbWU9IkxheWVyIDEiIHhtbG5zPSJodHRwOi8vd3d3LnczLm9yZy8yMDAwL3N2ZyIgdmlld0JveD0iMCAwIDE4NC40IDEzNS45NyI+PGRlZnM+PHN0eWxlPi5lMWJjMjAyNS0xODAwLTRkYzItODc4NS1jNDZlZDEwM2Y0OTJ7ZmlsbDojMjMxZjIwO308L3N0eWxlPjwvZGVmcz48cGF0aCBjbGFzcz0iZTFiYzIwMjUtMTgwMC00ZGMyLTg3ODUtYzQ2ZWQxMDNmNDkyIiBkPSJNNzguMDcsODMuNDVWNTVIODYuMnY4LjEzaDE2LjI2djQuMDdoNC4wN1Y4My40NUg5OC40VjY3LjE5SDg2LjJWODMuNDVaIi8+PHBvbHlnb24gcG9pbnRzPSIxNTMuMTMgNjMuNyAxNTMuMTMgNTEuMzkgMTQwLjU0IDUxLjM5IDE0MC41NCAzOS4wOSAxMjcuOTUgMzkuMDkgMTI3Ljk1IDI2Ljc5IDEwMi43OCAyNi43OSAxMDIuNzggMzkuMDkgMTE1LjM2IDM5LjA5IDExNS4zNiA1MS4zOSAxMjcuOTUgNTEuMzkgMTI3Ljk1IDYzLjcgMTQwLjU0IDYzLjcgMTQwLjU0IDc2IDEyNy4zNiA3NiAxMjcuMzYgODguMyAxMTQuNzggODguMyAxMTQuNzggMTAwLjYxIDEwMi4xOSAxMDAuNjEgMTAyLjE5IDExMi45MSAxMjcuMzYgMTEyLjkxIDEyNy4zNiAxMDAuNjEgMTM5Ljk1IDEwMC42MSAxMzkuOTUgODguMyAxNTIuNTQgODguMyAxNTIuNTQgNzYgMTY1LjcyIDc2IDE2NS43MiA2My43IDE1My4xMyA2My43Ii8+PHBvbHlnb24gcG9pbnRzPSIzMy4xMyA2My43IDMzLjEzIDUxLjM5IDQ1LjcyIDUxLjM5IDQ1LjcyIDM5LjA5IDU4LjMxIDM5LjA5IDU4LjMxIDI2Ljc5IDgzLjQ4IDI2Ljc5IDgzLjQ4IDM5LjA5IDcwLjg5IDM5LjA5IDcwLjg5IDUxLjM5IDU4LjMxIDUxLjM5IDU4LjMxIDYzLjcgNDUuNzIgNjMuNyA0NS43MiA3NiA1OC44OSA3NiA1OC44OSA4OC4zIDcxLjQ4IDg4LjMgNzEuNDggMTAwLjYxIDg0LjA3IDEwMC42MSA4NC4wNyAxMTIuOTEgNTguODkgMTEyLjkxIDU4Ljg5IDEwMC42MSA0Ni4zMSAxMDAuNjEgNDYuMzEgODguMyAzMy43MiA4OC4zIDMzLjcyIDc2IDIwLjU0IDc2IDIwLjU0IDYzLjcgMzMuMTMgNjMuNyIvPjwvc3ZnPg==)](https://haxtheweb.org/)

# I AM..
The two most powerful words to follow that which represents you. Your image in the world. Your voice. Your ability to set your own agenda. Not who others say you are. Not who WE are, but who I am. We empower individuals to have their own voice, their own platform, be whoever they need to say they are in the world.

## Code
This is a packaging wrapper for HAXcms to to allow anyone to spawn their own microsite management platform. The intention is that HAXcms microsites can be published to the publishing directory. This directory can define multiple forms of publishing endpoint, all of which are static HAXcms microsites.

This is *not* container based and is instead closer to a WordPress / Drupal concept known as "multi-site" where all of the code is pegged to one code base.

### Advantages
- Easy to maintain, upgrade 1 package in 1 location, everything is upgraded to match
- Upgrade all sites at the same time
- Everything under one directory tree
- Single sign on documented for Azure AD / OpenIDConnect

### Disadvantages
- Scale (theoretically..) however this is mitigated by everything being static files with minimal PHP endpoints
- Security (technically write access to one is write access to all at server level, application manages access)
- Upgrading 1 site means upgrading all sites (some people want them to be locked in time for stability)

## Quick installation
```bash
curl -fsSL https://raw.githubusercontent.com/haxtheweb/HAXiam/master/scripts/install/ubuntu26.04.sh -o ubuntu26.04.sh && sh ubuntu26.04.sh
```

## Installation
There are install scripts for Ubuntu 20, 22, 24, and 26. You can invoke it as follows
```bash
bash scripts/install/ubuntu26.04.sh
```

## Configuration
After installation make sure you review the files created in the _config/ directory. These files are commented heavily as to what they do. You can see the two main ones under `system/boilerplate/systemsetup` but let the system install so that you can modify them in the appropriate location.

## Organization-wide skeletons (shared templates)
HAXiam supports shared skeleton templates for all users through a centralized organization directory:

- Shared org templates: `_iamConfig/skeletons`
- User-private templates remain in each user's own config space

Skeleton resolution order is:

1. `users/<username>/_config/user/skeletons` (private user skeletons)
2. `users/<username>/_config/skeletons` (user instance skeletons)
3. `_iamConfig/skeletons` (organization-wide shared skeletons)
4. `cores/HAXcms-1.x.x/system/coreConfig/skeletons` (core defaults)

This preserves private user-created templates while allowing admins to publish templates to everyone.

### Version-control workflow for shared skeletons
Recommended pattern: manage `_iamConfig/skeletons` as its own Git repository (or a submodule) so teams can push/pull template updates without versioning sensitive `_iamConfig` files.

1. Create a repository for shared skeleton JSON files (example: `haxiam-skeletons`).
2. Clone it into `_iamConfig/skeletons` in your HAXiam deployment.
3. Add/update `*.json` skeleton files, commit, and push.
4. On each HAXiam deployment, run `git pull` in `_iamConfig/skeletons` to deploy template updates for all users.

If you prefer tracking this in the main HAXiam repository, update `.gitignore` carefully so only `_iamConfig/skeletons` is versioned and other `_iamConfig` files remain ignored.

## Vanity domains
A "vanity domain" is a custom domain (e.g. `haxtheweb.org`) that serves a single site out of a HAXiam deployment, instead of being reached via `https://your-iam-domain/<user>/sites/<site>/`. `haxtheweb.org` itself is served this way and has always worked correctly because its vhost follows the pattern below.

### The correct pattern
When adding a new vanity domain, create an Apache vhost whose `DocumentRoot` points **directly at the site's real path** inside `users_sites` (never a separate shallow alias/symlink directory outside of it), and grant `AllowOverride All` on the shared `users_sites` root so the site's own `.htaccess` is honored. For example:
```apache
ServerAdmin webmaster@localhost
ServerName <vanity-domain>
DocumentRoot /var/www/oer/<iam-username>/sites/<site-machine-name>/
SetEnv HAXSITE_BASE_URL /
<Directory /var/www/oer/>
        Options Indexes FollowSymLinks
        Header set Access-Control-Allow-Origin "*"
        AllowOverride All
</Directory>
```
Key points:
- `DocumentRoot` is the real `users_sites/<user>/sites/<site>/` path, not a separate symlinked directory elsewhere on disk (e.g. under a different vhost's docroot). Apache's `<Directory>` matching happens against the canonicalized (symlink-resolved) path, so directives attached to a shallow alias won't apply to the real target.
- `SetEnv HAXSITE_BASE_URL /` tells HAXcms it's being served at the domain root instead of under `/<user>/sites/<site>/`, so the `<base>` tag and routing resolve correctly.
- `AllowOverride All` must be granted on the shared `users_sites` root (not just the leaf site directory), so the site's own `.htaccess` pretty-URL rewrite rules are honored for deep links (e.g. `/some-page`), not just the homepage.

### Generating a vhost stanza
Use `scripts/utilities/vanity-domain-vhost.sh` to print a ready-to-review vhost stanza following this pattern for any existing user/site:
```bash
bash scripts/utilities/vanity-domain-vhost.sh <iam-username> <site-machine-name> <vanity-domain> [ssl-cert-path] [ssl-key-path]
```
This only prints the suggested stanza to the console - it does not write any file or touch your live Apache configuration. Review the output, adjust SSL paths as needed, then add it to your own vhost file (e.g. `/etc/apache2/sites-available/<domain>.conf`) and enable/reload Apache yourself.

After enabling, verify both the homepage and a deep link resolve dynamically (e.g. `https://<domain>/` and `https://<domain>/some-page`).
