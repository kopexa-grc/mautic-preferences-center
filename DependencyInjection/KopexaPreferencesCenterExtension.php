<?php

declare(strict_types=1);

namespace MauticPlugin\KopexaPreferencesCenterBundle\DependencyInjection;

use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\DependencyInjection\Loader\PhpFileLoader;

/**
 * Loads Config/services.php; without this extension Mautic registers the
 * bundle but none of its services.
 */
final class KopexaPreferencesCenterExtension extends Extension
{
    /**
     * @param mixed[] $configs
     */
    public function load(array $configs, ContainerBuilder $container): void
    {
        (new PhpFileLoader($container, new FileLocator(__DIR__.'/../Config')))->load('services.php');
    }
}
