<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        return response()->json([
            'view' => 'dashboard',
            'user' => $request->user()?->only(['id', 'name', 'email']),
            'metrics' => [
                'active_patients' => 12,
                'appointments_today' => 3,
                'active_meal_plans' => 10,
            ],
        ]);
    }

    public function patients(): JsonResponse
    {
        return response()->json([
            'view' => 'patients.index',
            'data' => [],
        ]);
    }

    public function agenda(): JsonResponse
    {
        return response()->json([
            'view' => 'agenda.index',
            'timezone' => config('app.timezone', 'America/Sao_Paulo'),
            'slots' => [],
        ]);
    }
}
