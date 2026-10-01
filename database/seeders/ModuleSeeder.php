<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Module;

class ModuleSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $defaultPermissions = ['VIEW', 'ADD', 'EDIT', 'DELETE'];

        $modules = [
            ['name' => 'Dashboard', 'permission' => ['VIEW'], 'status' => 'Active'],
            ['name' => 'Staff', 'permission' => $defaultPermissions, 'status' => 'Active'],
            ['name' => 'Clients', 'permission' => $defaultPermissions, 'status' => 'Active'],
            ['name' => 'Setup', 'permission' => $defaultPermissions, 'status' => 'Active'],
            ['name' => 'Membership Plans', 'permission' => $defaultPermissions, 'status' => 'Active'],
            ['name' => 'Renewals', 'permission' => $defaultPermissions, 'status' => 'Active'],
            ['name' => 'Transactions', 'permission' => $defaultPermissions, 'status' => 'Active'],
            ['name' => 'Reports', 'permission' => ['VIEW'], 'status' => 'Active'],
            ['name' => 'Role Access', 'permission' => $defaultPermissions, 'status' => 'Active'],
            ['name' => 'Roles & Modules (Staff)', 'permission' => $defaultPermissions, 'status' => 'Active'],
            ['name' => 'Modules (Products)', 'permission' => $defaultPermissions, 'status' => 'Active'],
            ['name' => 'Settings', 'permission' => $defaultPermissions, 'status' => 'Active'],
            ['name' => 'Notifications', 'permission' => $defaultPermissions, 'status' => 'Active'],
        ];

        foreach ($modules as $module) {
            Module::firstOrCreate(['name' => $module['name']], $module);
        }
    }
}
