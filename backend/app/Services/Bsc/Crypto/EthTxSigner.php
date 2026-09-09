<?php

namespace App\Services\Bsc\Crypto;

use App\Services\Bsc\WeiMath;
use RuntimeException;

/**
 * Builds and signs a legacy EIP-155 USDT (BEP-20) `transfer()` transaction
 * entirely offline. RLP is hand-rolled so the only external dependency is the
 * secp256k1/keccak primitives used by EthereumCrypto.
 */
class EthTxSigner
{
    private const TRANSFER_SELECTOR = 'a9059cbb';
    private const BALANCEOF_SELECTOR = '70a08231';

    /**
     * Build the `transfer(address to, uint256 amount)` calldata (no 0x).
     * $weiDec is the RAW amount in wei (18-dp), as a decimal string — e.g.
     * `WeiMath::toWei("25.5")`. This is what the chain needs; do not convert
     * again inside.
     */
    public static function transferCalldata(string $toAddress, string $weiDec): string
    {
        if (!EthereumCrypto::isAddress($toAddress)) {
            throw new RuntimeException('Invalid destination address.');
        }

        $to = substr(EthereumCrypto::normalizeAddress($toAddress), 2);
        $amount = WeiMath::decToHex($weiDec);

        return self::TRANSFER_SELECTOR
            . WeiMath::padHex($to, 32)
            . WeiMath::padHex($amount, 32);
    }

    /** `balanceOf(address)` calldata for eth_call (no 0x). */
    public static function balanceOfCalldata(string $address): string
    {
        $addr = substr(EthereumCrypto::normalizeAddress($address), 2);
        return self::BALANCEOF_SELECTOR . WeiMath::padHex($addr, 32);
    }

    /**
     * Sign and serialize a legacy EIP-155 value (BNB) or token transfer.
     * Pass $weiDec = '0' and $data = '' for a plain BNB send.
     */
    public static function signTransfer(
        string $privateKeyHex,
        int $nonce,
        string $gasPriceWeiDec,
        int $gasLimit,
        string $toAddress,
        string $weiDec,
        string $data = '',
        ?int $chainId = null
    ): string {
        $chainId ??= (int) config('bsc.networks.' . config('bsc.network') . '.chain_id', 97);

        $nonceBytes = self::minimalBytes(WeiMath::decToHex((string) $nonce));
        $gasPriceBytes = self::minimalBytes(WeiMath::decToHex($gasPriceWeiDec));
        $gasLimitBytes = self::minimalBytes(WeiMath::decToHex((string) $gasLimit));
        $toBytes = self::minimalBytes(substr(EthereumCrypto::normalizeAddress($toAddress), 2));
        $valueBytes = self::minimalBytes(WeiMath::decToHex($weiDec));
        $dataBytes = $data === '' ? '' : hex2bin(strlen($data) % 2 ? '0' . $data : $data);
        $chainBytes = self::minimalBytes(WeiMath::decToHex((string) $chainId));

        // EIP-155 signing data: the six fields plus chainId, 0, 0.
        $signingData = self::rlpList([
            $nonceBytes, $gasPriceBytes, $gasLimitBytes,
            $toBytes, $valueBytes, $dataBytes, $chainBytes, '', '',
        ]);

        $signature = EthereumCrypto::signDigest(EthereumCrypto::keccakHex($signingData), $privateKeyHex);

        $v = $signature['recovery'] + ($chainId * 2) + 35;
        $raw = self::rlpList([
            $nonceBytes, $gasPriceBytes, $gasLimitBytes,
            $toBytes, $valueBytes, $dataBytes,
            self::minimalBytes(WeiMath::decToHex((string) $v)),
            self::minimalBytes($signature['r']),
            self::minimalBytes($signature['s']),
        ]);

        return '0x' . bin2hex($raw);
    }

    /* ----------------------------- RLP ----------------------------- */

    private static function rlpList(array $byteItems): string
    {
        $body = '';
        foreach ($byteItems as $item) {
            $body .= self::rlpBytes($item);
        }
        return self::lengthPrefix(0xc0, strlen($body)) . $body;
    }

    private static function rlpBytes(string $bytes): string
    {
        $len = strlen($bytes);
        if ($len === 1 && ord($bytes) < 0x80) {
            return $bytes;
        }
        return self::lengthPrefix(0x80, $len) . $bytes;
    }

    private static function lengthPrefix(int $offset, int $len): string
    {
        if ($len <= 55) {
            return chr($offset + $len);
        }

        $hex = dechex($len);
        if (strlen($hex) % 2) {
            $hex = '0' . $hex;
        }
        $bytes = hex2bin($hex);

        return chr($offset + 55 + strlen($bytes)) . $bytes;
    }

    /** Bytes for a quantity: minimal big-endian, '' when zero. */
    private static function minimalBytes(string $hex): string
    {
        $hex = ltrim(strtolower($hex), '0');
        if ($hex === '') {
            return '';
        }
        if (strlen($hex) % 2) {
            $hex = '0' . $hex;
        }
        return hex2bin($hex);
    }
}
