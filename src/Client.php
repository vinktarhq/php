<?php

declare(strict_types=1);

namespace Vinktar;

use Vinktar\Internal\Breadcrumbs;
use Vinktar\Internal\Bytes;
use Vinktar\Internal\Clock;
use Vinktar\Internal\Dedupe;
use Vinktar\Internal\Dispatcher;
use Vinktar\Internal\ExceptionBuilder;
use Vinktar\Internal\Handlers;
use Vinktar\Internal\Hooks;
use Vinktar\Internal\Ids;
use Vinktar\Internal\InboundFilter;
use Vinktar\Internal\Input;
use Vinktar\Internal\KeyedValve;
use Vinktar\Internal\Limits;
use Vinktar\Internal\Logger;
use Vinktar\Internal\Normalizer;
use Vinktar\Internal\Options;
use Vinktar\Internal\Sampling;
use Vinktar\Internal\ScopeStore;
use Vinktar\Internal\Shutdown;
use Vinktar\Internal\Traits;
use Vinktar\Internal\Valve;

/**
 * Product analytics and error tracking, for one project.
 *
 *     $vinktar = new Vinktar\Client(['writeKey' => getenv('VINKTAR_KEY')]);
 *     $vinktar->track('order_created', ['total' => 42]);
 *     $vinktar->captureException($e);
 *
 * Everything one request or job sets lives in its scope, which belongs to this client alone; what
 * is configured for the whole service lives here. Nothing is kept in a static, so two clients never
 * see each other's scopes, and a long-running worker that runs each job in `withScope()` or after
 * `enterScope()` never carries one job's user into the next.
 *
 * Sending is synchronous and bounded. Records are queued and sent when the queue reaches `flushAt`,
 * when you call `flush()`, and at shutdown (`autoFlush`).
 *
 * Nothing here throws into your code, raises a PHP warning in it, or holds it up for longer than it
 * asked, whatever it is handed, and that includes the constructor: an enabled client with no key
 * says so once, as an error, and then does nothing, like a disabled one. The types are in the
 * PHPDoc and the signatures accept anything, because a caller with `declare(strict_types=1)` would
 * otherwise get a TypeError for `identify(42)` before a line of this class ran. An argument that
 * cannot be used is logged and the call does nothing. The one thing that passes through is what
 * your own callback throws inside `withScope()`.
 *
 * @phpstan-import-type WireException from ExceptionBuilder
 */
final class Client
{
    private const PENDING_ERRORS = 200;
    private const QUEUE_BYTES = 32 * 1024 * 1024;
    private const ERROR_QUEUE_BYTES = 8 * 1024 * 1024;

    /** Headers attached to an error without `sendDefaultPii`. Never cookies or credentials. */
    private const SAFE_HEADERS = ['user-agent', 'referer', 'accept', 'accept-language', 'content-type', 'content-length', 'host', 'x-request-id', 'x-forwarded-proto'];
    private const NEVER_HEADERS = ['cookie', 'set-cookie', 'authorization', 'proxy-authorization', 'x-api-key', 'x-vinktar-key'];

    private readonly Options $o;
    private readonly Logger $logger;
    private readonly Scope $root;
    private readonly ScopeStore $scopes;
    private readonly Dispatcher $dispatcher;
    private readonly Dedupe $dedupe;
    private readonly Valve $errorValve;
    private readonly Valve $eventValve;
    private readonly KeyedValve $typeValve;
    private readonly Normalizer $normalizer;
    private readonly ExceptionBuilder $exceptions;
    /** @var array<string, mixed> */
    private readonly array $context;
    private bool $closed = false;
    private ?bool $closeResult = null;
    private bool $handlersInstalled = false;

