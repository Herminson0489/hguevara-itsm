<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use App\Models\User;

class AuthController extends Controller
{
    public function login(Request $request)
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        $user = User::where('email', $credentials['email'])->first();

        if (!$user || !Hash::check($credentials['password'], $user->password)) {
            return response()->json([
                'message' => 'Credenciales incorrectas.',
            ], 401);
        }

        if (!$user->company || $user->company->status !== 'active') {
            return response()->json([
                'message' => 'La empresa del usuario no está activa.',
            ], 403);
        }

        $token = $user->createToken('hguevara-itsm')->plainTextToken;

        return response()->json([
            'message' => 'Inicio de sesión exitoso.',
            'token' => $token,
            'token_type' => 'Bearer',
            'user' => [
    'id' => $user->id,
    'name' => $user->name,
    'email' => $user->email,
    'company_id' => $user->company_id,
    'role' => $user->role,
],
        ]);
    }
}
