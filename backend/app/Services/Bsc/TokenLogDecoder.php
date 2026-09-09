<?php

namespace App\Services\Bsc;

use RuntimeException;

/**
 * Turns one raw BEP-20 `Transfer` log into plain values.
 *
 * Log shape (ERC-20): topics[0] = keccak("Transfer(address,address,uint256)"),
 * topics[1] = from (32-byte padded, indexed), topics[2] = to (32-byte padded,
 * indexed), data = value (uint256, 32 bytes).
 */
class TokenLogDecoder
{
    public const TRANSFER_TOPIC = '0xddf252ad1be2c89b69c2b068fc378daa952ba7f163c4a11628f55a4df523b3ef';

    /** Normalized (lower-case, 0x) address, stripping 32-byte word padding. */
    public static function topicAddress(string $topic): string
    {
        $hex = str_starts_with($topic, '0x') ? substr($topic, 2) : $topic;
        if (strlen($hex) !== 64) {
            throw new RuntimeException('Transfer topic is not 32 bytes.');
        }
        return '0x' . substr($hex, 24);
    }

    /** Decode a raw log array from eth_getLogs. */
    public static function decode(array $log): array
    {
        $topics = $log['topics'] ?? [];
        if (count($topics) < 3) {
            throw new RuntimeException('Transfer log is missing indexed topics.');
        }

        $from = self::topicAddress($topics[1]);
        $to = self::topicAddress($topics[2]);
        $valueRaw = WeiMath::hexToDec((string) ($log['data'] ?? '0')); // full-width uint256

        return [
            'from' => $from,
            'to' => $to,
            'value_raw' => $valueRaw,                 // wei as a decimal string
            'amount' => (float) WeiMath::fromWeiFloor($valueRaw, 8), // 8-dp internal
            'tx_hash' => (string) ($log['transactionHash'] ?? ''),
            'block_number' => (int) WeiMath::hexToDec((string) ($log['blockNumber'] ?? '0x0')),
            'contract' => strtolower((string) ($log['address'] ?? '')),
        ];
    }
}
