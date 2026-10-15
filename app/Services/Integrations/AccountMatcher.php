<?php

namespace App\Services\Integrations;

use App\Models\Client;
use App\Models\IntegrationAccount;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Rapprochement automatique d'un compte externe avec un client TechIndex :
 * même nom (sans forme juridique ni accents) ou même domaine (site web,
 * adresse e-mail). Seule une correspondance unique est proposée.
 */
class AccountMatcher
{
    private const LEGAL_FORMS = [
        'sas', 'sasu', 'sarl', 'eurl', 'sa', 'sci', 'snc', 'scop', 'selarl', 'ei', 'eirl',
        'inc', 'llc', 'ltd', 'limited', 'gmbh', 'ag', 'bv', 'nv', 'srl', 'spa', 'plc', 'corp', 'co',
        'groupe', 'group', 'societe', 'ste', 'cie', 'et', 'and', 'the', 'le', 'la', 'les',
    ];

    private const PUBLIC_MAIL = [
        'gmail', 'googlemail', 'outlook', 'hotmail', 'live', 'msn', 'yahoo', 'icloud', 'me', 'aol',
        'orange', 'wanadoo', 'free', 'sfr', 'neuf', 'laposte', 'bbox', 'numericable', 'gmx', 'proton', 'protonmail',
    ];

    /** @var Collection<int, array{id: int, name: string, domains: list<string>}>|null */
    private ?Collection $clients = null;

    public function suggest(IntegrationAccount $account): ?int
    {
        $clients = $this->clients();
        $name = $this->normalize($account->name);

        $byName = $name === '' ? collect() : $clients->filter(fn (array $client): bool => $client['name'] === $name);

        if ($byName->count() === 1) {
            return $byName->first()['id'];
        }

        $domain = $this->domain($account->domain);

        if ($domain === null) {
            return null;
        }

        $byDomain = $clients->filter(fn (array $client): bool => in_array($domain, $client['domains'], true));

        return $byDomain->count() === 1 ? $byDomain->first()['id'] : null;
    }

    public function normalize(string $name): string
    {
        $words = preg_split('/[^a-z0-9]+/', Str::lower(Str::ascii($name))) ?: [];

        return implode(' ', array_values(array_filter($words, fn (string $word): bool => $word !== '' && ! in_array($word, self::LEGAL_FORMS, true))));
    }

    /**
     * Domaine principal d'une adresse web, d'un e-mail ou d'un domaine
     * (contoso.onmicrosoft.com → contoso).
     */
    private function domain(?string $value): ?string
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        $host = Str::lower(trim($value));
        $host = str_contains($host, '@') ? Str::after($host, '@') : (parse_url(str_contains($host, '://') ? $host : 'https://'.$host, PHP_URL_HOST) ?: $host);
        $host = preg_replace('/^www\./', '', $host) ?? $host;

        if (str_ends_with($host, '.onmicrosoft.com')) {
            return Str::before($host, '.onmicrosoft.com');
        }

        $parts = explode('.', $host);
        $label = count($parts) >= 2 ? $parts[count($parts) - 2] : null;

        // Messageries grand public : un même domaine pour des milliers d'entreprises.
        return in_array($label, self::PUBLIC_MAIL, true) ? null : $label;
    }

    /**
     * @return Collection<int, array{id: int, name: string, domains: list<string>}>
     */
    private function clients(): Collection
    {
        return $this->clients ??= Client::query()
            ->where('status', 'active')
            ->get(['id', 'name', 'website', 'email'])
            ->map(fn (Client $client): array => [
                'id' => $client->id,
                'name' => $this->normalize($client->name),
                'domains' => array_values(array_filter([$this->domain($client->website), $this->domain($client->email)])),
            ]);
    }
}
