<?php

namespace App\Services\Bol;

use RuntimeException;

/**
 * Lightweight OpenAPI 3.0 request-body shape checker, sufficient to detect
 * field-name / type / required-key regressions between our payloads and the
 * Bol.com retailer API.
 *
 * Not a full JSON Schema validator: it resolves $refs, walks the schema tree,
 * and asserts type + required-key conformance. Anything more nuanced
 * (enum values, formats, allOf intersections) is intentionally out of scope.
 */
class BolContractValidator
{
    /** @var array<string, mixed> The cached spec (merged across multiple files) */
    private array $spec;

    /**
     * @param  array<int, string>|string  $specPaths  One or more OpenAPI specs to load and merge.
     */
    public function __construct(array|string $specPaths)
    {
        $paths = is_array($specPaths) ? $specPaths : [$specPaths];
        $merged = ['paths' => [], 'components' => ['schemas' => []]];

        foreach ($paths as $path) {
            if (! is_file($path)) {
                throw new RuntimeException("OpenAPI spec not found: {$path}");
            }

            $contents = file_get_contents($path);
            $decoded = json_decode($contents, true);

            if (! is_array($decoded)) {
                throw new RuntimeException("Failed to parse OpenAPI spec at {$path}: ".json_last_error_msg());
            }

            $merged['paths'] = array_merge($merged['paths'], $decoded['paths'] ?? []);
            $merged['components']['schemas'] = array_merge(
                $merged['components']['schemas'],
                $decoded['components']['schemas'] ?? [],
            );
        }

        $this->spec = $merged;
    }

    /**
     * Validate a request body against the OpenAPI operation's request schema.
     *
     * @param  string  $method  HTTP method, lowercase
     * @param  string  $path  Path as it appears in spec, e.g. "/retailer/offers"
     * @param  array  $body  Decoded request body
     * @param  string  $mediaType  e.g. "application/vnd.retailer.v10+json"
     * @return string[] Empty array on success, list of violations otherwise
     */
    public function validateRequest(string $method, string $path, array $body, string $mediaType): array
    {
        $operation = $this->spec['paths'][$path][strtolower($method)] ?? null;
        if ($operation === null) {
            return ["Spec has no operation for {$method} {$path}"];
        }

        $schema = $operation['requestBody']['content'][$mediaType]['schema'] ?? null;
        if ($schema === null) {
            return ["Spec has no requestBody for {$method} {$path} ({$mediaType})"];
        }

        return $this->validateValue($body, $this->resolve($schema), 'body');
    }

    /**
     * Validate a parsed response body against the OpenAPI operation's response schema.
     */
    public function validateResponse(string $method, string $path, int $status, array $body, string $mediaType): array
    {
        $operation = $this->spec['paths'][$path][strtolower($method)] ?? null;
        if ($operation === null) {
            return ["Spec has no operation for {$method} {$path}"];
        }

        $schema = $operation['responses'][(string) $status]['content'][$mediaType]['schema'] ?? null;
        if ($schema === null) {
            return ["Spec has no response schema for {$method} {$path} status {$status}"];
        }

        return $this->validateValue($body, $this->resolve($schema), "response[{$status}]");
    }

    private function validateValue(mixed $value, array $schema, string $where): array
    {
        $errors = [];
        $schema = $this->resolve($schema);

        if (isset($schema['allOf'])) {
            foreach ($schema['allOf'] as $sub) {
                $errors = array_merge($errors, $this->validateValue($value, $this->resolve($sub), $where));
            }

            return $errors;
        }

        $type = $schema['type'] ?? null;
        $nullable = $schema['nullable'] ?? false;

        if ($value === null) {
            if ($nullable) {
                return [];
            }

            return ["{$where}: null not allowed"];
        }

        if ($type === 'object' || isset($schema['properties'])) {
            if (! is_array($value)) {
                return ["{$where}: expected object, got ".gettype($value)];
            }

            foreach (($schema['required'] ?? []) as $req) {
                if (! array_key_exists($req, $value)) {
                    $errors[] = "{$where}: missing required property '{$req}'";
                }
            }

            foreach ($value as $key => $sub) {
                $propSchema = $schema['properties'][$key] ?? null;
                if ($propSchema === null) {
                    continue;
                }
                $errors = array_merge($errors, $this->validateValue($sub, $this->resolve($propSchema), "{$where}.{$key}"));
            }

            return $errors;
        }

        if ($type === 'array') {
            if (! is_array($value)) {
                return ["{$where}: expected array, got ".gettype($value)];
            }

            $itemSchema = $this->resolve($schema['items'] ?? []);
            foreach ($value as $i => $item) {
                $errors = array_merge($errors, $this->validateValue($item, $itemSchema, "{$where}[{$i}]"));
            }

            return $errors;
        }

        return match ($type) {
            'string'  => is_string($value) ? [] : ["{$where}: expected string, got ".gettype($value)],
            'integer' => is_int($value) ? [] : ["{$where}: expected integer, got ".gettype($value)],
            'number'  => (is_int($value) || is_float($value)) ? [] : ["{$where}: expected number, got ".gettype($value)],
            'boolean' => is_bool($value) ? [] : ["{$where}: expected boolean, got ".gettype($value)],
            default   => [],
        };
    }

    private function resolve(array $schema): array
    {
        $seen = [];

        while (isset($schema['$ref'])) {
            $ref = $schema['$ref'];

            if (isset($seen[$ref])) {
                throw new RuntimeException("Circular \$ref detected: {$ref}");
            }

            $seen[$ref] = true;

            if (! str_starts_with($ref, '#/')) {
                throw new RuntimeException("Unsupported external \$ref: {$ref}");
            }

            $segments = explode('/', substr($ref, 2));
            $node = $this->spec;
            foreach ($segments as $segment) {
                $node = $node[$segment] ?? null;
                if ($node === null) {
                    throw new RuntimeException("Cannot resolve \$ref: {$ref}");
                }
            }

            $schema = $node;
        }

        return $schema;
    }
}
