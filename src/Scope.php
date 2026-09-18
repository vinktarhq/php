<?php

declare(strict_types=1);

namespace Vinktar;

use Vinktar\Internal\Breadcrumbs;
use Vinktar\Internal\Ids;
use Vinktar\Internal\Input;
use Vinktar\Internal\Logger;
use Vinktar\Internal\Normalizer;

/**
 * Who and what an event belongs to, for one unit of work.
 *
 * A scope holds everything that belongs to one request or job: the identity (user, device,
 * session), tags, error context, the request, the breadcrumb trail, and the properties registered
 * for analytics. Nothing a request or job sets lives anywhere else, so nothing it sets can reach
 * another one. What is configured once for the whole service (`superProperties`, `initialScope`
 * tags and context) lives on the client.
 *
 * Get the current one with {@see Client::scope()}.
 *
 * Like the client, nothing here throws into your code, whatever it is handed. The types are in the
 * PHPDoc and the signatures accept anything: an argument that cannot be used is logged and ignored.
 * The client's methods of the same name are these, on the current scope.
 *
 * @phpstan-import-type Crumb from Breadcrumbs
 */
final class Scope
{
    private ?string $userId = null;
    private ?string $deviceId = null;
    private ?string $sessionId = null;
    /** @var array<string, string> */
    private array $tags = [];
    /** @var array<string, mixed> */
    private array $context = [];
    /** @var array<string, mixed> */
    private array $properties = [];
    /** @var array<string, mixed>|null */
    private ?array $request = null;
    private Breadcrumbs $breadcrumbs;
    private readonly Logger $logger;
    private readonly Normalizer $normalizer;

    /**
     * @internal scopes are made by the client
     *
     * @param array<string, string> $tags
     * @param array<string, mixed>  $context
     */
    public function __construct(private readonly int $maxBreadcrumbs, array $tags = [], array $context = [], ?Logger $logger = null, ?Normalizer $normalizer = null)
    {
        $this->breadcrumbs = new Breadcrumbs($maxBreadcrumbs);
        $this->tags = $tags;
        $this->context = $context;
        $this->logger = $logger ?? new Logger();
        $this->normalizer = $normalizer ?? new Normalizer();
    }

    public function userId(): ?string
    {
        return $this->userId;
    }

    public function deviceId(): ?string
    {
        return $this->deviceId;
    }

    public function sessionId(): ?string
    {
        return $this->sessionId;
    }

    /** @return array<string, string> */
    public function tags(): array
    {
        return $this->tags;
    }

    /** @return array<string, mixed> */
    public function context(): array
    {
        return $this->context;
    }

    /** @return array<string, mixed> */
    public function properties(): array
    {
        return $this->properties;
    }

    /** @return array<string, mixed>|null */
    public function request(): ?array
    {
        return $this->request;
    }

    /** @return list<Crumb> */
    public function breadcrumbs(): array
    {
        return $this->breadcrumbs->list();
    }

    /**
     * @internal set through Client::identify(), setUser() and scopeFromHeaders()
     *
     * @param string|int|null $userId
     */
    public function setUserId(mixed $userId): void
    {
        $this->userId = $this->idFrom('userId', $userId, Ids::validUserId(...), $this->userId);
    }

    /**
     * @internal
     *
     * @param string|null $deviceId
     */
    public function setDeviceId(mixed $deviceId): void
    {
        $this->deviceId = $this->idFrom('deviceId', $deviceId, Ids::pickId(...), $this->deviceId);
    }

    /**
     * @internal
     *
     * @param string|null $sessionId
     */
    public function setSessionId(mixed $sessionId): void
    {
        $this->sessionId = $this->idFrom('sessionId', $sessionId, Ids::pickId(...), $this->sessionId);
    }

    /**
     * @param string                $key
     * @param string|int|float|bool $value
     */
    public function setTag(mixed $key, mixed $value): void
    {
        $this->tag($key, $value);
    }

    private function tag(mixed $key, mixed $value): void
    {
        $name = Input::text($key);
        $text = Input::tagValue($value);
        if ($name === null || $name === '' || $text === null) {
            $this->logger->warn('setTag() needs a key and a string, number or boolean value; '.Input::describe($key).' => '.Input::describe($value).' was ignored');

            return;
        }
        $this->tags[$name] = $text;
    }

    /**
     * @param array<string, string|int|float|bool> $tags
     */
    public function setTags(mixed $tags): void
    {
        $tags = Input::map($tags);
        if ($tags === null) {
            $this->logger->warn('setTags() needs an array; nothing was set');

            return;
        }
        foreach ($tags as $key => $value) {
            $this->tag($key, $value);
        }
    }

