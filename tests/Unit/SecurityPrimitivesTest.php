<?php

namespace Tests\Unit;

use App\Models\NetworkInterface;
use App\Models\Subnet;
use App\Secrets\Exceptions\SecretVaultException;
use App\Secrets\Keyring;
use App\Secrets\LocalSecretVault;
use App\Services\Audit\Redactor;
use App\Services\Import\Normalizer;
use App\Services\Spreadsheets\SpreadsheetWriter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class SecurityPrimitivesTest extends TestCase
{
    public function test_keyring_rejects_invalid_or_missing_keys(): void
    {
        $this->expectException(SecretVaultException::class);
        (new Keyring('base64:'.base64_encode('trop court'), null))->current();
    }

    public function test_keyring_requires_a_key(): void
    {
        $this->assertFalse((new Keyring(null, null))->isConfigured());
        $this->assertFalse((new Keyring('', '/chemin/inexistant'))->isConfigured());
    }

    public function test_keyring_reads_key_from_file_and_keeps_previous_keys(): void
    {
        $current = Keyring::generate();
        $previous = Keyring::generate();
        $file = tempnam(sys_get_temp_dir(), 'key');
        file_put_contents($file, $current."\n");

        $keyring = new Keyring(null, $file, [$previous]);

        $this->assertCount(2, $keyring->fingerprints());
        $this->assertSame(Keyring::fingerprint((string) base64_decode(substr($current, 7))), $keyring->currentId());
        $this->assertSame(16, strlen($keyring->currentId()));
        unlink($file);
    }

    public function test_each_encryption_uses_a_fresh_iv(): void
    {
        $vault = new LocalSecretVault(new Keyring(Keyring::generate(), null));

        $a = $vault->seal('même valeur');
        $b = $vault->seal('même valeur');

        $this->assertNotSame($a->ciphertext, $b->ciphertext);
        $this->assertSame('même valeur', $vault->unseal($a));
        $this->assertFalse($vault->needsResealing($a));
    }

    public function test_redactor_masks_sensitive_keys_recursively(): void
    {
        $clean = Redactor::scrub([
            'name' => 'visible',
            'password' => 'p@ss',
            'nested' => ['api_key' => 'k', 'ok' => 1],
            'secret' => ['old' => 'a', 'new' => 'b'],
            'remember_token' => 'x',
        ]);

        $this->assertSame('visible', $clean['name']);
        $this->assertSame(Redactor::REDACTED, $clean['password']);
        $this->assertSame(Redactor::REDACTED, $clean['nested']['api_key']);
        $this->assertSame(1, $clean['nested']['ok']);
        $this->assertSame(['old' => null, 'new' => Redactor::REDACTED], $clean['secret']);
        $this->assertSame(Redactor::REDACTED, $clean['remember_token']);
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function formulaProvider(): array
    {
        return [
            'égal' => ['=SUM(A1)', "'=SUM(A1)"],
            'plus' => ['+33 1 23', "'+33 1 23"],
            'moins' => ['-2+3', "'-2+3"],
            'arobase' => ['@cmd', "'@cmd"],
            'tabulation' => ["\t=1", "'\t=1"],
            'texte normal' => ['Serveur', 'Serveur'],
            'vide' => ['', ''],
        ];
    }

    #[DataProvider('formulaProvider')]
    public function test_spreadsheet_cells_are_neutralized(string $input, string $expected): void
    {
        $this->assertSame($expected, SpreadsheetWriter::neutralize($input));
    }

    public function test_import_dates_are_normalized(): void
    {
        $this->assertSame('2027-12-31', Normalizer::date('31/12/2027'));
        $this->assertSame('2027-12-31', Normalizer::date('2027-12-31'));
        $this->assertSame('2027-01-05', Normalizer::date('05.01.2027'));
        $this->assertSame('2027-02-30', Normalizer::date('2027-02-30'));
        $this->assertNull(Normalizer::date('  '));
        $this->assertSame(['10.0.0.1', '10.0.0.2'], Normalizer::list('10.0.0.1, 10.0.0.2 ;10.0.0.1', splitOnSpaces: true));
    }

    public function test_mac_addresses_are_normalized(): void
    {
        $this->assertSame('00:11:22:AA:BB:CC', NetworkInterface::normalizeMac('00-11-22-aa-bb-cc'));
        $this->assertSame('00:11:22:AA:BB:CC', NetworkInterface::normalizeMac('0011.22aa.bbcc'));
        $this->assertNull(NetworkInterface::normalizeMac(''));
    }

    public function test_subnet_membership(): void
    {
        $subnet = new Subnet(['cidr' => '192.168.10.0/24']);

        $this->assertTrue($subnet->contains('192.168.10.42'));
        $this->assertFalse($subnet->contains('192.168.11.1'));
        $this->assertFalse($subnet->contains('pas une ip'));
        $this->assertTrue((new Subnet(['cidr' => '10.0.0.0/8']))->contains('10.200.3.4'));
    }
}
