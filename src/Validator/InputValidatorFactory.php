<?php

declare(strict_types=1);

namespace Overblog\GraphQLBundle\Validator;

use InvalidArgumentException;
use Overblog\GraphQLBundle\Resolver\ResolverArgs;
use Overblog\GraphQLBundle\Resolver\TypeResolver;
use Overblog\GraphQLBundle\Validator\Config\TypeValidationConfig;
use Symfony\Component\Validator\Validator\ValidatorInterface;

final class InputValidatorFactory
{
    private array $typeValidationConfigs;

    private ValidatorInterface $validator;

    private ValidatorFactory $validatorFactory;

    private TypeResolver $typeResolver;

    public function __construct(
        ValidatorInterface $validator,
        ValidatorFactory $validatorFactory,
        TypeResolver $typeResolver,
        array $typeValidationConfigs = []
    ) {
        $this->typeResolver = $typeResolver;
        $this->validator = $validator;
        $this->validatorFactory = $validatorFactory;
        foreach ($typeValidationConfigs as $typeName => $typeValidationConfig) {
            $this->addTypeValidationConfig(
                $typeName,
                $typeValidationConfig
            );
        }
    }

    public function addTypeValidationConfig(
        string $typeName,
        array $typeValidationConfigs
    ): self {
        $this->typeValidationConfigs[$typeName] = $typeValidationConfigs;

        return $this;
    }

    public function getTypeValidationConfig(string $typeName): ?TypeValidationConfig
    {
        if (!$this->hasTypeValidationConfig($typeName)) {
            return null;
        }

        return new TypeValidationConfig($this->typeValidationConfigs[$typeName]);
    }

    public function hasTypeValidationConfig(string $typeName): bool
    {
        return isset($this->typeValidationConfigs[$typeName]);
    }

    public function create(ResolverArgs $resolverArgs): InputValidator
    {
        $type = $resolverArgs->getInfo()->parentType;

        if (!$this->hasTypeValidationConfig($type->name)) {
            throw new InvalidArgumentException(sprintf(
                'TypeValidationConfig not found for type "%s".',
                $type->name
            ));
        }

        return new InputValidator(
            $resolverArgs,
            $this->validator,
            $this->validatorFactory,
            $this->typeResolver,
            [$this, 'getTypeValidationConfig'],
        );
    }
}
