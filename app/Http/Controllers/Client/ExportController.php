<?php

namespace App\Http\Controllers\Client;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Services\Audit\AuditLogger;
use App\Services\Export\ExportService;
use App\Services\Spreadsheets\SpreadsheetWriter;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Export d'un jeu de données d'un client (droit "exportation" requis).
 */
class ExportController extends Controller
{
    public function __invoke(Client $client, string $dataset, string $format, ExportService $exports, SpreadsheetWriter $writer, AuditLogger $audit): StreamedResponse
    {
        abort_unless(array_key_exists($dataset, ExportService::CLIENT_DATASETS), 404);
        Gate::authorize('export', $client);

        $audit->log('export.generated', $client, metadata: [
            'données' => ExportService::CLIENT_DATASETS[$dataset],
            'format' => $format,
            'lignes' => $exports->count($dataset, null, $client),
        ], clientId: $client->id);

        return $writer->download(
            Str::slug($client->reference.'-'.$dataset).'-'.now()->format('Ymd-His'),
            $format,
            $exports->headings($dataset),
            $exports->rows($dataset, null, $client),
        );
    }
}
