<?php

namespace App\Http\Controllers;

use App\Models\Faculty;
use App\Models\Building;
use Illuminate\Http\Request;

class FacultyController extends Controller
{
    /**
     * 
     * Fetch all faculty members, including their associated building.
     *
     * @return \Illuminate\Http\Response
     */
    public function store(Request $request)
{
    $request->validate([
        'name' => 'required|string',
        'email' => 'required|email',
        'building_id' => 'required|exists:buildings,id', // Assuming faculty belongs to a building
    ]);

    $faculty = Faculty::create([
        'faculty_name' => $request->name,
        'email' => $request->email,
        'building_id' => $request->building_id,
        
    ]);

    return response()->json($faculty, 201);
}


public function update(Request $request, $id)
{
    // Validate incoming request data
    $request->validate([
        'name' => 'required|string|max:255',
        'email' => 'required|email|max:255|unique:faculty,email,' . $id,
        'building_id' => 'required|exists:buildings,id', // Ensure building_id exists in the buildings table
    ]);

    // Find the faculty record by ID
    $faculty = Faculty::findOrFail($id);

    // Update faculty details
    $faculty->update([
        'name' => $request->input('name'),
        'email' => $request->input('email'),
        'building_id' => $request->input('building_id'),
    ]);

    // Return a response
    return response()->json([
        'message' => 'Faculty updated successfully!',
        'faculty' => $faculty,
    ]);
}

public function destroy($id)
    {
        // Find the faculty by id
        $faculty = Faculty::findOrFail($id);

        // Delete the faculty
        $faculty->delete();

        // Return a success response
        return response()->json([
            'message' => 'Faculty deleted successfully'
        ], 200);
    }
    public function index()
    {
        // Fetch all faculty, eager load their associated building
        $faculty = Faculty::with('building')->get();

        return response()->json($faculty);
    }

    /**
     * Fetch faculty members based on a specific building.
     *
     * @param  int  $buildingId
     * @return \Illuminate\Http\Response
     */
    public function getByBuilding($buildingId)
    {
        // Get all faculty members that belong to the specified building
        $faculty = Faculty::where('building_id', $buildingId)->get();

        if ($faculty->isEmpty()) {
            return response()->json(['error' => 'No faculty found for this building'], 404);
        }

        return response()->json($faculty);
    }

    /**
     * Fetch a specific faculty member by ID.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function show($id)
    {
        // Find the faculty member by ID
        $faculty = Faculty::find($id);

        if (!$faculty) {
            return response()->json(['error' => 'Faculty member not found'], 404);
        }

        return response()->json($faculty);
    }
}
