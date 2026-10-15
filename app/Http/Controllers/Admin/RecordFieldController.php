<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\RecordFieldRequest;
use App\Models\RecordField;
use App\Models\RecordType;
use App\Services\Records\RecordTypeManager;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Champs d'une fiche : chaque ajout crée une colonne dans la table de la
 * fiche ; chaque suppression la retire avec ses valeurs.
 */
class RecordFieldController extends Controller
{
    public function __construct(private readonly RecordTypeManager $manager) {}

    public function store(RecordFieldRequest $request, RecordType $recordType): RedirectResponse
    {
        Gate::authorize('manage-settings');

        $field = $this->manager->addField($recordType, $request->fieldData());

        return back()->with('success', 'Champ « '.$field->label.' » ajouté : colonne '.$field->column_name.' créée dans la table '.$recordType->table_name.'.');
    }

    public function update(RecordFieldRequest $request, RecordType $recordType, RecordField $field): RedirectResponse
    {
        Gate::authorize('manage-settings');

        $this->manager->updateField($field, $request->fieldData());

        return back()->with('success', 'Champ « '.$field->label.' » mis à jour.');
    }

    public function destroy(Request $request, RecordType $recordType, RecordField $field): RedirectResponse
    {
        Gate::authorize('manage-settings');

        $request->validate([
            'confirmation' => ['required', 'string', function (string $attribute, mixed $value, \Closure $fail) use ($field): void {
                if (trim((string) $value) !== $field->label) {
                    $fail('Le texte de confirmation ne correspond pas.');
                }
            }],
        ]);

        $this->manager->deleteField($field);

        return back()->with('success', 'Champ « '.$field->label.' » supprimé, ainsi que ses valeurs.');
    }
}
