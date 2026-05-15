<?php

namespace App\Services\Bol;

use App\Clients\BolApiClient;
use App\Enums\BolSyncEventStatus;
use App\Enums\BolSyncState;
use App\Enums\BolSyncStep;
use App\Mail\BolComSyncSuccess;
use App\Models\BolComCredential;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Mail;
use Webkul\Product\Models\Product;

class BolSyncStateMachine
{
    public const POLL_DELAY_SECONDS = 30;

    public function __construct(
        private readonly BolApiClient $apiClient,
        private readonly BolPayloadBuilder $builder,
        private readonly BolOfferUpdater $offerUpdater,
        private readonly BolProductValidator $validator,
        private readonly BolSyncEventRecorder $recorder,
        private readonly BolViolationTranslator $translator,
    ) {}

    /**
     * Result describing what should happen next: either terminal (no follow-up)
     * or a delayed re-dispatch with an optional Bol process id to poll.
     */
    public function start(Product $product, BolComCredential $credential, bool $previouslyLinked = false): BolSyncAdvance
    {
        $this->apiClient->setCredential($credential);

        if (! $product->bol_com_sync) {
            return $this->retireIfLinked($product, $credential, $previouslyLinked);
        }

        $validation = $this->validator->validate($product);

        if ($validation->failed()) {
            $this->recorder->record(
                product: $product,
                credential: $credential,
                step: BolSyncStep::Validation,
                status: BolSyncEventStatus::Failed,
                message: 'Validation blocked sync',
                customerMessage: $validation->customerSummary(),
                payload: ['failures' => $validation->toArray()],
                advanceTo: BolSyncState::Failed,
            );

            return BolSyncAdvance::terminal();
        }

        $this->recorder->record(
            product: $product,
            credential: $credential,
            step: BolSyncStep::Validation,
            status: BolSyncEventStatus::Success,
            customerMessage: 'Productgegevens gecontroleerd, klaar voor verzending naar Bol.com.',
            advanceTo: BolSyncState::Validating,
        );

        if ($validation->normalizedEan !== null && $validation->normalizedEan !== ($product->values['common']['ean'] ?? null)) {
            $values = $product->values;
            $values['common']['ean'] = $validation->normalizedEan;
            $product->values = $values;
            $product->saveQuietly();
        }

        $reference = $this->currentReference($product, $credential);

        if ($reference !== null) {
            return $this->updateExisting($product, $credential, $reference);
        }

        return $this->submitContent($product, $credential);
    }

