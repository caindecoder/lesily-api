<?php

namespace App\Compliance\Dsar\UseCases\Sections;

interface DsarSectionProvider
{
    public function key(): string;

    /**
     * The subject's data in this category, scoped to one company — never a
     * secret, never another company's rows.
     *
     * @return array<int|string, mixed>
     */
    public function collect(string $subjectUserId, string $companyId): array;
}
