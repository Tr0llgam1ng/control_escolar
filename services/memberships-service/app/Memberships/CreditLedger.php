<?php

namespace App\Memberships;

use App\Models\CreditTransaction;
use App\Models\Wallet;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Accountability of credits: the only class that changes a wallet balance, and every change
 * writes one immutable ledger line with the resulting balance.
 */
class CreditLedger
{
    public function balance(int $userId): int
    {
        return (int) (Wallet::where('user_id', $userId)->value('balance') ?? 0);
    }

    /** @param array{type:string, id:string|int|null} $actor */
    public function credit(int $userId, int $amount, string $type, string $concept, array $actor, ?string $sourceType = null, ?int $sourceId = null): CreditTransaction
    {
        abort_if($amount <= 0, 422, 'La cantidad de créditos debe ser mayor a cero.');

        return DB::transaction(function () use ($userId, $amount, $type, $concept, $actor, $sourceType, $sourceId) {
            $wallet = $this->lockWallet($userId);
            $wallet->balance += $amount;
            $wallet->save();

            return $this->record($wallet, $amount, $type, $concept, $actor, $sourceType, $sourceId);
        });
    }

    /** Debit; null when the amount is 0 (a free use leaves no ledger line). */
    public function debit(int $userId, int $amount, string $concept, array $actor, ?string $sourceType = null, ?int $sourceId = null, string $type = CreditTransaction::SPEND): ?CreditTransaction
    {
        if ($amount === 0) {
            return null;
        }
        abort_if($amount < 0, 422, 'La cantidad de créditos no es válida.');

        return DB::transaction(function () use ($userId, $amount, $concept, $actor, $sourceType, $sourceId, $type) {
            $wallet = $this->lockWallet($userId);
            if ($wallet->balance < $amount) {
                throw MembershipException::insufficientCredits($amount, $wallet->balance);
            }
            $wallet->balance -= $amount;
            $wallet->save();

            return $this->record($wallet, -$amount, $type, $concept, $actor, $sourceType, $sourceId);
        });
    }

    private function record(Wallet $wallet, int $amount, string $type, string $concept, array $actor, ?string $sourceType, ?int $sourceId): CreditTransaction
    {
        return CreditTransaction::create([
            'user_id' => $wallet->user_id,
            'type' => $type,
            'amount' => $amount,
            'balance_after' => $wallet->balance,
            'concept' => mb_substr($concept, 0, 160),
            'source_type' => $sourceType,
            'source_id' => $sourceId,
            'actor_type' => $actor['type'] ?? 'service',
            'actor_id' => isset($actor['id']) ? (string) $actor['id'] : null,
            'created_at' => now(),
        ]);
    }

    private function lockWallet(int $userId): Wallet
    {
        try {
            Wallet::firstOrCreate(['user_id' => $userId]);
        } catch (UniqueConstraintViolationException) {
            // created by a concurrent request: just lock it below
        }

        return Wallet::where('user_id', $userId)->lockForUpdate()->firstOrFail();
    }
}
