<?php

namespace App\Services\P2p;

use App\Models\P2pOffer;
use App\Models\P2pTrade;
use App\Models\User;
use App\Models\Wallet;
use App\Support\Money;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Every way a trade ends.
 *
 * Escrow with no way out is worse than no escrow, because the seller has been
 * told their USDT is "protected" while it is in fact unreachable. That cuts
 * three ways, and all three live here:
 *
 *   - the buyer walked away, so the seller or the `trades:expire` sweep
 *     cancels and refunds (cancel, cancelExpired);
 *   - the buyer says they paid and the seller disagrees, so either party
 *     flags it and an administrator rules (openDispute, resolveToBuyer,
 *     resolveToSeller).
 *
 * Every path re-reads the trade under a row lock and guards on its status, so
 * each one is safe to run twice and safe to run concurrently with the others.
 *
 * Rows are always locked in the same order — trade, then offer, then wallet —
 * so two paths touching the same ad and the same balance can only ever queue,
 * never deadlock. Any new transition must respect that order; the one place it
 * is subtle is that the offer lock has to be taken before the wallet lock even
 * where the wallet is the thing being changed.
 */
class TradeEscrow
{
    /** Reason recorded when the platform closes an order the buyer never paid. */
    public const REASON_EXPIRED = 'Payment window expired — the buyer did not pay in time.';

