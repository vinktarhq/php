# vinktarhq/php

[![CI](https://github.com/vinktarhq/php/actions/workflows/ci.yml/badge.svg)](https://github.com/vinktarhq/php/actions/workflows/ci.yml)

Product analytics and error tracking for PHP, with the identity of the request that is being
served.

Events and errors from a server carry the user, device and session of the visitor they were
served for, so a backend error sits next to the browser events that led to it in
[Vinktar](https://vinktar.com). Every request and every job gets a scope of its own: nothing one
sets reaches another, in PHP-FPM, in a FrankenPHP worker, in a queue worker that runs for a month,
or in Fibers.

```php
$vinktar = new Vinktar\Client(['writeKey' => getenv('VINKTAR_KEY'), 'captureErrors' => true]);

$vinktar->scopeFromHeaders($_SERVER);          // the browser SDK's device and session
$vinktar->identify($user->id, ['plan' => 'pro']);
$vinktar->track('order_created', ['total' => 42]);
```

That is the whole setup for a PHP-FPM request. What is queued is sent when the request ends.

**PHP 8.2+. Zero runtime dependencies (`ext-curl`, `ext-json`). Never throws into your code.**
Not from the constructor, not for an argument of the wrong type under `strict_types`, not for a
value that cannot be serialised. Everything the SDK cannot send is said once, in your logs.

```sh
composer require vinktarhq/php:^0.2@beta
```

This is a beta: the API can still change before 0.2.0, and the changelog says when it does.

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
you pass none. When there is still none it does not throw: it logs one line at error level, in the
first deploy's logs where a missing key belongs, and from then on behaves like a client built with
`'enabled' => false`, which needs no key and does nothing. `flush()` and `close()` return `true`.

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

The types in the method signatures are documentation: every public method accepts anything, so a
file with `declare(strict_types=1)` never gets a `TypeError` from the SDK. What can sensibly be
used is: an integer or `Stringable` user id is sent as its string (`identify($row['id'])` works),
and a tag value can be a number or a boolean. Anything else is logged once and the call does
nothing. Values inside properties and context can be anything at all, including objects whose
`jsonSerialize()` or `__toString()` throws, cycles, `NAN` and resources: each becomes a placeholder
such as `[Circular]` or `[Unreadable]`, and the event is still sent.

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
what it throws through, unreported: your callback's own exception is the only thing the SDK ever
lets out. Given something that is not callable, it logs that and returns `null`. `enterScope()` starts a fresh one: the tags, context and
properties set for the whole process, and never a user, device, session or breadcrumbs from earlier
work.

`setUser(null)` clears the user and nothing else; the device stays. `reset()` clears the whole
scope and applies the configured `initialScope` tags and context again, never a configured identity.

`scopeFromHeaders()` adopts the browser SDK's `X-Vinktar-Device-Id` and `X-Vinktar-Session-Id`, from
`$_SERVER`, `getallheaders()` or a PSR-7 `getHeaders()` array. They are validated, and they are
correlation, never authentication.

`$vinktar->scope()` is the current scope itself, with the same `setTag()`, `setTags()`,
`setContext()`, `setRequest()`, `register()`, `registerOnce()` and `unregister()`. The client's
methods of those names are these, so an argument is treated the same way through either.

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

One part of that needs your help when the application (or its framework) has an error handler of
its own. `set_error_handler()` takes a mask of levels, PHP only calls the handler for those, and
PHP has no way to ask what the mask was. A handler registered for `E_WARNING` that throws must not
suddenly be called for a deprecation because the SDK sits in front of it, and a handler must not
go quiet either. So the SDK does not guess:

```php
new Vinktar\Client(['writeKey' => $key, 'captureErrors' => true, 'previousHandlerLevels' => E_ALL]);
```

`previousHandlerLevels` is the mask the existing handler was registered with: `E_ALL` for Symfony,
Laravel and any `set_error_handler($handler)` with no second argument. The SDK then forwards
exactly those levels to it, with the same arguments, and returns what it returns; every other level
gets PHP's standard handling, as it did before. Without the option, and with a handler already
installed, the SDK leaves the error handler alone and says so once in the log: uncaught exceptions
and fatal errors are still reported, warnings and the breadcrumbs from notices are not. That is
the trade: a missing option costs you warnings in Vinktar, never a change in what your application
does. With no handler installed before the SDK there is nothing to declare. (A framework that
turns warnings into `ErrorException`s reports them as exceptions either way.)

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
| FrankenPHP worker mode, Laravel Octane on FrankenPHP, Symfony on FrankenPHP | One client per worker; each request in its own scope; `flush()` after the response. See [Worker mode](#worker-mode-frankenphp). |
| RoadRunner | Not tested. Its workers also serve one request at a time, so the same setup should hold, but nothing here proves it yet. |
| Swoole, Octane on Swoole | Not supported. Swoole serves requests concurrently in one process, in coroutines, and scopes are kept per Fiber, not per coroutine. |

### Worker mode (FrankenPHP)

A worker boots once and serves request after request, so the client does too. Three things follow:
build the client before the loop, run each request in a scope of its own, and call `flush()` once
the response has gone. The SDK never reads `$_SERVER` or headers by itself, so nothing is captured
at boot and left stale; what the request carries comes from `scopeFromHeaders()`, called inside
the request.

A plain worker script:

```php
<?php
// public/index.php, served by `frankenphp php-server --root public --worker public/index.php`
require __DIR__.'/../vendor/autoload.php';

$vinktar = new Vinktar\Client(['writeKey' => getenv('VINKTAR_KEY'), 'captureErrors' => true]);
$app = new App\Kernel();

$handler = static function () use ($vinktar, $app): void {
    $vinktar->withScope(static function () use ($vinktar, $app): void {
        $vinktar->scopeFromHeaders($_SERVER);
        try {
            $app->handle();
        } catch (Throwable $e) {
            $vinktar->captureException($e, ['handled' => false]);
            http_response_code(500);
        }
    });
};

while (frankenphp_handle_request($handler)) {
    $vinktar->flush();
}
```

Catch inside the scope. An exception that escapes the handler does not end a FrankenPHP worker:
FrankenPHP turns it into a fatal error for that one response, sent with a 200, serves the next
request, and PHP's exception handler never sees it. With `captureErrors` the next `flush()` still
reports it, as a fatal error, but the request's scope is gone by then, so it has no user. A real
fatal error, running out of memory for one, does end the worker: it is reported with the
request's user as the worker shuts down, and FrankenPHP starts a new one.

`flush()` after `frankenphp_handle_request()` returns runs after the response has been sent, so it
adds nothing to the response time. It does hold the worker: until it returns, that worker takes no
new request. When ingest is slow, a flush waits for it, up to `requestTimeoutMs` for each request
it sends (errors and events are separate requests); after a failure the SDK backs off, and the
flushes that follow return at once. Lower `requestTimeoutMs` if a worker should never wait that
long.

**Laravel Octane.** Build the client once per worker by warming it, start a scope when a request
arrives, and flush when Octane has sent the response:

```php
// app/Providers/AppServiceProvider.php, with 'vinktar' => ['key' => env('VINKTAR_KEY')] in config/services.php
use Illuminate\Support\Facades\Event;
use Laravel\Octane\Events\RequestReceived;
use Laravel\Octane\Events\RequestTerminated;
use Vinktar\Client;

public function register(): void
{
    $this->app->singleton(Client::class, fn () => new Client(['writeKey' => config('services.vinktar.key')]));
}

public function boot(): void
{
    Event::listen(RequestReceived::class, function (RequestReceived $event): void {
        $vinktar = app(Client::class);
        $vinktar->enterScope();
        $vinktar->scopeFromHeaders($event->request->headers->all());
    });
    Event::listen(RequestTerminated::class, fn () => app(Client::class)->flush());
}
```

```php
// config/octane.php
'warm' => [...Octane::defaultServicesToWarm(), Vinktar\Client::class],
```

Laravel catches exceptions before PHP can call a handler, so report them from `bootstrap/app.php`:
`->withExceptions(fn (Exceptions $exceptions) => $exceptions->report(fn (Throwable $e) => app(Vinktar\Client::class)->captureException($e)))`.

**Symfony.** Symfony's runtime runs a FrankenPHP worker by itself (7.4 and later, or
`runtime/frankenphp-symfony` before that), and calls `kernel.terminate` after the response has
gone. One subscriber covers it, and is just as right under PHP-FPM:

```php
// src/EventSubscriber/VinktarSubscriber.php
namespace App\EventSubscriber;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\KernelEvents;
use Vinktar\Client;

final class VinktarSubscriber implements EventSubscriberInterface
{
    public function __construct(private readonly Client $vinktar)
    {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::REQUEST => ['onRequest', 4096],
            KernelEvents::EXCEPTION => 'onException',
            KernelEvents::TERMINATE => ['onTerminate', -4096],
        ];
    }

    public function onRequest(RequestEvent $event): void
    {
        if ($event->isMainRequest()) {
            $this->vinktar->enterScope();
            $this->vinktar->scopeFromHeaders($event->getRequest()->headers->all());
        }
    }

    public function onException(ExceptionEvent $event): void
    {
        if (!$event->getThrowable() instanceof HttpExceptionInterface) {
            $this->vinktar->captureException($event->getThrowable());
        }
    }

    public function onTerminate(): void
    {
        $this->vinktar->flush();
    }
}
```

```yaml
# config/services.yaml, under services:
Vinktar\Client:
    arguments: [{ writeKey: '%env(VINKTAR_KEY)%' }]
```

What lasts for the life of a worker, on purpose: the dedupe window (the same error for the same
person within five seconds, so two anonymous visitors who hit one bug moments apart are one report
and a count), `maxEventsPerMinute` and `maxErrorsPerMinute`, which count per worker rather than per
request, a wait the server asked for, and log lines said once. Nothing about a visitor lasts.

A client made inside the request, rather than once per worker, still sends what it holds when it
is released at the end of the request, but that happens before the response is sent.

## Sending, flushing and closing

```php
$delivered = $vinktar->flush();   // true when the server accepted everything queued
$vinktar->close();                // once, when a worker stops
```

Sending is synchronous and bounded. Records are sent when the queue reaches `flushAt`, when you
call `flush()`, and when the process shuts down (`autoFlush`). The send that `flushAt` triggers
happens inside your `track()` or `captureException()` call, so it is held to `shutdownTimeout`
for everything it sends, not to a request timeout per endpoint; when the host does not answer, the
next captures queue and return at once until the backoff has passed. There is no background thread and
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
| `writeKey` | `$VINKTAR_KEY` | Without one the client logs an error and sends nothing. |
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
| `shutdownTimeout` | `2000` | The bound on `close()`, the shutdown flush, and a send triggered by `flushAt`. |
| `autoFlush` | `true` | Flush when the process shuts down, or when the client is released before that. |
| `gzip` | `true` | Bodies over 1 KiB, when `ext-zlib` is loaded. |
| `captureErrors` | `false` | Install the error handlers. |
| `previousHandlerLevels` | | The levels your own error handler was registered for (`E_ALL` for most frameworks). See [Errors](#errors). |
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
