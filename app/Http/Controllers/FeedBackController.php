<?php

namespace App\Http\Controllers;

use App\Models\Feedback;
use Illuminate\Http\Request;

class FeedBackController extends Controller
{
    // Store the feedback
    public function store(Request $request)
{
    // Validate the incoming request
    $validated = $request->validate([
        'user_email' => 'required',   // Removed the email format validation
        'message' => 'required|string|min:5', // Message validation
    ]);

    // Create a new feedback record in the database
    Feedback::create([
        'user_email' => $validated['user_email'],   // Save the user email
        'message' => $validated['message'],   // Save the message
    ]);

    // Return a success message
    return response()->json([
        'message' => 'Feedback submitted successfully!',
    ], 200);
}
}
