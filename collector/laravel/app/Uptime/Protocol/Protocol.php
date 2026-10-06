<?php

namespace Pterodactyl\Uptime\Protocol;

/**
 * UpTime-Server event protocol, version 1 (PHP implementation; the Go reference is
 * protocol/ in the UpTime-Server repository and docs/protocol.md is the specification).
 *
 * Canonical payloads are flat JSON objects: keys [a-z0-9_]{1,32} sorted by byte value,
 * values either printable-ASCII strings without '"' or '\' (max 256 bytes) or
 * non-negative integers up to 2^53 - 1, no whitespace. The collector verifies the
 * signature over the exact received bytes and requires them to be canonical.
 */
final class Protocol
{
    public const VERSION = 1;
    public const SIGNATURE_CONTEXT = "uptime-server/event/v1\n";
    public const GENESIS = '0000000000000000000000000000000000000000000000000000000000000000';
    public const MAX_SAFE_INTEGER = 9007199254740991;

    public const TYPES = ['boot', 'start', 'heartbeat', 'stop'];
    public const STATUSES = ['ok', 'degraded', 'stopping'];

    private const FIELDS = [
        'agent_build', 'agent_commit', 'agent_sha256', 'agent_version', 'boot', 'checks_failed', 'key',
        'mono_ms', 'node', 'prev', 'proto', 'run', 'seq', 'status', 'ts', 'type',
    ];

    /**
     * Encodes fields canonically.
     *
     * @param array<string, string|int> $fields
     */
    public static function encode(array $fields): string
    {
        if ($fields === [] || count($fields) > 32) {
            throw new ProtocolException('payload must have 1 to 32 fields');
        }
        ksort($fields, SORT_STRING);
        $parts = [];
        foreach ($fields as $key => $value) {
            $key = (string) $key;
            if (preg_match('/^[a-z0-9_]{1,32}$/', $key) !== 1) {
                throw new ProtocolException('invalid field name');
            }
            if (is_int($value)) {
                if ($value < 0 || $value > self::MAX_SAFE_INTEGER) {
                    throw new ProtocolException("field {$key}: integer out of range");
                }
                $parts[] = '"' . $key . '":' . $value;
            } elseif (is_string($value)) {
                if (!self::validString($value)) {
                    throw new ProtocolException("field {$key}: invalid string");
                }
                $parts[] = '"' . $key . '":"' . $value . '"';
            } else {
                throw new ProtocolException("field {$key}: unsupported type");
            }
        }

        return '{' . implode(',', $parts) . '}';
    }

    /**
     * Strictly parses canonical bytes; anything that does not re-encode to exactly the
     * same bytes is rejected.
     *
     * @return array<string, string|int>
     */
    public static function decode(string $data): array
    {
        $len = strlen($data);
        if ($len < 2 || $len > 2048 || $data[0] !== '{') {
            throw new ProtocolException('payload is not in canonical form');
        }
        $pos = 1;
        $fields = [];
        while (true) {
            if ($pos >= $len || $data[$pos] !== '"') {
                throw new ProtocolException('payload is not in canonical form');
            }
            $end = strpos($data, '"', $pos + 1);
            if ($end === false) {
                throw new ProtocolException('payload is not in canonical form');
            }
            $key = substr($data, $pos + 1, $end - $pos - 1);
            if (array_key_exists($key, $fields)) {
                throw new ProtocolException('duplicate field');
            }
            $pos = $end + 1;
            if ($pos >= $len || $data[$pos] !== ':') {
                throw new ProtocolException('payload is not in canonical form');
            }
            ++$pos;
            if ($pos < $len && $data[$pos] === '"') {
                $end = strpos($data, '"', $pos + 1);
                if ($end === false) {
                    throw new ProtocolException('payload is not in canonical form');
                }
                $fields[$key] = substr($data, $pos + 1, $end - $pos - 1);
                $pos = $end + 1;
            } else {
                $start = $pos;
                while ($pos < $len && ctype_digit($data[$pos])) {
                    ++$pos;
                }
                $digits = substr($data, $start, $pos - $start);
                if ($digits === '' || strlen($digits) > 16) {
                    throw new ProtocolException('invalid integer');
                }
                $fields[$key] = (int) $digits;
            }
            if (count($fields) > 32) {
                throw new ProtocolException('too many fields');
            }
            if ($pos < $len && $data[$pos] === ',') {
                ++$pos;
                continue;
            }
            if ($pos !== $len - 1 || $data[$pos] !== '}') {
                throw new ProtocolException('payload is not in canonical form');
            }
            break;
        }
        if (self::encode($fields) !== $data) {
            throw new ProtocolException('payload is not in canonical form');
        }

        return $fields;
    }

