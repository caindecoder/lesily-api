<?php

namespace App\Compliance\Dsar\UseCases\Sections;

class DsarSections
{
    /**
     * @param  array<int, DsarSectionProvider>  $providers
     */
    public function __construct(private readonly array $providers) {}

    /**
     * Every section's data for the subject in one company, keyed by section.
     *
     * @return array<string, array<int|string, mixed>>
     */
    public function collectFor(string $subjectUserId, string $companyId): array
    {
        $sections = [];

        foreach ($this->providers as $provider) {
            $sections[$provider->key()] = $provider->collect($subjectUserId, $companyId);
        }

        return $sections;
    }
}
