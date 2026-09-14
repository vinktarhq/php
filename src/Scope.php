<?php

declare(strict_types=1);

namespace Vinktar;

use Vinktar\Internal\Breadcrumbs;

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

    /**
     * @internal scopes are made by the client
     *
     * @param array<string, string> $tags
     * @param array<string, mixed>  $context
     */
    public function __construct(private readonly int $maxBreadcrumbs, array $tags = [], array $context = [])
    {
        $this->breadcrumbs = new Breadcrumbs($maxBreadcrumbs);
        $this->tags = $tags;
        $this->context = $context;
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

    /** @internal set through Client::identify(), setUser() and scopeFromHeaders(), which validate the id */
    public function setUserId(?string $userId): void
    {
        $this->userId = $userId;
    }

    /** @internal */
    public function setDeviceId(?string $deviceId): void
    {
        $this->deviceId = $deviceId;
    }

    /** @internal */
    public function setSessionId(?string $sessionId): void
    {
        $this->sessionId = $sessionId;
    }

    public function setTag(string $key, string $value): void
    {
        if ($key !== '') {
            $this->tags[$key] = $value;
        }
    }

    /**
     * @param array<string, string> $tags
     */
    public function setTags(array $tags): void
    {
        foreach ($tags as $key => $value) {
            $this->setTag((string) $key, (string) $value);
        }
    }

    /**
     * Merge into the error context; null clears it.
     *
     * @param array<string, mixed>|null $context
     */
    public function setContext(?array $context): void
    {
        $this->context = $context === null ? [] : array_replace($this->context, $context);
    }

    /**
     * The HTTP request this unit of work serves, attached to its errors: `url`, `method`, `headers`,
     * `query`. Headers other than a safe few are left out unless `sendDefaultPii` is on.
     *
     * @param array<string, mixed>|null $request
     */
    public function setRequest(?array $request): void
    {
        $this->request = $request;
    }

    /**
     * @internal use Client::addBreadcrumb(), which shapes and bounds it
     *
     * @param Crumb $crumb
     */
    public function addBreadcrumb(array $crumb): void
    {
        $this->breadcrumbs->add($crumb);
    }

    /**
     * @param array<string, mixed> $properties
     */
    public function register(array $properties): void
    {
        $this->properties = array_replace($this->properties, $properties);
    }

    /**
     * @param array<string, mixed> $properties
     */
    public function registerOnce(array $properties): void
    {
        $this->properties += $properties;
    }

    public function unregister(string $key): void
    {
        unset($this->properties[$key]);
    }

    /** @internal a copy for nested work: changes inside stay inside */
    public function fork(): self
    {
        $child = new self($this->maxBreadcrumbs, $this->tags, $this->context);
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
        $scope = new self($this->maxBreadcrumbs, $this->tags, $this->context);
        $scope->properties = $this->properties;

        return $scope;
    }

    /**
     * @internal everything cleared, registered properties included, then the configured defaults applied
     *
     * @param array<string, string> $tags
     * @param array<string, mixed>  $context
     */
    public function reset(array $tags, array $context): void
    {
        $this->userId = null;
        $this->deviceId = null;
        $this->sessionId = null;
        $this->tags = $tags;
        $this->context = $context;
        $this->properties = [];
        $this->request = null;
        $this->breadcrumbs->clear();
    }
}
