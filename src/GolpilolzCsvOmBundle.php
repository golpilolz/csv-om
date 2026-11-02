<?php

declare(strict_types=1);

namespace Golpilolz\CsvOm;

use Golpilolz\CsvOm\DependencyInjection\GolpilolzCsvOmExtension;
use Symfony\Component\DependencyInjection\Extension\ExtensionInterface;
use Symfony\Component\HttpKernel\Bundle\Bundle;

final class GolpilolzCsvOmBundle extends Bundle
{
    /**
     * {@inheritdoc}
     */
    public function boot(): void
    {
    }

    public function getContainerExtension(): ?ExtensionInterface
    {
        if (null === $this->extension) {
            $this->extension = new GolpilolzCsvOmExtension();
        }

        return $this->extension;
    }
}