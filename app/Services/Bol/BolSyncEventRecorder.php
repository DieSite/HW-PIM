<?php

namespace App\Services\Bol;

use App\Enums\BolSyncEventStatus;
use App\Enums\BolSyncState;
use App\Enums\BolSyncStep;
use App\Mail\BolComSyncFailed;
use App\Models\BolComCredential;
use App\Models\BolSyncEvent;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Webkul\Product\Models\Product;

class BolSyncEventRecorder
{
    public function record(
        Product $product,
        ?BolComCredential $credential,
        BolSyncStep $step,
        BolSyncEventStatus $status,
        ?string $message = null,
        ?string $customerMessage = null,
        ?string $bolProcessId = null,
        ?array $payload = null,
        ?BolSyncState $advanceTo = null,
    ): BolSyncEvent {
        $event = BolSyncEvent::create([
            'product_id'            => $product->id,
            'bol_com_credential_id' => $credential?->id,
            'step'                  => $step,
            'status'                => $status,
            'message'               => $message,
            'customer_message'      => $customerMessage,
            'bol_process_id'        => $bolProcessId,
            'payload'               => $payload,
        ]);

        $product->bol_last_event_id = $event->id;

        if ($advanceTo !== null) {
            $product->bol_sync_state = $advanceTo->value;
            $product->bol_sync_state_at = now();
        }

        $additional = $product->additional ?? [];

        if ($status === BolSyncEventStatus::Failed && $customerMessage) {
            $additional['product_sync_error'] = $customerMessage;
        } elseif ($status === BolSyncEventStatus::Success && $advanceTo === BolSyncState::Live) {
            unset($additional['product_sync_error']);
        }

        $product->additional = $additional;
        $product->saveQuietly();

        Log::channel(config('logging.channels.bolcom') ? 'bolcom' : 'stack')->info(
            'Bol.com sync event',
            [
                'product_id'     => $product->id,
                'sku'            => $product->sku,
                'credential_id'  => $credential?->id,
                'step'           => $step->value,
                'status'         => $status->value,
                'state'          => $advanceTo?->value,
                'bol_process_id' => $bolProcessId,
                'message'        => $message,
            ],
        );

        if ($advanceTo === BolSyncState::Failed && $credential !== null) {
            $this->dispatchFailureMail($product, $credential, $event);
        }

        if ($status === BolSyncEventStatus::Failed) {
            $this->captureToSentry($product, $credential, $event);
        }

        return $event;
    }

    /**
     * The state machine catches Bol.com API failures and records them as
     * failed events rather than throwing — that's the right product behavior
     * (no auto-retry storms, customer sees the reason). But it means Sentry
     * never sees these failures unless we explicitly capture them here.
     */
    private function captureToSentry(Product $product, ?BolComCredential $credential, BolSyncEvent $event): void
    {
        if (! function_exists('\Sentry\captureMessage')) {
            return;
        }

        \Sentry\withScope(function (\Sentry\State\Scope $scope) use ($product, $credential, $event) {
            $scope->setTag('bolcom.step', $event->step?->value ?? 'unknown');
            $scope->setTag('bolcom.product_id', (string) $product->id);
            $scope->setTag('bolcom.product_sku', (string) $product->sku);
            if ($credential) {
                $scope->setTag('bolcom.credential_id', (string) $credential->id);
            }
            if ($event->bol_process_id) {
                $scope->setTag('bolcom.process_id', $event->bol_process_id);
            }
            $scope->setContext('bolcom_event', [
                'id'               => $event->id,
                'step'             => $event->step?->value,
                'message'          => $event->message,
                'customer_message' => $event->customer_message,
            ]);
            if (is_array($event->payload)) {
                $scope->setContext('bolcom_event_payload', $event->payload);
            }

            \Sentry\captureMessage(
                sprintf('Bol.com sync failed: %s (%s)', $event->customer_message ?? $event->message ?? 'unknown', $event->step?->value),
                \Sentry\Severity::warning(),
            );
        });
    }

    private function dispatchFailureMail(Product $product, BolComCredential $credential, BolSyncEvent $event): void
    {
        $recipients = config('bolcom.email_recipients', []);
        $recipients = array_filter(is_array($recipients) ? $recipients : []);

        if ($recipients === []) {
            return;
        }

        Mail::to($recipients)->send(new BolComSyncFailed($product, $event, $credential));
    }
}
