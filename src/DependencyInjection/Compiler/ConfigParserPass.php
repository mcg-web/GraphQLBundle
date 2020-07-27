<?php

declare(strict_types=1);

namespace Overblog\GraphQLBundle\DependencyInjection\Compiler;

use InvalidArgumentException;
use Overblog\GraphQLBundle\Config\Parser\AnnotationParser;
use Overblog\GraphQLBundle\Config\Parser\GraphQLParser;
use Overblog\GraphQLBundle\Config\Parser\ParserInterface;
use Overblog\GraphQLBundle\Config\Parser\XmlParser;
use Overblog\GraphQLBundle\Config\Parser\YamlParser;
use Overblog\GraphQLBundle\DependencyInjection\TypesConfiguration;
use Overblog\GraphQLBundle\OverblogGraphQLBundle;
use ReflectionClass;
use ReflectionException;
use Symfony\Component\Config\Definition\Exception\ForbiddenOverwriteException;
use Symfony\Component\Config\Definition\Processor;
use Symfony\Component\Config\Resource\FileResource;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\Finder\Finder;
use function array_count_values;
use function array_filter;
use function array_keys;
use function array_map;
use function array_merge;
use function array_replace_recursive;
use function dirname;
use function implode;
use function is_dir;
use function sprintf;

class ConfigParserPass implements CompilerPassInterface
{
    /**
     * @var ParserInterface[]
     */
    private array $parsers = [];

    private static array $defaultDefaultConfig = [
        'definitions' => [
            'mappings' => [
                'auto_discover' => [
                    'root_dir' => true,
                    'bundles' => true,
                ],
                'types' => [],
            ],
        ],
    ];

    public const DEFAULT_TYPES_SUFFIX = '.types';

    public function __construct()
    {
        foreach ($this->getDefaultParsers() as $parser) {
            $this->parsers[$parser->getName()] = $parser;
        }
    }

    public function process(ContainerBuilder $container): void
    {
        $config = $this->processConfiguration([$this->getConfigs($container)]);
        $container->setParameter('overblog_graphql_types.config', $config);
    }

    public function processConfiguration(array $configs): array
    {
        return (new Processor())->processConfiguration(new TypesConfiguration(), $configs);
    }

    /**
     * @return ParserInterface[]
     */
    private function getDefaultParsers(): iterable
    {
        yield new YamlParser();
        yield new XmlParser();
        yield new GraphQLParser();
        yield new AnnotationParser();
    }

    private function getConfigs(ContainerBuilder $container): array
    {
        $config = $container->getParameterBag()->resolveValue($container->getParameter('overblog_graphql.config'));
        $container->getParameterBag()->remove('overblog_graphql.config');
        $container->setParameter('overblog_graphql_types.classes_map', []);
        $typesMappings = $this->mappingConfig($config, $container);
        $typesMappings = array_merge(...$typesMappings);
        $typeConfigs = [];

        $typesMappingsGroupByParser = [];
        $treatedFiles = [];

        foreach ($typesMappings as $params) {
            foreach ($params['files'] as  $file) {
                $fileRealPath = $file->getRealPath();
                if (isset($treatedFiles[$fileRealPath])) {
                    continue;
                }
                $typesMappingsGroupByParser[$params['type']][] = $file;
                $treatedFiles[$file->getRealPath()] = true;
            }
        }

        foreach ($typesMappingsGroupByParser as $type => $files) {
            $typeConfigs = array_merge($typeConfigs, $this->parsers[$type]->parseFiles($files, $container, $config));
        }

        $this->checkTypesDuplication($typeConfigs);

        // flatten config is a requirement to support inheritance
        return array_merge(...$typeConfigs);
    }

    private function checkTypesDuplication(array $typeConfigs): void
    {
        $types = array_merge(...array_map('array_keys', $typeConfigs));
        $duplications = array_keys(array_filter(array_count_values($types), function ($count) {
            return $count > 1;
        }));
        if (!empty($duplications)) {
            throw new ForbiddenOverwriteException(sprintf(
                'Types (%s) cannot be overwritten. See inheritance doc section for more details.',
                implode(', ', array_map('json_encode', $duplications))
            ));
        }
    }

    private function mappingConfig(array $config, ContainerBuilder $container): array
    {
        // use default value if needed
        $config = array_replace_recursive(self::$defaultDefaultConfig, $config);

        $mappingConfig = $config['definitions']['mappings'];
        $typesMappings = $mappingConfig['types'];

        // app only config files (yml or xml or graphql)
        if ($mappingConfig['auto_discover']['root_dir'] && $container->hasParameter('kernel.root_dir')) {
            $typesMappings[] = ['dir' => $container->getParameter('kernel.root_dir').'/config/graphql', 'types' => null];
        }
        if ($mappingConfig['auto_discover']['bundles']) {
            $mappingFromBundles = $this->mappingFromBundles($container);
            $typesMappings = array_merge($typesMappings, $mappingFromBundles);
        } else {
            // enabled only for this bundle
            $typesMappings[] = [
                'dir' => $this->bundleDir(OverblogGraphQLBundle::class).'/Resources/config/graphql',
                'types' => ['yaml'],
            ];
        }

        // from config
        $typesMappings = $this->detectFilesFromTypesMappings($typesMappings, $container);

        return $typesMappings;
    }

    private function detectFilesFromTypesMappings(array $typesMappings, ContainerBuilder $container): array
    {
        return array_filter(array_map(
            function (array $typeMapping) use ($container) {
                $suffix = $typeMapping['suffix'] ?? '';
                $types = $typeMapping['types'] ?? null;

                return $this->detectFilesByTypes($container, $typeMapping['dir'], $suffix, $types);
            },
            $typesMappings
        ));
    }

    private function mappingFromBundles(ContainerBuilder $container): array
    {
        $typesMappings = [];
        $bundles = $container->getParameter('kernel.bundles');

        // auto detect from bundle
        foreach ($bundles as $name => $class) {
            $bundleDir = $this->bundleDir($class);

            // only config files (yml or xml)
            $typesMappings[] = ['dir' => $bundleDir.'/Resources/config/graphql', 'types' => null];
        }

        return $typesMappings;
    }

    private function detectFilesByTypes(ContainerBuilder $container, string $path, string $suffix, array $types = null): array
    {
        // add the closest existing directory as a resource
        $resource = $path;
        while (!is_dir($resource)) {
            $resource = dirname($resource);
        }
        $container->addResource(new FileResource($resource));

        $stopOnFirstTypeMatching = empty($types);

        $types = $stopOnFirstTypeMatching ? array_keys($this->parsers) : $types;
        $files = [];

        foreach ($types as $type) {
            $finder = Finder::create();
            try {
                $finder->files()->in($path)->name(sprintf('*%s.{%s}', $suffix, join(',', $this->parsers[$type]->supportedExtensions())));
            } catch (InvalidArgumentException $e) {
                continue;
            }
            if ($finder->count() > 0) {
                $files[] = [
                    'type' => $type,
                    'files' => $finder,
                ];
                if ($stopOnFirstTypeMatching) {
                    break;
                }
            }
        }

        return $files;
    }

    /**
     * @throws ReflectionException
     */
    private function bundleDir(string $bundleClass): string
    {
        $bundle = new ReflectionClass($bundleClass); // @phpstan-ignore-line

        return dirname($bundle->getFileName());
    }
}