    /**
     * Never throws. With no write key (and `enabled` not false) the client logs one error and is inert.
     *
     * @param array<string, mixed>|string $options the options, or just the write key
     */
    public function __construct(mixed $options = [])
    {
        $given = \is_string($options) ? ['writeKey' => $options] : Input::map($options);
        try {
            $logger = new Logger($given['logger'] ?? null, ($given['debug'] ?? false) === true);
        } catch (\Throwable) {
            $logger = new Logger();
        }
        if ($given === null) {
            $logger->warn('the options must be an array or a write key; '.Input::describe($options).' was ignored');
        }
        try {
            $o = Options::resolve($given ?? [], $logger);
        } catch (\Throwable $error) {
            // Whatever went wrong, it is not the application's problem: it gets a client that does nothing.
            $logger->error('the client could not be set up, so nothing will be sent', ['error' => $error->getMessage()]);
            $o = Options::inert('the client could not be set up');
        }
        $this->logger = $logger;
        $this->o = $o;

        $this->normalizer = new Normalizer($this->o->maxValueBytes, $this->o->normalizeDepth, Limits::MAX_PROPERTIES_PER_EVENT, $this->o->redactedKeys, $this->o->propertyDenylist);
        $this->exceptions = new ExceptionBuilder($this->o->projectRoot, $this->o->includeRawStack, $this->o->contextLines);
        $this->context = $this->baseContext();

        // The configured identity is the root's and nobody else's: fresh scopes and reset() never bring it back.
        $this->root = new Scope($this->o->maxBreadcrumbs, $this->o->initialScope['tags'], $this->o->initialScope['context'], $this->logger, $this->normalizer);
        $this->root->setUserId($this->o->initialScope['userId']);
        $this->root->setDeviceId($this->o->initialScope['deviceId']);
        $this->root->setSessionId($this->o->initialScope['sessionId']);
        $this->scopes = new ScopeStore($this->root);

        $this->dedupe = new Dedupe();
        $this->errorValve = new Valve($this->o->maxErrorsPerMinute);
        $this->eventValve = new Valve($this->o->maxEventsPerMinute);
        $this->typeValve = new KeyedValve(max(1, intdiv($this->o->maxErrorsPerMinute, 2)));

        $onError = $this->o->onError;
        $this->dispatcher = new Dispatcher(
            transport: $this->o->transport,
            logger: $this->logger,
            host: $this->o->host,
            writeKey: $this->o->writeKey,
            maxQueueSize: $this->o->maxQueueSize,
            maxPendingErrors: self::PENDING_ERRORS,
            maxQueueBytes: self::QUEUE_BYTES,
            maxPendingErrorBytes: self::ERROR_QUEUE_BYTES,
            gzip: $this->o->gzip,
            requestTimeoutMs: $this->o->requestTimeoutMs,
            onBilling: $onError === null ? null : static function () use ($onError): void {
                $onError(new \RuntimeException('vinktar: the monthly cap was reached; events are paused'));
            },
        );

        if ($this->o->inert === null && $this->o->autoFlush) {
            $this->guarded(fn () => Shutdown::register($this, static function (object $client): void {
                if ($client instanceof self) {
                    $client->flushAtShutdown();
                }
            }));
        }
        if ($this->o->inert === null && $this->o->captureErrors) {
            $this->registerHandlers();
        }
    }

    // Analytics -------------------------------------------------------------------------------------

    /**
     * @param string                                                                                                                                 $name
     * @param array<string, mixed>                                                                                                                   $properties
     * @param array{userId?: string|int|null, deviceId?: string|null, sessionId?: string|null, timestamp?: string|int|float|\DateTimeInterface|null} $options    per-call identity, which never changes the scope
     */
    public function track(mixed $name, mixed $properties = [], mixed $options = []): void
    {
        $this->guarded(function () use ($name, $properties, $options): void {
            if (!$this->ready('track')) {
                return;
            }
            // Cut here, so a name of megabytes is not repeated in every log line about it.
            $name = Bytes::truncate(trim(Input::text($name) ?? ''), 255);
            if ($name === '') {
                $this->logger->warn('track() needs an event name; nothing was sent');

                return;
            }
            $properties = $this->arrayArgument('track', 'properties', $properties);
            $options = $this->arrayArgument('track', 'options', $options);
            if (!$this->o->analytics) {
                $this->logger->debug("analytics is off; \"{$name}\" was not sent");

                return;
            }

            $scope = $this->scopes->current();
            $overrides = [
                'userId' => self::override($options, 'userId', Ids::validUserId(...)),
                'deviceId' => self::override($options, 'deviceId', Ids::pickId(...)),
                'sessionId' => self::override($options, 'sessionId', Ids::pickId(...)),
            ];
            $invalid = array_keys(array_filter($overrides, static fn (string|false|null $v): bool => $v === false));
            if ($invalid !== []) {
                // Falling back to the scope's actor would attribute this event to someone the caller did not name.
                $this->drop('invalid', 'event');
                $this->logger->warn("\"{$name}\" was not sent: ".implode(' and ', $invalid).' is not a usable id, and it will not be sent as someone else');

                return;
            }
            $userId = \is_string($overrides['userId']) ? $overrides['userId'] : $scope->userId();
            $deviceId = \is_string($overrides['deviceId']) ? $overrides['deviceId'] : $scope->deviceId();
            $sessionId = \is_string($overrides['sessionId']) ? $overrides['sessionId'] : $scope->sessionId();

            if (!$this->eventValve->take()) {
                $this->drop('ratelimit', 'event');
                $this->logger->warn("more than {$this->o->maxEventsPerMinute} events in a minute; dropping until the valve refills");

                return;
            }
            if (!Sampling::sampled($userId ?? $deviceId ?? '', $this->o->sampleRate)) {
                $this->drop('sample_rate', 'event');

                return;
            }
            $timestamp = $this->timestampFor($options['timestamp'] ?? null, $name);
            if ($timestamp === null) {
                return;
            }

            $context = $this->normalizer->normalize($this->context);
            $payload = $this->normalizer->normalize(
                array_replace($this->o->superProperties, $scope->properties(), $properties),
                fn (string $key, string $reason) => $this->logger->warn("property \"{$key}\" on \"{$name}\" was ".match ($reason) {
                    'truncated' => 'truncated',
                    'depth' => "flattened past depth {$this->o->normalizeDepth}",
                    default => 'dropped: too many properties',
                }),
            );
            $event = [
                'name' => $name,
                'event_id' => Ids::uuidv7(),
                'timestamp' => $timestamp,
                'payload' => $this->capProperties($name, $payload, $context),
                'context' => $context,
            ];
            if ($userId !== null) {
                $event['user_id'] = $userId;
            }
            if ($deviceId !== null) {
                $event['device_id'] = $deviceId;
            }
            if ($sessionId !== null) {
                $event['session_id'] = $sessionId;
            }

            $hooked = Hooks::run($this->o->beforeTrack, $event);
            if ($hooked['value'] === null) {
                $this->drop('before_send', 'event');
                if ($hooked['threw'] !== null) {
                    $this->logger->warn('beforeTrack threw; the event was dropped', ['error' => $hooked['threw']->getMessage()]);
                }

                return;
            }
            $final = $this->o->beforeTrack !== [] ? $this->renormalizeEvent($name, $hooked['value']) : $hooked['value'];
            if (!$this->dispatcher->events->push('event', $final)) {
                $this->drop('before_send', 'event');
                $this->logger->warn("\"{$name}\" was dropped: beforeTrack returned something that cannot be sent (a resource, NAN or INF, or not an array)");

                return;
            }
            $this->afterCapture();
        });
    }

