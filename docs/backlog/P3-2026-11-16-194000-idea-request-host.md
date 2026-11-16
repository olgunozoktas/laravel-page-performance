# Measure pages that need a host (tenant subdomains)

Created: 2026-11-16 (UTC)
Priority: **P3**
Tags: idea, back-end
Need: in a multi-tenant Laravel 13 host app, `php artisan perf:pages --only=<part> --as=<user> --param=…` measured 4
signed-in pages, and all 4 answered 302 "NOT THE PAGE" (2026-11-16 22:38 +0300). `PageMeasurer::handle()` builds
`Request::create($path, 'GET')` from a relative path, so the host is `localhost`; the app's tenant middleware sends
each request without the tenant's subdomain to the login page. `APP_URL=<tenant host>` did not change it.
Status legend: `- [ ]` = to do, `- [x]` = implemented

## Context

- Apps with stancl/tenancy or their own subdomain middleware identify the tenant from the request host.
- Today such an app gets no query count and no Defect check for any tenant page.

## Items

- [ ] An option `--host=<host>` (and a config key `page-performance.host`) that `Request::create()` uses for each page.
- [ ] Optionally a per-route host, e.g. `--param=<route>:host=<value>`, for apps with several tenant domains.
- [ ] The report's conditions line names the host that it used.
- [ ] README: the option, with one example.

## Acceptance

- Tests in this package (`vendor/bin/pest`): a route that answers 302 without the host is measured with `--host`;
  without the option, behaviour stays as today. Break the guard; name the failing test.
- In a host app with tenant subdomains, `perf:pages --host=<tenant host>` returns 200 rows for the signed-in pages.

## Tradeoffs

- A wrong host still answers 302 and stays "NOT THE PAGE": the report shows it, never ranks it.
