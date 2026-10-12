<?php
namespace common\modules\world\support;

/** Observer subscriptions; durability and replay protection remain in outbox/inbox. */
final class EventSubject
{
    private $observers = [];
    public function attach(string $event, string $name, callable $observer): void { $this->observers[$event][$name] = $observer; }
    public function detach(string $event, string $name): void { unset($this->observers[$event][$name]); }
    public function notify(string $event, array $payload): void
    {
        foreach ($this->observers[$event] ?? [] as $observer) $observer($payload);
    }
}