    /**
     * @param string|null                                                                                                                            $name
     * @param array<string, mixed>                                                                                                                   $properties
     * @param array{userId?: string|int|null, deviceId?: string|null, sessionId?: string|null, timestamp?: string|int|float|\DateTimeInterface|null} $options
     */
    public function page(mixed $name = null, mixed $properties = [], mixed $options = []): void
    {
        $this->guarded(function () use ($name, $properties, $options): void {
            $properties = $this->arrayArgument('page', 'properties', $properties);
            $name = Input::text($name);
            if ($name !== null && $name !== '') {
                $properties['$page_name'] = $name;
            }
            $this->track('$pageview', $properties, $options);
        });
    }

    /**
     * Set the user on the current scope, and send their traits and the device they were seen on.
     *
     * @param string|int                                          $userId     an integer id is sent as its string
     * @param array<string, string|int|float|bool>                $traits     last write wins
     * @param array<string, string|int|float|bool>                $traitsOnce first write wins
     * @param array{unset?: list<string>, deviceId?: string|null} $options    `deviceId` links that device for this call only
     */
    public function identify(mixed $userId, mixed $traits = [], mixed $traitsOnce = [], mixed $options = []): void
    {
        $this->guarded(function () use ($userId, $traits, $traitsOnce, $options): void {
            if (!$this->ready('identify')) {
                return;
            }
            $id = Ids::validUserId($userId);
            if ($id === null) {
                $this->logger->warn('identify('.Input::describe($userId).') was ignored: not a usable user id');

                return;
            }
            $traits = $this->arrayArgument('identify', 'traits', $traits);
            $traitsOnce = $this->arrayArgument('identify', 'traitsOnce', $traitsOnce);
            $options = $this->arrayArgument('identify', 'options', $options);
            $device = self::override($options, 'deviceId', Ids::pickId(...));
            if ($device === false) {
                $this->drop('invalid', 'identify');
                $this->logger->warn("identify(\"{$id}\") was ignored: its deviceId is not a usable id");

                return;
            }
            $parsed = Traits::parse(['$set' => $traits, '$set_once' => $traitsOnce, '$unset' => $options['unset'] ?? null]);
            foreach ($parsed['drops'] as $drop) {
                $this->logger->warn("trait \"{$drop['key']}\" was dropped ({$drop['code']})");
            }

            $scope = $this->scopes->current();
            $scope->setUserId($id);
            $deviceId = $device ?? $scope->deviceId();

            if ($deviceId === null && $parsed['set'] === [] && $parsed['setOnce'] === [] && $parsed['unset'] === []) {
                // The server needs a device to link or a trait to store; a bare user id is a no-op there.
                $this->logger->debug("identify(\"{$id}\"): no device to link and no traits; the user is set on the scope only");

                return;
            }
            $entry = ['user_id' => $id];
            if ($deviceId !== null) {
                $entry['device_id'] = $deviceId;
            }
            if ($parsed['set'] !== []) {
                $entry['$set'] = $parsed['set'];
            }
            if ($parsed['setOnce'] !== []) {
                $entry['$set_once'] = $parsed['setOnce'];
            }
            if ($parsed['unset'] !== []) {
                $entry['$unset'] = $parsed['unset'];
            }
            $this->dispatcher->events->push('identify', $entry);
            $this->afterCapture();
        });
    }

    /**
     * @param array<string, string|int|float|bool> $traits
     * @param array<string, string|int|float|bool> $traitsOnce
     */
    public function setTraits(mixed $traits, mixed $traitsOnce = []): void
    {
        $this->withUser('setTraits', fn (string $id) => $this->identify($id, $traits, $traitsOnce));
    }

    /**
     * @param array<string, string|int|float|bool> $traits
     */
    public function setTraitsOnce(mixed $traits): void
    {
        $this->withUser('setTraitsOnce', fn (string $id) => $this->identify($id, [], $traits));
    }

    /**
     * @param list<string> $keys
     */
    public function unsetTraits(mixed $keys): void
    {
        $this->withUser('unsetTraits', fn (string $id) => $this->identify($id, [], [], ['unset' => $keys]));
    }

