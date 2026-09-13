# Deploying Mastery on the DreamHost VPS

Three hostnames, one directory, one database:

| Hostname | What it shows |
|---|---|
| `mastery.brianrosenthal.org` | Login, the authoring area (`/manage/`), admin. Also every user's site at `/site/{slug}/`. |
| `mastery.charlierosenthal.org` | Charlie's public site at `/` (and `/login.php`, `/manage/` still work here). |
| `mastery.lillyrosenthal.org` | Lilly's public site at `/`. |

PHP decides which site to render from the `Host` header (`www/lib/SiteResolver.php`),
so all three hostnames must simply reach the same document root.

## Can DreamHost route several domains to one directory?

Yes.

- **On the self-managed VPS (what we use):** Apache does it with `ServerAlias`.
  One `<VirtualHost>` lists all three names and one `DocumentRoot`. The
  `.htaccess` in `www/` cannot do this part (it only runs after Apache has
  already chosen a vhost); it handles the pretty-URL rewrites and security
  denies. See `deploy/apache-vhost.conf.example`.
- **On DreamHost shared/panel hosting (for reference):** in the panel, add each
  domain under *Websites → Manage Websites* as a fully hosted domain and set its
  *Web directory* to the same folder (e.g. `/home/user/mastery.brianrosenthal.org`).
  Alternatively add the kid domains as *Mirror* domains of the main one. Either
  way the app then works identically, because it only looks at the hostname.

## 1. DNS

For each hostname create an `A` record pointing at the VPS IP (and `AAAA` if
the VPS has IPv6). The kid domains live in their own DreamHost DNS zones; add
the `mastery` subdomain there.

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

## 4. Apache

```bash
sudo cp deploy/apache-vhost.conf.example /etc/apache2/sites-available/mastery.conf
sudo nano /etc/apache2/sites-available/mastery.conf   # fix DocumentRoot / paths
sudo a2enmod rewrite
sudo a2ensite mastery
sudo apache2ctl configtest && sudo systemctl reload apache2
sudo certbot --apache -d mastery.brianrosenthal.org -d mastery.charlierosenthal.org -d mastery.lillyrosenthal.org
```

`AllowOverride All` is required (the rewrites live in `www/.htaccess`).
PHP needs `curl`, `mbstring`, `pdo_mysql` and `iconv`. **No upload limits need
raising**: video bytes never pass through PHP.

Adding another kid's domain later: add it to `ServerAlias`, re-run certbot with
the extra `-d`, add the DNS record, set the domain in Admin → Sites → Settings,
then Admin → Video Storage → *Apply CORS for all site origins*.

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
