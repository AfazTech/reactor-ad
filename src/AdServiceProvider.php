<?php

declare(strict_types=1);

namespace AfazTech\ReactorAd;

use AfazTech\ReactorAd\Contracts\AdManagerInterface;
use AfazTech\ReactorAd\Support\AdConfig;
use Reactor\Contracts\DatabaseManagerInterface;
use Reactor\Contracts\LoggerInterface;
use Reactor\Contracts\ServiceProviderInterface;
use Reactor\Core\Container;

/**
 * Registers the reactor-ad package services in the container.
 *
 * AdConfig is bound as a singleton with default values. The host
 * application may override this binding in its own service provider
 * (which runs after package providers) to customise the storage table.
 *
 * The package does not register any middleware, handler or event
 * listener. Wiring the delivery hooks (HANDLER_EXECUTED for random
 * ads, and the /start flow for start ads) is entirely up to the host
 * application.
 */
class AdServiceProvider implements ServiceProviderInterface
{
    public function register(Container $container): void
    {
        if (!$container->has(AdConfig::class)) {
            $container->singleton(AdConfig::class, fn () => new AdConfig());
        }

        if (!$container->has(AdManagerInterface::class)) {
            $container->singleton(AdManagerInterface::class, function (Container $c) {
                return new AdManager(
                    $c->get(DatabaseManagerInterface::class),
                    $c->get(\Neili\Client::class),
                    $c->get(LoggerInterface::class),
                    $c->get(AdConfig::class),
                );
            });
        }
    }

    public function boot(Container $container): void
    {
    }
}
