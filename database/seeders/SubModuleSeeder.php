<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Module;
use App\Models\SubModule;

class SubModuleSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // 1. Create or find the 'Patient' module
        $patientModule = Module::firstOrCreate(
            ['name' => 'Patient'],
            [
                'permission' => ['VIEW', 'ADD', 'EDIT', 'DELETE'],
                'status' => 'Active'
            ]
        );

        // 2. Sub-modules for the 'Patient' module
        $patientSubModules = [
            ['name' => 'All Patient', 'status' => 'Active', 'permission' => ['VIEW']],
            ['name' => 'Add Patient', 'status' => 'Active', 'permission' => ['ADD']],
            ['name' => 'Edit Patient', 'status' => 'Active', 'permission' => ['EDIT']],
            ['name' => 'Delete Patient', 'status' => 'Active', 'permission' => ['DELETE']],
        ];

        foreach ($patientSubModules as $subModule) {
            SubModule::firstOrCreate(
                [
                    'module_id' => $patientModule->id,
                    'name' => $subModule['name']
                ],
                [
                    'status' => $subModule['status'],
                    'permission' => $subModule['permission']
                ]
            );
        }
    }
}
