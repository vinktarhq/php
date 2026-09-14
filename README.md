# vinktarhq/php

[![CI](https://github.com/vinktarhq/php/actions/workflows/ci.yml/badge.svg)](https://github.com/vinktarhq/php/actions/workflows/ci.yml)

Product analytics and error tracking for PHP, with the identity of the request that is being
served.

**Not released yet.** This package is being built and is not on Packagist. Until it is, send events
over the HTTP API described at [vinktar.com/api.md](https://vinktar.com/api.md), and errors from any
Sentry SDK pointed at your project's Vinktar DSN.

## What it will be

- PHP 8.2 and newer. Zero runtime Composer dependencies: `ext-curl` and `ext-json`, and `ext-zlib`
  when it is there.
- The same API as [`@vinktarhq/node`](https://github.com/vinktarhq/node): `track`, `identify`,
  `captureException`, scopes per request or job, and a `flush()` that is `true` only when the
  server accepted everything.
- For PHP-FPM requests, CLI scripts and long-running queue workers. Nothing one request or job sets
  reaches the next.
- Never throws into your code. Everything it cannot send is said once in your logs.

Symfony and Laravel integrations follow as separate packages once the core is stable.

## Licence

MIT
