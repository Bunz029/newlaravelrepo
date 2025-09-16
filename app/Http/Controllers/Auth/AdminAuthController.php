<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\Admin;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AdminAuthController extends Controller
{
    public function login(Request $request)
    {
        try {
            $credentials = $request->validate([
                'email' => ['required', 'email'],
                'password' => ['required', 'string'],
            ]);

            $admin = Admin::where('email', $credentials['email'])->first();

            if (! $admin) {
                return response()->json([
                    'success' => false,
                    'message' => 'Admin user not found',
                    'error' => 'Admin with this email does not exist'
                ], 404);
            }

            if (! Hash::check($credentials['password'], $admin->password)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Invalid password',
                    'error' => 'The provided password is incorrect'
                ], 401);
            }

            // Revoke existing tokens (optional for simplicity)
            $admin->tokens()->delete();

            $token = $admin->createToken('admin-token')->plainTextToken;

            return response()->json([
                'success' => true,
                'token' => $token,
                'user' => [
                    'id' => $admin->id,
                    'name' => $admin->name,
                    'email' => $admin->email,
                ],
            ]);

        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $e->errors()
            ], 422);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Login failed',
                'error' => $e->getMessage(),
                'trace' => config('app.debug') ? $e->getTraceAsString() : null
            ], 500);
        }
    }

    public function me(Request $request)
    {
        $admin = $request->user();
        return response()->json([
            'id' => $admin->id,
            'name' => $admin->name,
            'email' => $admin->email,
        ]);
    }

    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()->delete();
        return response()->json(['success' => true]);
    }
}


