# Mastery — application spec (what the app does today)

Mastery is a PHP/MySQL site where Brian's kids publish "teach it back" content.
Once they have learned something they record a short video explaining it, write
a description and attach supporting links. One database powers the
admin/authoring site (mastery.brianrosenthal.org) and a public, branded site per
kid (mastery.charlierosenthal.org, mastery.lillyrosenthal.org). No framework, no
build step. It follows the conventions in docs/php-guidelines.md throughout
(PDO only, SQL only inside lib/* management classes, every write takes a
UserContext and is activity-logged, `page.php` + `page_eval.php` pairing, CSRF
on all POSTs, dedicated single-purpose AJAX endpoint files).

## Content model

Per user: **Categories** ("Algebra II") → **Subcategories** ("Sequences and
Series") → **Concepts** ("Derivation of e^x"). A concept has a video (in
DreamObjects), a Markdown description, supporting links, and a published flag.
Categories and subcategories have a name, URL slug, Markdown description and a
sort order. Each user has one **Site**: title, tagline, homepage Markdown,
accent colour, public flag, a path slug and an optional custom domain.

## The public site (what a visitor sees)

- Served at `/site/{slug}/…` on any host, or at `/…` on the site's custom
  domain. `SiteResolver` decides from the `?site=` rewrite parameter or the
  `Host` header. Pretty URLs are rewritten by `www/.htaccess`
  (`deploy/dev-router.php` locally) to `public_site.php`.
- **Home**: hero with title and tagline, the homepage Markdown, then a card per
  category (published-concept count, teaser). Header nav lists the categories.
- **Category**: description, then one section per subcategory listing its
  concepts (so both subcategory links and direct concept links are present).
- **Subcategory**: description and the concept list.
- **Concept**: breadcrumb, 16:9 video player, description, resources,
  previous/next within the subcategory.
- Only published concepts are shown, and categories/subcategories with no
  published concept are hidden. Markdown is rendered with Parsedown in safe
  mode (raw HTML escaped). Each site picks an accent colour from a fixed
  palette (`SiteManagement::ACCENTS`), applied as CSS variables in `site.css`.

## Editing in context (owner or admin, signed in)

Every public page shows a dark **owner bar** with page-specific actions: edit
this page, add a category/subcategory/concept here, publish/unpublish,
delete (only when empty; disabled otherwise), Manage, Log out. Drafts and empty
containers are visible to the owner with a Draft badge. Actions carry `next=`
back to the public page so saving returns there.

## Authoring area (`/manage/`)

- **Dashboard**: the whole tree with publish/video status and per-node
  actions; "View site" and "Site settings". Admins get a user switcher
  (`?user_id=`) to manage anyone's site. A user without a site can create one.
- **Site settings**: title, tagline, homepage Markdown (with Preview), accent
  colour, public toggle. Slug and custom domain are admin-only fields.
- **Category / subcategory add & edit**: name, optional slug (generated from
  the name, de-duplicated, reserved names refused), Markdown description,
  order. Delete is offered only when empty.
- **Concept add**: title, slug, description, supporting links, publish-now;
  then the editor opens for the video.
- **Concept editor**: video panel (upload a file with drag-and-drop, or record
  in the browser with camera+mic via MediaRecorder; progress bar; remove),
  publish/unpublish, details, links, delete.
- Failed forms come back pre-filled (long Markdown is round-tripped through
  the session, `ManageUI::stashForm`).

### Video upload flow (`manage/video.js`)

1. Browser POSTs `{concept_id, content_type, size}` + CSRF to
   `video_presign_eval.php`; the server checks ownership, type and size and
   returns a presigned PUT URL for a fresh key `videos/{user}/{concept}/{random}.{ext}`.
2. Browser PUTs the Blob straight to DreamObjects (XHR, so upload progress is
   observable). No ACL header: DreamObjects rejects canned ACLs, so objects
   stay private.
3. Browser POSTs the key to `concept_video_attach_eval.php`, which HEADs the
   object to verify it, records it on the concept, deletes the previous
   object, and returns the refreshed video panel as an HTML fragment.

The secret key never leaves the server; a presigned URL authorizes exactly one
key for 15 minutes. The bucket's CORS rule (applied from Admin → Video Storage)
is limited to the site origins. Playback uses presigned GET URLs
(`VideoStorage::playbackUrlFor`) whose timestamp is quantized to a 6-hour
window (cacheable, byte-identical for every viewer in the window) with a
24-hour lifetime.

## What an admin can do (Admin dropdown)

- **Users**: admin-created accounts only, with an activation email; a site is
  created automatically for each new user. Edit/delete users, resend
  activation, send password reset.
- **Sites**: every site with its path and domain; links to its settings (where
  slug/domain are set) and its content.
- **Settings**: site title, time zone, site URL (used in email links).
- **Video Storage**: credentials check, create bucket, apply CORS for all site
  origins, test upload (full PUT/HEAD/GET/delete cycle with the raw storage
  response), bucket vs database reconciliation, delete orphans.
- **Migrations**: `db_migrations/*.sql` with applied status from
  `schema_migrations`; apply pending ones. `db_migrations/migrate.sh` does the
  same from a shell.
- **Activity Log** / **Email Log**: filtered, paginated views.

## Data model

`www/schema.sql` is the complete, standalone truth. Tables: `users`,
`settings`, `activity_log`, `emails_sent`, `schema_migrations` (infrastructure
per the guidelines), `sites` (1:1 with users), `categories` (user_id),
`subcategories` (category_id), `concepts` (subcategory_id, video_* columns),
`concept_resources` (concept_id, cascade). Deleting is RESTRICTed upward so
only empty categories/subcategories (and users without content) can be
deleted.

## Code layout (web root = www/)

- `config.php`, `config.local.php(.example)`, `settings.php`, `partials.php`,
  `mailer.php` — bootstrap, secrets, settings, escaping/chrome helpers, SMTP.
- `index.php` (front door: custom-domain home or redirect), `login*.php`,
  `logout.php`, `forgot_password*`, `reset_password*`, `set_password*`,
  `verify_email.php`, `profile/`.
- `public_site.php` — router for the public sites; `site.css` its theme.
- `manage/` — authoring pages and AJAX endpoints; `manage.js`, `video.js`.
- `admin/` — users, sites, settings, video storage, migrations, logs.
- `lib/` — `Application`, `ApplicationUI` (authoring chrome), `SiteUI`
  (public chrome + fragments), `SitePages` (public page renderers),
  `ManageUI`, `SiteResolver`, `SiteManagement`, `CategoryManagement`,
  `SubcategoryManagement`, `ConceptManagement`, `ContentAccess`, `Slugger`,
  `MarkdownRenderer` + vendored `Parsedown`, `DreamObjects` (SigV4 client),
  `VideoStorage`, `MigrationRunner`, `UserContext`, `UserManagement`,
  `ActivityLog`, `EmailLog`.
- `db_migrations/` — `NNN_description.sql`, `migrate.sh`, README.
- `styles.css`, `main.js` — authoring/admin theme (kanchess colour scheme).

## Development & deployment

- Local: see CLAUDE.md. Tests: `php unit-tests/tools/phpunit.phar -c unit-tests/phpunit.xml`
  (real `_test` database rebuilt from schema.sql; storage faked by
  `unit-tests/tests/Support/FakeDreamObjects.php`). No endpoint or UI tests, per
  the guidelines.
- Production: DreamHost VPS, one Apache vhost with `ServerAlias` for all
  hostnames, DreamObjects bucket with CORS. Full runbook in docs/deployment.md.

## Original request (historical)

See docs/spec.md.
