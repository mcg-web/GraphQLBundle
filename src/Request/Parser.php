<?php

declare(strict_types=1);

namespace Overblog\GraphQLBundle\Request;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use function array_filter;
use function explode;
use function is_string;
use function json_decode;
use function json_last_error;
use const JSON_ERROR_NONE;

final class Parser implements ParserInterface
{
    use UploadParserTrait;

    public function parse(Request $request): array
    {
        // Extracts the GraphQL request parameters
        $parsedBody = $this->getParsedBody($request);

        return $this->getParams($request, $parsedBody);
    }

    /**
     * Gets the body from the request based on Content-Type header.
     */
    private function getParsedBody(Request $request): array
    {
        $body = $request->getContent();
        $method = $request->getMethod();
        $contentType = explode(';', (string) $request->headers->get('content-type'), 2)[0];

        switch ($contentType) {
            // Plain string
            case self::CONTENT_TYPE_GRAPHQL:
                $parsedBody = [self::PARAM_QUERY => $body];
                break;

            // JSON object
            case self::CONTENT_TYPE_JSON:
                if (empty($body)) {
                    if (Request::METHOD_GET === $method) {
                        $parsedBody = [];
                        break;
                    }
                    throw new BadRequestHttpException('The request content body must not be empty when using json content type request.');
                }

                $parsedBody = json_decode($body, true);

                if (JSON_ERROR_NONE !== json_last_error()) {
                    throw new BadRequestHttpException('POST body sent invalid JSON');
                }
                break;

            // URL-encoded query-string
            case self::CONTENT_TYPE_FORM:
                $parsedBody = $request->request->all();
                break;

            case self::CONTENT_TYPE_FORM_DATA:
                $parsedBody = $this->handleUploadedFiles($request->request->all(), $request->files->all());
                break;

            default:
                $parsedBody = [];
                break;
        }

        return $parsedBody;
    }

    /**
     * Gets the GraphQL parameters from the request.
     */
    private function getParams(Request $request, array $data = []): array
    {
        // Add default request parameters
        $data = array_filter($data) + [
                self::PARAM_QUERY => null,
                self::PARAM_VARIABLES => null,
                self::PARAM_OPERATION_NAME => null,
            ];

        // Use all query parameters, since starting from Symfony 6 there will be an exception accessing array parameters
        // via request->query->get(key), and another exception accessing non-array parameter via request->query->all(key)
        $queryParameters = $request->query->all();

        // Override request using query-string parameters
        $query = $queryParameters[self::PARAM_QUERY] ?? $data[self::PARAM_QUERY];
        $variables = $queryParameters[self::PARAM_VARIABLES] ?? $data[self::PARAM_VARIABLES];
        $operationName = $queryParameters[self::PARAM_OPERATION_NAME] ?? $data[self::PARAM_OPERATION_NAME];

        // `query` parameter is mandatory.
        if (empty($query)) {
            throw new BadRequestHttpException('Must provide query parameter');
        }

        // Variables can be defined using a JSON-encoded object.
        // If the parsing fails, an exception will be thrown.
        if (is_string($variables)) {
            $variables = json_decode($variables, true);

            if (JSON_ERROR_NONE !== json_last_error()) {
                throw new BadRequestHttpException('Variables are invalid JSON');
            }
        }

        return [
            self::PARAM_QUERY => $query,
            self::PARAM_VARIABLES => $variables,
            self::PARAM_OPERATION_NAME => $operationName,
        ];
    }
}
