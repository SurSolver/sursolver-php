<?php

declare(strict_types=1);

namespace SurSolver\Transport;

/**
 * Sends one request. Return a Response for any HTTP status; throw TransportFailure only when no
 * response arrived. It must not follow redirects.
 */
interface Transport
{
    /** @throws TransportFailure */
    public function send(Request $request): Response;
}
