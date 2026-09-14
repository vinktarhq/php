<?php

declare(strict_types=1);

namespace Vinktar\Internal;

use Vinktar\Scope;

/**
 * Where one client's current scope lives. Every client has its own, so a scope entered through one
 * client is invisible to another.
 *
 * A stack per execution context: the main program, and each running Fiber. Work run inside a Fiber
 * (ReactPHP, Amp, Revolt) keeps its scopes while other Fibers run, and a Fiber that has not entered
 * a scope of its own starts from the client's root scope, never from whichever Fiber ran last.
 * Nothing is kept in a static, so nothing survives the client.
 *
 * @internal
 */
final class ScopeStore
{
    /** @var \WeakMap<object, array{entered: Scope|null, stack: list<Scope>}> keyed by Fiber */
    private \WeakMap $fibers;

    /** @var array{entered: Scope|null, stack: list<Scope>} */
    private array $main = ['entered' => null, 'stack' => []];

    public function __construct(private readonly Scope $root)
    {
        $this->fibers = new \WeakMap();
    }

    public function current(): Scope
    {
        $state = $this->load();
        if ($state['stack'] !== []) {
            return $state['stack'][\count($state['stack']) - 1];
        }

        return $state['entered'] ?? $this->root;
    }

    /**
     * Run $work with $scope current, and put the previous scope back however it ends.
     *
     * @template T
     *
     * @param callable(Scope): T $work
     *
     * @return T
     */
    public function run(Scope $scope, callable $work): mixed
    {
        $state = $this->load();
        $state['stack'][] = $scope;
        $depth = \count($state['stack']);
        $this->save($state);
        try {
            return $work($scope);
        } finally {
            $state = $this->load();
            $state['stack'] = \array_slice($state['stack'], 0, $depth - 1);
            $this->save($state);
        }
    }

    /** Make $scope current for the rest of the enclosing run(), or of this context when there is none. */
    public function enter(Scope $scope): void
    {
        $state = $this->load();
        if ($state['stack'] !== []) {
            $state['stack'][\count($state['stack']) - 1] = $scope;
        } else {
            $state['entered'] = $scope;
        }
        $this->save($state);
    }

    /**
     * @return array{entered: Scope|null, stack: list<Scope>}
     */
    private function load(): array
    {
        $fiber = \Fiber::getCurrent();
        if ($fiber === null) {
            return $this->main;
        }

        return $this->fibers[$fiber] ?? ['entered' => null, 'stack' => []];
    }

    /**
     * @param array{entered: Scope|null, stack: list<Scope>} $state
     */
    private function save(array $state): void
    {
        $fiber = \Fiber::getCurrent();
        if ($fiber === null) {
            $this->main = $state;
        } elseif ($state['entered'] === null && $state['stack'] === []) {
            unset($this->fibers[$fiber]);
        } else {
            $this->fibers[$fiber] = $state;
        }
    }
}
