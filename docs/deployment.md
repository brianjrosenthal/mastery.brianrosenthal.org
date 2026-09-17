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
- `VIDEO_STORAGE_PROVIDER`, `R2_*`, `DREAMOBJECTS_*`, `VIDEO_MAX_BYTES` — see step 5.

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

## 5. Video storage (Cloudflare R2)

Videos live in an S3-compatible bucket, not on the server. New uploads go to
Cloudflare R2; DreamHost DreamObjects, the previous provider, is only needed
while videos remain there (see *Migrating from DreamObjects* below).

1. Cloudflare dashboard → *R2 Object Storage* → **Create bucket** named
   `mastery-videos` (leave it private; no public access or custom domain is
   needed, playback uses signed URLs). On the bucket's *Settings* tab copy the
   **S3 API** URL, `https://<account-id>.r2.cloudflarestorage.com/mastery-videos`.
2. *R2 Object Storage* → *Manage R2 API Tokens* → **Create API token**:
   permission *Object Read & Write*, scoped to the `mastery-videos` bucket.
   Copy the *Access Key ID* and *Secret Access Key* (shown once).
3. In `config.local.php`: `VIDEO_STORAGE_PROVIDER = 'r2'`, the S3 API URL as
   `R2_ENDPOINT` (with or without the trailing `/mastery-videos`, both work),
   the two keys as `R2_ACCESS_KEY` / `R2_SECRET_KEY`, and
   `R2_VIDEO_BUCKET = 'mastery-videos'`. Leave `R2_REGION` unset (`auto`).
4. Admin → Video Storage: the Cloudflare R2 card should read *Ready*. Click
   **Apply CORS for all site origins** (R2 accepts the same S3 CORS rule as
   DreamObjects; it is what lets a browser on each site PUT directly to the
   bucket) and re-apply it whenever a domain is added. **Create bucket** is
   there too if you skipped step 1.
5. Click **Test upload** on the same page; it should report success against
   Cloudflare R2.
6. Upload a test video from a concept editor and play it on the public page.

How it stays secure: the secret key never leaves the server. For each upload,
PHP signs a URL that authorizes one PUT to one object key for 15 minutes;
after the upload PHP checks the object's type and size before recording it.
Objects stay **private** (R2 buckets are private unless you enable public
access; DreamObjects rejects canned ACLs); the public site plays them through
presigned GET URLs whose timestamp is rounded down to a 6-hour window, so
browsers can cache the video, and which stay valid for 24 hours
(`VIDEO_URL_WINDOW_SECONDS` / `VIDEO_URL_TTL_SECONDS`). Deleting a concept or
replacing its video deletes the object from whichever provider holds it. R2
charges nothing for egress, so playback traffic is free.

**Test upload** on Admin → Video Storage performs the whole cycle from the
server (presigned PUT, HEAD, presigned GET, delete) and prints the raw storage
response, so any misconfiguration shows up there before a kid hits it.

### Migrating from DreamObjects

Each concept records which provider holds its video (`concepts.video_storage`,
added by migration `002`), so R2 and DreamObjects can be configured at the same
time: old videos keep playing from DreamObjects while new ones go to R2.

1. Deploy, then apply migration `002_concept_video_storage.sql` (Admin →
   Migrations or `bash www/db_migrations/migrate.sh`). It backfills every
   existing video as held in `dreamobjects`.
2. Keep the `DREAMOBJECTS_*` keys in `config.local.php` for now; add the R2
   settings and set `VIDEO_STORAGE_PROVIDER = 'r2'` (steps 1–5 above).
3. Copy the videos across. From a shell on the server (no request time limit;
   each video streams through a temp file, so `/tmp` needs room for the
   largest one):

   ```
   php deploy/migrate-videos.php --dry-run   # what would be copied
   php deploy/migrate-videos.php             # copy, verify size, switch each concept over
   ```

   Every concept is switched to R2 only after its copy is confirmed with the
   same size, and the run can be interrupted and repeated. For a handful of
   small videos, **Migrate next video** on Admin → Video Storage does one per
   click instead. (Bulk-copying the bucket with `rclone` first also works: the
   script then finds each copy already present and only flips the rows.)
4. Play a couple of migrated videos on the public site.
5. The originals are still in DreamObjects and now show as *Orphans* on that
   provider's card: **Delete orphans** removes them (or run the script with
   `--delete-source` in step 3 to delete each original as it is verified).
6. Once the DreamObjects card shows no videos recorded and no objects, delete
   the `DREAMOBJECTS_*` lines from `config.local.php` and close the DreamObjects
   account.

## 6. Smoke test after deploying

- `https://mastery.brianrosenthal.org/` → login page; sign in → `/manage/`.
- Admin → Users → add Charlie; his site is created automatically. Admin →
  Sites → Settings → set domain `mastery.charlierosenthal.org`.
- `https://mastery.charlierosenthal.org/` → Charlie's homepage (404 page
  "This site is not public yet" if he unticked *public*).
- Charlie signs in at `https://mastery.charlierosenthal.org/login.php` and sees
  the owner bar on his own pages.
