<?php

namespace App\Http\Controllers\Admin;

use App\Exceptions\AdministrativeActionException;
use App\Http\Controllers\Controller;
use App\Services\Content\QuestionCsvImporter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class QuestionImportController extends Controller
{
    public function __construct(protected QuestionCsvImporter $importer) {}

    public function create(): View
    {
        return view('admin.content.import', [
            'required' => QuestionCsvImporter::REQUIRED,
            'optional' => QuestionCsvImporter::OPTIONAL,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $request->validate([
            // Content is parsed as CSV text only; the extension and MIME checks just reject obvious mistakes.
            'file' => ['required', 'file', 'mimes:csv,txt', 'max:'.config('competition.questions.import_max_kb')],
        ]);

        $preview = $this->importer->preview($request->file('file'), $request->user());

        return redirect()->route('admin.questions.import.show', $preview['token']);
    }

    public function show(Request $request, string $token): View|RedirectResponse
    {
        $preview = $this->importer->find($token, $request->user());

        if ($preview === null) {
            return redirect()->route('admin.questions.import')->with('error', 'This import preview has expired. Upload the file again.');
        }

        return view('admin.content.import-preview', ['preview' => $preview]);
    }

    public function commit(Request $request, string $token): RedirectResponse
    {
        try {
            $result = $this->importer->commit($token, $request->user());
        } catch (AdministrativeActionException $e) {
            return redirect()->route('admin.questions.import')->with('error', $e->getMessage());
        }

        return redirect()->route('admin.questions.index')
            ->with('success', "Imported {$result['created']} question(s); {$result['skipped']} row(s) were skipped.");
    }

    public function sample(): StreamedResponse
    {
        $rows = [
            [...QuestionCsvImporter::REQUIRED, ...QuestionCsvImporter::OPTIONAL],
            ['General Knowledge', 'What is the capital of Pakistan?', 'Karachi', 'Islamabad', 'B', 'Lahore', 'Peshawar', '', '', 'easy', 'Islamabad has been the capital since 1967.', 'geography; capitals', '20', 'draft'],
            ['Science', 'What is the chemical symbol for gold?', 'Ag', 'Au', 'B', 'Gd', 'Go', '', '', 'medium', '', 'chemistry', '', 'active'],
        ];

        return response()->streamDownload(function () use ($rows) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF"); // UTF-8 BOM so spreadsheet software reads Urdu/accents correctly
            foreach ($rows as $row) {
                fputcsv($out, $row, ',', '"', '');
            }
            fclose($out);
        }, 'question-import-sample.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}
