<?php

declare(strict_types=1);

namespace App\Services;

/** /auth 的判定结果。注意：无论允许还是拒绝，HTTP 状态码都是 200（Hysteria 的契约）。 */
final class AuthResult
{
    public function __construct(
        public readonly bool $ok,
        public readonly string $id,
        public readonly string $reason = '',
        public readonly array $meta = [],
    ) {
    }

    public static function allow(string $id, array $meta = []): self
    {
        return new self(true, $id, 'ok', $meta);
    }

    public static function deny(string $reason, array $meta = []): self
    {
        return new self(false, '', $reason, $meta);
    }

    /** Hysteria 只认这两个字段：ok / id；其它字段它会忽略，方便我们自己排障。 */
    public function toArray(): array
    {
        $payload = ['ok' => $this->ok, 'id' => $this->id];

        if (! $this->ok && $this->reason !== '') {
            $payload['reason'] = $this->reason;
        }

        return $payload;
    }
}
