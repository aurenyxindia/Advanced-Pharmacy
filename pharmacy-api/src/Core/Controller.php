<?php

declare(strict_types=1);

namespace App\Core;

use App\Support\Validator;

/**
 * Base controller with shared response/validation helpers.
 */
abstract class Controller
{
    protected function validate(Request $request, array $rules): array
    {
        $validator = new Validator($request->all(), $rules);
        if ($validator->fails()) {
            throw HttpException::validation($validator->errors());
        }
        return $validator->validated();
    }

    protected function success(mixed $data = null, string $message = 'OK', int $status = 200): Response
    {
        return Response::success($data, $message, $status);
    }

    protected function created(mixed $data = null, string $message = 'Created'): Response
    {
        return Response::success($data, $message, 201);
    }

    protected function error(string $message, int $status = 400, array $errors = []): Response
    {
        return Response::error($message, $status, $errors);
    }
}
