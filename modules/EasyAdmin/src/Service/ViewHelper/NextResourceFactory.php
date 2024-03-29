<?php declare(strict_types=1);

namespace EasyAdmin\Service\ViewHelper;

use EasyAdmin\View\Helper\NextResource;
use Interop\Container\ContainerInterface;
use Laminas\ServiceManager\Factory\FactoryInterface;

class NextResourceFactory implements FactoryInterface
{
    public function __invoke(ContainerInterface $services, $requestedName, array $options = null)
    {
        $plugins = $services->get('ControllerPluginManager');
        $currentSite = $services->get('ViewHelperManager')->get('currentSite');
        return new NextResource(
            $services->get('Omeka\ApiAdapterManager'),
            $services->get('Omeka\Connection'),
            $services->get('Omeka\EntityManager'),
            $plugins->has('searchResources') ? $plugins->get('searchResources') : null,
            $currentSite()
        );
    }
}
