# Subdomain Worker

`worker.js` makes `{slug}.kidsthatteach.org` work without any per-site setup
on DreamHost, which cannot host a wildcard domain. Cloudflare answers for
`*.kidsthatteach.org` and the Worker forwards each request to the main host,
passing the visitor's hostname in `X-Forwarded-Host` and a shared secret in
`X-Site-Proxy-Key`. `request_host()` in `www/config.php` trusts the forwarded
hostname only when the secret matches `SUBDOMAIN_PROXY_KEY`.

## One-time setup (Cloudflare dashboard, Free plan)

1. **DNS** (zone `kidsthatteach.org`):
   - `A  kidsthatteach.org  → <DreamHost VPS IP>`  — *DNS only* (grey cloud), so
     DreamHost's Let's Encrypt keeps managing the apex certificate.
   - `A  *                  → <DreamHost VPS IP>`  — *Proxied* (orange cloud).
   - optional `A www → <same IP>`, proxied (the Worker redirects it to the apex).
2. **SSL/TLS → Overview**: encryption mode *Full (strict)*. Universal SSL
   already covers `kidsthatteach.org` and `*.kidsthatteach.org`.
3. **Workers & Pages → Create → Start with Hello World**, name it
   `kidsthatteach-subdomains`, replace the code with `worker.js`, deploy.
4. **Worker → Settings → Variables and Secrets**:
   - variable `ORIGIN_HOST` = `kidsthatteach.org`
   - secret `PROXY_KEY` = a long random string, e.g.
     `php -r 'echo bin2hex(random_bytes(32));'`
5. **Worker → Settings → Domains & Routes → Add route**:
   route `*.kidsthatteach.org/*`, zone `kidsthatteach.org`.
6. On the server, set `SUBDOMAIN_PROXY_KEY` in `config.local.php` to the same
   value as `PROXY_KEY`.

## Check

`curl -I https://milton.kidsthatteach.org/` returns Milton's homepage (or the
"not public yet" page), and `https://www.kidsthatteach.org/` redirects to the
apex. Without a matching key PHP ignores `X-Forwarded-Host`, so a request that
reaches DreamHost directly can never impersonate a subdomain.
