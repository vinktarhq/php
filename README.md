# vinktarhq/php

[![CI](https://github.com/vinktarhq/php/actions/workflows/ci.yml/badge.svg)](https://github.com/vinktarhq/php/actions/workflows/ci.yml)

Product analytics and error tracking for PHP, with the identity of the request that is being
served.

Events and errors from a server carry the user, device and session of the visitor they were
served for, so a backend error sits next to the browser events that led to it in
[Vinktar](https://vinktar.com). Every request and every job gets a scope of its own: nothing one
sets reaches another, in PHP-FPM, in a queue worker that runs for a month, or in Fibers.

```php
$vinktar = new Vinktar\Client(['writeKey' => getenv('VINKTAR_KEY'), 'captureErrors' => true]);

$vinktar->scopeFromHeaders($_SERVER);          // the browser SDK's device and session
$vinktar->identify($user->id, ['plan' => 'pro']);
$vinktar->track('order_created', ['total' => 42]);
```

That is the whole setup for a PHP-FPM request. What is queued is sent when the request ends.

**PHP 8.2+. Zero runtime dependencies (`ext-curl`, `ext-json`). Never throws into your code.**
Everything the SDK cannot send is said once, in your logs.

> **Not on Packagist yet.** The first beta is being prepared. Until then, send events over the
> HTTP API at [vinktar.com/api.md](https://vinktar.com/api.md).

---

## Contents

- [Getting a key](#getting-a-key)
- [Analytics](#analytics)
- [Scopes and identity](#scopes-and-identity)
- [Errors](#errors)
- [Where it runs](#where-it-runs)
- [Sending, flushing and closing](#sending-flushing-and-closing)
- [Options](#options)
- [How it works](#how-it-works)
- [Licence](#licence)

---

## Getting a key

A server can use the project's write key (`vnk_pk_…`) or a secret key (`vnk_sk_…`); both can
append events. Keys are created in the project settings. `new Client()` reads `VINKTAR_KEY` when
you pass none, and throws when there is still none: a server with no key is misconfigured, and
that belongs in the first deploy's logs. A client built with `'enabled' => false` needs no key and
does nothing.

## Analytics

```php
$vinktar->track('checkout_started', ['items' => 3]);
$vinktar->page('Pricing');                                   // $pageview

$vinktar->identify('user_123', ['email' => 'dana@example.com', 'plan' => 'pro'], ['created' => '2026-01-04']);
$vinktar->setTraits(['plan' => 'team']);                     // for the scope's user
$vinktar->unsetTraits(['trial_ends_at']);

$vinktar->register(['region' => 'eu-west']);                 // on every event from this scope
```

Traits are strings, numbers and booleans. A value over 255 bytes is dropped, never truncated, and
said so. `email`, `name`, `username`, `avatar` and `created` are stored as `$email` and friends.

A call can name its own actor, which never changes the scope:

```php
$vinktar->track('invoice_paid', ['total' => 90], ['userId' => $invoice->ownerId]);
```

An explicit id that is not usable (`''`, `'guest'`, `'null'`, …) refuses the event rather than
sending it as whoever is on the scope.

## Scopes and identity

A scope holds the user, device, session, tags, error context, request, breadcrumbs and registered
properties for one unit of work. Each client has its own.

```php
// A job in a long-running worker: everything set inside stays inside.
$vinktar->withScope(function () use ($vinktar, $job) {
    $vinktar->setUser(['id' => $job->userId]);
    $job->handle();
});

// A framework hook that runs before the handler and does not wrap it.
$vinktar->enterScope();
```

`withScope()` starts from a copy of the current scope, returns what the callback returns, and lets
what it throws through, unreported. `enterScope()` starts a fresh one: the tags, context and
properties set for the whole process, and never a user, device, session or breadcrumbs from earlier
work.

`setUser(null)` clears the user and nothing else; the device stays. `reset()` clears the whole
scope and applies the configured `initialScope` tags and context again, never a configured identity.

`scopeFromHeaders()` adopts the browser SDK's `X-Vinktar-Device-Id` and `X-Vinktar-Session-Id`, from
`$_SERVER`, `getallheaders()` or a PSR-7 `getHeaders()` array. They are validated, and they are
correlation, never authentication.

## Errors

```php
try {
    $gateway->charge($order);
} catch (PaymentFailed $e) {
    $vinktar->captureException($e, ['tags' => ['area' => 'billing'], 'context' => ['order' => $order->id]]);
}

$vinktar->captureMessage('Webhook retried 5 times', ['level' => 'warning']);
$vinktar->addBreadcrumb(['category' => 'queue', 'message' => 'job started', 'data' => ['id' => $job->id]]);
```

Uncaught exceptions, warnings and fatal errors are reported when you ask:

```php
new Vinktar\Client(['writeKey' => $key, 'captureErrors' => true]);   // or $vinktar->registerHandlers()
```

This is opt-in because a handler changes what the process does, and the SDK keeps that change
invisible: the exception handler that was there before still runs, PHP's own error handling still
logs and displays, and a crash ends the script with the output and exit code PHP gives it without
the SDK. Warnings are reported unless `error_reporting()` or `@` says otherwise; notices and
deprecations become breadcrumbs. Fatal errors, out-of-memory included, are reported at shutdown.

Errors carry the `getPrevious()` chain, frames with the application's marked and made relative to
the project root, and a few lines of source around the frames nearest the crash. Secrets in
messages and source lines (card numbers, API tokens, bearer headers) are masked before they leave.
A repeat of the same error for the same person within five seconds is counted, not sent.

## Where it runs

| Runtime | How |
|---|---|
| PHP-FPM, mod_php | One client per request. It flushes when the request ends, after `fastcgi_finish_request()` when you call it. |
| CLI scripts | Flushed at shutdown; call `flush()` or `close()` yourself when the result matters. |
| Queue workers | One long-lived client; each job in `withScope()`; `flush()` after each job. |
| Fibers (ReactPHP, Amp, Revolt) | Scopes are kept per Fiber. A Fiber starts from the client's root scope, so enter a scope inside it. |
| Swoole, RoadRunner, FrankenPHP worker mode | Not supported yet. |

## Sending, flushing and closing

```php
$delivered = $vinktar->flush();   // true when the server accepted everything queued
$vinktar->close();                // once, when a worker stops
```

Sending is synchronous and bounded. Records are sent when the queue reaches `flushAt`, when you
call `flush()`, and when the process shuts down (`autoFlush`). There is no background thread and
nothing ever sleeps: when the server asks the SDK to wait (a rate limit, an outage), the wait is
remembered, and a flush during it returns `false` at once.

`flush()` returns `true` only when everything queued when it was called was accepted. A batch that is
held, retrying or refused returns `false`; the SDK keeps it and tries again at a later flush. A
`false` is about telemetry, not your application: never retry your own work because of it.

`close()` refuses new work at once, spends at most `shutdownTimeout` delivering what is queued, and
stops. Every later call gets the same answer.

## Options

| Option | Default | |
|---|---|---|
| `writeKey` | `$VINKTAR_KEY` | Required unless `enabled` is false. |
| `host` | `$VINKTAR_HOST`, `https://in.vinktar.com` | |
| `enabled` | `true` | `false`: no key needed, nothing sent. |
| `environment` | `$VINKTAR_ENVIRONMENT`, `$APP_ENV`, `production` | |
| `enabledEnvironments` | `[]` | Send only from these. |
| `release` | `$VINKTAR_RELEASE` | |
| `serverName` | hostname | |
| `analytics`, `errors` | `true` | Turn either product off. |
| `initialScope` | | `userId`, `deviceId`, `sessionId` for the root scope only; `tags`, `context` for every scope. |
| `superProperties` | `[]` | On every event, from every scope. |
| `flushAt` | `20` | Records that trigger a send. |
| `maxQueueSize` | `1000` | Oldest dropped beyond it, and counted. |
| `requestTimeoutMs` | `5000` | One deadline for the whole request. |
| `shutdownTimeout` | `2000` | The bound on `close()` and the shutdown flush. |
| `autoFlush` | `true` | Flush when the process shuts down. |
| `gzip` | `true` | Bodies over 1 KiB, when `ext-zlib` is loaded. |
| `captureErrors` | `false` | Install the error handlers. |
| `sampleRate`, `errorSampleRate` | `1` | Deterministic per user, or per issue. |
| `maxEventsPerMinute`, `maxErrorsPerMinute` | `6000`, `100` | |
| `dedupe` | `true` | |
| `ignoreErrors` | `[]` | Substrings of messages not to report. |
| `sendDefaultPii` | `false` | Query strings and every request header on errors. |
| `redactedKeys`, `propertyDenylist` | `[]` | Mask keys containing these; drop these top-level keys. |
| `maxValueBytes`, `normalizeDepth` | `255`, `3` | |
| `projectRoot` | the Composer project | Decides which frames are yours. |
| `contextLines` | `5` | Source lines around a frame; `0` turns it off. |
| `attachStacktrace`, `includeRawStack` | `false` | |
| `maxBreadcrumbs` | `50` | |
| `beforeTrack`, `beforeSend`, `beforeBreadcrumb` | | Callables: return the record, rewritten or not, or `null` to drop it. |
| `onError` | | Called with the SDK's own failures. |
| `logger` | `error_log()` | A callable `($level, $message, $context)` or a PSR-3 logger. |
| `transport` | cURL | Any `Vinktar\Transport\Transport`, for a proxy or a test. |
| `debug` | `false` | Verbose logging. |

## How it works

Every record is encoded when it is queued, so a hook that returns something unencodable costs only
that record. Events and errors go to separate endpoints with separate holds, so a throttled event
stream never delays a crash report. Any 2xx is an acceptance, a redirect is never followed (it would
carry your key somewhere else), a 429 holds only the categories the server named, and a 503 is kept
and tried again later with backoff. What the SDK drops (sampled, deduplicated, refused, lost) is
counted and sent to Vinktar, so you can see it.

## Licence

MIT
