<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Isp;
use App\Services\Attachments\AttachmentStorage;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Catalogue des fournisseurs d'accès à Internet (FAI).
 *
 * Les opérateurs livrés avec TechIndex ont un logo officiel fourni ; un
 * administrateur peut le remplacer par un logo déposé (PNG, JPEG, WebP).
 */
class IspController extends Controller
{
    public function __construct(private readonly AttachmentStorage $storage) {}

    public function index(): Response
    {
        Gate::authorize('manage-settings');

        $usage = DB::table('internet_links')->selectRaw('isp_id, COUNT(*) as total')->whereNotNull('isp_id')->groupBy('isp_id')->pluck('total', 'isp_id');

        return Inertia::render('Admin/Isps/Index', [
            'isps' => Isp::query()->orderBy('name')->get()->map(fn (Isp $isp): array => [
                ...$isp->only([
                    'id', 'name', 'country', 'website', 'customer_area_url', 'status_url', 'support_phone',
                    'business_support_phone', 'support_email', 'notes', 'is_active',
                ]),
                'logo_url' => $isp->logoUrl(),
                'has_custom_logo' => $isp->logo_path !== null,
                'has_bundled_logo' => $isp->bundledLogoPath() !== null,
                'links_count' => (int) ($usage[$isp->id] ?? 0),
            ]),
            'countries' => self::countries(),
            'maxLogoKb' => (int) config('techindex.logos.max_size_kb'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        Gate::authorize('manage-settings');

        $data = $this->validated($request);
        $isp = new Isp($data);
        $isp->slug = $this->uniqueSlug($data['name']);
        $this->storeLogo($request, $isp);
        $isp->save();

        return back()->with('success', 'Opérateur ajouté au catalogue.');
    }

    public function update(Request $request, Isp $isp): RedirectResponse
    {
        Gate::authorize('manage-settings');

        $isp->fill($this->validated($request, $isp));

        if ($request->boolean('remove_logo') && $isp->logo_path !== null) {
            $this->storage->disk()->delete($isp->logo_path);
            $isp->logo_path = null;
            $isp->addAuditChange('logo', 'déposé', $isp->bundledLogoPath() !== null ? 'logo fourni' : 'aucun');
        }

        if ($this->storeLogo($request, $isp)) {
            $isp->addAuditChange('logo', null, 'remplacé');
        }

        $isp->save();
        $isp->flushPendingAudit();

        return back()->with('success', 'Opérateur mis à jour.');
    }

    public function destroy(Isp $isp): RedirectResponse
    {
        Gate::authorize('manage-settings');

        $count = $isp->internetLinks()->count();

        if ($count > 0) {
            return back()->with('error', "Cet opérateur est utilisé par {$count} lien(s) Internet : masquez-le plutôt que de le supprimer.");
        }

        if ($isp->logo_path !== null) {
            $this->storage->disk()->delete($isp->logo_path);
        }

        $isp->delete();

        return back()->with('success', 'Opérateur supprimé du catalogue.');
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, ?Isp $isp = null): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120', Rule::unique('isps', 'name')->ignore($isp?->id)],
            'country' => ['nullable', 'string', Rule::in(array_column(self::countries(), 'value'))],
            'website' => ['nullable', 'string', 'max:255', 'url:http,https'],
            'customer_area_url' => ['nullable', 'string', 'max:500', 'url:http,https'],
            'status_url' => ['nullable', 'string', 'max:500', 'url:http,https'],
            'support_phone' => ['nullable', 'string', 'max:50'],
            'business_support_phone' => ['nullable', 'string', 'max:50'],
            'support_email' => ['nullable', 'email', 'max:255'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'is_active' => ['boolean'],
            'logo' => ['nullable', 'image', 'mimes:png,jpg,jpeg,webp', 'max:'.config('techindex.logos.max_size_kb'), 'dimensions:max_width=4000,max_height=4000'],
            'remove_logo' => ['nullable', 'boolean'],
        ], [
            'name.unique' => 'Un opérateur porte déjà ce nom dans le catalogue.',
        ]);

        unset($data['logo'], $data['remove_logo']);
        $data['is_active'] = (bool) ($data['is_active'] ?? true);

        return $data;
    }

    private function storeLogo(Request $request, Isp $isp): bool
    {
        if (! $request->hasFile('logo')) {
            return false;
        }

        if ($isp->logo_path !== null) {
            $this->storage->disk()->delete($isp->logo_path);
        }

        // Même emplacement que les logos des clients : inclus dans les sauvegardes.
        $isp->logo_path = $request->file('logo')->storeAs(
            'logos',
            Str::uuid().'.'.$request->file('logo')->extension(),
            config('techindex.attachments.disk'),
        );

        return true;
    }

    private function uniqueSlug(string $name): string
    {
        $base = Str::slug($name) ?: 'operateur';
        $slug = $base;
        $i = 2;

        while (Isp::query()->where('slug', $slug)->exists()) {
            $slug = $base.'-'.$i++;
        }

        return $slug;
    }

    /**
     * Pays proposés pour classer les opérateurs.
     *
     * @return list<array{value: string, label: string}>
     */
    public static function countries(): array
    {
        $countries = [
            'FR' => 'France', 'BE' => 'Belgique', 'CH' => 'Suisse', 'LU' => 'Luxembourg', 'MC' => 'Monaco',
            'DE' => 'Allemagne', 'ES' => 'Espagne', 'IT' => 'Italie', 'PT' => 'Portugal', 'GB' => 'Royaume-Uni',
            'IE' => 'Irlande', 'NL' => 'Pays-Bas', 'AT' => 'Autriche', 'SE' => 'Suède', 'NO' => 'Norvège',
            'DK' => 'Danemark', 'FI' => 'Finlande', 'PL' => 'Pologne', 'CA' => 'Canada', 'US' => 'États-Unis',
            'MA' => 'Maroc', 'DZ' => 'Algérie', 'TN' => 'Tunisie', 'SN' => 'Sénégal', 'CI' => 'Côte d\'Ivoire',
            'AE' => 'Émirats arabes unis', 'QA' => 'Qatar', 'IN' => 'Inde', 'JP' => 'Japon', 'AU' => 'Australie',
            'ZA' => 'Afrique du Sud', 'NG' => 'Nigeria', 'RE' => 'La Réunion', 'GP' => 'Guadeloupe', 'MQ' => 'Martinique',
            'GF' => 'Guyane', 'YT' => 'Mayotte', 'NC' => 'Nouvelle-Calédonie', 'PF' => 'Polynésie française',
        ];

        asort($countries);

        return array_values(array_map(
            static fn (string $code, string $label): array => ['value' => $code, 'label' => $label],
            array_keys($countries),
            $countries,
        ));
    }
}
