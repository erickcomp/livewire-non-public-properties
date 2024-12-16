<?php

namespace ErickComp\LivewireNonPublicProperties\Features\SupportsNonPublicProperties;

use ErickComp\LivewireNonPublicProperties\Attributes\HasNonPublicProperties as HasNonPublicPropertiesAttribute;
use ErickComp\LivewireNonPublicProperties\Contracts\HasNonPublicProperties as HasNonPublicPropertiesContract;
use Illuminate\Support\Collection as LaravelCollection;
use Illuminate\Support\Facades\Log;
use Livewire\Component as LivewireComponent;
use Livewire\ComponentHook as LivewireComponentHook;
use Livewire\Mechanisms\HandleComponents\ComponentContext as LivewireComponentContext;

use function Livewire\on;

class NonPublicPropertyManager
{
    public static function assignNonPublicAndNonStaticProps(LivewireComponent $component, array $params, $key, $parent)
    {
        if (!static::isUsingNonPublicPropsFeature($component)) {
            return;
        }

        $reflProperties = static::getNonStaticAndNonPublicReflectionProperties($component);

        // Assign all non-public component properties that have matching parameters.
        // This code was borrowed from Livewire core. =)
        collect(
            array_intersect_key(
                $params,
                static::getNonStaticAndNonPublicPropsExcludingLivewireComponentOnes($component),
            ),
        )->each(function ($value, $property) use ($component, $reflProperties) {
            $reflectionProperty = $reflProperties[$property];

            $reflectionProperty->setAccessible(true);
            $reflectionProperty->setValue($component, $value);
        });
    }

    public static function hydrateNonPublicAndNonStaticProps(LivewireComponent $component, array $memo, LivewireComponentContext $context)
    {
        if (!static::isUsingNonPublicPropsFeature($component)) {
            return;
        }

        // Already did the working on mounting
        if ($context->isMounting()) {
            return;
        }

        $memoKey = self::getNonPublicPropsMemoKey($component);

        if (!\array_key_exists($memoKey, $memo) && config('app.debug')) {
            $errmsg = $component::class . '::hydrate: Memo array does not contain "non-public-props" key. It should not happen';

            if (\function_exists('debug')) {
                debug($errmsg);
            }

            Log::debug($errmsg);
        }

        $nonPublicPropsMemoData = $memo[$memoKey] ?? [];

        if (empty($nonPublicPropsMemoData)) {
            return;
        }

        $reflProperties = static::getNonStaticAndNonPublicReflectionProperties($component);
        $decryptedProps = \decrypt($nonPublicPropsMemoData);

        foreach ($decryptedProps as $propName => $propValue) {
            $reflectionProperty = $reflProperties[$propName];
            $reflectionProperty->setAccessible(true);
            $reflectionProperty->setValue($component, $propValue);
        }
    }

    public static function dehydrateNonPublicAndNonStaticProps(LivewireComponent $component, LivewireComponentContext $context)
    {
        if (!static::isUsingNonPublicPropsFeature($component)) {
            return;
        }

        $encryptedProps = \encrypt(static::getNonStaticAndNonPublicPropsExcludingLivewireComponentOnes($component));
        $context->addMemo(self::getNonPublicPropsMemoKey($component), $encryptedProps);
    }

    public static function getComponentHook(): LivewireComponentHook
    {
        return new class () extends LivewireComponentHook {
            public static function provide()
            {
                on('mount', function (LivewireComponent $component, $params, $key, $parent) {
                    NonPublicPropertyManager::assignNonPublicAndNonStaticProps($component, $params, $key, $parent);
                });

                on('hydrate', function (LivewireComponent $component, array $memo, LivewireComponentContext $context) {
                    NonPublicPropertyManager::hydrateNonPublicAndNonStaticProps($component, $memo, $context);
                });

                on('dehydrate', function ($component, $context) {
                    NonPublicPropertyManager::dehydrateNonPublicAndNonStaticProps($component, $context);
                });
            }
        };
    }

    private static function getNonPublicPropsMemoKey(LivewireComponent $component): string
    {
        return \hash('md5', 'nonPublicPropsMemoKey:' . $component->getId());
    }

    private static function isUsingNonPublicPropsFeature(LivewireComponent $component): bool
    {
        if ($component instanceof HasNonPublicPropertiesContract) {
            return true;
        }

        return !empty((new \ReflectionClass($component))->getAttributes(HasNonPublicPropertiesAttribute::class));
    }

    private static function getNonStaticAndNonPublicPropsExcludingLivewireComponentOnes(LivewireComponent $target): array
    {
        // Code borrowed from Livewire core. =)

        return static::getNonStaticAndNonPublicProps($target, function ($property) {
            // Filter out any properties from the first-party Component class...
            return $property->getDeclaringClass()->getName() !== LivewireComponent::class;
        });
    }

    private static function getNonStaticAndNonPublicProps(LivewireComponent $target, $filter = null): array
    {
        // Code borrowed from Livewire core. =)

        return static::getNonStaticAndNonPublicReflectionProperties($target)
            ->filter($filter ?? fn() => true)
            ->mapWithKeys(function ($property) use ($target) {
                // Ensures typed property is initialized in PHP >=7.4, if so, return its value,
                // if not initialized, return null (as expected in earlier PHP Versions)
                if (method_exists($property, 'isInitialized') && !$property->isInitialized($target)) {
                    // If a type of `array` is given with no value, let's assume users want
                    // it prefilled with an empty array...
                    $value = (method_exists($property, 'getType') && $property->getType() && method_exists($property->getType(), 'getName') && $property->getType()->getName() === 'array')
                        ? [] : null;
                } else {
                    $value = $property->getValue($target);
                }

                return [$property->getName() => $value];
            })
            ->all();
    }

    private static function getNonStaticAndNonPublicReflectionProperties(LivewireComponent $target): LaravelCollection
    {
        $propsHash = [];
        foreach ((new \ReflectionObject($target))->getProperties() as $reflProp) {
            if ($reflProp->isPublic() || $reflProp->isStatic() || !$reflProp->isDefault()) {
                continue;
            }

            $propsHash[$reflProp->getName()] = $reflProp;
        }

        return \collect($propsHash);
    }
}
