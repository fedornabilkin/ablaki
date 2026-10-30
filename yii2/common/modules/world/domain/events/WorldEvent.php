<?php
namespace common\modules\world\domain\events;

use common\modules\world\domain\EntityReference;
use common\modules\world\domain\WorldEntity;
use common\modules\world\domain\characteristics\Characteristics;

/** A gameplay occurrence, distinct from an outbox notification about a committed command. */
final readonly class WorldEvent extends WorldEntity
{
    public function __construct(
        int $id,
        public int $scopeNodeId,
        public string $status,
        public int $startsAt,
        public ?int $endsAt,
        Characteristics $features
    ) {
        if ($scopeNodeId < 1 || $startsAt < 1 || ($endsAt !== null && $endsAt <= $startsAt)
            || !in_array($status, ['draft', 'active', 'closed'], true)) {
            throw new \InvalidArgumentException('Invalid world event lifecycle.');
        }
        parent::__construct(new EntityReference('event', $id), $features);
    }

    /** The caller supplies the containing node and its ancestors, after access checks. */
    public function affectsScope(array $nodeAndAncestorIds): bool
    {
        return in_array($this->scopeNodeId, $nodeAndAncestorIds, true);
    }

    public function activeAt(int $now): bool
    {
        return $this->status === 'active' && $this->startsAt <= $now
            && ($this->endsAt === null || $this->endsAt > $now);
    }

    /** Historical effects survive natural expiry, but early administrative closure cancels them. */
    public function overlaps(int $from, int $until, int $observedAt): bool
    {
        if ($until <= $from || $this->status === 'draft' || $this->startsAt > $observedAt
            || $this->startsAt >= $until || ($this->endsAt !== null && $this->endsAt <= $from)) {
            return false;
        }
        return $this->status !== 'closed' || ($this->endsAt !== null && $this->endsAt <= $observedAt);
    }
}
