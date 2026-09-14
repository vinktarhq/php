# Changelog

## Unreleased

Not yet published.

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
