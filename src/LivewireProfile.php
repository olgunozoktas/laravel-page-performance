<?php

declare(strict_types=1);

namespace Olgun\PagePerformance;

use Livewire\Component;
use Livewire\EventBus;

/**
 * Collects the per-phase timings Livewire already computes and throws away.
 *
 * LIVEWIRE PROFILES ITSELF AND NOTHING LISTENS. `HandleComponents` fires
 * `trigger('profile', $phase, $componentId, [$start, microtime(true)])` around
 * mount, hydrate, render, dehydrate and every action call, and
 * `SupportNestingComponents` fires one more around each child render. None of
 * it is in the documentation; it was found by reading the package source. So
 * the per-component measurer is not a profiler to build — it is a listener to
 * register.
 *
 * IT IS NOT REGISTERED ANYWHERE. No service provider binds this, no middleware
 * constructs it, and no request a visitor makes reaches it. That is the whole
 * answer to "what does it cost in production": there is no production code path
 * to it. A provider hook would cost a branch on every request forever and would
 * need an environment gate that can be set wrong.
 *
 * IT REFUSES TO BE SILENTLY EMPTY. Every one of Livewire's eight `profile`
 * triggers is wrapped in `if (config('app.debug'))`. With debug off this
 * collects nothing — and a zero here would read as "Livewire is fast", which is
 * the worst failure available. `available()` says so, and the command prints it
 * rather than reporting zeros.
 *
 * THE MEMO IS AN INSTANCE, NEVER `static` OR `once()`. Both of those live for
 * the whole PHP process: in a test suite that means one measurement reaching
 * the next, and this application already carries a live example of the damage
 * in `SellingSwitch::configurationIsTrustworthy()`.
 */
final class LivewireProfile
{
    /**
     * Above this, it stops appending and SAYS it stopped. A silent cap reports
     * a lighter page than the one that ran.
     */
    private const int MAX_SPANS = 5_000;

    /** @var list<Span> */
    private array $spans = [];

    /** @var array<string, string> component id => component name */
    private array $names = [];

    /** @var array<string, string> child id => parent id */
    private array $parents = [];

    private bool $truncated = false;

    /** @var list<callable(): void> */
    private array $unsubscribers = [];

    /**
     * Whether Livewire will emit any timing at all in this process.
     *
     * This is a permanent ceiling, not a setting to change: production runs
     * `APP_DEBUG=false` and must, so the phase breakdown is a local and staging
     * measurement forever. Payload bytes are the metric that survives — see
     * {@see SnapshotReader}.
     */
    public static function installed(): bool
    {
        return class_exists(EventBus::class);
    }

    public static function available(): bool
    {
        return self::installed() && config()->boolean('app.debug');
    }

    /** Why there will be no per-component timing, in words a reader can act on. */
    public static function unavailableReason(): string
    {
        if (! self::installed()) {
            return 'Livewire is not installed, so there are no components to time. Page-level measurement is unaffected.';
        }

        return 'Livewire gates its own profile event on app.debug, which is false. This is a permanent ceiling in production, not a setting to change.';
    }

    public function subscribe(): void
    {
        // Livewire is optional. Without it there is nothing to subscribe to,
        // and every page-level number this package reports is unaffected.
        if (! self::installed()) {
            return;
        }

        /*
         * The EventBus directly, not `Livewire::listen()`. The facade method is
         * typed `void` even though it forwards a `callable` unsubscriber back
         * from the bus, so taking the return value through it is a static
         * analysis error at level max — and dropping the unsubscriber would
         * leak a listener into every later iteration of the sweep.
         */
        $bus = resolve(EventBus::class);

        $this->unsubscribers[] = $bus->on('mount', function (Component $component, mixed $params = null, mixed $key = null, mixed $parent = null): void {
            $id = $this->idOf($component);

            if ($id === null) {
                return;
            }

            $this->names[$id] = $this->nameOf($component);

            $parentId = $parent instanceof Component ? $this->idOf($parent) : null;

            if ($parentId !== null) {
                $this->parents[$id] = $parentId;
            }
        });

        // `mount` never fires on an update, so hydrate is the only place a name
        // can be learned for a component that arrived as a snapshot.
        $this->unsubscribers[] = $bus->on('hydrate', function (Component $component): void {
            $id = $this->idOf($component);

            if ($id !== null) {
                $this->names[$id] = $this->nameOf($component);
            }
        });

        $this->unsubscribers[] = $bus->on('profile', function (string $phase, string $componentId, array $times): void {
            if (count($this->spans) >= self::MAX_SPANS) {
                $this->truncated = true;

                return;
            }

            /** @var array{0: float, 1: float} $times */
            $this->spans[] = Span::fromTrigger($phase, $componentId, $times);
        });
    }

    /**
     * Livewire types `getId()` as mixed. A component without a usable id cannot
     * be attributed to, so it is dropped rather than keyed on something that is
     * not a string — which would merge two components into one row.
     */
    /** Livewire types `getName()` as mixed too; an unnamed component is shown by id. */
    private function nameOf(Component $component): string
    {
        $name = $component->getName();

        return is_string($name) && $name !== '' ? $name : 'unknown';
    }

    private function idOf(Component $component): ?string
    {
        $id = $component->getId();

        return is_string($id) && $id !== '' ? $id : null;
    }

    public function unsubscribe(): void
    {
        foreach ($this->unsubscribers as $off) {
            $off();
        }

        $this->unsubscribers = [];
    }

    /** Between iterations. Names and parents are kept: they do not change per run. */
    public function reset(): void
    {
        $this->spans = [];
        $this->truncated = false;
    }

    /**
     * One entry per component, worst self-time first.
     *
     * @return list<ComponentTiming>
     */
    public function timings(): array
    {
        $byComponent = [];

        foreach ($this->spans as $span) {
            $byComponent[$span->componentId][] = $span;
        }

        $timings = [];

        foreach ($byComponent as $id => $spans) {
            $timings[] = ComponentTiming::build((string) $id, $spans, $this->names, $this->parents[$id] ?? null, 0);
        }

        usort($timings, static fn (ComponentTiming $a, ComponentTiming $b): int => $b->selfMs() <=> $a->selfMs());

        return $timings;
    }

    /**
     * Every millisecond Livewire accounted for, children counted once.
     *
     * Sums `selfMs` rather than `renderMs`, because a parent's render already
     * contains its children and adding both would charge nesting twice.
     */
    public function livewireMs(): float
    {
        $total = 0.0;

        foreach ($this->timings() as $timing) {
            $total += $timing->selfMs() + $timing->ms('mount') + $timing->ms('hydrate') + $timing->ms('dehydrate') + $timing->ms('call');
        }

        return $total;
    }

    /**
     * Child id => milliseconds the PARENT spent rendering that child.
     *
     * Needed because `child-heavy` is about the largest single child, not the
     * sum: a shell mounting three children at 10% each is a normal composition,
     * not a finding.
     *
     * @return array<string, float>
     */
    public function childMilliseconds(): array
    {
        $byChild = [];

        foreach ($this->spans as $span) {
            $childId = $span->childId();

            if ($childId !== null) {
                $byChild[$childId] = ($byChild[$childId] ?? 0.0) + $span->ms;
            }
        }

        return $byChild;
    }

    public function truncated(): bool
    {
        return $this->truncated;
    }

    public function spanCount(): int
    {
        return count($this->spans);
    }
}
