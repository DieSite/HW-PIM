<?php

namespace App\Services\AI;

interface AiTextClient
{
    /**
     * Send one completion request and return the provider's answer.
     *
     * Implementations throw RuntimeException on a failed or empty response;
     * retries and back-off are the caller's concern.
     */
    public function complete(AiRequest $request): AiResponse;

    /**
     * The model identifier this client will use, for logging and drafts.
     */
    public function model(): string;
}
