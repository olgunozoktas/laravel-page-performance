<?php

declare(strict_types=1);

namespace Olgun\PagePerformance;

/**
 * One timed phase of one Livewire component, as a value.
 *
 * WHERE THESE COME FROM. Livewire already profiles itself. `HandleComponents`
 * fires `trigger('profile', $phase, $componentId, [$start, $end])` around mount,
 * hydrate, render, dehydrate and each action call, and
 * `SupportNestingComponents` fires one more around every child render. Nothing
 * in this application listened, so the numbers were computed and thrown away on
 * every request. This class is the shape they are kept in.
 *
 * THE ONE SUBTLETY, AND IT IS LOAD-BEARING. A `child:` span is fired as
 * `trigger('profile', 'child:'.$child->getId(), $parent->getId(), …)` — the
 * phase names the CHILD while the subject is the PARENT. The time it measures
 * is time the PARENT spent rendering that child, so it belongs to the parent's
 * ledger, and `$componentId` is already the right owner. What the phase adds is
 * only WHICH child it was spent on, which is `childId()`.
 *
 * Read it the other way round — attribute a child span to the child — and every
 * child is charged twice while its parent looks free. `selfMs` in
 * ComponentTiming is subtraction, and subtraction with the wrong sign is silent.
 *
 * IT CARRIES NO NAME. A name is only knowable once `mount` or `hydrate` has run
 * for that id, and a span is captured before the map is complete. Naming happens
 * in ComponentTiming, once, rather than in every listener.
 */
final readonly class Span
{
    private const string CHILD_PREFIX = 'child:';

    private const string CALL_PREFIX = 'call';

    public function __construct(
        /** The raw phase Livewire fired: mount, hydrate, render, dehydrate, callN, child:<id>. */
        public string $phase,
        /** The component whose ledger this time belongs to. For a child span, the PARENT. */
        public string $componentId,
        public float $ms,
    ) {}

    /**
     * @param  array{0: float, 1: float}  $times  [start, end], both from microtime(true)
     */
    public static function fromTrigger(string $phase, string $componentId, array $times): self
    {
        return new self($phase, $componentId, ($times[1] - $times[0]) * 1000);
    }

    public function isChild(): bool
    {
        return str_starts_with($this->phase, self::CHILD_PREFIX);
    }

    /** Every `call0`, `call1`, … span. Livewire numbers them per action within one message. */
    public function isCall(): bool
    {
        return str_starts_with($this->phase, self::CALL_PREFIX);
    }

    /** Which child this parent spent the time on, for a child span only. */
    public function childId(): ?string
    {
        return $this->isChild()
            ? substr($this->phase, strlen(self::CHILD_PREFIX))
            : null;
    }

    /**
     * The phase with its per-instance suffix removed, so spans can be summed.
     *
     * `call0` and `call7` are both `call`; `child:abc` is `child`.
     */
    public function normalizedPhase(): string
    {
        if ($this->isChild()) {
            return 'child';
        }

        return $this->isCall() ? 'call' : $this->phase;
    }
}