    /**
     * `['id' => …, …traits]` identifies. `null` clears the user on the current scope and nothing else:
     * the device stays, and events from a device the server has linked still resolve to that user.
     * Start a fresh scope per request, or call reset(), to forget an actor.
     *
     * @param array<string, mixed>|null $user
     */
    public function setUser(mixed $user): void
    {
        $this->guarded(function () use ($user): void {
            if ($user === null) {
                $this->scopes->current()->setUserId(null);

                return;
            }
            $user = Input::map($user);
            $id = $user['id'] ?? null;
            if ($user === null || (!\is_string($id) && !\is_int($id))) {
                $this->logger->warn('setUser() needs ["id" => …] or null');

                return;
            }
            unset($user['id']);
            $traits = array_filter($user, static fn (mixed $v): bool => \is_scalar($v));
            $this->identify($id, $traits);
        });
    }

    /**
     * Forget everything on the current scope (identity, tags, context, request, breadcrumbs and
     * registered properties) and apply the configured tags and context again. A configured identity is
     * never restored, and nothing queued is flushed or dropped.
     */
    public function reset(): void
    {
        $this->guarded(fn () => $this->scopes->current()->reset($this->o->initialScope['tags'], $this->o->initialScope['context']));
    }

    /**
     * Properties sent on this scope's analytics events, and on nothing else.
     *
     * @param array<string, mixed> $properties
     */
    public function register(mixed $properties): void
    {
        $this->guarded(fn () => $this->scopes->current()->register($properties));
    }

    /**
     * @param array<string, mixed> $properties
     */
    public function registerOnce(mixed $properties): void
    {
        $this->guarded(fn () => $this->scopes->current()->registerOnce($properties));
    }

    /**
     * @param string $key
     */
    public function unregister(mixed $key): void
    {
        $this->guarded(fn () => $this->scopes->current()->unregister($key));
    }

    // Errors ----------------------------------------------------------------------------------------

    /**
     * @param \Throwable                                                                                                                                                $error
     * @param array{level?: string, tags?: array<string, string>, context?: array<string, mixed>, fingerprint?: list<string>, handled?: bool, userId?: string|int|null} $hint
     *
     * @return string the event id, or '' when nothing was captured
     */
    public function captureException(mixed $error, mixed $hint = []): string
    {
        return $this->guarded(function () use ($error, $hint): string {
            if (!$this->ready('captureException')) {
                return '';
            }
            if (!$error instanceof \Throwable) {
                $this->logger->warn('captureException() needs a Throwable; '.Input::describe($error).' was ignored. captureMessage() takes text');

                return '';
            }
            $hint = $this->arrayArgument('captureException', 'hint', $hint);

            return $this->emit($this->exceptions->fromThrowable($error), false, 'manual', ($hint['handled'] ?? true) !== false, $hint, \is_string($hint['level'] ?? null) ? $hint['level'] : 'error');
        }, '');
    }

    /**
     * @param string                                                                                                                                                    $message
     * @param array{level?: string, tags?: array<string, string>, context?: array<string, mixed>, fingerprint?: list<string>, handled?: bool, userId?: string|int|null} $hint
     *
     * @return string the event id, or '' when nothing was captured
     */
    public function captureMessage(mixed $message, mixed $hint = []): string
    {
        return $this->guarded(function () use ($message, $hint): string {
            if (!$this->ready('captureMessage')) {
                return '';
            }
            $text = Input::text($message);
            if ($text === null) {
                $this->logger->warn('captureMessage() needs text; '.Input::describe($message).' was ignored');

                return '';
            }
            $message = $text;
            $hint = $this->arrayArgument('captureMessage', 'hint', $hint);
            $frames = [];
            if ($this->o->attachStacktrace) {
                // The frames of the call site; this method and the guard around it are the SDK's own.
                $backtrace = debug_backtrace(\DEBUG_BACKTRACE_IGNORE_ARGS);
                foreach ($backtrace as $i => $call) {
                    if ($call['function'] === 'captureMessage' && ($call['class'] ?? null) === self::class) {
                        $frames = $this->exceptions->frames(\array_slice($backtrace, $i + 1), $call['file'] ?? '', $call['line'] ?? 0);
                        break;
                    }
                }
            }

            // Synthetic: the stack, if any, is where the message was written, not where anything failed.
            return $this->emit(ExceptionBuilder::fromMessage($message, $frames), true, 'manual', ($hint['handled'] ?? true) !== false, $hint, \is_string($hint['level'] ?? null) ? $hint['level'] : 'info');
        }, '');
    }

    /**
     * A step on the way to an error, kept on the current scope and sent with its next error.
     *
     * @param array{message?: string, category?: string, level?: string, data?: array<string, mixed>, timestamp?: string} $crumb
     */
    public function addBreadcrumb(mixed $crumb): void
    {
        $this->guarded(function () use ($crumb): void {
            if ($this->closed || $this->o->inert !== null) {
                return;
            }
            $shaped = Breadcrumbs::shape($crumb, $this->normalizer);
            if ($shaped === null) {
                $this->logger->warn('addBreadcrumb() needs an array with a message or data; nothing was added');

                return;
            }
            $hooked = Hooks::run($this->o->beforeBreadcrumb, $shaped);
            if ($hooked['value'] !== null) {
                // The scope shapes it again: what a hook returned meets the same bounds.
                $this->scopes->current()->addBreadcrumb($hooked['value']);
            }
        });
    }

