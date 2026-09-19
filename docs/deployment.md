# Deploying Kids That Teach on the DreamHost VPS

One directory, one database, on a panel-managed DreamHost VPS (no root;
everything is done from the DreamHost panel and a shell as the site user),
with Cloudflare in front of `kidsthatteach.org` for the wildcard subdomains.

| Hostname | What it shows | Who serves it |
|---|---|---|
| `kidsthatteach.org` | Login, the authoring area (`/manage/`), admin. Also every user's site at `/site/{slug}/`. | DreamHost (DNS-only record, Let's Encrypt from the panel) |
| `{slug}.kidsthatteach.org` | That user's public site at `/` (and `/login.php`, `/manage/` work here too). Automatic for every site. | Cloudflare Worker → DreamHost (`deploy/cloudflare-worker/`) |
| `mastery.charlierosenthal.org` (a custom domain) | That user's public site at `/`. Optional, one panel entry per domain. | DreamHost |
| `mastery.brianrosenthal.org` (former main host) | 301 redirect to the same path on `kidsthatteach.org`. | DreamHost |

PHP decides which site to render from the hostname (`www/lib/SiteResolver.php`,
using `request_host()` in `www/config.php`), so every hostname must simply
reach the same document root. The subdomains are the one exception: DreamHost's
managed hosting cannot host a wildcard domain (wildcard DNS is a support
ticket and wildcard certificates are unsupported), so Cloudflare answers for
`*.kidsthatteach.org` and a Worker forwards each request to the main host with
the visitor's hostname in `X-Forwarded-Host` plus a shared secret.

## 1. Cloudflare (Free plan)

The account that already holds the R2 bucket.

1. *Add a domain* → `kidsthatteach.org`, Free plan. Change the nameservers at
   the registrar to the two Cloudflare gives you (already done if the domain
   was registered with Cloudflare).
2. **DNS**:
   - `A  kidsthatteach.org → <VPS IP>` — **DNS only** (grey cloud) so
     DreamHost's Let's Encrypt keeps issuing the apex certificate.
   - `A  * → <VPS IP>` — **Proxied** (orange cloud). This is what makes every
     subdomain resolve and get HTTPS.
   - optional `A  www → <VPS IP>`, proxied; the Worker redirects it to the apex.
3. **SSL/TLS → Overview**: encryption mode **Full (strict)**. Universal SSL
   (on by default) covers `kidsthatteach.org` and `*.kidsthatteach.org`.
4. **Worker**: follow `deploy/cloudflare-worker/README.md` — paste
   `worker.js`, set the `ORIGIN_HOST` variable and `PROXY_KEY` secret, add the
   route `*.kidsthatteach.org/*`.

Custom kid domains (`mastery.charlierosenthal.org`) do not go through
Cloudflare at all: their DNS points straight at the VPS as before.

## 2. DreamHost panel

Each domain in the panel has its own *Web directory*; several domains may
point at the same one. Do NOT symlink a second domain's directory to the main
one: Apache and Let's Encrypt are configured from the panel's directory
setting, so change that setting instead.

1. *Websites → Manage Websites → Add Website* for `kidsthatteach.org`.
   Choose **Fully hosted** (a *Mirror* domain cannot get HTTPS). Set **Web
   directory** to `/home/brosenthvps/mastery.brianrosenthal.org` (the directory
   holding the contents of the repo's `www/`; its name on disk does not
   matter and is left as it was). Same PHP version as before. Enable **Let's
   Encrypt** and the HTTPS-only redirect. Leave *Add WWW* off (www is handled
   by Cloudflare and the Worker).
2. Keep the existing `mastery.brianrosenthal.org` entry: the app redirects it.
3. For each custom kid domain (`mastery.charlierosenthal.org`), the same
   steps: fully hosted, shared web directory, Let's Encrypt. If the domain's
   DNS is not at DreamHost, add the `A` record the panel shows.

Nothing on disk changes: one copy of the files, one `config.local.php`, one
`.htaccess`. The app tells the sites apart by hostname.

### Self-managed VPS (root access) instead

Apache does it with `ServerAlias`: one `<VirtualHost>` lists all the names
and one `DocumentRoot`. See `deploy/apache-vhost.conf.example`. The Worker
setup is the same; a self-managed box could alternatively terminate the
wildcard itself with certbot's DNS challenge, which the panel cannot do.

## 3. Files on the server

