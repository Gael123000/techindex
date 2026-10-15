<?php

namespace Database\Seeders;

use App\Enums\AccessType;
use App\Enums\BillingPeriod;
use App\Enums\CertificateStatus;
use App\Enums\CertificateType;
use App\Enums\DocumentStatus;
use App\Enums\EquipmentLinkType;
use App\Enums\EquipmentStatus;
use App\Enums\InternetConnectionMode;
use App\Enums\InternetLinkRole;
use App\Enums\InternetLinkStatus;
use App\Enums\InternetTechnology;
use App\Enums\LicenseKind;
use App\Enums\LicenseStatus;
use App\Enums\RecordStatus;
use App\Enums\ServiceCategory;
use App\Enums\ServiceStatus;
use App\Enums\SubnetPurpose;
use App\Enums\UserRole;
use App\Models\Certificate;
use App\Models\Client;
use App\Models\ClientAssignment;
use App\Models\Credential;
use App\Models\Document;
use App\Models\DocumentCategory;
use App\Models\Equipment;
use App\Models\EquipmentLink;
use App\Models\EquipmentType;
use App\Models\FileFolder;
use App\Models\Folder;
use App\Models\InternetLink;
use App\Models\Isp;
use App\Models\License;
use App\Models\Service;
use App\Models\Site;
use App\Models\Subnet;
use App\Models\User;
use App\Models\VaultFolder;
use App\Models\Vlan;
use App\Secrets\CredentialSecrets;
use App\Services\Audit\AuditLogger;
use App\Services\Documents\DocumentService;
use App\Services\Inventory\EquipmentService;
use App\Services\Tags\TagResolver;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Données de démonstration ENTIÈREMENT FICTIVES : noms, adresses (domaines
 * .example / .test), numéros de série et secrets générés aléatoirement.
 * Les comptes de démonstration reçoivent un mot de passe aléatoire jamais
 * affiché : utilisez « Envoyer un lien de réinitialisation » pour les tester.
 */
class DemoSeeder extends Seeder
{
    public const REFERENCE_PREFIX = 'DEMO-';

    public function __construct(
        private readonly EquipmentService $equipment,
        private readonly DocumentService $documents,
        private readonly CredentialSecrets $secrets,
        private readonly TagResolver $tags,
        private readonly AuditLogger $audit,
    ) {}

    public function run(): void
    {
        $this->call(ReferenceDataSeeder::class);

        $this->audit->withoutLogging(function (): void {
            DB::transaction(function (): void {
                $users = $this->users();

                foreach ($this->clients() as $index => $definition) {
                    $this->seedClient($definition, $users, $index);
                }
            });
        });

        $this->audit->log('demo.loaded', metadata: ['clients' => count($this->clients())]);
    }

