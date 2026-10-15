<?php

namespace App\Services\Integrations\Data;

use App\Enums\BillingPeriod;
use App\Enums\LicenseKind;
use App\Enums\LicenseStatus;
use Carbon\CarbonImmutable;

/**
 * Licence ou abonnement tel que décrit par le service externe, prêt à être
 * enregistré dans les licences du client rattaché.
 */
final readonly class ExternalLicense
{
    public function __construct(
        /** Identifiant stable chez le fournisseur (abonnement, SKU...). */
        public string $externalId,
        public string $name,
        public ?string $publisher = null,
        public ?int $quantity = null,
        /** Licences attribuées ou utilisées, si le service le fournit. */
        public ?int $assigned = null,
        public LicenseStatus $status = LicenseStatus::Active,
        public ?CarbonImmutable $startsAt = null,
        /** Fin d'engagement, échéance ou date de renouvellement. */
        public ?CarbonImmutable $expiresAt = null,
        public ?BillingPeriod $billingPeriod = null,
        public ?bool $autoRenewal = null,
        /** Référence lisible (identifiant d'abonnement, référence produit). */
        public ?string $reference = null,
        public LicenseKind $kind = LicenseKind::Subscription,
    ) {}
}
