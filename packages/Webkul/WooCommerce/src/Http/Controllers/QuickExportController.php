<?php

namespace Webkul\WooCommerce\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Log;
use Webkul\DataTransfer\Helpers\Export;
use Webkul\DataTransfer\Jobs\Export\ExportTrackBatch;
use Webkul\DataTransfer\Repositories\JobInstancesRepository;
use Webkul\DataTransfer\Repositories\JobTrackRepository;
use Webkul\Product\Repositories\ProductRepository;
use Webkul\WooCommerce\Services\WooCommerceService;

class QuickExportController
{
    public function __construct(protected JobInstancesRepository $jobInstancesRepository, protected WooCommerceService $connectorService, protected ProductRepository $productRepository, protected JobTrackRepository $jobTrackRepository) {}

    /**
     * Handle WooCommerce Webhook
     */
    public function handleQuickExport(Request $request)
    {
        $data = $request->input('params', []);

        $productIds = $data['productIds'] ?? [];
        $jobCode = $data['format'] ?? null;
        $withMedia = $data['with_media'] ?? 0;

        // Get credentials for quick export
        $credential = $this->connectorService->getCredentialForQuickExport();

        if (! $credential) {
            return response()->json(['error' => 'None of the credentials are set as default for quick export.'], 400);
        }

        // Fetch SKUs of selected products
        $skus = $this->productRepository->whereIn('id', $productIds)->pluck('sku')->toArray();

        // Get quick settings
        $quickSettings = $credential['extras']['quicksettings'] ?? [];

        // Ensure required settings exist
        if (! isset($quickSettings['quick_channel'], $quickSettings['quick_locale'], $quickSettings['quick_currency'])) {
            return response()->json(['error' => 'Quick export settings are not configured in the default credential set for quick export.'], 400);
        }

        // Format data for Job Instance creation
        $formattedDataForJobInstance = [
            'code'        => $jobCode,
            'type'        => 'system',
            'entity_type' => $jobCode,
            'filters'     => [
                'channel'    => $quickSettings['quick_channel'],
                'locale'     => $quickSettings['quick_locale'],
                'currency'   => $quickSettings['quick_currency'],
                'credential' => $credential['id'],
                'productSKU' => implode(',', $skus),
                'with_media' => (int) $withMedia,
            ],
        ];

        // Create or update job instance
        $jobInstance = $this->jobInstancesRepository->updateOrCreate(['code' => $jobCode], $formattedDataForJobInstance);

        if (! $jobInstance) {
            return response()->json(['error' => 'Failed to create or update job instance'], 500);
        }

        // Execute export job
        $this->exportNow($jobInstance->id);

        return response()->json([
            'message' => 'The quick export job has been launched successfully. You can view it in the job tracker.',
        ], 200);
    }

    /**
     * exportNow function dispatch the job asynchronously
     */
    public function exportNow(int $id)
    {
        try {
            // Retrieve the export instance or fail with a 404
            $jobInstance = $this->jobInstancesRepository->findOrFail($id);

            // Get the authenticated user's ID
            $userId = auth()->guard('admin')->user()->id;

            // Dispatch an event before the export process starts
            Event::dispatch('data_transfer.exports.export.now.before', $jobInstance);

            $jobTrackInstance = $this->jobTrackRepository->create([
                'state'            => Export::STATE_PENDING,
                'allowed_errors'   => $jobInstance->allowed_errors,
                'field_separator'  => $jobInstance->field_separator,
                'file_path'        => $jobInstance->file_path,
                'meta'             => $jobInstance->toJson(),
                'job_instances_id' => $jobInstance->id,
                'user_id'          => $userId,
                'created_at'       => now(),
                'updated_at'       => now(),
            ]);

            // Dispatch the Export job
            ExportTrackBatch::dispatch($jobTrackInstance);

            // Redirect to the tracker view
            return redirect()->route('admin.settings.data_transfer.tracker.view', $jobTrackInstance->id);
        } catch (\Exception $e) {
            // Log the error and redirect with an error message
            \Log::error('Export failed for job instance '.$id.': '.$e->getMessage());

            return redirect()->route('admin.settings.data_transfer.tracker.view', $id)
                ->with('error', 'Failed to start the expor process. Please try again.');
        }
    }
}