    /**
     * Decodes and validates a version 1 payload.
     *
     * @return array{proto: int, type: string, node: string, key: string, seq: int, ts: int, mono_ms: int, boot: string, run: string, status: string, checks_failed: int, prev: string, agent_version: string, agent_commit: string, agent_build: string, agent_sha256: string}
     */
    public static function parsePayload(string $canonical): array
    {
        $f = self::decode($canonical);
        $keys = array_keys($f);
        sort($keys, SORT_STRING);
        if ($keys !== self::FIELDS) {
            throw new ProtocolException('payload must have exactly the version 1 fields');
        }
        $int = fn (string $k) => is_int($f[$k]) ? $f[$k] : throw new ProtocolException("field {$k} must be an integer");
        $str = fn (string $k) => is_string($f[$k]) ? $f[$k] : throw new ProtocolException("field {$k} must be a string");
        $checks = [
            'proto' => $int('proto') === self::VERSION,
            'type' => in_array($str('type'), self::TYPES, true),
            'node' => self::validNodeId($str('node')),
            'key' => preg_match('/^[0-9a-f]{64}$/', $str('key')) === 1,
            'seq' => $int('seq') >= 1,
            'ts' => $int('ts') >= 1,
            'mono_ms' => $int('mono_ms') >= 0,
            'boot' => preg_match('/^[0-9a-f]{32}$/', $str('boot')) === 1,
            'run' => preg_match('/^[0-9a-f]{32}$/', $str('run')) === 1,
            'status' => in_array($str('status'), self::STATUSES, true),
            'checks_failed' => $int('checks_failed') <= 64,
            'prev' => preg_match('/^[0-9a-f]{64}$/', $str('prev')) === 1,
            'agent_version' => preg_match('/^[A-Za-z0-9._+-]{1,64}$/', $str('agent_version')) === 1,
            'agent_commit' => preg_match('/^([0-9a-f]{7,40}|unknown)$/', $str('agent_commit')) === 1,
            'agent_build' => preg_match('/^([0-9TZ:.+-]{10,40}|unknown)$/', $str('agent_build')) === 1,
            'agent_sha256' => preg_match('/^[0-9a-f]{64}$/', $str('agent_sha256')) === 1,
        ];
        foreach ($checks as $field => $ok) {
            if (!$ok) {
                throw new ProtocolException("invalid {$field}");
            }
        }

        return $f;
    }

    public static function validNodeId(string $id): bool
    {
        return preg_match('/^[a-z0-9][a-z0-9-]{2,39}$/', $id) === 1;
    }

    public static function fingerprint(string $rawPublicKey): string
    {
        return hash('sha256', $rawPublicKey);
    }

    public static function verifySignature(string $rawPublicKey, string $canonical, string $rawSignature): bool
    {
        if (strlen($rawPublicKey) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES || strlen($rawSignature) !== SODIUM_CRYPTO_SIGN_BYTES) {
            return false;
        }

        try {
            return sodium_crypto_sign_verify_detached($rawSignature, self::SIGNATURE_CONTEXT . $canonical, $rawPublicKey);
        } catch (\SodiumException) {
            return false;
        }
    }

    public static function payloadHash(string $canonical): string
    {
        return hash('sha256', $canonical);
    }

    /**
     * SHA-256(prev_hash || payload_hash) over the raw 32-byte values.
     */
    public static function eventHash(string $prevHex, string $payloadHashHex): string
    {
        if (preg_match('/^[0-9a-f]{64}$/', $prevHex) !== 1 || preg_match('/^[0-9a-f]{64}$/', $payloadHashHex) !== 1) {
            throw new ProtocolException('invalid hash');
        }

        return hash('sha256', hex2bin($prevHex) . hex2bin($payloadHashHex));
    }

    private static function validString(string $value): bool
    {
        return strlen($value) <= 256 && preg_match('/^[\x20\x21\x23-\x5b\x5d-\x7e]*$/', $value) === 1;
    }
}