    /**
     * @param string                $key
     * @param string|int|float|bool $value
     */
    public function setTag(mixed $key, mixed $value): void
    {
        $this->guarded(fn () => $this->scopes->current()->setTag($key, $value));
    }

    /**
     * @param array<string, string|int|float|bool> $tags
     */
    public function setTags(mixed $tags): void
    {
        $this->guarded(fn () => $this->scopes->current()->setTags($tags));
    }

    /**
     * Error context for this scope: sent with errors, never with analytics events.
     *
     * @param array<string, mixed>|null $context
     */
    public function setContext(mixed $context): void
    {
        $this->guarded(fn () => $this->scopes->current()->setContext($context));
    }

    // Scopes ----------------------------------------------------------------------------------------

    /** The current scope. */
    public function scope(): Scope
    {
        return $this->guarded(fn (): Scope => $this->scopes->current(), $this->root);
    }

    /**
     * Run $work in a child of the current scope: it starts as a copy, and what changes inside stays
     * inside. Returns what $work returns, and lets what it throws through, unreported. That is the
     * application's own exception, and the only thing this class ever lets out. Given something that
     * is not callable, it logs that and returns null.
     *
     * @template T
     *
     * @param callable(Scope): T $work
     *
     * @return T|null
     */
    public function withScope(mixed $work): mixed
    {
        if (!\is_callable($work)) {
            $this->logger->warn('withScope() needs a callable; '.Input::describe($work).' was not run');

            return null;
        }

        return $this->scopes->run($this->scopes->current()->fork(), $work);
    }

    /**
     * Start a fresh scope and make it current for the rest of this request or job: the process scope's
     * tags, context and registered properties, and never a user, device, session, request or
     * breadcrumbs from earlier work.
     */
    public function enterScope(): Scope
    {
        return $this->guarded(function (): Scope {
            $fresh = $this->root->detached();
            $this->scopes->enter($fresh);

            return $fresh;
        }, $this->root);
    }

    /**
     * Adopt the browser SDK's device and session from an inbound request, so this request's events and
     * errors stitch to the visitor who made it. Accepts `getallheaders()`, a PSR-7 style
     * `getHeaders()` array, or `$_SERVER`. Ids are validated and are correlation, never authentication.
     *
     * @param array<string, mixed> $headers
     */
    public function scopeFromHeaders(mixed $headers): Scope
    {
        return $this->guarded(function () use ($headers): Scope {
            $scope = $this->scopes->current();
            $headers = Input::map($headers);
            if ($headers === null) {
                $this->logger->warn('scopeFromHeaders() needs an array of headers; nothing was adopted');

                return $scope;
            }
            $found = [];
            foreach ($headers as $name => $value) {
                $key = strtolower(str_replace('_', '-', $name));
                if (str_starts_with($key, 'http-')) {
                    $key = substr($key, 5);
                }
                $found[$key] = Input::header($value);
            }
            $device = Ids::pickId($found['x-vinktar-device-id'] ?? null);
            $session = Ids::pickId($found['x-vinktar-session-id'] ?? null);
            if ($device !== null) {
                $scope->setDeviceId($device);
            }
            if ($session !== null) {
                $scope->setSessionId($session);
            }

            return $scope;
        }, $this->root);
    }

    /**
     * Report uncaught exceptions, PHP warnings and fatal errors, for this client and every other client
     * that asks. Opt-in (or `captureErrors`), because installing a handler changes what the process
     * does: the handlers that were there before still run, and a crash still ends the script with the
     * output and exit code PHP gives it without the SDK.
     */
    public function registerHandlers(): void
    {
        $this->guarded(function (): void {
            if ($this->handlersInstalled || $this->closed || $this->o->inert !== null) {
                return;
            }
            $this->handlersInstalled = true;
            Handlers::register($this, static function (object $client, string $kind, array $details): void {
                if ($client instanceof self) {
                    $client->handle($kind, $details);
                }
            }, $this->o->previousHandlerLevels, $this->logger);
        });
    }

    // Lifecycle -------------------------------------------------------------------------------------

    /**
     * Send what is queued. True only when everything queued when the call started was accepted by the
     * server; false while any of it is held, retrying, or was refused. Safe to call again later.
     */
    public function flush(): bool
    {
        return $this->guarded(function (): bool {
            if ($this->o->inert !== null) {
                return true;
            }
            if ($this->closed) {
                return $this->closeResult ?? false;
            }

            return $this->dispatcher->flush();
        }, false);
    }

    /**
     * Refuse new work at once, spend at most `shutdownTimeout` delivering what is queued, and stop.
     * Every call gets the first call's answer. What could not be delivered is counted as lost.
     */
    public function close(): bool
    {
        if ($this->closeResult !== null) {
            return $this->closeResult;
        }
        $this->closed = true;
        if ($this->o->inert !== null) {
            return $this->closeResult = true;
        }

        return $this->closeResult = $this->guarded(function (): bool {
            try {
                return $this->dispatcher->flush($this->deadline()) && $this->dispatcher->pending() === 0;
            } finally {
                $left = $this->dispatcher->pending();
                if ($left > 0) {
                    $this->dispatcher->abandon('send_error');
                    $this->logger->warn("close(): {$left} record(s) could not be delivered and are lost");
                }
                $this->dispatcher->stop();
            }
        }, false);
    }