    /**
     * Merge into the error context; null clears it.
     *
     * @param array<string, mixed>|null $context
     */
    public function setContext(mixed $context): void
    {
        if ($context === null) {
            $this->context = [];

            return;
        }
        $context = Input::map($context);
        if ($context === null) {
            $this->logger->warn('setContext() needs an array, or null to clear it; nothing was set');

            return;
        }
        $this->context = array_replace($this->context, $context);
    }

    /**
     * The HTTP request this unit of work serves, attached to its errors: `url`, `method`, `headers`,
     * `query`. Headers other than a safe few are left out unless `sendDefaultPii` is on.
     *
     * @param array<string, mixed>|null $request
     */
    public function setRequest(mixed $request): void
    {
        if ($request !== null && !\is_array($request)) {
            $this->logger->warn('setRequest() needs an array, or null to clear it; nothing was set');

            return;
        }
        $this->request = Input::map($request);
    }

    /**
     * @internal use Client::addBreadcrumb(), which runs the `beforeBreadcrumb` hooks
     */
    public function addBreadcrumb(mixed $crumb): void
    {
        try {
            // Shaped here as well as in the client, so what a hook returned, or what was passed to
            // the scope directly, meets the same bounds before it is kept.
            $shaped = Breadcrumbs::shape($crumb, $this->normalizer);
        } catch (\Throwable) {
            $shaped = null;
        }
        if ($shaped === null) {
            $this->logger->warn('addBreadcrumb() needs an array with a message or data; nothing was added');

            return;
        }
        $this->breadcrumbs->add($shaped);
    }

    /**
     * @param array<string, mixed> $properties
     */
    public function register(mixed $properties): void
    {
        $properties = Input::map($properties);
        if ($properties === null) {
            $this->logger->warn('register() needs an array; nothing was registered');

            return;
        }
        $this->properties = array_replace($this->properties, $properties);
    }

    /**
     * @param array<string, mixed> $properties
     */
    public function registerOnce(mixed $properties): void
    {
        $properties = Input::map($properties);
        if ($properties === null) {
            $this->logger->warn('registerOnce() needs an array; nothing was registered');

            return;
        }
        $this->properties += $properties;
    }

    /**
     * @param string $key
     */
    public function unregister(mixed $key): void
    {
        $name = Input::text($key);
        if ($name === null) {
            $this->logger->warn('unregister() needs a key; '.Input::describe($key).' was ignored');

            return;
        }
        unset($this->properties[$name]);
    }

    /** @internal a copy for nested work: changes inside stay inside */
    public function fork(): self
    {
        $child = new self($this->maxBreadcrumbs, $this->tags, $this->context, $this->logger, $this->normalizer);
        $child->userId = $this->userId;
        $child->deviceId = $this->deviceId;
        $child->sessionId = $this->sessionId;
        $child->request = $this->request;
        $child->properties = $this->properties;
        foreach ($this->breadcrumbs->list() as $crumb) {
            $child->breadcrumbs->add($crumb);
        }

        return $child;
    }

    /**
     * @internal a new request or job: this scope's tags, context and registered properties, and
     * nothing that identifies anyone or belongs to earlier work
     */
    public function detached(): self
    {
        $scope = new self($this->maxBreadcrumbs, $this->tags, $this->context, $this->logger, $this->normalizer);
        $scope->properties = $this->properties;

        return $scope;
    }

    /**
     * @internal everything cleared, registered properties included, then the configured defaults applied
     *
     * @param array<string, string> $tags
     * @param array<string, mixed>  $context
     */
    public function reset(mixed $tags = [], mixed $context = []): void
    {
        $this->userId = null;
        $this->deviceId = null;
        $this->sessionId = null;
        $this->tags = [];
        foreach (Input::map($tags) ?? [] as $key => $value) {
            $this->tag($key, $value);
        }
        $this->context = Input::map($context) ?? [];
        $this->properties = [];
        $this->request = null;
        $this->breadcrumbs->clear();
    }

    /**
     * Null clears the id. Anything else is validated, and an id that cannot be used leaves the one
     * that is there: better an event with the visitor it had than one with nobody.
     *
     * @param callable(mixed): (string|null) $validate
     */
    private function idFrom(string $which, mixed $value, callable $validate, ?string $current): ?string
    {
        if ($value === null) {
            return null;
        }
        $id = $validate($value);
        if ($id === null) {
            $this->logger->warn("{$which} ".Input::describe($value).' is not a usable id and was ignored');

            return $current;
        }

        return $id;
    }
}
