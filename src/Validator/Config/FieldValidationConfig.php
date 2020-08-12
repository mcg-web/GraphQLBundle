<?php

declare(strict_types=1);

namespace Overblog\GraphQLBundle\Validator\Config;

final class FieldValidationConfig
{
    private array $config;

    public function __construct(array $config)
    {
        $this->config = $config;
    }

    public function getValidationRules(): array
    {
        return $this->config['validationRules'] ?? [];
    }

    public function getProperties(): array
    {
        return $this->config['properties'] ?? [];
    }

    public function getClass(): array
    {
        return $this->config['class'] ?? [];
    }

    public function getValidationGroups(): ?array
    {
        return $this->config['validationGroups'] ?? null;
    }
}
