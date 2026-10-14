<?php

namespace Database\Seeders;

use App\Models\DocumentCategory;
use App\Models\EquipmentType;
use Illuminate\Database\Seeder;

/**
 * Données de référence nécessaires au fonctionnement (types d'équipements,
 * catégories de documentation). Idempotent : peut être relancé à chaque
 * installation ou mise à jour sans dupliquer ni écraser les personnalisations.
 */
class ReferenceDataSeeder extends Seeder
{
    public function run(): void
    {
        $types = [
            ['physical-server', 'Serveur physique', 'server'],
            ['virtual-server', 'Serveur virtuel', 'server-cog'],
            ['workstation', 'Poste de travail', 'monitor'],
            ['laptop', 'Ordinateur portable', 'laptop'],
            ['switch', 'Switch', 'network'],
            ['router', 'Routeur', 'router'],
            ['firewall', 'Pare-feu', 'shield'],
            ['wifi-ap', 'Borne Wi-Fi', 'wifi'],
            ['storage', 'NAS / stockage', 'hard-drive'],
            ['printer', 'Imprimante', 'printer'],
            ['network-device', 'Périphérique réseau', 'cpu'],
            ['ups', 'Onduleur', 'battery-charging'],
            ['phone', 'Téléphonie', 'phone'],
            ['camera', 'Caméra / vidéosurveillance', 'camera'],
            ['other', 'Autre équipement', 'box'],
        ];

        foreach ($types as $index => [$slug, $name, $icon]) {
            EquipmentType::query()->firstOrCreate(
                ['slug' => $slug],
                ['name' => $name, 'icon' => $icon, 'sort_order' => ($index + 1) * 10],
            );
        }

        $categories = [
            ['installation', 'Installation'],
            ['exploitation', 'Exploitation'],
            ['depannage', 'Dépannage'],
            ['architecture', 'Architecture'],
            ['maintenance', 'Maintenance'],
            ['sauvegarde-restauration', 'Sauvegarde et restauration'],
            ['securite', 'Sécurité'],
            ['autre', 'Autre'],
        ];

        foreach ($categories as $index => [$slug, $name]) {
            DocumentCategory::query()->firstOrCreate(
                ['slug' => $slug],
                ['name' => $name, 'sort_order' => ($index + 1) * 10],
            );
        }
    }
}
