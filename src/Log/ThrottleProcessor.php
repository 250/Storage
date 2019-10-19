<?php
declare(strict_types=1);

namespace ScriptFUSION\Steam250\Shared\Log;

use ScriptFUSION\Async\Throttle\Throttle;

final class ThrottleProcessor
{
    public function __invoke(array $record): array
    {
        $throttle = $record['context']['throttle'] ?? null;

        if ($throttle instanceof Throttle) {
            $record['message'] .= " AR: {$throttle->countAwaiting()}";
        }

        return $record;
    }
}
