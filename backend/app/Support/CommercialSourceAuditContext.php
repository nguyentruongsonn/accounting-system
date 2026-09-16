<?php

namespace App\Support;

use Illuminate\Database\Eloquent\Model;

/** One-shot lifecycle label consumed by CommercialSourceAuditObserver. */
class CommercialSourceAuditContext
{
    /** @var array<int, string> */
    private static array $events = [];

    public static function mark(Model $model, string $event): void
    {
        self::$events[spl_object_id($model)] = $event;
    }

    public static function consume(Model $model, string $fallback): string
    {
        $key = spl_object_id($model);
        $event = self::$events[$key] ?? $fallback;
        unset(self::$events[$key]);

        return $event;
    }
}
