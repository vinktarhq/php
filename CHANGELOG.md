# Changelog

## Unreleased

- A client switched off with `'enabled' => false` no longer logs `inert: enabled is false` as a
  warning when it is created. Turning the SDK off is a decision, not a problem, so only `'debug' => true`
  mentions it. A missing or refused key is still an error, once.

## 0.2.0-beta.2

FrankenPHP worker mode is supported, plain, under Laravel Octane and under Symfony's runtime. The
README's "Worker mode" section has the setup. `WorkerModeTest` serves requests with different
`$_SERVER` values through one client.

- With `captureErrors`, an exception that escapes a FrankenPHP worker's handler is reported by the
  next `flush()`. FrankenPHP turns it into a fatal error without calling the exception handler and
  keeps the worker running, so it went unreported until the worker stopped, and was then reported
  late, with whatever scope was current.
- A client released while the process goes on sends what it holds, bounded by `shutdownTimeout`.
  A client made per request in a worker lost its queue at the end of every request.

## 0.2.0-beta.1

Nothing the SDK does may break the application it is installed in. These are the places where it
could, and no longer can. `spec/fixtures/hostile.json` holds the rule as cases and `HostileTest`
runs them.

- **Changed:** `new Client()` no longer throws when there is no write key. It logs one line at
  error level and is inert, like `'enabled' => false`: nothing is sent, `flush()` and `close()`
  return `true`. Nothing else in construction throws either. If you relied on the
  `InvalidArgumentException` to catch a missing key at deploy, look for the log line instead.
- **Changed:** every public method of `Client` and `Scope` now declares its parameters as `mixed`,
  with the types in PHPDoc. Under `declare(strict_types=1)` a call such as `identify(42)` or
  `setTag('plan', 3)` used to be a `TypeError` before the SDK ran. An integer or `Stringable` user
  id is now sent as its string, tag values can be numbers or booleans, and anything unusable is
  logged and ignored. `withScope()` given something that is not callable logs that and returns
  `null`. What your callback throws inside `withScope()` still passes through.
- **Changed:** with `captureErrors`, an error handler that was installed before the SDK is only
  called for the levels it was registered for. PHP does not expose that mask, so it is the new
  `previousHandlerLevels` option (`E_ALL` for most frameworks). Without it, an existing error
  handler is left in place and warnings are not captured; uncaught exceptions and fatal errors
  still are. Before, every level was forwarded, so a handler registered for `E_WARNING` was called
  for deprecations.
- `Scope`'s methods validate what they are given exactly as the client's do. `setTags()` with an
  object or an array as a value threw or raised "Array to string conversion".
- A send triggered by `flushAt` is bounded by `shutdownTimeout` as a whole. With an unreachable host
  a `track()` could block for a request timeout per endpoint, 10 seconds by default.
- Normalising is bounded by depth, by the number of `jsonSerialize()` results followed for one
  value, and by a budget of 10,000 nested values per call. A `jsonSerialize()` that returned a new
  instance of its own class recursed until the memory limit, a fatal error no `catch` sees. A
  `jsonSerialize()` or `__toString()` that throws now costs that one property (`[Unreadable]`), not
  the event.
- `scopeFromHeaders()` and the request attached to an error accept PSR-7 headers with an empty
  list or a non-string value. An empty list was a deprecation on PHP 8.5 and lost the error event.
- Hosts with `disable_functions` (`gethostname`, `getenv`, `getcwd`, `ini_set`) or `open_basedir`
  no longer get an `Error` from the constructor or a warning while an error is being reported.
- Log lines are capped at 2 KiB, so a 5 MB event name is not repeated in the log.

## 0.1.0-beta.1

The first release.

- The wire contract in `spec/` and the parts of it that need no client: request and error limits,
  blocked ids, the inbound filter, trait parsing, deterministic sampling, and the decision for
  every ingest response.
- `Vinktar\Client`: analytics, identity and traits, error capture, breadcrumbs, and scopes per
  request, job and Fiber, over a synchronous, bounded cURL transport. Every common and backend
  behaviour scenario in `spec/fixtures/scenarios.json` runs against it.
- `captureErrors` / `registerHandlers()`: uncaught exceptions, warnings and fatal errors (out of
  memory included) are reported, while the handlers that were there before still run and the
  script ends with the output and exit code PHP gives it without the SDK.
- Source lines around the in-app frames nearest the crash (`contextLines`, 5 by default).
