<?php

namespace App\Contracts\Payments;

/**
 * How to hand the player to the provider: a redirect, or an auto-submitted
 * POST form (common for hosted-checkout providers). Contains no secrets.
 */
final class CheckoutSession
{
    /**
     * @param  array<string, string>  $fields
     */
    public function __construct(
        public readonly string $method,
        public readonly string $url,
        public readonly array $fields = [],
        public readonly ?string $providerReference = null,
    ) {}

    public static function redirect(string $url, ?string $providerReference = null): self
    {
        return new self('redirect', $url, [], $providerReference);
    }

    /**
     * @param  array<string, string>  $fields
     */
    public static function post(string $url, array $fields, ?string $providerReference = null): self
    {
        return new self('post', $url, $fields, $providerReference);
    }

    /**
     * @return array{method: string, url: string, fields: array<string, string>}
     */
    public function toArray(): array
    {
        return ['method' => $this->method, 'url' => $this->url, 'fields' => $this->fields];
    }
}
