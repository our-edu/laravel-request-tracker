<?php

namespace OurEdu\RequestTracker\Attributes;

use Attribute;

#[Attribute(Attribute::TARGET_METHOD)]
class TrackRequest
{
    public string $module;
    public ?string $submodule;
    public ?string $annotation;

    /**
     * Track request with custom module and annotation
     * 
     * @param string $mapping Format: "module" or "module.submodule" or "module.submodule|Annotation"
     * 
     * Examples:
     *   #[TrackRequest('users')]
     *   #[TrackRequest('users.profile')]
     *   #[TrackRequest('users.profile|User Profile Management')]
     */
    public function __construct(string $mapping)
    {
        $this->annotation = null;
        
        // Check for annotation (text after |)
        if (str_contains($mapping, '|')) {
            [$mapping, $this->annotation] = explode('|', $mapping, 2);
        }

        // Check for submodule (text after .)
        if (str_contains($mapping, '.')) {
            [$this->module, $this->submodule] = explode('.', $mapping, 2);
        } else {
            $this->module = $mapping;
            $this->submodule = null;
        }
    }

    public function toArray(): array
    {
        return [
            'module' => $this->module,
            'submodule' => $this->submodule,
            'annotation' => $this->annotation,
        ];
    }
}
