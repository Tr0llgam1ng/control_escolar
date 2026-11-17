<?php

namespace App\Memberships;

use App\Models\LibrarySession;
use Illuminate\Support\Facades\DB;

/**
 * Time module 2 — digital library used by the minute.
 * Opening a session takes a snapshot of the minutes available (limit); closing it charges the
 * minutes used, rounded up to the minute. A session that reaches its limit closes by itself.
 */
class LibraryService
{
    public function __construct(private TimeBank $time) {}

    public function start(int $userId, string $resource): LibrarySession
    {
        abort_unless(config("memberships.library.resources.{$resource}"), 422, 'El recurso de la biblioteca no existe.');

        return DB::transaction(function () use ($userId, $resource) {
            $this->closeExpired($userId);
            $open = LibrarySession::where('user_id', $userId)->where('status', LibrarySession::OPEN)->lockForUpdate()->first();
            abort_if($open, 409, "Ya tienes una sesión abierta en {$open?->resource_name}. Ciérrala antes de abrir otra.");

            $available = $this->time->available($userId, 'library');
            if ($available < 60) {
                throw MembershipException::insufficientTime('library', 60, $available);
            }

            return LibrarySession::create([
                'user_id' => $userId, 'resource' => $resource, 'started_at' => now(),
                'limit_seconds' => $available, 'status' => LibrarySession::OPEN,
            ]);
        });
    }

    public function close(LibrarySession $session, string $by = 'user'): LibrarySession
    {
        return DB::transaction(function () use ($session, $by) {
            $session = LibrarySession::whereKey($session->id)->lockForUpdate()->firstOrFail();
            if ($session->status === LibrarySession::CLOSED) {
                return $session;
            }

            $elapsed = $session->elapsed_seconds;               // already capped to the limit
            $billable = min($session->limit_seconds, max(60, (int) ceil($elapsed / 60) * 60));
            $used = $this->time->spend($session->user_id, 'library', $billable,
                "Biblioteca digital: {$session->resource_name}", 'library_session', $session->id, partial: true);

            $session->forceFill([
                'status' => LibrarySession::CLOSED,
                'ended_at' => $by === 'limit' ? $session->started_at->copy()->addSeconds($session->limit_seconds) : now(),
                'used_seconds' => $used['included'] + $used['extra'],
                'included_seconds' => $used['included'],
                'extra_seconds' => $used['extra'],
                'closed_by' => $by,
            ])->save();

            return $session;
        });
    }

    /** Closes the user's sessions that already reached their limit. */
    public function closeExpired(int $userId): void
    {
        LibrarySession::where('user_id', $userId)->where('status', LibrarySession::OPEN)->get()
            ->filter(fn (LibrarySession $s) => $s->started_at->copy()->addSeconds($s->limit_seconds)->lessThanOrEqualTo(now()))
            ->each(fn (LibrarySession $s) => $this->close($s, 'limit'));
    }
}
