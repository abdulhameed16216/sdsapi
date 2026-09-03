<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ProjectStepMaster;

class ProjectStepMasterController extends Controller
{
    public function index()
    {
        $steps = ProjectStepMaster::activeOrdered()->get([
            'id',
            'step_id',
            'name',
            'sort_order',
        ]);

        return response()->json([
            'success' => true,
            'data' => $steps,
        ]);
    }
}
