<?php

namespace App\Services\Logging;

use App\Models\IntegrationLog;
use Illuminate\Http\Client\Response;
use Throwable;

class IntegrationLogger
{
    public function startWebhook(string $source, array $receivedBody): IntegrationLog
    {
        return IntegrationLog::create([
            'type'          => 'webhook',
            'source'        => $source,
            'status'        => 'received',
            'received_body' => $receivedBody,
        ]);
    }

    public function webhookValidationSucceeded(IntegrationLog $log, array $validatedData): void
    {
        $log->update([
            'status'            => 'validated',
            'validation_result' => [
                'success' => true,
                'data'    => $validatedData,
            ],
        ]);
    }

    public function webhookValidationFailed(string $source, array $receivedBody, array $errors): IntegrationLog
    {
        return IntegrationLog::create([
            'type'              => 'webhook',
            'source'            => $source,
            'status'            => 'validation_failed',
            'received_body'     => $receivedBody,
            'validation_result' => [
                'success' => false,
                'errors'  => $errors,
            ],
            'error_message'     => 'Webhook validation failed.',
        ]);
    }

    public function webhookSentBody(IntegrationLog $log, array $sentBody): void
    {
        $log->update([
            'status'    => 'sent',
            'sent_body' => $sentBody,
        ]);
    }

    public function webhookResponse(IntegrationLog $log, Response $response): void
    {
        $log->update([
            'status'        => $response->successful() ? 'completed' : 'failed',
            'http_status'   => $response->status(),
            'result_body'   => [
                'successful' => $response->successful(),
                'status'     => $response->status(),
                'body'       => $this->responseBody($response),
            ],
            'error_message' => $response->successful()
                ? null
                : $response->body(),
        ]);
    }

    public function webhookFailed(IntegrationLog $log, Throwable $exception): void
    {
        $log->update([
            'status'        => 'failed',
            'error_message' => $exception->getMessage(),
        ]);
    }

    public function startFeed(string $source): IntegrationLog
    {
        return IntegrationLog::create([
            'type'   => 'feed',
            'source' => $source,
            'status' => 'started',
        ]);
    }

    public function feedProductsRead(IntegrationLog $log, int $productsRead): void
    {
        $log->update([
            'status'        => 'products_read',
            'products_read' => $productsRead,
        ]);
    }

    public function feedFinished(
        IntegrationLog $log,
        int            $productsUpdated,
        int            $updatesFailed,
        array          $resultBody = [],
    ): void
    {
        $log->update([
            'status'           => $updatesFailed > 0 ? 'completed_with_errors' : 'completed',
            'products_updated' => $productsUpdated,
            'updates_failed'   => $updatesFailed,
            'result_body'      => $resultBody,
        ]);
    }

    public function feedFailed(IntegrationLog $log, Throwable $exception): void
    {
        $log->update([
            'status'        => 'failed',
            'error_message' => $exception->getMessage(),
            'result_body'   => [
                'exception' => [
                    'class'   => $exception::class,
                    'message' => $exception->getMessage(),
                    'file'    => $exception->getFile(),
                    'line'    => $exception->getLine(),
                ],
            ],
        ]);
    }

    private function responseBody(Response $response): mixed
    {
        $json = $response->json();

        if ($json !== null) {
            return $json;
        }

        return $response->body();
    }

    public function webhookFinished(
        IntegrationLog $log,
        array          $resultBody,
        ?int           $httpStatus = null,
    ): void
    {
        $log->update([
            'status'      => 'completed',
            'http_status' => $httpStatus,
            'result_body' => $resultBody,
        ]);
    }

    public function webhookAfasResponse(IntegrationLog $log, Response $response): void
    {
        $successful = $response->successful();

        $log->update([
            'status'        => $successful ? 'afas_response_received' : 'failed',
            'http_status'   => $response->status(),
            'result_body'   => [
                'afas' => [
                    'successful' => $successful,
                    'status'     => $response->status(),
                    'body'       => $this->responseBody($response),
                ],
            ],
            'error_message' => $successful ? null : $response->body(),
        ]);
    }

    public function webhookCompletedWithWarning(
        IntegrationLog $log,
        string $message,
        array $extra = [],
    ): void {
        $currentResultBody = $log->result_body ?? [];

        $log->update([
            'status' => 'completed_with_errors',
            'error_message' => $message,
            'result_body' => array_merge($currentResultBody, [
                'warning' => [
                    'message' => $message,
                    ...$extra,
                ],
            ]),
        ]);
    }
}
