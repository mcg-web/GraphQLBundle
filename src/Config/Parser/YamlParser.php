<?php

declare(strict_types=1);

namespace Overblog\GraphQLBundle\Config\Parser;

use SplFileInfo;
use Symfony\Component\Config\Resource\FileResource;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Exception\InvalidArgumentException;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Parser;
use Symfony\Component\Yaml\Yaml;
use function file_get_contents;
use function is_array;
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

        return is_array($typesConfig) ? $typesConfig : [];
    }
}
