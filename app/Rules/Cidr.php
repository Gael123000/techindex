<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Notation CIDR IPv4 (192.168.1.0/24) ou IPv6 (2001:db8::/32).
 */
class Cidr implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || ! str_contains($value, '/')) {
            $fail('Le réseau doit être au format CIDR, par exemple 192.168.10.0/24.');

            return;
        }

        [$address, $prefix] = explode('/', trim($value), 2);

        if (! ctype_digit($prefix)) {
            $fail('Le préfixe du réseau est invalide.');

            return;
        }

        $max = match (true) {
            filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false => 32,
            filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false => 128,
            default => null,
        };

        if ($max === null || (int) $prefix > $max) {
            $fail('Le réseau doit être au format CIDR, par exemple 192.168.10.0/24.');
        }
    }
}
