<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class LandingController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json([
            'app' => 'ClyoNutri II',
            'framework' => 'Laravel 13',
            'status' => 'operational',
            'message' => 'Plataforma Clínica de Alta Precisão para Nutricionistas',
        ]);
    }

    public function terms(): JsonResponse
    {
        return response()->json([
            'document' => 'Termos de Uso',
            'version' => '1.0.0',
            'status' => 'active',
        ]);
    }

    public function privacy(): JsonResponse
    {
        return response()->json([
            'document' => 'Política de Privacidade e LGPD',
            'compliance' => 'Lei 13.709/2018',
            'dpo_contact' => 'privacidade@clyonutri.com.br',
        ]);
    }
}
