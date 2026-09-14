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

`spec/fixtures/scenarios.json` describes behaviour every Vinktar SDK must share. `ScenariosTest`
lists the server-side ones; each is reported as incomplete until the client can run it, so a test
run always shows what is still owed. (`fixtures/stacks.json` is JavaScript stack text and does not
apply to PHP.)

## House style

- **Zero runtime dependencies.** Composer's `require` holds PHP and extensions, nothing else.
- **Nothing fails silently.** Every drop, refusal and no-op is a warning that names the
  consequence, logged once and rate limited.
- **Never throw into the application.** The one exception is constructing an enabled client
  without a key, which is a misconfiguration and throws on purpose.
- **No state in statics.** A long-running worker serves many people; a static is how one job's user
  ends up on the next job's events.
- Comments explain *why*, not *what*, and are worth writing where a rule looks arbitrary.

## Releasing

Maintainers only. Packagist publishes from the tag.

1. Set `Vinktar\Version::VERSION` and move the changelog heading.
2. Create a GitHub release tagged `v<version>`, marked as a prerelease for a `-beta` version.

The release workflow fails when the tag and the constant disagree.
