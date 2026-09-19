<?php

namespace App\Services\P2p;

use App\Models\P2pOffer;
use App\Models\P2pTrade;
use App\Models\P2pTradeMessage;
use App\Models\User;
use App\Models\Wallet;
use App\Support\Money;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class TradeEscrow
{
    public const REASON_EXPIRED = 'Payment window expired — the buyer did not pay in time.';

    /**
     * Mark an unpaid trade as paid and attach proof.
     */
    public function markPaid(P2pTrade $trade, User $buyer, string $proofPath, ?string $note = null): P2pTrade
    {
        return DB::transaction(function () use ($trade, $buyer, $proofPath, $note) {
            $locked = $this->lockTrade($trade);

            if ($locked->status !== P2pTrade::STATUS_PENDING) {
                throw new DomainException(
                    'This order can no longer be marked paid — it has been cancelled, released or disputed.'
                );
            }

            if ($locked->hasExpired()) {
                throw new DomainException(
                    'The payment window for this order has closed. Place a new order to try again.'
                );
            }

            $locked->update([
                'status' => P2pTrade::STATUS_PAID,
                'paid_at' => now(),
            ]);

            P2pTradeMessage::create([
                'trade_id' => $locked->id,
                'sender_id' => $buyer->id,
                'message' => $note ?: 'Payment has been sent.',
                'attachment_path' => $proofPath,
                'is_proof_of_payment' => true,
            ]);

            return $locked->fresh(['offer', 'buyer', 'seller']);
        });
    }

    /**
     * Cancel an unpaid trade and return the escrowed USDT to the seller.
     */
    public function cancel(P2pTrade $trade, string $reason, bool $enforceExpiry = true): P2pTrade
    {
        return DB::transaction(function () use ($trade, $reason, $enforceExpiry) {
            $locked = $this->lockTrade($trade);

            if ($locked->status !== P2pTrade::STATUS_PENDING) {
                throw new DomainException(match ($locked->status) {
                    P2pTrade::STATUS_PAID =>
                        'The buyer has already marked this order paid — release it or raise a dispute.',
                    P2pTrade::STATUS_DISPUTED =>
                        'This order is in dispute — only an administrator can close it now.',
                    default => 'This order is no longer open.',
                });
            }

            // A seller cannot cancel an active trade while the buyer still has time to pay.
            if ($enforceExpiry && !$locked->hasExpired()) {
                throw new DomainException(
                    'You cannot cancel while the buyer\'s payment window is still active.'
                );
            }

            /** @var User $seller */
            $seller = $locked->seller;

            // Offer lock before wallet lock to preserve lock ordering hierarchy.
            $this->restoreOfferCapacity($locked);

            $sellerWallet = Wallet::lockedFor($seller->id);
            $sellerWallet->refundEscrow((string) $locked->crypto_amount, $locked);

            $locked->update([
                'status' => P2pTrade::STATUS_CANCELLED,
                'cancelled_at' => now(),
                'cancel_reason' => $reason,
            ]);

            return $locked->fresh(['offer', 'buyer', 'seller']);
        });
    }

    /**
     * Flag a paid trade for adjudication.
     */
    public function openDispute(P2pTrade $trade, User $raisedBy, string $reason): P2pTrade
    {
        return DB::transaction(function () use ($trade, $raisedBy, $reason) {
            $locked = $this->lockTrade($trade);

            if ($locked->status !== P2pTrade::STATUS_PAID) {
                throw new DomainException(
                    $locked->status === P2pTrade::STATUS_DISPUTED
                        ? 'This order is already in dispute.'
                        : 'Only an order the buyer has marked paid can be disputed.'
                );
            }

            $locked->update([
                'status' => P2pTrade::STATUS_DISPUTED,
                'disputed_at' => now(),
                'disputed_by' => $raisedBy->id,
                'dispute_reason' => $reason,
            ]);

            return $locked->fresh(['offer', 'buyer', 'seller']);
        });
    }

    /**
     * Admin resolution in favor of buyer.
     */
    public function resolveToBuyer(P2pTrade $trade, User $admin, string $note): P2pTrade
    {
        return DB::transaction(function () use ($trade, $admin, $note) {
            $locked = $this->assertResolvable($trade);

            $sellerWallet = Wallet::where('user_id', $locked->seller_id)->where('currency', 'USDT')->firstOrFail();
            $buyerWallet = Wallet::firstOrCreate(
                ['user_id' => $locked->buyer_id, 'currency' => 'USDT'],
                ['available_balance' => 0, 'escrow_balance' => 0]
            );

            // releaseEscrowTo internally locks both rows in sorted ID order, preventing deadlocks.
            $sellerWallet->releaseEscrowTo($buyerWallet, (string) $locked->crypto_amount, $locked);

            $locked->update([
                'status' => P2pTrade::STATUS_COMPLETED,
                'completed_at' => now(),
                'resolved_at' => now(),
                'resolved_by' => $admin->id,
                'resolution_note' => $note,
            ]);

            return $locked->fresh(['offer', 'buyer', 'seller']);
        });
    }

    /**
     * Admin resolution in favor of seller.
     */
    public function resolveToSeller(P2pTrade $trade, User $admin, string $note): P2pTrade
    {
        return DB::transaction(function () use ($trade, $admin, $note) {
            $locked = $this->assertResolvable($trade);

            $this->restoreOfferCapacity($locked);

            $sellerWallet = Wallet::lockedFor($locked->seller_id);
            $sellerWallet->refundEscrow((string) $locked->crypto_amount, $locked);

            $locked->update([
                'status' => P2pTrade::STATUS_REFUNDED,
                'resolved_at' => now(),
                'resolved_by' => $admin->id,
                'resolution_note' => $note,
            ]);

            return $locked->fresh(['offer', 'buyer', 'seller']);
        });
    }

    /**
     * Seller releases escrow to buyer.
     */
    public function releaseBySeller(P2pTrade $trade): P2pTrade
    {
        return DB::transaction(function () use ($trade) {
            $locked = $this->lockTrade($trade);

            if (!in_array($locked->status, P2pTrade::RESOLVABLE_STATUSES, true)) {
                throw new DomainException(
                    $locked->status === P2pTrade::STATUS_PENDING
                        ? 'The buyer has not marked this order paid yet.'
                        : 'This order is already closed.'
                );
            }

            $sellerWallet = Wallet::where('user_id', $locked->seller_id)->where('currency', 'USDT')->firstOrFail();
            $buyerWallet = Wallet::firstOrCreate(
                ['user_id' => $locked->buyer_id, 'currency' => 'USDT'],
                ['available_balance' => 0, 'escrow_balance' => 0]
            );

            // releaseEscrowTo handles row sorting and deadlock-free locking.
            $sellerWallet->releaseEscrowTo($buyerWallet, (string) $locked->crypto_amount, $locked);

            $locked->update([
                'status' => P2pTrade::STATUS_COMPLETED,
                'completed_at' => now(),
            ]);

            return $locked->fresh(['offer', 'buyer', 'seller']);
        });
    }

    /**
     * Cron sweep to cancel expired trades.
     */
    public function cancelExpired(int $limit = 200): array
    {
        $stats = ['cancelled' => 0, 'skipped' => 0, 'errored' => 0];

        $expired = P2pTrade::expired()->orderBy('id')->limit($limit)->get();

        foreach ($expired as $trade) {
            try {
                $this->cancel($trade, self::REASON_EXPIRED, enforceExpiry: false);
                $stats['cancelled']++;
            } catch (DomainException $e) {
                $stats['skipped']++;
            } catch (\Throwable $e) {
                $stats['errored']++;
                Log::error('Trade ' . $trade->trade_ref . ' could not be expired: ' . $e->getMessage());
            }
        }

        return $stats;
    }

    protected function lockTrade(P2pTrade $trade): P2pTrade
    {
        $locked = P2pTrade::whereKey($trade->id)->lockForUpdate()->first();

        if (!$locked) {
            throw new DomainException('Trade not found.');
        }

        return $locked;
    }

    protected function assertResolvable(P2pTrade $trade): P2pTrade
    {
        $locked = $this->lockTrade($trade);

        if (!in_array($locked->status, P2pTrade::RESOLVABLE_STATUSES, true)) {
            throw new DomainException(
                $locked->status === P2pTrade::STATUS_PENDING
                    ? 'This order has not been marked paid yet.'
                    : 'This order is already closed.'
            );
        }

        return $locked;
    }

    protected function restoreOfferCapacity(P2pTrade $trade): void
    {
        $offer = P2pOffer::whereKey($trade->offer_id)->lockForUpdate()->first();

        if (!$offer) {
            return;
        }

        $offer->remaining_amount = Money::add(
            (string) $offer->remaining_amount,
            (string) $trade->crypto_amount
        );

        if ($offer->status === P2pOffer::STATUS_COMPLETED && Money::isPositive($offer->remaining_amount)) {
            $offer->status = P2pOffer::STATUS_ACTIVE;
        }

        $offer->save();
    }
}