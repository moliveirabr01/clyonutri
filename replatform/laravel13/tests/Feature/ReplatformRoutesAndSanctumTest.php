<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

test('rota raiz entrega status operacional do ClyoNutri II no Laravel 13', function () {
    $response = $this->get('/');

    $response->assertStatus(200);
    $response->assertJson([
        'app' => 'ClyoNutri II',
        'framework' => 'Laravel 13',
        'status' => 'operational',
    ]);
});

test('rotas institucionais de termos e privacidade estao acessiveis', function () {
    $this->get('/termos')->assertStatus(200)->assertJsonFragment(['document' => 'Termos de Uso']);
    $this->get('/privacidade')->assertStatus(200)->assertJsonFragment(['compliance' => 'Lei 13.709/2018']);
});

test('rota /dashboard exige autenticacao', function () {
    $response = $this->get('/dashboard');
    // Deve redirecionar para login ou negar acesso não autenticado
    $this->assertTrue(in_array($response->status(), [302, 401], true));
});

test('rota /api/v1/patient/home rejeita requisicao sem bearer token com 401', function () {
    $response = $this->getJson('/api/v1/patient/home');

    $response->assertStatus(401);
});

test('fluxo completo de autenticacao Sanctum, acesso protegido e revogacao de token', function () {
    // 1. Criar usuário paciente real
    $patient = User::create([
        'name' => 'Paciente Verificavel E2E',
        'email' => 'paciente.sanctum@clyonutri.com.br',
        'password' => Hash::make('SenhaForte@2026'),
    ]);

    // 2. Login via API REST
    $loginResponse = $this->postJson('/api/v1/patient/login', [
        'email' => 'paciente.sanctum@clyonutri.com.br',
        'password' => 'SenhaForte@2026',
        'device_name' => 'iPhone 15 Pro Test',
    ]);

    $loginResponse->assertStatus(200);
    $loginResponse->assertJson(['success' => true]);
    $token = $loginResponse->json('token');
    $this->assertNotEmpty($token);

    // 3. Acesso a endpoint protegido com Bearer Token
    $homeResponse = $this->withHeader('Authorization', 'Bearer ' . $token)
        ->getJson('/api/v1/patient/home');

    $homeResponse->assertStatus(200);
    $homeResponse->assertJson([
        'success' => true,
        'patient' => [
            'id' => $patient->id,
            'email' => 'paciente.sanctum@clyonutri.com.br',
        ],
    ]);

    // 4. Registro de hidratação protegido
    $hydrationResponse = $this->withHeader('Authorization', 'Bearer ' . $token)
        ->postJson('/api/v1/patient/hydration', [
            'amount_ml' => 300,
        ]);

    $hydrationResponse->assertStatus(201);
    $hydrationResponse->assertJson(['success' => true, 'logged_ml' => 300]);

    // 5. Logout e revogação de token
    $logoutResponse = $this->withHeader('Authorization', 'Bearer ' . $token)
        ->postJson('/api/v1/patient/logout');

    $logoutResponse->assertStatus(200);
    $logoutResponse->assertJson(['success' => true]);

    // Comprovar persistência: token deve ter sido removido do banco relacional
    $this->assertDatabaseCount('personal_access_tokens', 0);

    // Limpar cache de autenticação na instância de teste para nova requisição
    $this->app['auth']->forgetGuards();

    // 6. Subsequente chamada com o token revogado deve falhar com 401
    $revokedResponse = $this->withHeader('Authorization', 'Bearer ' . $token)
        ->getJson('/api/v1/patient/home');

    $revokedResponse->assertStatus(401);
});

test('autenticacao com senha incorreta falha com 401', function () {
    User::create([
        'name' => 'Paciente Negativo',
        'email' => 'negativo@clyonutri.com.br',
        'password' => Hash::make('SenhaCerta@2026'),
    ]);

    $response = $this->postJson('/api/v1/patient/login', [
        'email' => 'negativo@clyonutri.com.br',
        'password' => 'SenhaErrada',
    ]);

    $response->assertStatus(401);
    $response->assertJson(['success' => false]);
});

test('tabelas de migracao relacional do Sanctum estao presentes e ativas', function () {
    $this->assertTrue(\Illuminate\Support\Facades\Schema::hasTable('users'));
    $this->assertTrue(\Illuminate\Support\Facades\Schema::hasTable('personal_access_tokens'));
    $this->assertTrue(\Illuminate\Support\Facades\Schema::hasTable('sessions'));
});
