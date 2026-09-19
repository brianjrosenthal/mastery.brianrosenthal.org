// Cloudflare Worker that serves every {slug}.kidsthatteach.org from the
// DreamHost server, which only knows the main hostname.
//
// DreamHost's managed hosting cannot host a wildcard domain, so Cloudflare
// terminates TLS for *.kidsthatteach.org (Universal SSL) and this Worker,
// bound to the route *.kidsthatteach.org/*, fetches the same path from
// https://kidsthatteach.org and tells PHP which subdomain the visitor used:
//
//   X-Forwarded-Host  the hostname the visitor typed (milton.kidsthatteach.org)
//   X-Site-Proxy-Key  a shared secret; PHP (request_host() in config.php) only
//                     trusts X-Forwarded-Host when this matches
//                     SUBDOMAIN_PROXY_KEY in config.local.php
//
// Bindings (Worker -> Settings -> Variables and Secrets):
//   ORIGIN_HOST  variable  kidsthatteach.org
//   PROXY_KEY    secret    same value as SUBDOMAIN_PROXY_KEY
//
// Video bytes never pass through here (the browser talks to R2 directly with
// presigned URLs), so the free plan's request limits are ample.

export default {
  async fetch(request, env) {
    const url = new URL(request.url);
    const host = url.hostname.toLowerCase();
    const originHost = (env.ORIGIN_HOST || '').toLowerCase();

    if (!originHost) {
      return new Response('Worker is missing the ORIGIN_HOST variable.', { status: 500 });
    }
    // www is the main site, not a kid's subdomain.
    if (host === 'www.' + originHost) {
      return Response.redirect('https://' + originHost + url.pathname + url.search, 301);
    }

    const origin = new URL(url.toString());
    origin.protocol = 'https:';
    origin.hostname = originHost;
    origin.port = '';

    const headers = new Headers(request.headers);
    headers.set('X-Forwarded-Host', host);
    headers.set('X-Site-Proxy-Key', env.PROXY_KEY || '');

    // redirect: 'manual' hands PHP's Location headers (always root-relative
    // paths in this app) straight back to the browser, which resolves them
    // against the subdomain it is on.
    const init = {
      method: request.method,
      headers,
      redirect: 'manual',
    };
    if (request.method !== 'GET' && request.method !== 'HEAD') {
      init.body = request.body;
    }
    return fetch(origin.toString(), init);
  },
};
