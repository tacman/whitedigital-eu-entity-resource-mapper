<?php

namespace WhiteDigital\EntityDtoMapper\DependencyInjection;

use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\YamlFileLoader;
use Symfony\Component\HttpKernel\DependencyInjection\Extension;

class EntityDtoMapperExtension extends Extension
{
    public function load(array $configs, ContainerBuilder $containerBuilder)
    {
      $loader = new YamlFileLoader(
          $containerBuilder,
          new FileLocator(__DIR__.'/../Resources/config')
      );
      $loader->load('services.yaml');
    }
}