    /**
     * Cancel an unpaid trade and return the escrowed USDT to the seller.
     *
     * @throws DomainException when the trade is no longer open (already paid
     *                         for, disputed, released or cancelled)
     */
    public function cancel(P2pTrade $trade, string $reason): P2pTrade
    {
        return DB::transaction(function () use ($trade, $reason) {
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

            /** @var User $seller */
            $seller = $locked->seller;

            // Offer lock before wallet lock — see restoreOfferCapacity().
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
     *
     * No money moves. The USDT stays exactly where it is until an administrator
     * rules — which is the point: once one party disputes, the other cannot
     * close the order unilaterally.
     *
     * Note this does not lock the seller out of releasing. Releasing only ever
     * moves money towards the buyer and only the seller can trigger it, so
     * leaving that path open means a dispute can never be used to strand an
     * honest seller waiting on the owner to wake up.
     *
     * @throws DomainException when the trade is not in a state that can be disputed
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
     * Rule that the fiat payment was real, so the crypto belongs to the buyer.
     *
     * The same movement as the seller's own release, deliberately: a disputed
     * order that ends in the buyer's favour should settle identically to one
     * that was never disputed.
     *
     * The ad's capacity is *not* restored — this trade genuinely happened.
     */
    public function resolveToBuyer(P2pTrade $trade, User $admin, string $note): P2pTrade
    {
        return DB::transaction(function () use ($trade, $admin, $note) {
            $locked = $this->assertResolvable($trade);

            /** @var User $buyer */
            $buyer = $locked->buyer;

            $sellerWallet = Wallet::lockedFor($locked->seller_id);
            $buyerWallet = Wallet::lockedFor($buyer->id);

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
     * Rule that no payment arrived, so the escrow returns to the seller.
     *
     * The trade is void, which is why the ad's capacity comes back and a
     * fully-taken ad re-opens: the order is being unwound, exactly as a cancel
     * would. The seller keeps their USDT and their listing.
     */
    public function resolveToSeller(P2pTrade $trade, User $admin, string $note): P2pTrade
    {
        return DB::transaction(function () use ($trade, $admin, $note) {
            $locked = $this->assertResolvable($trade);

            // Offer lock before wallet lock — see restoreOfferCapacity().
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
     * The seller confirms the fiat arrived and hands the escrow to the buyer.
     *
     * Allowed while the order is merely paid *or* already disputed: releasing
     * only ever moves money towards the buyer and only the seller can trigger
     * it, so keeping this path open means a dispute can never be used to strand
     * an honest seller waiting on the platform owner to wake up.
     *
     * This used to run inline in TradeController, which read the status outside
     * any transaction and never re-checked it under the lock — the only
     * transition in the codebase that could be entered twice concurrently.
     * Routing it through here gives it the same lockTrade() the rest use.
     *
     * @throws DomainException when the order is not in a state that can be released
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

            /** @var User $buyer */
            $buyer = $locked->buyer;

            // Seller wallet then buyer wallet, in that order — the same order
            // resolveToBuyer() uses, so the two releases can't deadlock on each
            // other's wallets.
            $sellerWallet = Wallet::lockedFor($locked->seller_id);
            $buyerWallet = Wallet::lockedFor($buyer->id);

            $sellerWallet->releaseEscrowTo($buyerWallet, (string) $locked->crypto_amount, $locked);

            $locked->update([
                'status' => P2pTrade::STATUS_COMPLETED,
                'completed_at' => now(),
            ]);

            return $locked->fresh(['offer', 'buyer', 'seller']);
        });
    }

    /**
     * Close every unpaid trade whose payment window has passed.
     *
     * One bad row must not stop the sweep — it is retried next run, and
     * cancel() is idempotent through its status guard.
     */
    public function cancelExpired(int $limit = 200): array
    {
        $stats = ['cancelled' => 0, 'skipped' => 0, 'errored' => 0];

        $expired = P2pTrade::expired()->orderBy('id')->limit($limit)->get();

        foreach ($expired as $trade) {
            try {
                $this->cancel($trade, self::REASON_EXPIRED);
                $stats['cancelled']++;
            } catch (DomainException $e) {
                // The buyer marked it paid between the query and the lock.
                $stats['skipped']++;
            } catch (\Throwable $e) {
                $stats['errored']++;
                Log::error('Trade ' . $trade->trade_ref . ' could not be expired: ' . $e->getMessage());
            }
        }

        return $stats;
    }

    /**
     * Re-read the trade under a row lock.
     *
     * Every transition goes through here. A resolve racing the buyer's
     * mark-paid, or two administrators resolving at once, has to serialise
     * rather than both read the same status and both move the same escrow.
     */
    protected function lockTrade(P2pTrade $trade): P2pTrade
    {
        /** @var P2pTrade|null $locked */
        $locked = P2pTrade::whereKey($trade->id)->lockForUpdate()->first();

        if (!$locked) {
            throw new DomainException('Trade not found.');
        }

        return $locked;
    }

    /**
     * Lock the trade and refuse anything an administrator must not touch.
     *
     * This status guard is what makes a resolve idempotent: a second call finds
     * COMPLETED or REFUNDED and throws, rather than paying the same escrow out
     * a second time.
     */
    protected function assertResolvable(P2pTrade $trade): P2pTrade
    {
        $locked = $this->lockTrade($trade);

        if (!in_array($locked->status, P2pTrade::RESOLVABLE_STATUSES, true)) {
            throw new DomainException(
                $locked->status === P2pTrade::STATUS_PENDING
                    ? 'This order has not been marked paid yet — the seller can still cancel it.'
                    : 'This order is already closed.'
            );
        }

        return $locked;
    }

    /**
     * Hand the escrowed amount back to the ad so someone else can take it.
     *
     * The lock order here is load-bearing, and every caller must therefore take
     * this lock *before* the wallet lock: trade row, then offer, then wallet.
     *
     * initiate() has to lock the ad first — it cannot know whose wallet to lock
     * until it has read the ad to see which side is selling. If this path took
     * the wallet first and then the offer, the two would acquire the same two
     * rows in opposite orders and deadlock whenever a taker and a cancel raced
     * on the same ad.
     *
     * Reading remaining_amount after lockForUpdate (rather than before) is what
     * makes the add safe against a concurrent initiate().
     */
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

        // initiate() marks a fully-taken ad 'completed'; once capacity comes
        // back it is sellable again.
        if ($offer->status === P2pOffer::STATUS_COMPLETED
            && Money::isPositive($offer->remaining_amount)) {
            $offer->status = P2pOffer::STATUS_ACTIVE;
        }

        $offer->save();
    }
}
