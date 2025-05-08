<?php

namespace App\Http\Controllers;

use App\Clients\BolApiClient;
use App\Jobs\BulkSyncProductsWithBolComJob;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class CustomBolComController extends Controller
{
    public function index()
    {
        $credentials = DB::table('bol_com_credentials')->get();

        return view('admin::custom.bolCom.index', compact('credentials'));
    }

    public function create()
    {
        return view('admin::custom.bolCom.create');
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name'          => 'required|string|max:255',
            'client_id'     => 'required|string|max:255|unique:bol_com_credentials',
            'client_secret' => 'required|string|max:255',
            'is_active'     => 'boolean',
        ]);

        DB::table('bol_com_credentials')->insert([
            'name'          => $validated['name'],
            'client_id'     => $validated['client_id'],
            'client_secret' => $validated['client_secret'],
            'is_active'     => $validated['is_active'] ?? true,
            'created_at'    => now(),
            'updated_at'    => now(),
        ]);

        return redirect()
            ->route('admin.custom.bolCom.index')
            ->with('success', 'Credentials added successfully');
    }

    public function edit($id)
    {
        $credential = DB::table('bol_com_credentials')->where('id', $id)->first();

        if (! $credential) {
            return redirect()
                ->route('admin.custom.bolCom.index')
                ->with('error', 'Credentials not found');
        }

        return view('admin::custom.bolCom.edit', compact('credential'));
    }

    public function update(Request $request, $id)
    {
        $credential = DB::table('bol_com_credentials')->where('id', $id)->first();

        if (! $credential) {
            return redirect()
                ->route('admin.custom.bolCom.index')
                ->with('error', 'Credentials not found');
        }

        $rules = [
            'name'      => 'required|string|max:255',
            'client_id' => [
                'required',
                'string',
                'max:255',
                Rule::unique('bol_com_credentials')->ignore($id),
            ],
            'is_active' => 'boolean',
        ];

        // Only validate client_secret if it's provided
        if ($request->filled('client_secret')) {
            $rules['client_secret'] = 'string|max:255';
        }

        $validated = $request->validate($rules);

        $updateData = [
            'name'       => $validated['name'],
            'client_id'  => $validated['client_id'],
            'is_active'  => $validated['is_active'] ?? true,
            'updated_at' => now(),
        ];

        // Only update the client secret if it's provided
        if ($request->filled('client_secret')) {
            $updateData['client_secret'] = $validated['client_secret'];
        }

        DB::table('bol_com_credentials')
            ->where('id', $id)
            ->update($updateData);

        return redirect()
            ->route('admin.custom.bolCom.index')
            ->with('success', 'Credentials updated successfully');
    }

    public function destroy($id)
    {
        $deleted = DB::table('bol_com_credentials')->where('id', $id)->delete();

        if (! $deleted) {
            return redirect()
                ->route('admin.custom.bolCom.index')
                ->with('error', 'Credentials not found');
        }

        return redirect()
            ->route('admin.custom.bolCom.index')
            ->with('success', 'Credentials deleted successfully');
    }

    public function test($id)
    {
        try {
            $credential = DB::table('bol_com_credentials')->where('id', $id)->first();

            if (! $credential) {
                return redirect()
                    ->route('admin.custom.bolCom.index')
                    ->with('error', 'Credentials not found');
            }

            $apiClient = new BolApiClient($id, true);
            $response = $apiClient->get('/retailer-demo/offers/13722de8-8182-d161-5422-4a0a1caab5c8');

            return redirect()
                ->route('admin.custom.bolCom.index')
                ->with('success', 'Connection test successful');
        } catch (\Exception $e) {
            return redirect()
                ->route('admin.custom.bolCom.index')
                ->with('error', 'Connection test failed: '.$e->getMessage());
        }
    }

    /**
     * Bulk sync products with Bol.com.
     */
    public function bulkSync(Request $request)
    {
        $request->validate([
            'credential_id' => 'required|exists:bol_com_credentials,id',
        ]);

        try {
            BulkSyncProductsWithBolComJob::dispatch(50, null, $request->credential_id)
                ->onQueue('bol-com-sync');

            return redirect()
                ->route('admin.custom.bolCom.index')
                ->with('success', 'Bulk sync has been initiated. Products with an EAN code will be synced with Bol.com.');
        } catch (\Exception $e) {
            return redirect()
                ->route('admin.custom.bolCom.index')
                ->with('error', 'Failed to start bulk sync: '.$e->getMessage());
        }
    }
}
