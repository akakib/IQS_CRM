<?php

namespace App\Models\Concerns;

use App\Services\ActivityLogger;

/**
 * Writes created / updated (changed fields only) / deleted / restored rows to
 * the activity log. Timestamps are skipped; secrets are stored as [hidden].
 */
trait LogsActivity
{
    protected static function bootLogsActivity(): void
    {
        $ignore = ['created_at', 'updated_at', 'deleted_at', 'remember_token'];
        $type = fn ($model) => ActivityLogger::typeOf($model);

        static::created(function ($model) use ($ignore, $type) {
            app(ActivityLogger::class)->log($type($model).'.created', $model, null, array_diff_key($model->getAttributes(), array_flip($ignore)));
        });

        static::updated(function ($model) use ($ignore, $type) {
            $after = array_diff_key($model->getChanges(), array_flip($ignore));
            if ($after === []) {
                return;
            }
            $before = array_intersect_key($model->getOriginal(), $after);
            app(ActivityLogger::class)->log($type($model).'.updated', $model, $before, $after);
        });

        static::deleted(function ($model) use ($type) {
            app(ActivityLogger::class)->log($type($model).'.deleted', $model);
        });

        if (method_exists(static::class, 'restored')) {
            static::restored(function ($model) use ($type) {
                app(ActivityLogger::class)->log($type($model).'.restored', $model);
            });
        }
    }
}
