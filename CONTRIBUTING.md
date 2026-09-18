# Contributing

Thanks for looking. Bug reports with the PHP version, the SAPI (FPM, CLI, a worker) and the lines
the SDK logged are worth a great deal.

## Getting set up

```bash
composer install
vendor/bin/phpunit
vendor/bin/phpstan analyse
vendor/bin/php-cs-fixer fix
```

## What the tests are for

`spec/` is the wire contract the server enforces, vendored from the published copy. Never edit it
here. `tests/Spec` runs every constant and fixture in it: limits, blocked ids, the inbound filter,
trait parsing, sampling, and every row of the response table, headers included. A number that
drifts from the published one fails the build rather than a customer's request.

`spec/fixtures/hostile.json` is what an application can hand an SDK that must not break it:
cycles, values whose serialiser throws or never ends, the wrong type in every argument, a host that
never answers. `HostileTest` runs every case that applies to PHP and says why it skips the rest.

`spec/fixtures/scenarios.json` describes behaviour every Vinktar SDK must share. `ScenariosTest`
lists the server-side ones; each is reported as incomplete until the client can run it, so a test
run always shows what is still owed. (`fixtures/stacks.json` is JavaScript stack text and does not
apply to PHP.)

## House style

- **Zero runtime dependencies.** Composer's `require` holds PHP and extensions, nothing else.
- **Nothing fails silently.** Every drop, refusal and no-op is a warning that names the
  consequence, logged once and rate limited.
- **Never break the application.** Nothing throws into it, raises a warning, notice or deprecation
  in it, holds it up for longer than it asked, or changes what it does, whatever it is handed. There
  are no exceptions, the constructor included: a client with no key logs an error and is inert.
  Public methods take `mixed` with the real types in PHPDoc, and read every argument through
  `Internal\Input` before anything else, because a `strict_types` caller gets a `TypeError` before
  a typed method's body runs. `spec/fixtures/hostile.json` is this rule as cases, and `HostileTest`
  runs them on a host whose error handler throws for every level. A new public method or option
  needs to survive the same.
- **No state in statics.** A long-running worker serves many people; a static is how one job's user
  ends up on the next job's events.
- Comments explain *why*, not *what*, and are worth writing where a rule looks arbitrary.

## Releasing

Maintainers only. Packagist publishes from the tag.

1. Set `Vinktar\Version::VERSION` and move the changelog heading.
2. Create a GitHub release tagged `v<version>`, marked as a prerelease for a `-beta` version.

The release workflow fails when the tag and the constant disagree.
