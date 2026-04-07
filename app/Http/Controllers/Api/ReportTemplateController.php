<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ReportTemplate;
use App\Services\ReportTemplatePdfService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;

class ReportTemplateController extends Controller
{
    private ReportTemplatePdfService $pdfService;

    public function __construct(ReportTemplatePdfService $pdfService)
    {
        $this->pdfService = $pdfService;
    }

    public function index(): JsonResponse
    {
        return response()->json(ReportTemplate::orderBy('id', 'desc')->get());
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'content' => 'required|string',
            'footer_text' => 'nullable|string',
        ]);

        $template = ReportTemplate::create($validated);

        return response()->json($template, 201);
    }

    public function show(ReportTemplate $template): JsonResponse
    {
        return response()->json($template);
    }

    public function update(Request $request, ReportTemplate $template): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'content' => 'required|string',
            'footer_text' => 'nullable|string',
        ]);

        $template->update($validated);

        return response()->json($template);
    }

    public function destroy(ReportTemplate $template): JsonResponse
    {
        $template->delete();

        return response()->json(['message' => 'Template deleted']);
    }

    public function pdf(Request $request, ReportTemplate $template): Response
    {
        $date = $request->query('date', now()->format('Y/m/d'));
        $pdf = $this->pdfService->createPdf($template, $date);
        $filename = sprintf('%s-%s.pdf', str_replace(' ', '_', mb_substr($template->name, 0, 40)), now()->format('YmdHis'));
        $output = $pdf->Output('', 'S');

        return response($output, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => "inline; filename=\"{$filename}\"",
        ]);
    }
}
