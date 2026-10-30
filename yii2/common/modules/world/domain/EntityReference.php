<?php
namespace common\modules\world\domain;

/** IDs belong to separate namespaces: node:12 is not item:12 or event:12. */
final readonly class EntityReference
{
    public function __construct(public string $kind, public int $id)
    {
        if (!preg_match('/^[a-z][a-z0-9._-]{0,63}$/D', $kind) || $id < 1) {
            throw new \InvalidArgumentException('Invalid world entity reference.');
        }
    }

    public function key(): string
    {
        return $this->kind . ':' . $this->id;
    }
}
