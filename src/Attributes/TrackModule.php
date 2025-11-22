<?php

namespace OurEdu\RequestTracker\Attributes;

use Attribute;

/**
 * Attribute to mark an entire controller with module/submodule tracking
 * Applied at class level, affects all methods in the controller
 */
#[Attribute(Attribute::TARGET_CLASS)]
class TrackModule
{
    public function __construct(
        public string $module,
        public ?string $submodule = null
    ) {
    }
}
