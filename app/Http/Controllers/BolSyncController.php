<?php

namespace App\Http\Controllers;

use App\Enums\BolSyncState;
use App\Jobs\SyncProductWithBolComJob;
use App\Models\BolComCredential;
use App\Models\Product;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class BolSyncController extends Controller
{
    public function retry(Request $request, int $productId): RedirectResponse
    {
        /** @var Product $product */
        $product = Product::with('bolComCredentials')->findOrFail($productId);

        $credentialIds = $product->bolComCredentials->pluck('id');

        if ($credentialIds->isEmpty()) {
            return back()->with('error', 'Geen Bol.com account gekoppeld aan dit product.');
        }

        $product->bol_sync_state = BolSyncState::Idle->value;
        $product->bol_sync_state_at = now();
        $product->saveQuietly();

        BolComCredential::whereIn('id', $credentialIds)
            ->get()
            ->each(fn (BolComCredential $cred) => SyncProductWithBolComJob::dispatch(
                $product, $cred, $product->bol_com_sync, null, false
            ));

        return back()->with('success', 'Synchronisatie opnieuw gestart. De voortgang verschijnt hieronder.');
    }

    public function timeline(int $productId)
    {
        $product = Product::with(['bolSyncEvents.credential', 'lastBolSyncEvent'])->findOrFail($productId);

        return view('admin::custom.bolCom.timeline', [
            'product' => $product,
            'events'  => $product->bolSyncEvents,
        ]);
    }
}