Everything runs as one Linux user:

```
~/mastery.brianrosenthal.org/        # DocumentRoot = a copy of the repo's www/
~/mastery.brianrosenthal.org/config.local.php   # secrets, never in git
~/mastery.brianrosenthal.org/logs/   # writable by Apache's user
```

Deploy with rsync (`deploy/deploy.sh.example` → copy to `deploy/deploy.sh`),
which never overwrites `config.local.php` or logs.

`config.local.php` values that matter in production (see
`www/config.local.php.example` for every option):

- `DB_*` — the MySQL database (DreamHost's MySQL host, e.g. `mysql.brianrosenthal.org`).
- `APP_NAME = 'Kids That Teach'`, `SMTP_FROM_NAME`.
- `MAIN_HOST = 'kidsthatteach.org'` — the main site; every site is also
  `{slug}.MAIN_HOST`, and a login on the main host is shared with the
  subdomains (the session cookie's Domain is `MAIN_HOST` there).
- `LEGACY_HOSTS = ['mastery.brianrosenthal.org']` — hostnames that 301 to `MAIN_HOST`.
- `SUBDOMAIN_PROXY_KEY` — the Worker's `PROXY_KEY`; empty means the forwarded
  hostname is ignored and subdomains stop resolving.
- `SMTP_*` — for activation and password-reset emails. Links in emails use the
  `site_base_url` setting (Admin → Settings), so they always point at the main host.
- `REMEMBER_TOKEN_KEY` — a long random string.
- `SUPER_PASSWORD` — leave `''` in production.
- `VIDEO_STORAGE_PROVIDER`, `R2_*`, `DREAMOBJECTS_*`, `VIDEO_MAX_BYTES` — see step 5.

## 4. Database

First install:

```bash
mysql -h mysql.brianrosenthal.org -u USER -p -e 'CREATE DATABASE mastery_brianrosenthal CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci'
mysql -h mysql.brianrosenthal.org -u USER -p mastery_brianrosenthal < www/schema.sql
```

Later releases: `bash ~/mastery.brianrosenthal.org/db_migrations/migrate.sh`
(or Admin → Migrations in the browser). Both record applied files in
`schema_migrations`, so they can be mixed freely. The rebrand release needs
`003_rebrand_kidsthatteach.sql`, which updates the seeded site title and
`site_base_url`; check Admin → Settings afterwards.

Change the seeded admin password immediately (profile menu → Change Password).

`.htaccess` rewrites are honoured by default on panel-managed hosting. This
app needs no `phprc` tweaks: video bytes never pass through PHP. PHP needs
`curl`, `mbstring`, `pdo_mysql` and `iconv`.

## Adding another kid later

Nothing. Add the user in Admin → Users; their site is created with a slug from
their first name and is live at `{slug}.kidsthatteach.org` immediately (the
storage CORS rule is refreshed automatically so they can upload from there).

Only a **custom domain** needs manual steps:

1. Panel: add the domain as fully hosted with the shared web directory and
   Let's Encrypt (or, self-managed: `ServerAlias` + certbot with the extra `-d`).
2. Admin → Sites → that site's Settings → Routing → Custom domain (saving
   re-applies CORS; Admin → Video Storage shows whether it worked).

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
   bucket). The rule lists the main host, every site's subdomain and every
   custom domain, and is re-applied automatically when a site is created or
   its routing changes; re-apply here if that ever fails. **Create bucket** is
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

- `https://kidsthatteach.org/` → login page; sign in → `/manage/`.
- `https://www.kidsthatteach.org/` → redirects to the apex.
- `https://mastery.brianrosenthal.org/manage/` → 301 to
  `https://kidsthatteach.org/manage/`.
- Admin → Users → add Milton; his site is created automatically.
  `https://milton.kidsthatteach.org/` → Milton's homepage (404 page
  "This site is not public yet" if he unticked *public*). Being signed in on
  the apex, you already see the owner bar there.
- Admin → Sites → Charlie's Settings → set custom domain
  `mastery.charlierosenthal.org` (after the panel entry). Both
  `https://mastery.charlierosenthal.org/` and
  `https://charlie.kidsthatteach.org/` show his site.
- Charlie signs in at `https://charlie.kidsthatteach.org/login.php` and sees
  the owner bar on his own pages; from a concept editor there, upload a video
  and play it back (this exercises the subdomain's CORS origin).
