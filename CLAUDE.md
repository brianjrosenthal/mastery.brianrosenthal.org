See ALL FILES in the docs/ directory.

## Local development

- Create a MySQL database `mastery_brianrosenthal` and load `www/schema.sql` into it.
- Copy `www/config.local.php.example` to `www/config.local.php` and fill in DB credentials (Cloudflare R2 keys are optional locally; video upload is disabled without them).
- Run: `php -S localhost:8080 -t www deploy/dev-router.php` (the router mimics `.htaccess` so pretty URLs like `/site/charlie/algebra-ii/` work).
- Sign in with the seeded admin: email `brian.rosenthal@gmail.com`, password `mastery` (change it after first login).
- A user's site is viewed at `/site/{slug}/` on any host, or at `/` on its custom domain (`sites.domain`).
- Tests: `php unit-tests/tools/phpunit.phar -c unit-tests/phpunit.xml` (drops/recreates `mastery_brianrosenthal_test` from `www/schema.sql` on every run; storage is faked).
- When the database changes, update `www/schema.sql` to the complete current state AND add a migration in `www/db_migrations/` (`NNN_description.sql`). Production applies migrations from Admin → Migrations or `bash www/db_migrations/migrate.sh`.
- Deployment (DreamHost VPS, three hostnames on one directory, Cloudflare R2 bucket + CORS; migrating videos off DreamObjects): `docs/deployment.md`.
