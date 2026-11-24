<?php

namespace App\Services\Security;

use App\Models\ActivityLog;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

class AuditLogService
{
    public static function record(
        string $actorType,
        ?int $actorId,
        string $action,
        string $description,
        ?Model $subject = null,
        array $properties = [],
        ?Request $request = null
    ): ActivityLog {
        $req = $request ?? request();

        return ActivityLog::create([
            'actor_type' => $actorType,
            'actor_id' => $actorId,
            'action' => $action,
            'subject_type' => $subject ? $subject::class : null,
            'subject_id' => $subject?->getKey(),
            'description' => $description,
            'properties' => $properties ?: null,
            'ip_address' => $req?->ip(),
            'user_agent' => $req?->userAgent(),
            'created_at' => now(),
        ]);
    }
}
