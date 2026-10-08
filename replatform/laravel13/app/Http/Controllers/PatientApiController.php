<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class PatientApiController extends Controller
{
    /**
     * Autenticação móvel do paciente via Sanctum (Personal Access Token).
     */
    public function login(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email' => 'required|email',
            'password' => 'required|string',
            'device_name' => 'nullable|string',
        ]);

        $user = User::where('email', $validated['email'])->first();

        if (!$user || !Hash::check($validated['password'], $user->password)) {
            return response()->json([
                'success' => false,
                'message' => 'Credenciais inválidas.',
            ], 401);
        }

        $deviceName = $validated['device_name'] ?? 'Mobile Device';
        $token = $user->createToken($deviceName)->plainTextToken;

        return response()->json([
            'success' => true,
            'message' => 'Autenticado com sucesso.',
            'token' => $token,
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
            ],
        ]);
    }

    /**
     * Encerramento de sessão móvel do paciente (revogação do token atual).
     */
    public function logout(Request $request): JsonResponse
    {
        $token = $request->user()?->currentAccessToken();
        if ($token) {
            $token->delete();
        }

        return response()->json([
            'success' => true,
            'message' => 'Token revogado com sucesso.',
        ]);
    }

    /**
     * Hub inicial do paciente (recurso protegido por auth:sanctum).
     */
    public function home(Request $request): JsonResponse
    {
        $user = $request->user();

        return response()->json([
            'success' => true,
            'patient' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
            ],
            'daily_summary' => [
                'hydration_current_ml' => 1250,
                'hydration_target_ml' => 2500,
                'meals_logged' => 2,
                'next_appointment' => '2026-10-15 14:00',
            ],
        ]);
    }

    /**
     * Registro de hidratação (recurso protegido por auth:sanctum).
     */
    public function logHydration(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'amount_ml' => 'required|integer|min:50|max:2000',
        ]);

        return response()->json([
            'success' => true,
            'logged_ml' => $validated['amount_ml'],
            'timestamp' => now()->toIso8601String(),
        ], 201);
    }
}