    // Internals -------------------------------------------------------------------------------------

    /**
     * What a process-wide handler saw.
     *
     * @param array<string, mixed> $details
     */
    private function handle(string $kind, array $details): void
    {
        $this->guarded(function () use ($kind, $details): void {
            if ($this->closed || $this->o->inert !== null || $this->dispatcher->isStopped()) {
                return;
            }

            if ($kind === 'exception' && $details['error'] instanceof \Throwable) {
                $this->emit($this->exceptions->fromThrowable($details['error']), false, 'uncaughtException', false, [], 'error');
                // The script ends here; the shutdown flush may never get its turn.
                $this->dispatcher->flush($this->deadline());

                return;
            }

            $type = \is_int($details['type'] ?? null) ? $details['type'] : 0;
            $message = \is_string($details['message'] ?? null) ? $details['message'] : '';
            $file = \is_string($details['file'] ?? null) ? $details['file'] : '';
            $line = \is_int($details['line'] ?? null) ? $details['line'] : 0;

            if ($kind === 'error') {
                if (($type & Handlers::REPORTED) === 0) {
                    // Notices and deprecations: context for the next error, not errors of their own.
                    $this->addBreadcrumb(['category' => 'php', 'message' => $message, 'level' => 'warning', 'data' => ['type' => Handlers::typeName($type), 'file' => $file, 'line' => $line]]);

                    return;
                }
                $trace = \is_array($details['trace'] ?? null) ? array_values(array_filter($details['trace'], \is_array(...))) : [];
                /** @var list<array<string, mixed>> $trace */
                $level = ($type & (\E_USER_ERROR | \E_RECOVERABLE_ERROR)) !== 0 ? 'error' : 'warning';
                $this->emit(ExceptionBuilder::fromMessage($message, $this->exceptions->frames($trace, $file, $line), Handlers::typeName($type)), false, 'onerror', true, [], $level);

                return;
            }

            if ($kind === 'fatal') {
                // No stack survives a fatal error; the location is all PHP keeps, so the frame is synthetic.
                $this->emit(ExceptionBuilder::fromMessage($message, $this->exceptions->frames([], $file, $line), Handlers::typeName($type)), true, 'uncaughtException', false, [], 'fatal');
                $this->dispatcher->flush($this->deadline());
            }
        });
    }

    private function flushAtShutdown(): void
    {
        if ($this->closed || $this->o->inert !== null) {
            return;
        }
        $this->dispatcher->flush($this->deadline());
        $left = $this->dispatcher->pending();
        if ($left > 0) {
            $this->logger->warn("{$left} record(s) were not delivered before the process ended");
        }
    }

