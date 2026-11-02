<?php

declare(strict_types=1);

namespace Golpilolz\CsvOm;

use Golpilolz\CsvOm\DependencyInjection\Compiler\RepositoryPass;
use Golpilolz\CsvOm\DependencyInjection\GolpilolzCsvOmExtension;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\ExtensionInterface;
use Symfony\Component\HttpKernel\Bundle\Bundle;

final class GolpilolzCsvOmBundle extends Bundle
{
    public function build(ContainerBuilder $container): void
    {
        parent::build($container);

        $container->addCompilerPass(new RepositoryPass());
    }

    public function getContainerExtension(): ?ExtensionInterface
    {
        return $this->extension ??= new GolpilolzCsvOmExtension();
    }
}