    public function advance(Product $product, BolComCredential $credential, string $processId): BolSyncAdvance
    {
        $this->apiClient->setCredential($credential);

        $latest = $product->bolSyncEvents()
            ->where('bol_process_id', $processId)
            ->orderByDesc('id')
            ->first();

        $step = $latest?->step ?? BolSyncStep::PollContent;
        $pollStep = match ($step) {
            BolSyncStep::SubmitContent, BolSyncStep::PollContent => BolSyncStep::PollContent,
            BolSyncStep::SubmitOffer, BolSyncStep::PollOffer     => BolSyncStep::PollOffer,
            default                                              => BolSyncStep::PollContent,
        };

        try {
            $response = $this->apiClient->get('/shared/process-status/'.$processId);
        } catch (\Throwable $e) {
            // Transient errors (5xx, 429, network hiccup) shouldn't terminally
            // fail an offer that may well succeed on a retry. Mark pending and
            // re-poll with a longer back-off; non-transient errors (4xx) really
            // are terminal.
            if ($this->isTransient($e)) {
                $this->recorder->record(
                    product: $product,
                    credential: $credential,
                    step: $pollStep,
                    status: BolSyncEventStatus::Pending,
                    message: $e->getMessage(),
                    customerMessage: $this->translator->translate($e),
                    bolProcessId: $processId,
                    payload: ['transient' => true, 'exception' => $e->getMessage()],
                );

                return BolSyncAdvance::poll($processId, 120);
            }

            return $this->recordApiFailure($product, $credential, $pollStep, $e);
        }

        $status = $response['status'] ?? null;

        if ($status === 'PENDING') {
            $this->recorder->record(
                product: $product,
                credential: $credential,
                step: $pollStep,
                status: BolSyncEventStatus::Pending,
                customerMessage: 'Bol.com verwerkt de aanvraag nog. We controleren over 30 seconden opnieuw.',
                bolProcessId: $processId,
                payload: $response,
            );

            return BolSyncAdvance::poll($processId);
        }

        if ($status === 'FAILURE' || $status === 'TIMEOUT') {
            $errorMessage = $response['errorMessage'] ?? ($status === 'TIMEOUT'
                ? 'Bol.com verwerking is verlopen (timeout)'
                : 'Onbekende fout bij Bol.com');

            $this->recorder->record(
                product: $product,
                credential: $credential,
                step: $pollStep,
                status: BolSyncEventStatus::Failed,
                message: $errorMessage,
                customerMessage: sprintf('Bol.com heeft de aanvraag afgewezen: %s', $errorMessage),
                bolProcessId: $processId,
                payload: $response,
                advanceTo: BolSyncState::Failed,
            );

            return BolSyncAdvance::terminal();
        }

        if ($status === 'SUCCESS') {
            return $this->onProcessSuccess($product, $credential, $pollStep, $response);
        }

        $this->recorder->record(
            product: $product,
            credential: $credential,
            step: $pollStep,
            status: BolSyncEventStatus::Pending,
            message: 'Unknown status: '.($status ?? 'null'),
            customerMessage: 'Bol.com gaf een onbekende status terug. We proberen het opnieuw.',
            bolProcessId: $processId,
            payload: $response,
        );

        return BolSyncAdvance::poll($processId);
    }

    private function onProcessSuccess(Product $product, BolComCredential $credential, BolSyncStep $pollStep, array $response): BolSyncAdvance
    {
        $entityId = $response['entityId'] ?? null;

        if ($pollStep === BolSyncStep::PollContent) {
            $this->recorder->record(
                product: $product,
                credential: $credential,
                step: BolSyncStep::PollContent,
                status: BolSyncEventStatus::Success,
                customerMessage: 'Productinformatie is geaccepteerd door Bol.com.',
                bolProcessId: $response['processStatusId'] ?? null,
                payload: $response,
                advanceTo: BolSyncState::SubmittingOffer,
            );

            return $this->submitOffer($product, $credential);
        }

        if ($pollStep === BolSyncStep::PollOffer) {
            if ($entityId) {
                $product->bolComCredentials()->syncWithoutDetaching([
                    $credential->id => ['reference' => $entityId],
                ]);
            }

            $this->recorder->record(
                product: $product,
                credential: $credential,
                step: BolSyncStep::PollOffer,
                status: BolSyncEventStatus::Success,
                customerMessage: 'Het aanbod staat live op Bol.com.',
                bolProcessId: $response['processStatusId'] ?? null,
                payload: $response,
                advanceTo: BolSyncState::Live,
            );

            $this->dispatchSuccessMail($product, $credential, $entityId);

            return BolSyncAdvance::terminal();
        }

        return BolSyncAdvance::terminal();
    }

    private function submitContent(Product $product, BolComCredential $credential): BolSyncAdvance
    {
        $this->recorder->record(
            product: $product,
            credential: $credential,
            step: BolSyncStep::SubmitContent,
            status: BolSyncEventStatus::Started,
            customerMessage: 'Productinformatie wordt naar Bol.com verstuurd.',
            advanceTo: BolSyncState::SubmittingContent,
        );

        try {
            $payload = $this->builder->content($product);
            $response = $this->apiClient->post('/retailer/content/products', $payload);
        } catch (\Throwable $e) {
            return $this->recordApiFailure($product, $credential, BolSyncStep::SubmitContent, $e);
        }

        $processId = $response['processStatusId'] ?? $response['id'] ?? null;

        $this->recorder->record(
            product: $product,
            credential: $credential,
            step: BolSyncStep::SubmitContent,
            status: BolSyncEventStatus::Success,
            customerMessage: 'Productinformatie verstuurd. We wachten op bevestiging van Bol.com.',
            bolProcessId: $processId,
            payload: $response,
            advanceTo: BolSyncState::AwaitingContentMatch,
        );

        if ($processId === null) {
            return $this->submitOffer($product, $credential);
        }

        return BolSyncAdvance::poll($processId);
    }

