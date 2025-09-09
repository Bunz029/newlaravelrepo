<?php

namespace App\Http\Controllers;

use App\Models\Employee;
use App\Models\Building;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class EmployeeController extends Controller
{
    /**
     * Fetch all employees, including their associated building.
     *
     * @return \Illuminate\Http\Response
     */
    public function index()
    {
        // Fetch all employees, eager load their associated building
        $employees = Employee::with('building')->get();

        return response()->json($employees);
    }

    /**
     * Fetch only published employees for the app.
     *
     * @return \Illuminate\Http\Response
     */
    public function getPublished()
    {
        // Fetch only published employees, eager load their associated building
        $employees = Employee::with('building')
            ->where('is_published', true)
            ->get();

        return response()->json($employees);
    }

    /**
     * Store a new employee record.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function store(Request $request)
    {
        $request->validate([
            'name' => 'required|string',
            'position' => 'nullable|string',
            'email' => 'nullable|email',
            'contact_number' => 'nullable|string',
            'building_id' => 'required|exists:buildings,id',
            'image' => 'nullable|image|max:2048'
        ]);

        $data = [
            'employee_name' => $request->name,
            'position' => $request->position,
            'email' => $request->email,
            'contact_number' => $request->contact_number,
            'building_id' => $request->building_id,
        ];

        // Handle employee image upload (optional - will use default if not provided)
        if ($request->hasFile('image')) {
            $data['employee_image'] = $request->file('image')->store('images/employees', 'public');
        }
        // If no image provided, the database will use the default value

        $employee = Employee::create($data);

        return response()->json($employee, 201);
    }

    /**
     * Fetch a specific employee by ID.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function show($id)
    {
        // Find the employee by ID
        $employee = Employee::with('building')->find($id);

        if (!$employee) {
            return response()->json(['error' => 'Employee not found'], 404);
        }

        return response()->json($employee);
    }

    /**
     * Update an employee record.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function update(Request $request, $id)
    {
        // Validate incoming request data
        $request->validate([
            'name' => 'required|string|max:255',
            'position' => 'nullable|string',
            'email' => 'nullable|email|max:255|unique:employees,email,' . $id,
            'contact_number' => 'nullable|string',
            'building_id' => 'required|exists:buildings,id',
            'image' => 'nullable|image|max:2048'
        ]);

        // Find the employee record by ID
        $employee = Employee::findOrFail($id);

        // Prepare update data
        $data = [
            'employee_name' => $request->input('name'),
            'position' => $request->input('position'),
            'email' => $request->input('email'),
            'contact_number' => $request->input('contact_number'),
            'building_id' => $request->input('building_id'),
        ];

        // Handle image update (optional)
        if ($request->hasFile('image')) {
            // Delete old image if exists and it's not the default
            if ($employee->employee_image && $employee->employee_image !== 'images/employees/default-profile-icon.png') {
                Storage::disk('public')->delete($employee->employee_image);
            }
            
            // Store new image
            $data['employee_image'] = $request->file('image')->store('images/employees', 'public');
        }

        // Update employee details
        $employee->update($data);

        return response()->json([
            'message' => 'Employee updated successfully!',
            'employee' => $employee,
        ]);
    }

    /**
     * Delete an employee record.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function destroy($id)
    {
        // Find the employee by id
        $employee = Employee::findOrFail($id);

        // Delete employee image if exists
        if ($employee->employee_image) {
            Storage::disk('public')->delete($employee->employee_image);
        }
        
        // Delete the employee
        $employee->delete();

        return response()->json([
            'message' => 'Employee deleted successfully'
        ], 200);
    }

    /**
     * Fetch employees based on a specific building.
     *
     * @param  int  $buildingId
     * @return \Illuminate\Http\Response
     */
    public function getByBuilding($buildingId)
    {
        // Get all employees that belong to the specified building
        $employees = Employee::where('building_id', $buildingId)->get();

        if ($employees->isEmpty()) {
            return response()->json(['error' => 'No employees found for this building'], 404);
        }

        return response()->json($employees);
    }

    /**
     * Fetch published employees based on a specific building.
     *
     * @param  int  $buildingId
     * @return \Illuminate\Http\Response
     */
    public function getPublishedByBuilding($buildingId)
    {
        // Get only published employees that belong to the specified building
        $employees = Employee::where('building_id', $buildingId)
            ->where('is_published', true)
            ->get();

        if ($employees->isEmpty()) {
            return response()->json(['error' => 'No published employees found for this building'], 404);
        }

        return response()->json($employees);
    }
} 