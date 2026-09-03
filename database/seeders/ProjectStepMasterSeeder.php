<?php

namespace Database\Seeders;

use App\Models\ProjectStepMaster;
use Illuminate\Database\Seeder;

class ProjectStepMasterSeeder extends Seeder
{
    public function run(): void
    {
        $steps = [
            ['step_id' => 1, 'name' => 'Project Information', 'sort_order' => 1],
            ['step_id' => 2, 'name' => 'Request a Quote', 'sort_order' => 2],
            ['step_id' => 3, 'name' => 'Project & Scope Review', 'sort_order' => 3],
            ['step_id' => 4, 'name' => 'Proposal & Pricing', 'sort_order' => 4],
            ['step_id' => 5, 'name' => 'Project Authorization', 'sort_order' => 5],
            ['step_id' => 6, 'name' => 'Initial Payment', 'sort_order' => 6],
            ['step_id' => 7, 'name' => 'Project Progress Payments', 'sort_order' => 7],
            ['step_id' => 8, 'name' => 'Final Payment', 'sort_order' => 8],
        ];

        foreach ($steps as $step) {
            ProjectStepMaster::updateOrCreate(
                ['step_id' => $step['step_id']],
                [
                    'name' => $step['name'],
                    'sort_order' => $step['sort_order'],
                    'is_active' => true,
                ]
            );
        }
    }
}
