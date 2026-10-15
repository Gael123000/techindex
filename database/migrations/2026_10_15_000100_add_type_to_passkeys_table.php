<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Type de clé d'accès (App\Enums\PasskeyType), choisi dans une liste à
        // l'ajout. Le nom devient une précision facultative.
        Schema::table('passkeys', function (Blueprint $table) {
            $table->string('type', 30)->default('other')->after('user_id');
        });

        // Clés existantes : type déduit du nom saisi librement, nom conservé.
        DB::table('passkeys')->select(['id', 'name'])->orderBy('id')->each(function (object $passkey): void {
            DB::table('passkeys')->where('id', $passkey->id)->update(['type' => $this->guessType((string) $passkey->name)]);
        });
    }

    public function down(): void
    {
        Schema::table('passkeys', function (Blueprint $table) {
            $table->dropColumn('type');
        });
    }

    private function guessType(string $name): string
    {
        $name = mb_strtolower($name);

        return match (true) {
            (bool) preg_match('/windows|hello/u', $name) => 'windows_hello',
            (bool) preg_match('/iphone|ipad|\bmac|apple|touch id|face id|icloud/u', $name) => 'apple',
            str_contains($name, 'android') => 'android',
            (bool) preg_match('/yubi|clé de sécurité|cle de securite|usb|nfc|fido|titan|feitian|solo/u', $name) => 'security_key',
            (bool) preg_match('/google|bitwarden|1password|dashlane|keepass|proton|nordpass|lastpass|enpass/u', $name) => 'password_manager',
            default => 'other',
        };
    }
};