    /**
     * @param list<WireException>  $exceptions
     * @param array<string, mixed> $hint
     */
    private function emit(array $exceptions, bool $synthetic, string $mechanism, bool $handled, array $hint, string $level): string
    {
        if (!$this->o->errors) {
            $this->logger->debug('errors is off; nothing was sent');

            return '';
        }
        $first = $exceptions[0] ?? null;
        if ($first === null) {
            return '';
        }
        $message = $first['value'];
        if (InboundFilter::isSuppressed($message, array_map(static fn (array $f): string => $f['file'], $first['stack']))) {
            return '';
        }
        foreach ($this->o->ignoreErrors as $pattern) {
            if ($pattern !== '' && (str_contains($message, $pattern) || str_contains($first['type'].': '.$message, $pattern))) {
                $this->logger->debug('ignored by ignoreErrors', ['message' => $message]);

                return '';
            }
        }

        $scope = $this->scopes->current();
        $explicit = self::override($hint, 'userId', Ids::validUserId(...));
        // An unusable explicit user is not replaced by the scope's: the error still goes, with no identity.
        $anonymous = $explicit === false;
        if ($anonymous) {
            $this->logger->warn('an explicit userId is not a usable id; the error is sent with no identity rather than as someone else');
        }
        $userId = $anonymous ? null : ($explicit ?? $scope->userId());
        $deviceId = $anonymous ? null : $scope->deviceId();
        $sessionId = $anonymous ? null : $scope->sessionId();

        // A repeat is the same error for the same actor. Two people hitting one bug are two occurrences.
        if ($this->o->dedupe && $this->dedupe->isDuplicate(ExceptionBuilder::key($exceptions).'|'.($userId ?? '').'|'.($deviceId ?? ''))) {
            $this->drop('deduplicated', 'error');
            $this->logger->debug('a repeat of an error sent moments ago; counted, not sent', ['message' => $message]);

            return '';
        }
        if (!$this->errorValve->take() || !$this->typeValve->take($first['type'])) {
            $this->drop('ratelimit', 'error');
            $this->logger->warn("more than {$this->o->maxErrorsPerMinute} errors in a minute; dropping until the valve refills");

            return '';
        }
        if (!Sampling::sampled(ExceptionBuilder::issueKey($exceptions), $this->o->errorSampleRate)) {
            $this->drop('sample_rate', 'error');

            return '';
        }

        $id = Ids::hexId();
        $hintContext = \is_array($hint['context'] ?? null) ? $hint['context'] : [];
        $hintTags = \is_array($hint['tags'] ?? null) ? $hint['tags'] : [];
        $event = [
            'event_id' => $id,
            'timestamp' => Clock::iso(),
            'level' => \in_array($level, Limits::LEVELS, true) ? $level : 'error',
            'exceptions' => $exceptions,
            'mechanism' => ['type' => $mechanism, 'handled' => $handled, 'synthetic' => $synthetic],
            'environment' => $this->o->environment,
            'context' => $this->normalizer->normalize(array_replace($this->context, $scope->context(), $hintContext)),
        ];
        if ($userId !== null) {
            $event['user_id'] = $userId;
        }
        if ($deviceId !== null) {
            $event['device_id'] = $deviceId;
        }
        if ($sessionId !== null) {
            $event['session_id'] = $sessionId;
        }
        if ($this->o->release !== '') {
            $event['release'] = $this->o->release;
        }
        $tags = Normalizer::tags(array_replace($scope->tags(), $hintTags), fn (string $key) => $this->logger->warn("tag \"{$key}\" dropped: at most ".Limits::MAX_TAGS.' tags'));
        if ($tags !== []) {
            $event['tags'] = $tags;
        }
        $crumbs = $scope->breadcrumbs();
        if ($crumbs !== []) {
            $event['breadcrumbs'] = $crumbs;
        }
        $request = $this->requestBlock($scope->request());
        if ($request !== null) {
            $event['request'] = $request;
        }
        if (\is_array($hint['fingerprint'] ?? null) && $hint['fingerprint'] !== []) {
            $event['fingerprint'] = array_map(
                static fn (mixed $part): string => Bytes::truncate(\is_scalar($part) ? (string) $part : '', Limits::MAX_FINGERPRINT_PART_BYTES),
                \array_slice(array_values($hint['fingerprint']), 0, Limits::MAX_FINGERPRINT_PARTS),
            );
        }

        $hooked = Hooks::run($this->o->beforeSend, $event);
        if ($hooked['value'] === null) {
            $this->drop('before_send', 'error');
            if ($hooked['threw'] !== null) {
                $this->logger->warn('beforeSend threw; the error was dropped', ['error' => $hooked['threw']->getMessage()]);
            }

            return '';
        }
        $final = $this->o->beforeSend !== [] ? $this->renormalizeError($hooked['value']) : $hooked['value'];
        if (!$this->dispatcher->errors->push('error', $final)) {
            $this->drop('before_send', 'error');
            $this->logger->warn('an error was dropped: beforeSend returned something that cannot be sent (a resource, NAN or INF, or not an array)');

            return '';
        }
        $this->afterCapture();

        return $id;
    }

    /**
     * @param array<string, mixed>|null $request
     *
     * @return array<string, mixed>|null
     */
    private function requestBlock(?array $request): ?array
    {
        if ($request === null) {
            return null;
        }
        $out = [];
        if (\is_string($request['method'] ?? null)) {
            $out['method'] = strtoupper($request['method']);
        }
        if (\is_string($request['url'] ?? null)) {
            $url = $this->o->sendDefaultPii ? $request['url'] : explode('?', $request['url'], 2)[0];
            $out['url'] = Bytes::truncate($url, 1024);
            if ($this->o->sendDefaultPii && \is_string($request['query'] ?? null)) {
                $out['query'] = Bytes::truncate($request['query'], 1024);
            }
        }
        if (\is_array($request['headers'] ?? null)) {
            $headers = [];
            foreach ($request['headers'] as $key => $value) {
                $name = strtolower((string) $key);
                $value = Input::header($value);
                if (\in_array($name, self::NEVER_HEADERS, true) || $value === null) {
                    continue;
                }
                if (!$this->o->sendDefaultPii && !\in_array($name, self::SAFE_HEADERS, true)) {
                    continue;
                }
                if (\count($headers) >= 50) {
                    break;
                }
                $headers[Bytes::truncate($name, 128)] = Bytes::truncate($value, 1024);
            }
            if ($headers !== []) {
                $out['headers'] = $headers;
            }
        }

        return $out === [] ? null : $out;
    }

    /**
     * @return array<string, mixed>
     */
    private function baseContext(): array
    {
        $context = [
            '$lib' => Version::LIB,
            '$lib_version' => Version::VERSION,
            '$environment' => $this->o->environment,
            '$runtime' => 'php',
            '$runtime_version' => \PHP_VERSION,
        ];
        if ($this->o->release !== '') {
            $context['$release'] = $this->o->release;
        }
        if ($this->o->serverName !== '') {
            $context['$server_name'] = $this->o->serverName;
        }

        return $context;
    }

    private function ready(string $method): bool
    {
        if ($this->closed) {
            $this->logger->warn("{$method}() after close() does nothing");

            return false;
        }
        if ($this->o->inert !== null) {
            $this->logger->debug("{$method}(): inert ({$this->o->inert})");

            return false;
        }
        if ($this->dispatcher->isStopped()) {
            // Already said once, loudly, when the server refused the key or redirected.
            $this->logger->debug("{$method}(): sending has stopped");

            return false;
        }

        return true;
    }

