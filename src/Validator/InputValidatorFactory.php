<?php

declare(strict_types=1);

namespace Overblog\GraphQLBundle\Validator;

use Overblog\GraphQLBundle\Resolver\Resolver;
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

    public function create(ResolverArgs $resolverArgs): ?InputValidator
    {
        if (!$this->hasTypeValidationConfig($resolverArgs->getInfo()->parentType->name)) {
            return null;
        }

        return new InputValidator(
            $resolverArgs,
            $this->validator,
            $this->validatorFactory,
            $this->typeResolver,
            [$this, 'getTypeValidationConfig'],
        );
    }

    public function createArgs(ResolverArgs $resolverArgs, Resolver $resolver): array
    {
        $handlerArgs = $resolver->getHandlerArgs();
        $requiredInputValidator = in_array('$validator', $handlerArgs);
        $requiredInputValidatorErrors = in_array('$errors', $handlerArgs);
        $errors = null;
        $validator = $this->create($resolverArgs);

        if ($validator) {
            $fieldValidationConfig = $this->getTypeValidationConfig($resolverArgs->getInfo()->parentType->name)
                ->getField($resolverArgs->getInfo()->fieldDefinition->name);

            $validationGroups = null === $fieldValidationConfig ? null : $fieldValidationConfig->getValidationGroups();
            if ($requiredInputValidatorErrors) {
                $errors = $validator->createResolveErrors($validationGroups);
            } elseif (!$requiredInputValidator) {
                $validator->validate($validationGroups);
            }
        }

        return ['validator' => $validator, 'errors' => $errors];
    }
}
