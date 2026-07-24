<?php

namespace App\Http\Controllers\Tools;

use App\Http\Controllers\Controller;
use App\Jobs\RunHordeurenAnalysisJob;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\View\View;

class HordeurenAnalysisController extends Controller
{
    public function index(): View
    {
        $reportPath = (string) config('competitor_pricing.hordeuren.output');

        /**
         * Een analyse die in de wachtrij staat of draait, is anders volledig
         * onzichtbaar voor de admin: het rapport komt pas na 30–60 minuten
         * per mail. De cache-vlag wordt gezet bij het starten en gewist zodra
         * de rapport- of foutmail is verstuurd.
         */
        $runningSince = Cache::get(RunHordeurenAnalysisJob::RUNNING_CACHE_KEY);

        return view('admin::tools.hordeuren-analyse', [
            'defaultEmail' => auth()->guard('admin')->user()?->email,
            'lastReportAt' => is_file($reportPath)
                ? Carbon::createFromTimestamp(filemtime($reportPath))->timezone('Europe/Amsterdam')
                : null,
            'runningSince' => $runningSince
                ? Carbon::parse($runningSince)->timezone('Europe/Amsterdam')
                : null,
        ]);
    }

    public function run(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'email'],
        ]);

        RunHordeurenAnalysisJob::dispatch($validated['email']);

        Cache::put(
            RunHordeurenAnalysisJob::RUNNING_CACHE_KEY,
            now()->toIso8601String(),
            now()->addHours(6),
        );

        session()->flash(
            'success',
            "De concurrentie-analyse voor hordeuren is gestart. Het rapport wordt gemaild naar {$validated['email']} (dit duurt ± 30–60 minuten)."
        );

        return redirect()->route('admin.tools.hordeuren-analyse.index');
    }
}
