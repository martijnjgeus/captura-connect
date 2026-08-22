<?php

namespace Database\Seeders;

use App\Models\CodeAllocatorBrandCompanyMapping;
use Illuminate\Database\Seeder;
use RuntimeException;

class CodeAllocatorBrandCompanyMappingSeeder extends Seeder
{
    public function run(): void
    {
        $rucanorCompany = trim((string) config('api.code_allocator.rucanor_ean_company'));
        $papillonCompany = trim((string) config('api.code_allocator.papillon_ean_company'));

        if ($rucanorCompany === '') {
            throw new RuntimeException('Missing config: api.code_allocator.rucanor_ean_company');
        }

        if ($papillonCompany === '') {
            throw new RuntimeException('Missing config: api.code_allocator.papillon_ean_company');
        }

        $mappings = [
            [
                'afas_brand_code' => '1',
                'afas_brand_name' => 'RUCANOR',
                'ean_company' => $rucanorCompany,
                'is_active' => true,
            ],
            [
                'afas_brand_code' => '50',
                'afas_brand_name' => 'PAPILLON',
                'ean_company' => $papillonCompany,
                'is_active' => true,
            ],
        ];

        foreach ($mappings as $mapping) {
            CodeAllocatorBrandCompanyMapping::query()->updateOrCreate(
                ['afas_brand_code' => $mapping['afas_brand_code']],
                $mapping
            );
        }
    }
}
