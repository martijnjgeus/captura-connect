<?php

namespace App\Http\Controllers\Api;

use App\Actions\Orders\CreateAfasOrderFromIncomingOrder;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\IncomingOrderRequest;
use App\Services\Logging\IntegrationLogger;
use Illuminate\Http\JsonResponse;
use Throwable;

class IncomingOrderController extends Controller
{
    public function __invoke(
        IncomingOrderRequest             $request,
        CreateAfasOrderFromIncomingOrder $createAfasOrder,
        IntegrationLogger                $logger,
    ): JsonResponse
    {
        $validated = $request->validated();

        $log = $logger->startWebhook(
            source: 'incoming_order',
            receivedBody: $request->all(),
        );

        $logger->webhookValidationSucceeded(
            log: $log,
            validatedData: $validated,
        );

        try {
            $result = $createAfasOrder->handle($validated, $log);

            $responseBody = [
                'message'           => 'OK',
                'incoming_order_id' => $result['incoming_order_id'],
                'order_number'      => $result['order_number'],
            ];

            $logger->webhookFinished(
                log: $log,
                resultBody: [
                    'action_result' => $result,
                    'response_body' => $responseBody,
                ],
                httpStatus: 200,
            );

            return response()->json($responseBody);
        } catch (Throwable $exception) {
            $logger->webhookFailed(
                log: $log,
                exception: $exception,
            );

            throw $exception;
        }
    }
}
