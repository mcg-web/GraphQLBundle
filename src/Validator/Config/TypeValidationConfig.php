<?php

declare(strict_types=1);

namespace Overblog\GraphQLBundle\Validator\Config;

final class TypeValidationConfig
{
    private array $config;

    public function __construct(array $config)
    {
        $this->config = $config;
    }

    public function getValidation(): ?array
    {
        return $this->config['validation'] ?? null;
    }

    public function getField(string $name): ?FieldValidationConfig
    {
        if (!isset($this->config['fields'][$name])) {
            return null;
        }

        return new FieldValidationConfig($this->config['fields'][$name]);
    }

    /**
     * @return FieldValidationConfig[]&iterable
     */
    public function getFields(): iterable
    {
        foreach ($this->config['fields'] ?? [] as $fieldName => $fieldConfig) {
            yield $fieldName => new FieldValidationConfig($fieldConfig);
        }
    }
}
