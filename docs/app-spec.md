# Kids That Teach — application spec (what the app does today)

Kids That Teach (kidsthatteach.org, formerly "Mastery" at
mastery.brianrosenthal.org) is a PHP/MySQL site where kids publish "teach it
back" content. Once they have learned something they record a short video
explaining it, write a description and attach supporting links. One database
powers the admin/authoring site (kidsthatteach.org) and a public, branded site
per kid, served automatically at {slug}.kidsthatteach.org and optionally at a
custom domain (mastery.charlierosenthal.org). No framework, no build step. It follows the conventions in docs/php-guidelines.md throughout
(PDO only, SQL only inside lib/* management classes, every write takes a
UserContext and is activity-logged, `page.php` + `page_eval.php` pairing, CSRF
on all POSTs, dedicated single-purpose AJAX endpoint files).

## Content model

Per user: **Categories** ("Algebra II") → **Subcategories** ("Sequences and
Series") → **Concepts** ("Derivation of e^x"). A concept has a video (in
object storage: Cloudflare R2, or DreamHost DreamObjects for videos not yet
migrated), a Markdown description, supporting links, and a published flag.
A concept also collects **Questions** from signed-in visitors; each question
holds at most one answer by the owner: Markdown text and/or an answer video
(same storage as concept videos).
Categories and subcategories have a name, URL slug, Markdown description and a
sort order. Each user has one **Site**: title, tagline, homepage Markdown,
accent colour, public flag, a path slug and an optional custom domain.

## The public site (what a visitor sees)

- Served at `/site/{slug}/…` on any host, at `/…` on the site's own subdomain
  `{slug}.MAIN_HOST`, or at `/…` on the site's custom domain. `SiteResolver`
  decides from the `?site=` rewrite parameter or the request hostname
  (`request_host()` in `config.php`, which honours `X-Forwarded-Host` from the
  subdomain Worker in `deploy/cloudflare-worker/` when its shared secret
  matches `SUBDOMAIN_PROXY_KEY`). The canonical address is the custom domain,
  else the subdomain. Former main hostnames (`LEGACY_HOSTS`) get a 301 to the
  main host from `Application::init()`. Pretty URLs are rewritten by
  `www/.htaccess` (`deploy/dev-router.php` locally) to `public_site.php`.
- Sessions and the remember-me cookie carry `Domain=MAIN_HOST` on the main
  host and its subdomains, so one login covers all of them; custom domains
  keep host-only cookies and their own login.
- **Home**: hero with title and tagline, the homepage Markdown, then a card per
  category (published-concept count, teaser). Header nav lists the categories.
- **Category**: description, then one section per subcategory listing its
  concepts (so both subcategory links and direct concept links are present).
- **Subcategory**: description and the concept list.
- **Concept**: breadcrumb, 16:9 video player, description, resources, the
  Questions section, previous/next within the subcategory. The video
  autoplays on load (`main.js`); when the browser blocks audible autoplay it
  retries muted and overlays a "Tap to unmute" button that restarts the video
  with sound.
- **Questions** (`SiteUI::questionsHtml`): any signed-in user may ask (a
  plain-text question, up to 2000 characters); anonymous visitors get a
  "Log in to ask a question" link that returns them to the page. A question
  is public once answered; while unanswered only the asker, the owner and
  admins see it (badge "Waiting for an answer"). The site setting
  `questions_public` (default on) hides the whole section from visitors who
  are not signed in. An asker may delete their own unanswered question; the
  owner/admin may delete any. Posting a question emails the owner; the first
  answer emails the asker (`QuestionNotifications`, links prefer the
  subdomain because it shares the main-host login cookie).
- Only published concepts are shown, and categories/subcategories with no
  published concept are hidden. Markdown is rendered with Parsedown in safe
  mode (raw HTML escaped). Each site picks a colour scheme from a fixed
  palette (`SiteManagement::ACCENTS`; Blue is the default, Purple is the
  `violet` key), applied as CSS variables in `site.css` that colour the
  header gradient, hero, links, cards and footer.

## Editing in context (owner or admin, signed in)

Every public page shows a dark **owner bar** with page-specific actions: edit
this page, add a category/subcategory/concept here, publish/unpublish,
delete (only when empty; disabled otherwise), Manage, Log out. Drafts and empty
containers are visible to the owner with a Draft badge. Actions carry `next=`
back to the public page so saving returns there.

Answering happens in place too: under each question the owner sees an
"Answer this question" editor with a Markdown text box and a video panel
(the same upload/record panel as the concept editor, `manage/video.js`),
and can edit or remove either later. "Answered" is derived: set when the
answer first has text or a video, cleared if both are removed.

## Authoring area (`/manage/`)

- **Dashboard**: an "Unanswered questions" card (when there are any) linking
  to each question on its concept page; then the whole tree with
  publish/video status and per-node actions; "View site" and "Site settings". Admins get a user switcher
  (`?user_id=`) to manage anyone's site. A user without a site can create one.
- **Site settings**: title, tagline, homepage Markdown (with Preview), colour
  scheme (swatch picker), public toggle, "questions are visible to visitors"
  toggle. Slug and custom domain are admin-only
  fields; a custom domain under `MAIN_HOST` is refused since subdomains are
  automatic. Slugs that would shadow the domain's own hostnames (`www`,
  `mail`, …) are reserved (`Slugger::RESERVED`).
- **Category / subcategory add & edit**: name, optional slug (generated from
  the name, de-duplicated, reserved names refused), Markdown description,
  order. Delete is offered only when empty. The subcategory editor also lists
  its concepts (drafts included) with public link, published and video
  badges, and Edit / Add concept links that return to the editor.
- **Concept add**: title, slug, description, supporting links, publish-now;
  then the editor opens for the video.
- **Concept editor**: video panel (upload a file with drag-and-drop, or record
  in the browser with camera+mic via MediaRecorder; progress bar; remove),
  a Questions card listing every question on the concept with the same
  answer editor as the public page (Markdown with Preview, answer video
  panel, delete), publish/unpublish, details, links, delete.
- Failed forms come back pre-filled (long Markdown is round-tripped through
  the session, `ManageUI::stashForm`).

### Video upload flow (`manage/video.js`)

The panel (`ManageUI::videoUploadPanelHtml`) is self-describing: data
attributes name its presign and attach endpoints and the id field, so a page
can hold several (one per question being answered). Concept videos and
answer videos follow the same three steps against parallel endpoints
(`video_presign_eval.php` / `concept_video_attach_eval.php` for concepts,
`answer_video_presign_eval.php` / `answer_video_attach_eval.php` for
answers); answer keys live in their own namespace
`videos/{user}/answers/{question}/{random}.{ext}` so a key can never be
attached to the wrong kind of row.

1. Browser POSTs `{concept_id, content_type, size}` + CSRF to
   `video_presign_eval.php`; the server checks ownership, type and size and
   returns a presigned PUT URL for a fresh key `videos/{user}/{concept}/{random}.{ext}`.
2. Browser PUTs the Blob straight to the active storage provider (Cloudflare
   R2; `VIDEO_STORAGE_PROVIDER`) with XHR, so upload progress is observable.
   No ACL header: objects stay private (R2 default; DreamObjects rejects
   canned ACLs).
3. Browser POSTs the key to `concept_video_attach_eval.php`, which HEADs the
   object to verify it, records it on the concept together with the provider
   (`concepts.video_storage`), deletes the previous object from whichever
   provider held it, and returns the refreshed video panel as an HTML fragment.

The secret key never leaves the server; a presigned URL authorizes exactly one
key for 15 minutes. The bucket's CORS rule (applied from Admin → Video Storage, and refreshed
automatically when a site is created or its routing changes) is limited to
the site origins: the main host, every site's subdomain and every custom
domain (`SiteManagement::listPublicHosts()`). Playback uses presigned GET URLs against the
provider that holds the video (`VideoStorage::playbackUrlForConcept`) whose
timestamp is quantized to a 6-hour window (cacheable, byte-identical for every
viewer in the window) with a 24-hour lifetime.

### Storage providers and migration

Storage diagnostics (orphans, missing objects) and migration cover both
tables: `VideoMigration::listRecordedObjectKeys` and `pending()` union
concepts and `concept_questions`, and `migratePending()` flips whichever row
holds the video. `VideoStorage` knows two providers, `r2` and `dreamobjects`, each configured by
its own `{R2,DREAMOBJECTS}_ENDPOINT/_ACCESS_KEY/_SECRET_KEY/_VIDEO_BUCKET`
constants and served by its own `S3Client` (one hand-rolled SigV4 client for
both, since both speak S3). Videos uploaded before the move to R2 are still in
DreamObjects; `VideoMigration` copies a concept's video into the active
provider under the same key, verifies the copy by size, then flips the row.
`deploy/migrate-videos.php` runs that over every pending concept from a shell;
Admin → Video Storage has a one-at-a-time button. Originals are kept until
deleted as orphans.

## What an admin can do (Admin dropdown)

- **Users**: admin-created accounts only, with an activation email; a site is
  created automatically for each new user. Edit/delete users, resend
  activation, send password reset.
- **Sites**: every site with its subdomain, path and custom domain; links to
  its settings (where slug/domain are set) and its content.
- **Settings**: site title, time zone, site URL (used in email links).
- **Video Storage**: one card per provider (R2 active, DreamObjects previous):
  credentials check, create bucket, apply CORS for all site origins, test
  upload (full PUT/HEAD/GET/delete cycle with the raw storage response),
  bucket vs database reconciliation, delete orphans; plus the migration
  status and a "Migrate next video" button.
- **Migrations**: `db_migrations/*.sql` with applied status from
  `schema_migrations`; apply pending ones. `db_migrations/migrate.sh` does the
  same from a shell.
- **Activity Log** / **Email Log**: filtered, paginated views.

## Data model

`www/schema.sql` is the complete, standalone truth. Tables: `users`,
`settings`, `activity_log`, `emails_sent`, `schema_migrations` (infrastructure
per the guidelines), `sites` (1:1 with users), `categories` (user_id),
`subcategories` (category_id), `concepts` (subcategory_id, video_* columns),
`concept_resources` (concept_id, cascade), `concept_questions` (concept_id
cascade; asked_by/answered_by SET NULL on user delete; answer_markdown,
answered_at, the same video_* columns as concepts). Deleting is RESTRICTed
upward so only empty categories/subcategories (and users without content)
can be deleted; `ConceptManagement::delete` removes answer videos from
storage before the rows cascade.

## Code layout (web root = www/)

- `config.php`, `config.local.php(.example)`, `settings.php`, `partials.php`,
  `mailer.php` — bootstrap, secrets, settings, escaping/chrome helpers, SMTP.
- `index.php` (front door: custom-domain home or redirect), `login*.php`,
  `logout.php`, `forgot_password*`, `reset_password*`, `set_password*`,
  `verify_email.php`, `profile/`.
- `public_site.php` — router for the public sites; `site.css` its theme.
- `manage/` — authoring pages and AJAX endpoints (including the question
  endpoints posted to from public pages); `manage.js`, `video.js`.
- `admin/` — users, sites, settings, video storage, migrations, logs.
- `lib/` — `Application`, `ApplicationUI` (authoring chrome), `SiteUI`
  (public chrome + fragments), `SitePages` (public page renderers),
  `ManageUI`, `SiteResolver`, `SiteManagement`, `CategoryManagement`,
  `SubcategoryManagement`, `ConceptManagement`, `QuestionManagement`,
  `QuestionNotifications`, `ContentAccess`, `Slugger`,
  `MarkdownRenderer` + vendored `Parsedown`, `S3Client` (SigV4 client),
  `VideoStorage`, `VideoMigration`, `MigrationRunner`, `UserContext`, `UserManagement`,
  `ActivityLog`, `EmailLog`.
- `db_migrations/` — `NNN_description.sql`, `migrate.sh`, README.
- `styles.css`, `main.js` — authoring/admin theme (kanchess colour scheme);
  `video-panel.css` — the upload/record panel, shared by both themes.

## Development & deployment

- Local: see CLAUDE.md. Tests: `php unit-tests/tools/phpunit.phar -c unit-tests/phpunit.xml`
  (real `_test` database rebuilt from schema.sql; storage faked by
  `unit-tests/tests/Support/FakeS3Client.php`, one per provider). No endpoint or UI tests, per
  the guidelines.
- Production: DreamHost managed VPS with one shared web directory for every
  hostname, Cloudflare DNS + a Worker for the `*.kidsthatteach.org`
  subdomains (DreamHost cannot host a wildcard), Cloudflare R2 bucket with
  CORS. Full runbook in docs/deployment.md.

## Origins

The project began (September 2026) as "mastery.brianrosenthal.org": one
database and admin site where Brian's kids, once they have mastered a
concept, must "teach" it by recording a video with a description and links,
and one public site per kid (originally mastery.charlierosenthal.org and
mastery.lillyrosenthal.org, now subdomains of kidsthatteach.org with the
custom domain optional). The brief asked for a site the kid would be proud
of, easy navigation from category to concept with Markdown on every level,
in-context editing from the public site, and deletion only of empty
containers. The user, email-log, activity-log, settings and db-migration
infrastructure was copied from the sibling project vocab.lillyrosenthal.org.
