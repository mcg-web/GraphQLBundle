<?php

declare(strict_types=1);

namespace Overblog\GraphQLBundle\Config\Parser;

use SplFileInfo;
use Symfony\Component\DependencyInjection\ContainerBuilder;

interface ParserInterface
{
    /**
     * @param SplFileInfo[] $files
     */
    public function parseFiles(array $files, ContainerBuilder $container, array $config = []): array;

    public function supportedExtensions(): array;

    public function getName(): string;
}