    /**
     * @param (callable(string): void) $work
     */
    private function withUser(string $method, callable $work): void
    {
        $this->guarded(function () use ($method, $work): void {
            $id = $this->scopes->current()->userId();
            if ($id === null) {
                $this->logger->warn("{$method}() was called with no user on the scope; call identify() first, so nothing was sent");

                return;
            }
            $work($id);
        });
    }

    /** Count a record the SDK did not send. The count goes out with the next request. */
    private function drop(string $reason, string $category): void
    {
        $this->dispatcher->reports->record($reason, $category);
    }

    /**
     * The queue reached `flushAt`. This send runs inside the application's own track() or
     * captureException(), so it gets the budget the shutdown flush gets, one deadline for both
     * queues, and not a full request timeout for each. When the host does not answer, the backoff
     * holds its categories, and the captures that follow return without trying.
     */
    private function afterCapture(): void
    {
        if ($this->dispatcher->events->count() >= $this->o->flushAt || $this->dispatcher->errors->count() >= $this->o->flushAt) {
            $this->dispatcher->flush($this->deadline());
        }
    }

    /** When a flush that must not hold the application up stops starting requests: `shutdownTimeout` from now. */
    private function deadline(): float
    {
        return microtime(true) + $this->o->shutdownTimeout / 1000;
    }

    /**
     * An argument documented as an array. Null is "not given"; anything else that is not an array is
     * said once and treated as empty, so the call still does what the rest of it asked.
     *
     * @return array<string, mixed>
     */
    private function arrayArgument(string $method, string $name, mixed $value): array
    {
        $map = Input::map($value);
        if ($map === null && $value !== null) {
            $this->logger->warn("{$method}(): {$name} must be an array; ".Input::describe($value).' was ignored');
        }

        return $map ?? [];
    }

    private function timestampFor(mixed $value, string $name): ?string
    {
        $now = microtime(true);
        if ($value === null) {
            return Clock::iso($now);
        }
        $at = match (true) {
            $value instanceof \DateTimeInterface => (float) $value->format('U.u'),
            \is_int($value), \is_float($value) => $value / 1000,
            \is_string($value) => ($parsed = strtotime($value)) === false ? null : (float) $parsed,
            default => null,
        };
        if ($at === null || !is_finite($at)) {
            $this->logger->warn("\"{$name}\" has an unreadable timestamp; using now");

            return Clock::iso($now);
        }
        if ($at < $now - Limits::TIMESTAMP_PAST_SECONDS || $at > $now + Limits::TIMESTAMP_FUTURE_SECONDS) {
            $this->logger->warn("\"{$name}\" was dropped: its timestamp is outside the window the server accepts (7 days back, 1 hour ahead)");
            $this->drop('invalid', 'event');

            return null;
        }

        return Clock::iso($at);
    }

    /**
     * @param array<string, mixed> $payload
     * @param array<string, mixed> $context
     *
     * @return array<string, mixed>
     */
    private function capProperties(string $name, array $payload, array $context): array
    {
        return Normalizer::capCombined($payload, $context, Limits::MAX_PROPERTIES_PER_EVENT, fn (string $key) => $this->logger->warn(
            "property \"{$key}\" on \"{$name}\" was dropped: an event carries at most ".Limits::MAX_PROPERTIES_PER_EVENT.' properties and context together',
        ));
    }

    /** A hook may have added anything. Its output meets the same limits the SDK's own did. */
    private function renormalizeEvent(string $name, mixed $event): mixed
    {
        if (!\is_array($event)) {
            return $event;
        }
        $context = \is_array($event['context'] ?? null) ? $this->normalizer->normalize($event['context']) : [];
        if (\is_array($event['context'] ?? null)) {
            $event['context'] = $context;
        }
        if (\is_array($event['payload'] ?? null)) {
            $event['payload'] = $this->capProperties($name, $this->normalizer->normalize($event['payload']), $context);
        }

        return $event;
    }

    private function renormalizeError(mixed $event): mixed
    {
        if (!\is_array($event)) {
            return $event;
        }
        if (\is_array($event['context'] ?? null)) {
            $event['context'] = $this->normalizer->normalize($event['context']);
        }
        if (\is_array($event['tags'] ?? null)) {
            $event['tags'] = Normalizer::tags($event['tags']);
        }

        return $event;
    }

    /**
     * Not given (null), given and usable (the id), or given and unusable (false): three different things.
     *
     * @param array<array-key, mixed>        $options
     * @param callable(mixed): (string|null) $validate
     */
    private static function override(array $options, string $key, callable $validate): string|false|null
    {
        if (!isset($options[$key])) {
            return null;
        }

        return $validate($options[$key]) ?? false;
    }

    /**
     * @template T
     *
     * @param callable(): T $work
     * @param T             $fallback
     *
     * @return T
     */
    private function guarded(callable $work, mixed $fallback = null): mixed
    {
        try {
            return $work();
        } catch (\Throwable $error) {
            $this->logger->error('internal failure', ['error' => $error->getMessage()]);
            try {
                if ($this->o->onError !== null) {
                    ($this->o->onError)($error);
                }
            } catch (\Throwable) {
                // The application's handler threw. Nothing more can be done about that here.
            }

            return $fallback;
        }
    }
}
