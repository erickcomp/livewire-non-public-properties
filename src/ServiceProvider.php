<?php

namespace ErickComp\LivewireNonPublicProperties;

use ErickComp\LivewireNonPublicProperties\Features\SupportsNonPublicProperties\NonPublicPropertyManager;
use Illuminate\Support\ServiceProvider as LaravelServiceProvider;

class ServiceProvider extends LaravelServiceProvider
{
    public function register() {}

    public function boot()
    {
        $this->addComponentMountHook();
    }

    private function addComponentMountHook()
    {
        $this->app['livewire']->componentHook(NonPublicPropertyManager::getComponentHook());
    }
}
