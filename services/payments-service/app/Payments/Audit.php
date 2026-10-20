<?php

namespace App\Payments;

use App\Models\Charge;
use App\Models\ChargeAuditLog;

final class Audit
{
    /** @param  array{type: string, id: string|int|null}  $actor */
    public static function record(Charge $charge, string $action, ?string $from, ?string $to, array $actor, ?string $reason = null, ?array $metadata = null): ChargeAuditLog
    {
        return ChargeAuditLog::create([
            'charge_id' => $charge->id,
            'action' => $action,
            'from_status' => $from,
            'to_status' => $to,
            'actor_type' => $actor['type'],
            'actor_id' => $actor['id'] === null ? null : (string) $actor['id'],
            'reason' => $reason,
            'metadata' => $metadata,
        ]);
    }
}
