<?php

namespace App\Services\Internet;

use App\Enums\InternetLinkStatus;
use App\Enums\InternetTechnology;
use App\Models\InternetLink;
use App\Services\Audit\AuditLogger;
use App\Support\IspCatalog;
use Illuminate\Support\Facades\DB;

/**
 * Conversion des services de l'ancienne catégorie « Connexion Internet »
 * en liens Internet (onglet FAI).
 *
 * Rien n'est perdu : champs structurés, étiquettes, pièces jointes,
 * équipements, documents et accès liés sont repris ; une valeur qui ne
 * trouve pas sa place (débit non reconnu, adresse web...) est ajoutée aux
 * notes. Un service auquel un certificat est rattaché n'est pas converti
 * (un lien Internet n'a pas de certificat) : il reste dans les services.
 */
final class LegacyInternetServiceConverter
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * @return array{converted: int, kept: int}
     */
    public function convertAll(): array
    {
        $converted = 0;
        $kept = 0;

        $services = DB::table('services')->where('category', 'internet')->orderBy('id')->get();

        foreach ($services as $service) {
            if (DB::table('certificates')->where('service_id', $service->id)->exists()) {
                $kept++;

                continue;
            }

            DB::transaction(fn () => $this->convert($service));
            $converted++;
        }

        return ['converted' => $converted, 'kept' => $kept];
    }

    private function convert(object $service): void
    {
        $details = is_string($service->details) ? (json_decode($service->details, true) ?: []) : [];
        $isp = IspCatalog::match($service->provider);
        $extra = [];

        $download = $this->bandwidth($details['bandwidth_down'] ?? null, 'Débit descendant', $extra);
        $upload = $this->bandwidth($details['bandwidth_up'] ?? null, 'Débit montant', $extra);
        $gtr = $this->fit($details['sla'] ?? null, 120, 'Garantie de temps de rétablissement', $extra);
        $supportPhone = $this->fit($details['support_phone'] ?? null, 50, 'Support opérateur', $extra);
        $lineReference = $this->fit($details['line_number'] ?? null, 150, 'Référence de ligne', $extra);
        $publicIps = $this->fit($details['public_ips'] ?? null, 500, 'Adresses IP publiques', $extra);

        if (filled($service->url)) {
            $extra[] = 'Adresse web : '.$service->url;
        }

        if ($isp === null && filled($service->provider) && mb_strlen($service->provider) > 120) {
            $extra[] = 'Opérateur : '.$service->provider;
        }

        $notes = trim(implode("\n\n", array_filter([
            $service->notes,
            $extra === [] ? null : "Repris du service « Connexion Internet » :\n".implode("\n", $extra),
        ])));

        $linkId = DB::table('internet_links')->insertGetId([
            'client_id' => $service->client_id,
            'site_id' => $service->site_id,
            'isp_id' => $isp?->id,
            'operator_name' => $isp === null && filled($service->provider) ? mb_substr($service->provider, 0, 120) : null,
            'name' => $service->name,
            'role' => 'primary',
            'technology' => InternetTechnology::fromLegacyLabel($details['technology'] ?? null)?->value,
            'status' => match ($service->status) {
                'inactive' => InternetLinkStatus::Terminated->value,
                'archived' => InternetLinkStatus::Archived->value,
                default => InternetLinkStatus::Active->value,
            },
            'download_mbps' => $download,
            'upload_mbps' => $upload,
            'contract_reference' => $service->reference,
            'line_reference' => $lineReference,
            'public_ips' => $publicIps,
            'gtr' => $gtr,
            'support_phone' => $supportPhone,
            'commissioned_at' => $service->start_date,
            'commitment_ends_at' => $service->end_date,
            'description' => $service->description,
            'notes' => $notes === '' ? null : $notes,
            'created_by' => $service->created_by,
            'updated_by' => $service->updated_by,
            'created_at' => $service->created_at,
            'updated_at' => $service->updated_at,
        ]);

        DB::table('taggables')->where('taggable_type', 'service')->where('taggable_id', $service->id)
            ->update(['taggable_type' => 'internet_link', 'taggable_id' => $linkId]);

        DB::table('attachments')->where('attachable_type', 'service')->where('attachable_id', $service->id)
            ->update(['attachable_type' => 'internet_link', 'attachable_id' => $linkId]);

        DB::table('equipment_internet_link')->insert(
            DB::table('equipment_service')->where('service_id', $service->id)->pluck('equipment_id')
                ->map(fn (mixed $equipmentId): array => ['equipment_id' => (int) $equipmentId, 'internet_link_id' => $linkId])
                ->all(),
        );

        DB::table('document_internet_link')->insert(
            DB::table('document_service')->where('service_id', $service->id)->pluck('document_id')
                ->map(fn (mixed $documentId): array => ['document_id' => (int) $documentId, 'internet_link_id' => $linkId])
                ->all(),
        );

        // Accès rattachés (espace client, identifiants PPPoE...) : déplacés avant la
        // suppression du service, qui supprimerait sinon les accès en cascade.
        DB::table('credentials')->where('service_id', $service->id)->update(['service_id' => null, 'internet_link_id' => $linkId]);

        DB::table('services')->where('id', $service->id)->delete();

        $this->audit->log(
            'internet_link.converted',
            InternetLink::query()->find($linkId),
            metadata: ['service' => $service->name, 'service_id' => $service->id],
            clientId: $service->client_id,
        );
    }

    /**
     * @param  list<string>  $extra
     */
    private function bandwidth(mixed $value, string $label, array &$extra): ?int
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        $mbps = InternetLink::parseMbps($value);

        if ($mbps === null) {
            $extra[] = $label.' : '.trim($value);
        }

        return $mbps;
    }

    /**
     * Valeur reprise telle quelle si elle tient dans la colonne, sinon notée.
     *
     * @param  list<string>  $extra
     */
    private function fit(mixed $value, int $max, string $label, array &$extra): ?string
    {
        if (! is_scalar($value) || trim((string) $value) === '') {
            return null;
        }

        $value = trim((string) $value);

        if (mb_strlen($value) <= $max) {
            return $value;
        }

        $extra[] = $label.' : '.$value;

        return null;
    }
}