    /**
     * @return array<string, User>
     */
    private function users(): array
    {
        $users = [];

        foreach ([
            'manager' => ['Camille Démo (responsable)', 'responsable.demo@techindex.test', UserRole::Manager],
            'technician' => ['Alex Démo (technicien)', 'technicien.demo@techindex.test', UserRole::Technician],
            'reader' => ['Dominique Démo (lecteur)', 'lecteur.demo@techindex.test', UserRole::Reader],
        ] as $key => [$name, $email, $role]) {
            $users[$key] = User::query()->firstOrCreate(['email' => $email], [
                'name' => $name,
                // Mot de passe aléatoire, jamais affiché ni connu.
                'password' => Str::password(40),
                'role' => $role,
                'is_active' => true,
                'must_change_password' => true,
            ]);
        }

        return $users;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function clients(): array
    {
        return [
            [
                'reference' => 'DEMO-001', 'name' => 'Atelier Fictif Dupont', 'city' => 'Lyon', 'postal_code' => '69007',
                'address' => '12 rue Imaginaire', 'domain' => 'atelier-fictif.example', 'net' => '10.10',
                'description' => 'Fabricant fictif de mobilier, 45 postes, deux sites.', 'tags' => ['Infogérance', 'PME'],
                'sites' => ['Siège Lyon' => 'Lyon', 'Entrepôt Vénissieux' => 'Vénissieux'],
            ],
            [
                'reference' => 'DEMO-002', 'name' => 'Cabinet Exemple & Associés', 'city' => 'Grenoble', 'postal_code' => '38000',
                'address' => '3 place des Démonstrations', 'domain' => 'cabinet-exemple.example', 'net' => '10.20',
                'description' => 'Cabinet d\'expertise fictif, 18 postes, télétravail.', 'tags' => ['Infogérance', 'Cloud'],
                'sites' => ['Bureaux Grenoble' => 'Grenoble'],
            ],
            [
                'reference' => 'DEMO-003', 'name' => 'Clinique Vétérinaire Démo', 'city' => 'Annecy', 'postal_code' => '74000',
                'address' => '8 avenue du Test', 'domain' => 'clinique-demo.example', 'net' => '10.30',
                'description' => 'Clinique fictive, logiciel métier sur site et imagerie.', 'tags' => ['Santé'],
                'sites' => ['Clinique' => 'Annecy'],
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $d
     * @param  array<string, User>  $users
     */
    private function seedClient(array $d, array $users, int $index): void
    {
        if (Client::query()->where('reference', $d['reference'])->exists()) {
            return;
        }

        $today = Carbon::today();
        $client = new Client([
            'name' => $d['name'],
            'reference' => $d['reference'],
            'description' => $d['description'],
            'address_line1' => $d['address'],
            'postal_code' => $d['postal_code'],
            'city' => $d['city'],
            'country' => 'France',
            'phone' => '04 00 00 0'.$index.' 00',
            'email' => 'contact@'.$d['domain'],
            'website' => 'https://www.'.$d['domain'],
            'account_manager_id' => $users['manager']->id,
            'notes' => 'Client de démonstration : toutes les informations sont fictives.',
        ]);
        $client->status = RecordStatus::Active;
        $client->save();
        $client->tags()->sync($this->tags->idsFor($d['tags']));

        foreach ($users as $key => $user) {
            ClientAssignment::query()->create([
                'client_id' => $client->id,
                'user_id' => $user->id,
                ...$user->role->defaultClientPermissions(),
            ]);
        }

        // Sites et contacts.
        $sites = [];
        $i = 0;

        foreach ($d['sites'] as $name => $city) {
            $site = new Site(['name' => $name, 'code' => strtoupper(Str::substr(Str::slug($city), 0, 3)).'-0'.(++$i), 'city' => $city, 'country' => 'France',
                'address_line1' => ($i * 7).' rue de l\'Exemple', 'access_instructions' => 'Accueil 8h-18h. Local technique au sous-sol (badge à demander).']);
            $site->client()->associate($client);
            $site->status = RecordStatus::Active;
            $site->save();
            $sites[] = $site;
        }

        foreach ([['Martin', 'Claire', 'Directrice', true, false], ['Bernard', 'Hugo', 'Référent informatique', false, true], ['Petit', 'Inès', 'Assistante', false, false]] as [$last, $first, $job, $primary, $technical]) {
            $client->contacts()->create([
                'site_id' => $sites[0]->id, 'first_name' => $first, 'last_name' => $last, 'job_title' => $job,
                'email' => Str::lower(Str::ascii($first)).'.'.Str::lower($last).'@'.$d['domain'], 'phone' => '04 00 00 00 0'.$index,
                'is_primary' => $primary, 'is_technical' => $technical,
            ]);
        }

        // Réseau.
        $vlanUsers = $this->vlan($client, $sites[0], 10, 'Utilisateurs');
        $vlanServers = $this->vlan($client, $sites[0], 20, 'Serveurs');
        $this->vlan($client, $sites[0], 99, 'Administration');
        $lan = $this->subnet($client, $sites[0], $vlanUsers, 'LAN utilisateurs', $d['net'].'.10.0/24', $d['net'].'.10.1', true);
        $srv = $this->subnet($client, $sites[0], $vlanServers, 'Serveurs', $d['net'].'.20.0/24', $d['net'].'.20.1', false);

        // Équipements.
        $type = fn (string $slug): int => EquipmentType::query()->where('slug', $slug)->value('id');
        $make = function (array $attributes, array $ips = [], array $tags = []) use ($client, $sites, $lan, $srv): Equipment {
            $interfaces = array_map(fn (string $ip, int $n): array => [
                'label' => $n === 0 ? 'LAN' : 'Interface '.($n + 1),
                'ip_address' => $ip,
                'mac_address' => implode(':', str_split(strtoupper(bin2hex(random_bytes(6))), 2)),
                'subnet_id' => str_contains($ip, '.20.') ? $srv->id : $lan->id,
                'is_primary' => $n === 0,
            ], $ips, array_keys($ips));

            return $this->equipment->save(new Equipment, $client, [
                'site_id' => $sites[0]->id,
                'status' => EquipmentStatus::InService->value,
                'serial_number' => strtoupper(Str::random(10)),
                ...$attributes,
                'interfaces' => $interfaces,
                'tags' => $tags,
            ], collect());
        };

        $host = $make(['equipment_type_id' => $type('physical-server'), 'name' => 'HYP-01', 'hostname' => 'hyp-01', 'manufacturer' => 'Dell', 'model' => 'PowerEdge R660',
            'os_name' => 'Proxmox VE', 'os_version' => '9.0', 'role' => 'Hyperviseur', 'location' => 'Baie 1 – U10',
            'purchase_date' => $today->copy()->subYears(4)->toDateString(), 'warranty_expires_at' => $today->copy()->addDays(25 + $index * 10)->toDateString(),
            'admin_url' => 'https://'.$d['net'].'.20.5:8006', 'supplier' => 'Distributeur Fictif'], [$d['net'].'.20.5', $d['net'].'.99.5'], ['Critique']);
        $dc = $make(['equipment_type_id' => $type('virtual-server'), 'name' => 'SRV-AD01', 'hostname' => 'srv-ad01', 'os_name' => 'Windows Server', 'os_version' => '2025',
            'role' => 'Contrôleur de domaine, DNS, DHCP'], [$d['net'].'.20.10'], ['Critique']);
        $files = $make(['equipment_type_id' => $type('virtual-server'), 'name' => 'SRV-FIC01', 'hostname' => 'srv-fic01', 'os_name' => 'Windows Server', 'os_version' => '2022',
            'role' => 'Serveur de fichiers'], [$d['net'].'.20.11']);
        $fw = $make(['equipment_type_id' => $type('firewall'), 'name' => 'FW-01', 'hostname' => 'fw-01', 'manufacturer' => 'Fortinet', 'model' => 'FortiGate 70G',
            'firmware_version' => '7.6.4', 'role' => 'Pare-feu périmétrique', 'admin_url' => 'https://'.$d['net'].'.99.1',
            'warranty_expires_at' => $today->copy()->addYears(2)->toDateString()], [$d['net'].'.99.1', $d['net'].'.10.1']);
        $switch = $make(['equipment_type_id' => $type('switch'), 'name' => 'SW-CORE-01', 'manufacturer' => 'HPE Aruba', 'model' => 'CX 6100 48G',
            'firmware_version' => '10.15', 'end_of_life_at' => $today->copy()->addDays(60)->toDateString()], [$d['net'].'.99.2']);
        $nas = $make(['equipment_type_id' => $type('storage'), 'name' => 'NAS-SAUV', 'manufacturer' => 'Synology', 'model' => 'RS1221+', 'role' => 'Cible de sauvegarde'], [$d['net'].'.20.20']);
        $ups = $make(['equipment_type_id' => $type('ups'), 'name' => 'OND-BAIE1', 'manufacturer' => 'APC', 'model' => 'Smart-UPS 1500',
            'warranty_expires_at' => $today->copy()->subDays(10)->toDateString(), 'end_of_life_at' => $today->copy()->subDays(5)->toDateString()], [$d['net'].'.99.10']);
        $make(['equipment_type_id' => $type('printer'), 'name' => 'IMP-ACCUEIL', 'manufacturer' => 'Brother', 'model' => 'MFC-L8900'], [$d['net'].'.10.50']);
        $make(['equipment_type_id' => $type('wifi-ap'), 'name' => 'WIFI-RDC', 'manufacturer' => 'Ubiquiti', 'model' => 'U7 Pro'], [$d['net'].'.99.20']);

        foreach (range(1, 4) as $n) {
            $make(['equipment_type_id' => $type('workstation'), 'name' => sprintf('PC-%s-%02d', strtoupper(Str::substr(Str::slug($d['city']), 0, 3)), $n),
                'manufacturer' => 'Lenovo', 'model' => 'ThinkCentre M70q', 'os_name' => 'Windows', 'os_version' => '11 Pro',
                'warranty_expires_at' => $today->copy()->addDays(40 + $n * 30)->toDateString()], [$d['net'].'.10.'.(100 + $n)]);
        }

        foreach ([[$host, $dc, EquipmentLinkType::Hosts], [$host, $files, EquipmentLinkType::Hosts], [$dc, $fw, EquipmentLinkType::ProtectedBy],
            [$host, $ups, EquipmentLinkType::PoweredBy], [$files, $nas, EquipmentLinkType::BackedUpBy], [$host, $switch, EquipmentLinkType::ConnectedTo]] as [$source, $target, $linkType]) {
            $link = new EquipmentLink(['source_equipment_id' => $source->id, 'target_equipment_id' => $target->id, 'type' => $linkType]);
            $link->client()->associate($client);
            $link->save();
        }

        // Accès Internet (FAI) : fibre principale chez un opérateur du catalogue,
        // secours 4G chez un opérateur fictif. Adresses publiques de documentation (RFC 5737).
        $operator = Isp::query()->where('slug', ['orange-business', 'sfr-business', 'bouygues-telecom'][$index % 3])->first();
        $block = 8 * ($index + 1);
        $internet = $this->internetLink($client, $sites[0], [
            'isp_id' => $operator?->id, 'operator_name' => $operator === null ? 'Opérateur Fictif Télécom' : null,
            'name' => 'Fibre principale', 'role' => InternetLinkRole::Primary, 'technology' => InternetTechnology::Ftto,
            'download_mbps' => 1000, 'upload_mbps' => 1000, 'guaranteed_mbps' => 1000,
            'customer_number' => 'CLI-'.strtoupper(Str::random(8)), 'contract_reference' => 'CT-'.strtoupper(Str::random(6)),
            'line_reference' => 'FTTO-DEMO-'.random_int(100000, 999999), 'connection_mode' => InternetConnectionMode::Static,
            'public_ips' => '203.0.113.'.$block.'/29', 'wan_gateway' => '203.0.113.'.($block + 1), 'gtr' => '4 h ouvrées',
            'commissioned_at' => $today->copy()->subYears(2)->toDateString(), 'commitment_ends_at' => $today->copy()->addMonths(14)->toDateString(),
            'monthly_cost' => 290 + 20 * $index, 'notes' => 'Lien de démonstration : références fictives.',
        ], [$fw]);
        $this->internetLink($client, $sites[0], [
            'operator_name' => 'Opérateur Fictif Mobile', 'name' => 'Secours 4G', 'role' => InternetLinkRole::Backup,
            'technology' => InternetTechnology::Mobile, 'download_mbps' => 150, 'upload_mbps' => 50,
            'connection_mode' => InternetConnectionMode::Dhcp, 'monthly_cost' => 35,
        ], [$fw]);

        $wan = new Subnet(['site_id' => $sites[0]->id, 'purpose' => SubnetPurpose::Wan, 'internet_link_id' => $internet->id, 'name' => 'WAN fibre',
            'cidr' => '203.0.113.'.$block.'/29', 'gateway' => '203.0.113.'.($block + 1), 'description' => 'Bloc d\'adresses publiques fourni par l\'opérateur.']);
        $wan->client()->associate($client);
        $wan->save();

        // Services.
        $this->service($client, null, ServiceCategory::Domain, $d['domain'], 'Registrar Exemple',
            ['domain_name' => $d['domain'], 'dns_hosting' => 'Registrar Exemple', 'nameservers' => "ns1.registrar.example\nns2.registrar.example", 'auto_renewal' => false],
            $today->copy()->addDays(20 + $index * 15), []);
        $this->service($client, null, ServiceCategory::Email, 'Messagerie collaborative', 'Éditeur Cloud Fictif',
            ['platform' => 'Exchange Online (Microsoft 365)', 'domains' => $d['domain'], 'mailbox_count' => 20 + $index * 5], null, []);
        $backup = $this->service($client, $sites[0], ServiceCategory::Backup, 'Sauvegarde des serveurs', 'Éditeur Sauvegarde Démo',
            ['solution' => 'Logiciel de sauvegarde fictif', 'backup_type' => 'Machines virtuelles', 'schedule' => 'Tous les jours à 22 h', 'retention' => '30 jours + 12 mensuelles',
                'destination' => 'NAS-SAUV puis copie externalisée', 'offsite_copy' => true, 'encrypted' => true, 'last_restore_test' => $today->copy()->subMonths(2)->toDateString()],
            null, [$host, $dc, $files, $nas]);
        $vpn = $this->service($client, null, ServiceCategory::Vpn, 'VPN nomade', 'Fortinet',
            ['vpn_type' => 'SSL / TLS nomade', 'remote_endpoint' => 'vpn.'.$d['domain'], 'authentication' => 'Identifiants'], null, [$fw]);

        // Licences et certificats.
        $this->license($client, 'Antivirus EDR', LicenseKind::Subscription, 'Éditeur Sécurité Fictif', 50, $today->copy()->addDays(15 + $index * 20), BillingPeriod::Yearly);
        $this->license($client, 'Suite bureautique', LicenseKind::Subscription, 'Éditeur Cloud Fictif', 25, $today->copy()->addMonths(7), BillingPeriod::Monthly);
        $this->license($client, 'Support pare-feu', LicenseKind::Support, 'Fortinet', 1, $today->copy()->addDays(45), BillingPeriod::Yearly, [$fw]);
        $this->license($client, 'Windows Server Datacenter', LicenseKind::License, 'Éditeur Système Fictif', 2, null, BillingPeriod::Perpetual, [$host]);

        $certificate = new Certificate(['name' => 'Certificat VPN', 'common_name' => 'vpn.'.$d['domain'], 'issuer' => 'Autorité Publique Exemple',
            'type' => CertificateType::Public, 'issued_at' => $today->copy()->subMonths(11)->toDateString(), 'expires_at' => $today->copy()->addDays(12 + $index * 9)->toDateString(),
            'auto_renewal' => false, 'renewal_method' => 'Renouvellement manuel sur la console du registrar, puis import sur le pare-feu.', 'status' => CertificateStatus::Active, 'service_id' => $vpn->id]);
        $certificate->client()->associate($client);
        $certificate->save();
        $certificate->equipment()->sync([$fw->id]);

        // Dossiers du gestionnaire de mots de passe et de l'explorateur de fichiers.
        $folder = function (string $class, string $name, ?Folder $parent = null) use ($client): Folder {
            $folder = new $class(['name' => $name, 'parent_id' => $parent?->id]);
            $folder->client()->associate($client);
            $folder->save();

            return $folder;
        };
        $infrastructure = $folder(VaultFolder::class, 'Infrastructure');
        $network = $folder(VaultFolder::class, 'Réseau', $infrastructure);
        $suppliers = $folder(VaultFolder::class, 'Prestataires');
        $folder(FileFolder::class, 'Contrats');
        $folder(FileFolder::class, 'Plans et schémas');
        $folder(FileFolder::class, 'Procédures');

        // Accès : secrets générés aléatoirement, chiffrés comme en production.
        foreach ([[$fw, 'Administration web pare-feu', AccessType::Web, 'admin', 443, $network], [$host, 'Console hyperviseur', AccessType::Web, 'root@pam', 8006, $infrastructure],
            [$dc, 'Compte d\'administration du domaine', AccessType::Rdp, 'ADM-demo', 3389, $infrastructure], [$switch, 'SSH switch cœur', AccessType::Ssh, 'manager', 22, $network]] as [$target, $label, $accessType, $username, $port, $vaultFolder]) {
            $credential = new Credential(['label' => $label, 'access_type' => $accessType, 'username' => $username, 'host' => $target->hostname ?? $target->name,
                'port' => $port, 'equipment_id' => $target->id, 'folder_id' => $vaultFolder->id, 'notes' => 'Identifiant fictif de démonstration.']);
            $credential->client()->associate($client);
            $this->secrets->apply($credential, Str::password(20));
            $credential->save();
        }

        $portal = new Credential(['label' => 'Portail opérateur', 'access_type' => AccessType::Web, 'username' => 'client-'.$d['reference'],
            'url' => 'https://espace-client.operateur.example', 'internet_link_id' => $internet->id, 'folder_id' => $suppliers->id]);
        $portal->client()->associate($client);
        $this->secrets->apply($portal, Str::password(18));
        $portal->save();

        // Documentation.
        $category = fn (string $slug): ?int => DocumentCategory::query()->where('slug', $slug)->value('id');
        $documents = [
            ['Procédure de restauration d\'une machine virtuelle', 'sauvegarde-restauration', $this->restoreProcedure(), [$host, $nas], [$backup], [], 200],
            ['Redémarrage planifié de l\'infrastructure', 'exploitation', $this->restartProcedure(), [$host, $dc, $files], [], [], 400],
            ['Architecture réseau', 'architecture', $this->architecture($d['net']), [$fw, $switch], [], [$internet], null],
        ];

        foreach ($documents as [$title, $slug, $html, $equipmentLinks, $serviceLinks, $internetLinks, $verifiedDaysAgo]) {
            $document = $this->documents->save(new Document, $client, [
                'title' => $title, 'content' => $html, 'category_id' => $category($slug), 'status' => DocumentStatus::Published->value,
                'owner_id' => $users['technician']->id, 'review_interval_days' => 180,
                'equipment_ids' => array_map(fn (Equipment $e): int => $e->id, $equipmentLinks),
                'service_ids' => array_map(fn (Service $s): int => $s->id, $serviceLinks),
                'internet_link_ids' => array_map(fn (InternetLink $link): int => $link->id, $internetLinks),
                'tags' => [],
            ], $users['technician']);

            if ($verifiedDaysAgo !== null) {
                $document->forceFill(['last_verified_at' => now()->subDays($verifiedDaysAgo), 'last_verified_by' => $users['manager']->id])->saveQuietly();
            }
        }
    }

    private function vlan(Client $client, Site $site, int $number, string $name): Vlan
    {
        $vlan = new Vlan(['site_id' => $site->id, 'number' => $number, 'name' => $name]);
        $vlan->client()->associate($client);
        $vlan->save();

        return $vlan;
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @param  list<Equipment>  $equipment
     */
    private function internetLink(Client $client, Site $site, array $attributes, array $equipment): InternetLink
    {
        $link = new InternetLink(['site_id' => $site->id, 'status' => InternetLinkStatus::Active, ...$attributes]);
        $link->client()->associate($client);
        $link->save();
        $link->equipment()->sync(array_map(fn (Equipment $e): int => $e->id, $equipment));

        return $link;
    }

    private function subnet(Client $client, Site $site, Vlan $vlan, string $name, string $cidr, string $gateway, bool $dhcp): Subnet
    {
        $subnet = new Subnet(['site_id' => $site->id, 'vlan_id' => $vlan->id, 'purpose' => SubnetPurpose::Lan, 'name' => $name, 'cidr' => $cidr, 'gateway' => $gateway,
            'dns_servers' => str_replace('.0/24', '.10', $cidr), 'dhcp_enabled' => $dhcp, 'dhcp_range' => $dhcp ? '.100 – .200' : null]);
        $subnet->client()->associate($client);
        $subnet->save();

        return $subnet;
    }

    /**
     * @param  array<string, mixed>  $details
     * @param  list<Equipment>  $equipment
     */
    private function service(Client $client, ?Site $site, ServiceCategory $category, string $name, string $provider, array $details, ?Carbon $endDate, array $equipment): Service
    {
        $service = new Service(['site_id' => $site?->id, 'category' => $category, 'name' => $name, 'provider' => $provider,
            'reference' => 'CT-'.strtoupper(Str::random(6)), 'status' => ServiceStatus::Active, 'end_date' => $endDate?->toDateString(),
            'details' => $category->normalizeDetails($details)]);
        $service->client()->associate($client);
        $service->save();
        $service->equipment()->sync(array_map(fn (Equipment $e): int => $e->id, $equipment));

        return $service;
    }

    /**
     * @param  list<Equipment>  $equipment
     */
    private function license(Client $client, string $name, LicenseKind $kind, string $publisher, int $quantity, ?Carbon $expires, BillingPeriod $period, array $equipment = []): void
    {
        $license = new License(['name' => $name, 'kind' => $kind, 'publisher' => $publisher, 'supplier' => 'Distributeur Fictif', 'quantity' => $quantity,
            'billing_period' => $period, 'expires_at' => $expires?->toDateString(), 'auto_renewal' => $kind === LicenseKind::Subscription,
            'renewal_notice_days' => 30, 'renewal_notes' => 'Demander le devis au distributeur un mois avant l\'échéance.', 'status' => LicenseStatus::Active,
            'contract_reference' => 'LIC-'.strtoupper(Str::random(8))]);
        $license->client()->associate($client);
        $license->save();
        $license->equipment()->sync(array_map(fn (Equipment $e): int => $e->id, $equipment));
    }

    private function restoreProcedure(): string
    {
        return '<h2>Objectif</h2><p>Restaurer une machine virtuelle à partir de la dernière sauvegarde valide.</p>'
            .'<h2>Prérequis</h2><ul><li>Accès à la console de sauvegarde (voir la rubrique <strong>Accès</strong>).</li><li>Prévenir le référent du client.</li></ul>'
            .'<h2>Étapes</h2><ol><li>Ouvrir la console de sauvegarde et sélectionner la machine.</li><li>Choisir le point de restauration.</li>'
            .'<li>Restaurer vers un nouvel identifiant, puis vérifier le démarrage.</li><li>Basculer le service et consigner l\'intervention.</li></ol>'
            .'<blockquote><p>Ne jamais écraser la machine d\'origine avant d\'avoir validé la restauration.</p></blockquote>';
    }

    private function restartProcedure(): string
    {
        return '<h2>Ordre d\'arrêt</h2><ol><li>Serveurs applicatifs</li><li>Serveur de fichiers</li><li>Contrôleur de domaine</li><li>Hyperviseur</li></ol>'
            .'<h2>Ordre de démarrage</h2><p>Ordre inverse. Vérifier la réplication de l\'annuaire :</p><pre><code>repadmin /replsummary</code></pre>';
    }

    private function architecture(string $net): string
    {
        return '<h2>Vue d\'ensemble</h2><p>Le pare-feu FW-01 assure le routage inter-VLAN et l\'accès Internet.</p>'
            .'<table><thead><tr><th>VLAN</th><th>Réseau</th><th>Usage</th></tr></thead><tbody>'
            ."<tr><td>10</td><td>{$net}.10.0/24</td><td>Utilisateurs</td></tr>"
            ."<tr><td>20</td><td>{$net}.20.0/24</td><td>Serveurs</td></tr>"
            ."<tr><td>99</td><td>{$net}.99.0/24</td><td>Administration</td></tr></tbody></table>";
    }
}
