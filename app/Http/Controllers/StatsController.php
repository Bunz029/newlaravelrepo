<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Faculty;
use App\Models\Building;

class StatsController extends Controller
{
    public function getCounts()
    {
        $facultyCount = Faculty::count();
        $buildingsCount = Building::count();

        return response()->json([
            'faculty' => $facultyCount,
            'buildings' => $buildingsCount,
        ]);
    }
}