    private function submitOffer(Product $product, BolComCredential $credential): BolSyncAdvance
    {
        $deliveryCode = $this->currentDeliveryCode($product, $credential);

        $this->recorder->record(
            product: $product,
            credential: $credential,
            step: BolSyncStep::SubmitOffer,
            status: BolSyncEventStatus::Started,
            customerMessage: 'Aanbod wordt aangemeld bij Bol.com.',
            advanceTo: BolSyncState::SubmittingOffer,
        );

        try {
            $payload = $this->builder->offer($product, $deliveryCode);
            $response = $this->apiClient->post('/retailer/offers', $payload);
        } catch (\Throwable $e) {
            return $this->recordApiFailure($product, $credential, BolSyncStep::SubmitOffer, $e);
        }

        $processId = $response['processStatusId'] ?? $response['id'] ?? null;
        $entityId = $response['entityId'] ?? null;

        if ($entityId) {
            $product->bolComCredentials()->syncWithoutDetaching([
                $credential->id => ['reference' => $entityId],
            ]);
        }

        $this->recorder->record(
            product: $product,
            credential: $credential,
            step: BolSyncStep::SubmitOffer,
            status: BolSyncEventStatus::Success,
            customerMessage: 'Aanbod aangemeld. We wachten op publicatie door Bol.com.',
            bolProcessId: $processId,
            payload: $response,
            advanceTo: BolSyncState::AwaitingOfferPublish,
        );

        if ($processId === null) {
            $this->recorder->record(
                product: $product,
                credential: $credential,
                step: BolSyncStep::PollOffer,
                status: BolSyncEventStatus::Success,
                customerMessage: 'Het aanbod staat live op Bol.com.',
                payload: $response,
                advanceTo: BolSyncState::Live,
            );
            $this->dispatchSuccessMail($product, $credential, $entityId);

            return BolSyncAdvance::terminal();
        }

        return BolSyncAdvance::poll($processId);
    }

    private function updateExisting(Product $product, BolComCredential $credential, string $reference): BolSyncAdvance
    {
        $this->recorder->record(
            product: $product,
            credential: $credential,
            step: BolSyncStep::SubmitContent,
            status: BolSyncEventStatus::Started,
            customerMessage: 'Bestaand aanbod wordt bijgewerkt op Bol.com.',
            advanceTo: BolSyncState::SubmittingContent,
        );

        try {
            $deliveryCode = $this->currentDeliveryCode($product, $credential);
            $this->offerUpdater->update($this->apiClient, $product, $reference, $deliveryCode);
        } catch (\Throwable $e) {
            return $this->recordApiFailure($product, $credential, BolSyncStep::SubmitContent, $e);
        }

        $this->recorder->record(
            product: $product,
            credential: $credential,
            step: BolSyncStep::SubmitContent,
            status: BolSyncEventStatus::Success,
            customerMessage: 'Het aanbod is bijgewerkt op Bol.com.',
            advanceTo: BolSyncState::Live,
        );

        return BolSyncAdvance::terminal();
    }

    private function retireIfLinked(Product $product, BolComCredential $credential, bool $previouslyLinked): BolSyncAdvance
    {
        $reference = $this->currentReference($product, $credential);

        if (! $previouslyLinked && ! $reference) {
            return BolSyncAdvance::terminal();
        }

        $this->recorder->record(
            product: $product,
            credential: $credential,
            step: BolSyncStep::Retire,
            status: BolSyncEventStatus::Started,
            customerMessage: 'Aanbod wordt verwijderd van Bol.com.',
        );

        try {
            if ($reference) {
                $this->apiClient->delete('/retailer/offers/'.$reference);
            }
        } catch (\Throwable $e) {
            return $this->recordApiFailure($product, $credential, BolSyncStep::Retire, $e);
        }

        $product->bolComCredentials()->detach($credential->id);

        $this->recorder->record(
            product: $product,
            credential: $credential,
            step: BolSyncStep::Retire,
            status: BolSyncEventStatus::Success,
            customerMessage: 'Het aanbod is verwijderd van Bol.com.',
            advanceTo: BolSyncState::Retired,
        );

        return BolSyncAdvance::terminal();
    }

