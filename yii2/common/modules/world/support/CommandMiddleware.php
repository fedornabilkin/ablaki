<?php
namespace common\modules\world\support;

interface CommandMiddleware
{
    /** @param callable(CommandRequest): array $next */
    public function handle(CommandRequest $command, callable $next): array;
}
