<?php

declare(strict_types=1);

namespace Overblog\GraphQLBundle\Config\Parser;

use LogicException;
use SplFileInfo;
use Symfony\Component\Config\Resource\FileResource;
use Symfony\Component\DependencyInjection\Argument\AbstractArgument;
use Symfony\Component\DependencyInjection\Argument\ArgumentInterface;
use Symfony\Component\DependencyInjection\Argument\IteratorArgument;
use Symfony\Component\DependencyInjection\Argument\ServiceLocatorArgument;
use Symfony\Component\DependencyInjection\Argument\TaggedIteratorArgument;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\DependencyInjection\Exception\InvalidArgumentException;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\ExpressionLanguage\Expression;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Parser;
use Symfony\Component\Yaml\Tag\TaggedValue;
use Symfony\Component\Yaml\Yaml;
use function file_get_contents;
use function in_array;
use function is_array;
use function is_string;
use function sprintf;

class YamlParser implements ParserInterface
{
    private Parser $yamlParser;

    public function __construct()
    {
        $this->yamlParser = new Parser();
    }

    /**
     * {@inheritdoc}
     */
    public function parseFiles(array $files, ContainerBuilder $container, array $config = []): array
    {
        return array_map(function (SplFileInfo $file) use ($container) {
            return $this->parseFile($file, $container);
        }, $files);
    }

    /**
     * {@inheritdoc}
     */
    public function supportedExtensions(): array
    {
        return ['yaml', 'yml'];
    }

    /**
     * {@inheritdoc}
     */
    public function getName(): string
    {
        return 'yaml';
    }

    private function parseFile(SplFileInfo $file, ContainerBuilder $container): array
    {
        $container->addResource(new FileResource($file->getRealPath()));

        try {
            $typesConfig = $this->yamlParser->parse(file_get_contents($file->getPathname()), Yaml::PARSE_CONSTANT | Yaml::PARSE_CUSTOM_TAGS);
        } catch (ParseException $e) {
            throw new InvalidArgumentException(sprintf('The file "%s" does not contain valid YAML.', $file), 0, $e);
        }
        $typesConfig = $this->resolveServices($typesConfig, $file->getRealPath() ?: null);

        return is_array($typesConfig) ? $typesConfig : [];
    }

    /**
     * Resolves services.
     *
     * @see \Symfony\Component\DependencyInjection\Loader\YamlFileLoader::resolveServices
     *
     * @param array|string|TaggedValue|mixed $value
     *
     * @return array|string|IteratorArgument|Reference|AbstractArgument|ArgumentInterface
     */
    private function resolveServices($value, ?string $file)
    {
        if ($value instanceof TaggedValue) {
            $argument = $value->getValue();
            if ('iterator' === $value->getTag()) {
                if (!is_array($argument)) {
                    throw new InvalidArgumentException(sprintf('"!iterator" tag only accepts sequences in "%s".', $file));
                }
                $argument = $this->resolveServices($argument, $file);
                try {
                    return new IteratorArgument($argument); // @phpstan-ignore-line
                } catch (InvalidArgumentException $e) {
                    throw new InvalidArgumentException(sprintf('"!iterator" tag only accepts arrays of "@service" references in "%s".', $file));
                }
            }
            if ('service_locator' === $value->getTag()) {
                if (!is_array($argument)) {
                    throw new InvalidArgumentException(sprintf('"!service_locator" tag only accepts maps in "%s".', $file));
                }

                $argument = $this->resolveServices($argument, $file);

                try {
                    return new ServiceLocatorArgument($argument); // @phpstan-ignore-line
                } catch (InvalidArgumentException $e) {
                    throw new InvalidArgumentException(sprintf('"!service_locator" tag only accepts maps of "@service" references in "%s".', $file));
                }
            }
            if (in_array($value->getTag(), ['tagged', 'tagged_iterator', 'tagged_locator'], true)) {
                $forLocator = 'tagged_locator' === $value->getTag();

                if (is_array($argument) && isset($argument['tag']) && $argument['tag']) {
                    if ($diff = array_diff(array_keys($argument), ['tag', 'index_by', 'default_index_method', 'default_priority_method'])) {
                        throw new InvalidArgumentException(sprintf('"!%s" tag contains unsupported key "%s"; supported ones are "tag", "index_by", "default_index_method", and "default_priority_method".', $value->getTag(), implode('", "', $diff)));
                    }

                    $argument = new TaggedIteratorArgument($argument['tag'], $argument['index_by'] ?? null, $argument['default_index_method'] ?? null, $forLocator, $argument['default_priority_method'] ?? null);
                } elseif (is_string($argument) && $argument) {
                    $argument = new TaggedIteratorArgument($argument, null, null, $forLocator);
                } else {
                    throw new InvalidArgumentException(sprintf('"!%s" tags only accept a non empty string or an array with a key "tag" in "%s".', $value->getTag(), $file));
                }

                if ($forLocator) {
                    $argument = new ServiceLocatorArgument($argument);
                }

                return $argument;
            }
            if ('service' === $value->getTag()) {
                throw new InvalidArgumentException(sprintf('Creating an alias using the tag "!service" is not allowed in "%s".', $file));
            }
            if ('abstract' === $value->getTag()) {
                return new AbstractArgument($value->getValue());
            }

            throw new InvalidArgumentException(sprintf('Unsupported tag "!%s".', $value->getTag()));
        }

        if (is_array($value)) {
            foreach ($value as $k => $v) {
                $value[$k] = $this->resolveServices($v, $file);
            }
        } elseif (is_string($value) && 0 === strpos($value, '@=')) {
            if (!class_exists(Expression::class)) {
                throw new LogicException(sprintf('The "@=" expression syntax cannot be used without the ExpressionLanguage component. Try running "composer require symfony/expression-language".'));
            }

            // we don't want expression to be evaluate on container build so we return raw value
            return $value;
        } elseif (is_string($value) && 0 === strpos($value, '@')) {
            if (0 === strpos($value, '@@')) {
                $value = substr($value, 1);
                $invalidBehavior = null;
            } elseif (0 === strpos($value, '@!')) {
                $value = substr($value, 2);
                $invalidBehavior = ContainerInterface::IGNORE_ON_UNINITIALIZED_REFERENCE;
            } elseif (0 === strpos($value, '@?')) {
                $value = substr($value, 2);
                $invalidBehavior = ContainerInterface::IGNORE_ON_INVALID_REFERENCE;
            } else {
                $value = substr($value, 1);
                $invalidBehavior = ContainerInterface::EXCEPTION_ON_INVALID_REFERENCE;
            }

            if (null !== $invalidBehavior) {
                $value = new Reference($value, $invalidBehavior);
            }
        }

        return $value;
    }
}
