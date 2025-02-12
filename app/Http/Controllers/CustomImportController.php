<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Jobs\ImportProductsJob;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CustomImportController extends Controller
{
    public function index()
    {
        $queue = [
            'pending' => DB::table('jobs')->count()
        ];

        return view('admin::custom.imports.index', compact('queue'));
    }

    public function upload(Request $request)
    {
        $request->validate([
            'file' => 'required|mimes:xlsx,xls,csv'
        ]);

        try {
            $path = $request->file('file')->store('imports');
            
            ImportProductsJob::dispatch($path);

            session()->flash('success', trans('admin::app.custom.imports.queued-success'));

            return redirect()->back();
        } catch (\Exception $e) {
            session()->flash('error', $e->getMessage());

            return redirect()->back();
        }
    }
}