    private function isTransient(\Throwable $e): bool
    {
        $current = $e;
        while ($current) {
            if ($current instanceof RequestException) {
                $status = $current->response->status();

                return $status >= 500 || $status === 429;
            }
            $current = $current->getPrevious();
        }

        // No HTTP response at all — probably a network/SSL hiccup. Transient.
        return true;
    }

    private function recordApiFailure(Product $product, BolComCredential $credential, BolSyncStep $step, \Throwable $e): BolSyncAdvance
    {
        $customerMessage = $this->translator->translate($e);
        $payload = ['exception' => $e->getMessage()];

        $request = $e instanceof RequestException ? $e : ($e->getPrevious() instanceof RequestException ? $e->getPrevious() : null);
        if ($request instanceof RequestException) {
            $payload['response_status'] = $request->response->status();
            $payload['response_body'] = $request->response->json() ?? $request->response->body();
        }

        $this->recorder->record(
            product: $product,
            credential: $credential,
            step: $step,
            status: BolSyncEventStatus::Failed,
            message: $e->getMessage(),
            customerMessage: $customerMessage,
            payload: $payload,
            advanceTo: BolSyncState::Failed,
        );

        return BolSyncAdvance::terminal();
    }

    private function currentReference(Product $product, BolComCredential $credential): ?string
    {
        $pivot = $product->bolComCredentials()
            ->where('bol_com_credentials.id', $credential->id)
            ->first();

        return $pivot?->pivot?->reference;
    }

    private function currentDeliveryCode(Product $product, BolComCredential $credential): string
    {
        $pivot = $product->bolComCredentials()
            ->where('bol_com_credentials.id', $credential->id)
            ->first();

        return $pivot?->pivot?->delivery_code ?? '1-8d';
    }

    private function dispatchSuccessMail(Product $product, BolComCredential $credential, ?string $entityId): void
    {
        if (! $entityId) {
            return;
        }

        try {
            $offer = $this->apiClient->get('/retailer/offers/'.$entityId);
        } catch (\Throwable $e) {
            $offer = ['offerId' => $entityId];
        }

        $offer ??= ['offerId' => $entityId];

        $this->recordPublishability($product, $credential, $offer);

        $recipients = array_filter(is_array(config('bolcom.email_recipients', [])) ? config('bolcom.email_recipients', []) : []);
        if ($recipients === []) {
            return;
        }

        Mail::to($recipients)->send(new BolComSyncSuccess($product, $offer, $credential));
    }

    /**
     * The /retailer/offers/{id} response may include notPublishableReasons.
     * Surface these into the timeline so the customer sees that the offer
     * exists on Bol but isn't yet for sale (e.g. missing assets, category
     * mismatch).
     */
    private function recordPublishability(Product $product, BolComCredential $credential, array $offer): void
    {
        $reasons = $offer['notPublishableReasons'] ?? [];

        if (! is_array($reasons) || $reasons === []) {
            return;
        }

        $messages = collect($reasons)
            ->map(fn ($r) => $r['description'] ?? $r['code'] ?? null)
            ->filter()
            ->implode(' / ');

        $this->recorder->record(
            product: $product,
            credential: $credential,
            step: BolSyncStep::PollOffer,
            status: BolSyncEventStatus::Pending,
            message: 'Offer accepted but not yet publishable',
            customerMessage: 'Het aanbod staat bij Bol.com, maar is nog niet verkoopbaar: '.$messages,
            payload: ['notPublishableReasons' => $reasons],
        );
    }
}
