<?php

namespace App\Services;

use App\Models\ActivityLog;
use Illuminate\Database\Eloquent\Model;

class ActivityLogger
{
    /** Never stored, even as a diff. */
    private const SECRET = ['password', 'remember_token', 'credentials', 'api_key', 'secret_key', 'token'];

    /**
     * @param  Model|array{0: string, 1: int|null}|null  $subject  a model, or [type, id]
     */
    public function log(string $action, Model|array|null $subject = null, ?array $before = null, ?array $after = null): ActivityLog
    {
        [$type, $id] = match (true) {
            $subject instanceof Model => [self::typeOf($subject), $subject->getKey()],
            is_array($subject) => $subject,
            default => [null, null],
        };

        return ActivityLog::create([
            'actor_id' => auth()->id(),
            'action' => $action,
            'subject_type' => $type,
            'subject_id' => $id,
            'before' => $before === null ? null : self::clean($before),
            'after' => $after === null ? null : self::clean($after),
            'ip' => app()->runningInConsole() ? null : request()->ip(),
        ]);
    }

    public static function typeOf(Model $model): string
    {
        return strtolower(class_basename($model));
    }

    private static function clean(array $values): array
    {
        foreach ($values as $key => $value) {
            if (in_array($key, self::SECRET, true)) {
                $values[$key] = '[hidden]';
            } elseif ($value instanceof \BackedEnum) {
                $values[$key] = $value->value;
            } elseif ($value instanceof \DateTimeInterface) {
                $values[$key] = $value->format('Y-m-d H:i:s');
            }
        }

        return $values;
    }
}
