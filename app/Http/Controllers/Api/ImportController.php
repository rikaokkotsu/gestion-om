<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Imports\ImportType;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use OpenSpout\Common\Exception\OpenSpoutException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class ImportController extends Controller
{
    public function template(ImportType $type): BinaryFileResponse
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'modele');
        $type->writeTemplate($path);
        $name = $type === ImportType::Places ? 'modele-lieux.xlsx' : 'modele-employes.xlsx';

        return response()->download($path, $name)->deleteFileAfterSend();
    }

    public function store(Request $request, ImportType $type): JsonResponse
    {
        $request->validate([
            'file' => ['required', 'file', 'max:5120', 'extensions:xlsx,csv'],
            'dry_run' => ['sometimes', 'boolean'],
        ]);

        $file = $request->file('file');
        $user = $request->user();
        $dryRun = $request->boolean('dry_run');

        try {
            $report = $type->import(
                $file->getRealPath(),
                $file->getClientOriginalExtension(),
                $dryRun,
                $user instanceof User ? $user : null,
            );
        } catch (OpenSpoutException) {
            throw ValidationException::withMessages(['file' => 'Le fichier est illisible ou corrompu.']);
        }

        if (! $dryRun && $report->missingColumns === []) {
            Audit::record("import.{$type->value}", null, [], [
                'file' => $file->getClientOriginalName(),
                'created' => $report->created,
                'updated' => $report->updated,
                'unchanged' => $report->unchanged,
                'rejected' => count($report->rejected),
            ]);
        }

        return response()->json(['data' => $report->toArray()]);
    }
}
