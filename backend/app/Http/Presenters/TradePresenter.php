<?php

namespace App\Http\Presenters;

use App\Models\P2pTrade;
use App\Models\User;

/**
 * Shape a trade for one viewer — the single definition of what a trade looks
 * like over the wire, shared by the party-facing and the admin controller.
 *
 * Built by hand rather than serialising the models: `$trade->load('seller')`
 * would hand the counterparty the other user's email address, and the raw
 * payment-method row carries bank details that must only ever reach the user
 * who owns them.
 */
class TradePresenter
{
    public static function for(P2pTrade $trade, User $viewer): array
    {
        return [
            'trade_ref' => $trade->trade_ref,
            'status' => $trade->status,
            // Null for anyone who is not a party — including an administrator.
            'role' => $trade->roleOf($viewer),
            // Amounts stay decimal strings so they survive the JSON hop intact.
            'crypto_amount' => (string) $trade->crypto_amount,
            'fiat_amount' => (string) $trade->fiat_amount,
            'unit_price' => (string) $trade->unit_price,
            'buyer_id' => $trade->buyer_id,
            'seller_id' => $trade->seller_id,
            'offer' => $trade->offer ? [
                'id' => $trade->offer->id,
                'type' => $trade->offer->type,
                'fiat_currency' => $trade->offer->fiat_currency,
                'price' => (string) $trade->offer->price,
            ] : null,
            'buyer' => $trade->buyer ? [
                'id' => $trade->buyer->id,
                'name' => $trade->buyer->name,
            ] : null,
            'seller' => $trade->seller ? [
                'id' => $trade->seller->id,
                'name' => $trade->seller->name,
            ] : null,
            'payment_method' => self::paymentMethod($trade, $viewer),
            'paid_at' => $trade->paid_at?->toIso8601String(),
            'completed_at' => $trade->completed_at?->toIso8601String(),
            'cancelled_at' => $trade->cancelled_at?->toIso8601String(),
            'cancel_reason' => $trade->cancel_reason,
            'disputed_at' => $trade->disputed_at?->toIso8601String(),
            'disputed_by' => $trade->disputed_by,
            'dispute_reason' => $trade->dispute_reason,
            'resolved_at' => $trade->resolved_at?->toIso8601String(),
            'resolution_note' => $trade->resolution_note,
            'expires_at' => $trade->expires_at?->toIso8601String(),
        ];
    }

    /**
     * The payment details, with the account number only for the user who owns
     * the method. Everyone else gets the channel, not the credentials.
     *
     * An administrator is not the owner either, so the same rule withholds the
     * details from staff. Adjudicating a dispute means reading the proof and
     * the order history, not the account number.
     */
    protected static function paymentMethod(P2pTrade $trade, User $viewer): ?array
    {
        $method = $trade->paymentMethod;

        if (!$method) {
            return null;
        }

        $isOwner = (int) $method->user_id === (int) $viewer->id;

        return array_filter([
            'id' => $method->id,
            'type' => $method->type,
            'bank_or_provider_name' => $method->bank_or_provider_name,
            'account_name' => $isOwner ? $method->account_name : null,
            'account_number' => $isOwner ? $method->account_number : null,
            'instructions' => $isOwner ? $method->instructions : null,
        ], fn ($value) => $value !== null);
    }
}
