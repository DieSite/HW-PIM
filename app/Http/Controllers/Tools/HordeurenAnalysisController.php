<?php

namespace App\Http\Controllers\Tools;

use App\Http\Controllers\Controller;
use App\Jobs\RunHordeurenAnalysisJob;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\View\View;

class HordeurenAnalysisController extends Controller
{
    public function index(): View
    {
        $reportPath = (string) config('competitor_pricing.hordeuren.output');

        return view('admin::tools.hordeuren-analyse', [
            'defaultEmail' => auth()->guard('admin')->user()?->email,
            'lastReportAt' => is_file($reportPath)
                ? Carbon::createFromTimestamp(filemtime($reportPath))->timezone('Europe/Amsterdam')
                : null,
        ]);
    }

    public function run(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'email'],
        ]);

        RunHordeurenAnalysisJob::dispatch($validated['email']);

        session()->flash(
            'success',
            "De concurrentie-analyse voor hordeuren is gestart. Het rapport wordt gemaild naar {$validated['email']} (dit duurt ± 10–20 minuten)."
        );

        return redirect()->route('admin.tools.hordeuren-analyse.index');
    }
}
