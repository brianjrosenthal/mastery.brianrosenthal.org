# Deploying Mastery on the DreamHost VPS

Three hostnames, one directory, one database, on a panel-managed DreamHost
VPS (no root; everything is done from the DreamHost panel and a shell as the
site user):

| Hostname | What it shows |
|---|---|
| `mastery.brianrosenthal.org` | Login, the authoring area (`/manage/`), admin. Also every user's site at `/site/{slug}/`. |
| `mastery.charlierosenthal.org` | Charlie's public site at `/` (and `/login.php`, `/manage/` still work here). |
| `mastery.lillyrosenthal.org` | Lilly's public site at `/`. |

PHP decides which site to render from the `Host` header (`www/lib/SiteResolver.php`),
so all three hostnames must simply reach the same document root.

## Can DreamHost route several domains to one directory?

Yes. Which way depends on whether you have root on the box.

### Panel-managed hosting (shared, or a VPS without sudo) — what we use

Each domain in the panel has its own *Web directory*; several domains may
point at the same one. Do NOT symlink `~/mastery.charlierosenthal.org` to the
main directory: Apache and Let's Encrypt are configured from the panel's
directory setting, so change that setting instead.

1. *Websites → Manage Websites → Add Website* for `mastery.charlierosenthal.org`.
   Choose **Fully hosted** (a *Mirror* domain cannot get HTTPS).
2. Set **Web directory** to `/home/USER/mastery.brianrosenthal.org` (the
   directory holding the contents of the repo's `www/`). Same PHP version as
   the main site.
3. Enable **Let's Encrypt** and the HTTPS-only redirect.
4. If the domain's DNS is not at DreamHost, add the `A` record the panel shows.

Nothing on disk changes: one copy of the files, one `config.local.php`, one
`.htaccess`. The app tells the sites apart by hostname.

### Self-managed VPS (root access)

Apache does it with `ServerAlias`: one `<VirtualHost>` lists all the names
and one `DocumentRoot`. See `deploy/apache-vhost.conf.example` and section 4b.

## 1. DNS

For each hostname create an `A` record pointing at the server IP (and `AAAA`
if it has IPv6). With panel-managed hosting DreamHost adds this for you when
the domain's DNS is hosted there.

## 2. Files on the server

Following the hackleyclubz layout, everything runs as one Linux user:

```
~/mastery.brianrosenthal.org/        # DocumentRoot = a copy of the repo's www/
~/mastery.brianrosenthal.org/config.local.php   # secrets, never in git
~/mastery.brianrosenthal.org/logs/   # writable by Apache's user
```

Deploy with rsync (`deploy/deploy.sh.example` → copy to `deploy/deploy.sh`),
which never overwrites `config.local.php` or logs.

`config.local.php` values that matter in production:

- `DB_*` — the MySQL database (DreamHost's MySQL host, e.g. `mysql.brianrosenthal.org`).
- `APP_NAME`, `MAIN_HOST = 'mastery.brianrosenthal.org'`.
- `SMTP_*` — for activation and password-reset emails. Links in emails use the
  `site_base_url` setting (Admin → Settings), so they always point at the main host.
- `REMEMBER_TOKEN_KEY` — a long random string.
- `SUPER_PASSWORD` — leave `''` in production.
- `DREAMOBJECTS_*`, `VIDEO_MAX_BYTES` — see step 5.

## 3. Database

First install:

```bash
mysql -h mysql.brianrosenthal.org -u USER -p -e 'CREATE DATABASE mastery_brianrosenthal CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci'
mysql -h mysql.brianrosenthal.org -u USER -p mastery_brianrosenthal < www/schema.sql
```

Later releases: `bash ~/mastery.brianrosenthal.org/db_migrations/migrate.sh`
(or Admin → Migrations in the browser). Both record applied files in
`schema_migrations`, so they can be mixed freely.

Change the seeded admin password immediately (profile menu → Change Password).

## 4. Web server

### 4a. Panel-managed hosting

Nothing to do beyond the panel steps above. `.htaccess` rewrites are honoured
by default. If uploads of the site's PHP settings are ever needed they go in a
`phprc` file, but this app needs none: video bytes never pass through PHP.

### 4b. Self-managed VPS

```bash
sudo cp deploy/apache-vhost.conf.example /etc/apache2/sites-available/mastery.conf
sudo nano /etc/apache2/sites-available/mastery.conf   # fix DocumentRoot / paths
sudo a2enmod rewrite
sudo a2ensite mastery
sudo apache2ctl configtest && sudo systemctl reload apache2
sudo certbot --apache -d mastery.brianrosenthal.org -d mastery.charlierosenthal.org -d mastery.lillyrosenthal.org
```

`AllowOverride All` is required (the rewrites live in `www/.htaccess`).
PHP needs `curl`, `mbstring`, `pdo_mysql` and `iconv`.

## Adding another kid's domain later

1. Panel: add the domain as fully hosted with the shared web directory and
   Let's Encrypt (or, self-managed: `ServerAlias` + certbot with the extra `-d`).
2. Admin → Sites → that site's Settings → Routing → Custom domain.
3. Admin → Video Storage → *Apply CORS for all site origins*.

## 5. Video storage (DreamObjects)

1. DreamHost panel → *Cloud Services → DreamObjects*. Create a user (or reuse
   one) and note its access key and secret key.
2. Put them in `config.local.php` as `DREAMOBJECTS_ACCESS_KEY` /
   `DREAMOBJECTS_SECRET_KEY`, with `DREAMOBJECTS_ENDPOINT` /
   `DREAMOBJECTS_REGION` for your cluster (e.g.
   `https://objects-us-east-1.dream.io` / `us-east-1`) and a globally unique
   `DREAMOBJECTS_VIDEO_BUCKET` name.
3. In the app: Admin → Video Storage → **Create bucket**, then **Apply CORS for
   all site origins**. The CORS rule is what lets a browser on each site PUT
   directly to the bucket; re-apply it whenever a domain is added.
4. Click **Test upload** on the same page; it should report success.
5. Upload a test video from a concept editor and play it on the public page.

How it stays secure: the secret key never leaves the server. For each upload,
PHP signs a URL that authorizes one PUT to one object key for 15 minutes;
after the upload PHP checks the object's type and size before recording it.
Objects stay **private** (DreamObjects rejects canned ACLs such as
`public-read`); the public site plays them through presigned GET URLs whose
timestamp is rounded down to a 6-hour window, so browsers can cache the video,
and which stay valid for 24 hours (`VIDEO_URL_WINDOW_SECONDS` /
`VIDEO_URL_TTL_SECONDS`). Deleting a concept or replacing its video deletes
the object.

**Test upload** on Admin → Video Storage performs the whole cycle from the
server (presigned PUT, HEAD, presigned GET, delete) and prints the raw storage
response, so any misconfiguration shows up there before a kid hits it.

## 6. Smoke test after deploying

- `https://mastery.brianrosenthal.org/` → login page; sign in → `/manage/`.
- Admin → Users → add Charlie; his site is created automatically. Admin →
  Sites → Settings → set domain `mastery.charlierosenthal.org`.
- `https://mastery.charlierosenthal.org/` → Charlie's homepage (404 page
  "This site is not public yet" if he unticked *public*).
- Charlie signs in at `https://mastery.charlierosenthal.org/login.php` and sees
  the owner bar on his own pages.
