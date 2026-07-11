<?php

declare(strict_types=1);

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;

abstract class ApiController extends AbstractController
{
    /**
     * AbstractController::json() always forces 'json_encode_options' to
     * JsonResponse::DEFAULT_ENCODING_OPTIONS in the serializer context, which
     * overrides the serializer's built-in JSON_PRESERVE_ZERO_FRACTION default.
     * Without this, a whole-number float like 1500.0 serializes as `1500`
     * instead of `1500.0`. Re-add the flag explicitly so numeric fields keep
     * their float shape in every API response.
     */
    protected function apiJson(mixed $data, int $status = 200): JsonResponse
    {
        return $this->json($data, $status, [], [
            'json_encode_options' => JsonResponse::DEFAULT_ENCODING_OPTIONS | \JSON_PRESERVE_ZERO_FRACTION,
        ]);
    }
}
