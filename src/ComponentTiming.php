<?php

declare(strict_types=1);

namespace Olgun\PagePerformance;

/**
 * Every span for one Livewire component, summed and named.
 *
 * WHY `selfMs` IS THE NUMBER THAT MATTERS. A parent's `render` span already
 * contains every child it rendered, so ranking components by render time ranks
 * parents first, always, and says nothing. `selfMs` subtracts the children back
 * out. Measured on this application's home page: `legacy-page` renders in
 * 26.15 ms and spends 5.65 ms of that inside `board.promotion-form`, so its own
 * Blade costs 20.50 ms. Those are two different findings and only the second
 * one names a file to open.
 *
 * WHY `child` IS NOT IN `totalMs`. It is a SUBSET of `render`, not another
 * phase beside it. Adding it would charge every child's time twice — once to
 * the child and once again to the parent — and inflate a page's apparent
 * Livewire cost by exactly the amount of nesting it happens to have.
 */
final readonly class ComponentTiming
{
    /** The phases that are genuinely additive. `child` is deliberately absent. */
    private const array ADDITIVE_PHASES = ['mount', 'hydrate', 'render', 'dehydrate', 'call'];

    /**
     * @param  array<string, float>  $phases  normalized phase => summed ms
     * @param  list<string>  $childIds  every child this component rendered
     */
    public function __construct(
        public string $id,
        public string $name,
        public ?string $parentId,
        public array $phases,
        public array $childIds,
        public int $snapshotBytes,
    ) {}

    /**
     * @param  list<Span>  $spans  every span whose componentId is this component
     * @param  array<string, string>  $names  component id => component name
     */
    public static function build(string $id, array $spans, array $names, ?string $parentId, int $snapshotBytes): self
    {
        $phases = [];
        $childIds = [];

        foreach ($spans as $span) {
            $phase = $span->normalizedPhase();
            $phases[$phase] = ($phases[$phase] ?? 0.0) + $span->ms;

            $childId = $span->childId();

            if ($childId !== null) {
                $childIds[] = $childId;
            }
        }

        return new self($id, $names[$id] ?? $id, $parentId, $phases, array_values(array_unique($childIds)), $snapshotBytes);
    }

    public function ms(string $phase): float
    {
        return $this->phases[$phase] ?? 0.0;
    }

    /** Time this component spent rendering, INCLUDING its children. */
    public function renderMs(): float
    {
        return $this->ms('render');
    }

    /** Time inside `renderMs` that belongs to children, not to this component. */
    public function childMs(): float
    {
        return $this->ms('child');
    }

    /**
     * This component's own render cost.
     *
     * Floored at zero: a child cannot cost more than the render that contains
     * it, so a negative value is measurement noise on a sub-millisecond span,
     * never a real number worth reporting.
     */
    public function selfMs(): float
    {
        return max(0.0, $this->renderMs() - $this->childMs());
    }

    /** Every phase this component was charged for. Excludes `child` — see the class docblock. */
    public function totalMs(): float
    {
        $total = 0.0;

        foreach (self::ADDITIVE_PHASES as $phase) {
            $total += $this->ms($phase);
        }

        return $total;
    }

    /**
     * Does ONE child dominate this component's render?
     *
     * It used to compare the SUM of every child against the parent and then
     * print "N% of its render is one child" — a false sentence about a normal
     * composition. A shell mounting a nav, a search box and a toast region at
     * 10% each reported 30% and named no child at all, and `child-heavy` is a
     * DEFECT label, so a composed interface could never reach zero.
     *
     * It also needs the absolute floor every other ratio label carries: a
     * quarter of a 2 ms render is not worth anybody's afternoon.
     *
     * @param  array<string, float>  $childMs  child id => ms this parent spent on it
     */
    public function isChildHeavy(array $childMs = [], float $ratio = 0.25, float $floorMs = 10.0): bool
    {
        $largest = $this->largestChildMs($childMs);

        return $this->renderMs() >= $floorMs
            && $largest >= $floorMs
            && $largest / max($this->renderMs(), 0.01) >= $ratio;
    }

    /**
     * @param  array<string, float>  $childMs
     */
    public function largestChildMs(array $childMs = []): float
    {
        $mine = array_intersect_key($childMs, array_flip($this->childIds));

        return $mine === [] ? 0.0 : max($mine);
    }

    /**
     * @param  array<string, float>  $childMs
     */
    public function largestChildId(array $childMs = []): ?string
    {
        $mine = array_intersect_key($childMs, array_flip($this->childIds));

        if ($mine === []) {
            return null;
        }

        return (string) array_search(max($mine), $mine, true);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'component' => $this->name,
            'id' => $this->id,
            'parent_id' => $this->parentId,
            'phases' => $this->phases,
            'render_ms' => round($this->renderMs(), 2),
            'self_ms' => round($this->selfMs(), 2),
            'child_ms' => round($this->childMs(), 2),
            'total_ms' => round($this->totalMs(), 2),
            'children' => count($this->childIds),
            'snapshot_bytes' => $this->snapshotBytes,
        ];
    }
}
