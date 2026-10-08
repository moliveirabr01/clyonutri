<?php

declare(strict_types=1);

namespace ClyoNutri\Application\Controllers;

use ClyoNutri\Core\Auth\TenantContext;
use ClyoNutri\Core\Database\Connection;
use ClyoNutri\Core\Security\Csrf;
use ClyoNutri\Domain\Recipe\RecipeService;
use PDO;

class RecipeController
{
    private PDO $db;
    private RecipeService $recipeService;

    public function __construct(?PDO $db = null, ?RecipeService $recipeService = null)
    {
        $this->db = $db ?? Connection::getInstance();
        $this->recipeService = $recipeService ?? new RecipeService($this->db);
    }

    private function getAuthInfo(): array
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        $userId = (int)($_SESSION['user_id'] ?? 0);
        $clinicId = (int)($_SESSION['clinic_id'] ?? 0);
        $userName = (string)($_SESSION['user_name'] ?? 'Nutricionista');

        if ($userId <= 0 || $clinicId <= 0) {
            header('Location: /login');
            exit;
        }

        // Buscar ID profissional
        $stmt = $this->db->prepare("SELECT id FROM professionals WHERE user_id = :uid LIMIT 1");
        $stmt->execute(['uid' => $userId]);
        $profId = (int)$stmt->fetchColumn();

        TenantContext::setClinicId($clinicId);
        TenantContext::set($clinicId, [
            'id' => $userId,
            'name' => $userName,
            'clinic_id' => $clinicId,
        ]);

        return [
            'userId' => $userId,
            'clinicId' => $clinicId,
            'userName' => $userName,
            'profId' => $profId > 0 ? $profId : 1,
        ];
    }

    /**
     * Biblioteca e Catálogo de Receitas Culinárias
     */
    public function index(array $params = []): void
    {
        $auth = $this->getAuthInfo();

        $search = trim((string)($_GET['q'] ?? ''));
        $mealType = trim((string)($_GET['meal_type'] ?? ''));
        $difficulty = trim((string)($_GET['difficulty'] ?? ''));
        $maxKcal = isset($_GET['max_kcal']) && $_GET['max_kcal'] !== '' ? (float)$_GET['max_kcal'] : null;

        $filters = [];
        if ($search !== '') {
            $filters['search'] = $search;
        }
        if ($mealType !== '') {
            $filters['meal_type'] = $mealType;
        }
        if ($difficulty !== '') {
            $filters['difficulty'] = $difficulty;
        }
        if ($maxKcal !== null) {
            $filters['max_kcal'] = $maxKcal;
        }

        $recipes = $this->recipeService->searchRecipes($filters, $auth['clinicId']);
        $csrfToken = Csrf::generateToken();

        header('Content-Type: text/html; charset=utf-8');
        ?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Receitas Culinárias & Composição Nutricional — ClyoNutri II</title>
    <style>
        :root {
            --primary: #059669;
            --primary-hover: #047857;
            --primary-light: #ecfdf5;
            --surface: #ffffff;
            --bg: #f8fafc;
            --border: #e2e8f0;
            --text-main: #0f172a;
            --text-muted: #64748b;
            --warning: #f59e0b;
            --danger: #ef4444;
            --blue: #2563eb;
        }
        * { box-sizing: border-box; margin: 0; padding: 0; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif; }
        body { background: var(--bg); color: var(--text-main); height: 100vh; display: flex; flex-direction: column; }
        
        .header {
            background: var(--surface); border-bottom: 1px solid var(--border); padding: 0.85rem 1.75rem;
            display: flex; align-items: center; justify-content: space-between;
        }
        .logo-area { display: flex; align-items: center; gap: 0.75rem; font-weight: 700; font-size: 1.15rem; color: var(--primary); }
        .logo-area span.badge { background: var(--primary-light); color: var(--primary); font-size: 0.72rem; padding: 2px 8px; border-radius: 999px; }
        .nav-links { display: flex; gap: 1rem; list-style: none; }
        .nav-links a { text-decoration: none; color: var(--text-muted); font-size: 0.9rem; font-weight: 500; padding: 0.4rem 0.65rem; border-radius: 6px; }
        .nav-links a:hover, .nav-links a.active { color: var(--primary); background: var(--primary-light); }
        
        .main-container { flex: 1; overflow-y: auto; padding: 1.75rem; max-width: 1300px; margin: 0 auto; width: 100%; }
        
        .top-bar { display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.5rem; flex-wrap: wrap; gap: 1rem; }
        .top-bar h1 { font-size: 1.6rem; font-weight: 800; color: #064e3b; }
        .top-bar p { font-size: 0.9rem; color: var(--text-muted); margin-top: 0.2rem; }
        
        .btn { display: inline-flex; align-items: center; gap: 0.5rem; padding: 0.6rem 1.1rem; border-radius: 8px; font-size: 0.9rem; font-weight: 600; text-decoration: none; cursor: pointer; border: none; transition: background 0.15s; }
        .btn-primary { background: var(--primary); color: #fff; }
        .btn-primary:hover { background: var(--primary-hover); }
        .btn-outline { background: #fff; border: 1px solid var(--border); color: var(--text-main); }
        .btn-outline:hover { background: #f1f5f9; }
        .btn-sm { padding: 0.35rem 0.65rem; font-size: 0.8rem; }
        
        .filter-panel { background: #fff; border: 1px solid var(--border); border-radius: 10px; padding: 1.1rem; margin-bottom: 1.5rem; display: flex; gap: 1rem; flex-wrap: wrap; align-items: flex-end; }
        .filter-group { display: flex; flex-direction: column; gap: 0.35rem; }
        .filter-group label { font-size: 0.8rem; font-weight: 600; color: var(--text-muted); }
        .filter-group input, .filter-group select { padding: 0.55rem 0.75rem; border: 1px solid var(--border); border-radius: 6px; font-size: 0.88rem; min-width: 160px; }
        
        .recipes-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(340px, 1fr)); gap: 1.25rem; }
        .recipe-card { background: #fff; border: 1px solid var(--border); border-radius: 12px; overflow: hidden; display: flex; flex-direction: column; transition: transform 0.15s, box-shadow 0.15s; }
        .recipe-card:hover { transform: translateY(-2px); box-shadow: 0 10px 15px -3px rgba(0,0,0,0.06); }
        
        .recipe-card-header { padding: 1.1rem 1.25rem 0.75rem; border-bottom: 1px solid #f1f5f9; }
        .recipe-badge-row { display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.5rem; }
        .pill { font-size: 0.72rem; font-weight: 600; padding: 3px 8px; border-radius: 999px; text-transform: uppercase; }
        .pill-meal { background: #e0f2fe; color: #0284c7; }
        .pill-difficulty { background: #fef3c7; color: #d97706; }
        .pill-variant { background: #f3e8ff; color: #7e22ce; }
        
        .recipe-title { font-size: 1.1rem; font-weight: 700; color: #1e293b; text-decoration: none; line-height: 1.3; }
        .recipe-title:hover { color: var(--primary); }
        .recipe-summary { font-size: 0.82rem; color: var(--text-muted); margin-top: 0.35rem; line-height: 1.4; display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden; }
        
        .recipe-macros { background: #f8fafc; padding: 0.75rem 1.25rem; display: grid; grid-template-columns: repeat(4, 1fr); text-align: center; border-bottom: 1px solid #f1f5f9; }
        .macro-item span { display: block; }
        .macro-val { font-size: 0.95rem; font-weight: 700; color: #0f172a; }
        .macro-label { font-size: 0.7rem; color: var(--text-muted); text-transform: uppercase; }
        
        .recipe-card-body { padding: 0.85rem 1.25rem; flex: 1; display: flex; flex-direction: column; justify-content: space-between; gap: 0.75rem; }
        .recipe-meta-row { display: flex; justify-content: space-between; font-size: 0.8rem; color: var(--text-muted); }
        
        .allergens-list { display: flex; gap: 0.35rem; flex-wrap: wrap; }
        .allergen-tag { font-size: 0.7rem; background: #fee2e2; color: #b91c1c; padding: 2px 6px; border-radius: 4px; font-weight: 600; }
        
        .card-actions { display: flex; gap: 0.5rem; margin-top: 0.5rem; }
        .card-actions .btn { flex: 1; justify-content: center; }
        
        .empty-state { text-align: center; padding: 3rem 1rem; color: var(--text-muted); background: #fff; border-radius: 12px; border: 1px dashed var(--border); grid-column: 1 / -1; }
    </style>
</head>
<body>
    <header class="header">
        <div class="logo-area">
            🥗 ClyoNutri II <span class="badge">SaaS Real</span>
        </div>
        <ul class="nav-links">
            <li><a href="/dashboard">📊 Dashboard</a></li>
            <li><a href="/agenda">📅 Agenda</a></li>
            <li><a href="/pacientes">👥 Pacientes</a></li>
            <li><a href="/planos">🥗 Planos Alimentares</a></li>
            <li><a href="/receitas" class="active">🍳 Receitas Culinárias</a></li>
            <li><a href="/chat">💬 Chat & Mensagens</a></li>
            <li><a href="/ajuda">❓ Central de Ajuda</a></li>
            <li><a href="/logout">🚪 Sair</a></li>
        </ul>
    </header>

    <main class="main-container">
        <div class="top-bar">
            <div>
                <h1>Biblioteca de Receitas Culinárias</h1>
                <p>Receitas padronizadas, cálculos nutricionais oficiais TACO, medidas caseiras e prescrição direta ao plano alimentar.</p>
            </div>
            <div>
                <a href="/receitas/nova" class="btn btn-primary">➕ Nova Receita</a>
            </div>
        </div>

        <form method="GET" action="/receitas" class="filter-panel">
            <div class="filter-group" style="flex: 2;">
                <label>Buscar Receita ou Ingrediente</label>
                <input type="text" name="q" value="<?= htmlspecialchars($search) ?>" placeholder="Ex: Panqueca de Aveia, Omelete...">
            </div>
            <div class="filter-group">
                <label>Refeição / Categoria</label>
                <select name="meal_type">
                    <option value="">Todas as refeições</option>
                    <option value="Café da Manhã" <?= $mealType === 'Café da Manhã' ? 'selected' : '' ?>>Café da Manhã</option>
                    <option value="Almoço" <?= $mealType === 'Almoço' ? 'selected' : '' ?>>Almoço</option>
                    <option value="Lanche da Tarde" <?= $mealType === 'Lanche da Tarde' ? 'selected' : '' ?>>Lanche da Tarde</option>
                    <option value="Jantar" <?= $mealType === 'Jantar' ? 'selected' : '' ?>>Jantar</option>
                    <option value="Ceia" <?= $mealType === 'Ceia' ? 'selected' : '' ?>>Ceia</option>
                    <option value="Pré-Treino" <?= $mealType === 'Pré-Treino' ? 'selected' : '' ?>>Pré-Treino</option>
                    <option value="Pós-Treino" <?= $mealType === 'Pós-Treino' ? 'selected' : '' ?>>Pós-Treino</option>
                    <option value="Sobremesa" <?= $mealType === 'Sobremesa' ? 'selected' : '' ?>>Sobremesa</option>
                </select>
            </div>
            <div class="filter-group">
                <label>Dificuldade</label>
                <select name="difficulty">
                    <option value="">Qualquer dificuldade</option>
                    <option value="fácil" <?= $difficulty === 'fácil' ? 'selected' : '' ?>>Fácil</option>
                    <option value="média" <?= $difficulty === 'média' ? 'selected' : '' ?>>Média</option>
                    <option value="difícil" <?= $difficulty === 'difícil' ? 'selected' : '' ?>>Difícil</option>
                </select>
            </div>
            <div class="filter-group">
                <label>Kcal Máxima / Porção</label>
                <input type="number" step="10" name="max_kcal" value="<?= $maxKcal !== null ? (int)$maxKcal : '' ?>" placeholder="Ex: 400">
            </div>
            <div class="filter-group">
                <button type="submit" class="btn btn-outline">Filtrar</button>
            </div>
        </form>

        <div class="recipes-grid">
            <?php if (empty($recipes)): ?>
                <div class="empty-state">
                    <h3>Nenhuma receita encontrada</h3>
                    <p>Crie sua primeira receita culinária personalizada com cálculo automático por porção.</p>
                    <div style="margin-top: 1rem;">
                        <a href="/receitas/nova" class="btn btn-primary">Criar Receita Agora</a>
                    </div>
                </div>
            <?php else: ?>
                <?php foreach ($recipes as $r): ?>
                    <div class="recipe-card">
                        <div class="recipe-card-header">
                            <div class="recipe-badge-row">
                                <span class="pill pill-meal"><?= htmlspecialchars($r['meal_type'] ?: $r['category']) ?></span>
                                <span class="pill pill-difficulty"><?= htmlspecialchars($r['difficulty'] ?? 'fácil') ?></span>
                                <?php if (!empty($r['parent_recipe_id'])): ?>
                                    <span class="pill pill-variant" title="Receita variante adaptada">Variante</span>
                                <?php endif; ?>
                            </div>
                            <a href="/receitas/<?= (int)$r['id'] ?>" class="recipe-title">
                                <?= htmlspecialchars($r['title']) ?>
                            </a>
                            <?php if (!empty($r['summary'])): ?>
                                <p class="recipe-summary"><?= htmlspecialchars($r['summary']) ?></p>
                            <?php endif; ?>
                        </div>

                        <div class="recipe-macros">
                            <div class="macro-item">
                                <span class="macro-val"><?= (int)round((float)$r['energy_kcal_per_serving']) ?></span>
                                <span class="macro-label">kcal</span>
                            </div>
                            <div class="macro-item">
                                <span class="macro-val"><?= round((float)$r['protein_g_per_serving'], 1) ?>g</span>
                                <span class="macro-label">Proteína</span>
                            </div>
                            <div class="macro-item">
                                <span class="macro-val"><?= round((float)$r['carbs_g_per_serving'], 1) ?>g</span>
                                <span class="macro-label">Carbo</span>
                            </div>
                            <div class="macro-item">
                                <span class="macro-val"><?= round((float)$r['fat_g_per_serving'], 1) ?>g</span>
                                <span class="macro-label">Gordura</span>
                            </div>
                        </div>

                        <div class="recipe-card-body">
                            <div class="recipe-meta-row">
                                <span>⏱️ <?= (int)$r['prep_time_minutes'] ?> min preparo</span>
                                <span>🍽️ <?= (int)$r['servings'] ?> porção(ões)</span>
                            </div>

                            <?php
                            // Alergênicos
                            $alStmt = $this->db->prepare("SELECT allergen_group FROM recipe_allergens WHERE recipe_id = :rid");
                            $alStmt->execute(['rid' => (int)$r['id']]);
                            $alList = $alStmt->fetchAll(PDO::FETCH_COLUMN);
                            ?>
                            <?php if (!empty($alList)): ?>
                                <div class="allergens-list">
                                    <?php foreach ($alList as $alg): ?>
                                        <span class="allergen-tag">⚠️ <?= htmlspecialchars($alg) ?></span>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>

                            <div class="card-actions">
                                <a href="/receitas/<?= (int)$r['id'] ?>" class="btn btn-outline btn-sm">Ver Detalhes</a>
                                <a href="/receitas/<?= (int)$r['id'] ?>/prescrever" class="btn btn-primary btn-sm">Prescrever</a>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </main>
</body>
</html>
        <?php
    }

    /**
     * Visualização Detalhada da Receita com Composição Nutricional e Passos
     */
    public function show(array $params = []): void
    {
        $auth = $this->getAuthInfo();
        $recipeId = (int)($params['id'] ?? 0);

        try {
            $recipe = $this->recipeService->getRecipeDetails($recipeId, $auth['clinicId']);
        } catch (\Throwable $e) {
            http_response_code(404);
            echo "<h1>Receita não encontrada ou sem permissão.</h1>";
            return;
        }

        $csrfToken = Csrf::generateToken();
        header('Content-Type: text/html; charset=utf-8');
        ?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($recipe['title']) ?> — ClyoNutri II</title>
    <style>
        :root {
            --primary: #059669;
            --primary-hover: #047857;
            --primary-light: #ecfdf5;
            --surface: #ffffff;
            --bg: #f8fafc;
            --border: #e2e8f0;
            --text-main: #0f172a;
            --text-muted: #64748b;
        }
        * { box-sizing: border-box; margin: 0; padding: 0; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif; }
        body { background: var(--bg); color: var(--text-main); line-height: 1.5; }
        
        .header { background: var(--surface); border-bottom: 1px solid var(--border); padding: 0.85rem 1.75rem; display: flex; align-items: center; justify-content: space-between; }
        .logo-area { display: flex; align-items: center; gap: 0.75rem; font-weight: 700; font-size: 1.15rem; color: var(--primary); }
        .nav-links { display: flex; gap: 1rem; list-style: none; }
        .nav-links a { text-decoration: none; color: var(--text-muted); font-size: 0.9rem; font-weight: 500; padding: 0.4rem 0.65rem; border-radius: 6px; }
        .nav-links a.active { color: var(--primary); background: var(--primary-light); }
        
        .container { max-width: 1000px; margin: 2rem auto; padding: 0 1.5rem; }
        .back-link { display: inline-flex; align-items: center; gap: 0.5rem; text-decoration: none; color: var(--text-muted); font-size: 0.9rem; margin-bottom: 1.25rem; font-weight: 600; }
        .back-link:hover { color: var(--primary); }
        
        .recipe-header-card { background: #fff; border: 1px solid var(--border); border-radius: 12px; padding: 1.75rem; margin-bottom: 1.5rem; }
        .header-top { display: flex; justify-content: space-between; align-items: flex-start; gap: 1rem; flex-wrap: wrap; }
        .recipe-title { font-size: 1.85rem; font-weight: 800; color: #064e3b; }
        .recipe-summary { color: var(--text-muted); margin-top: 0.5rem; font-size: 1rem; }
        
        .badges-bar { display: flex; gap: 0.5rem; margin-top: 0.75rem; flex-wrap: wrap; }
        .badge { font-size: 0.75rem; font-weight: 600; padding: 3px 10px; border-radius: 999px; background: #e2e8f0; color: #334155; }
        .badge-green { background: var(--primary-light); color: var(--primary); }
        
        .actions-bar { display: flex; gap: 0.75rem; margin-top: 1.25rem; }
        .btn { display: inline-flex; align-items: center; gap: 0.5rem; padding: 0.65rem 1.25rem; border-radius: 8px; font-size: 0.9rem; font-weight: 600; text-decoration: none; cursor: pointer; border: none; }
        .btn-primary { background: var(--primary); color: #fff; }
        .btn-primary:hover { background: var(--primary-hover); }
        .btn-outline { background: #fff; border: 1px solid var(--border); color: var(--text-main); }
        .btn-outline:hover { background: #f1f5f9; }
        
        .grid-details { display: grid; grid-template-columns: 2fr 1fr; gap: 1.5rem; }
        @media (max-width: 768px) { .grid-details { grid-template-columns: 1fr; } }
        
        .section-box { background: #fff; border: 1px solid var(--border); border-radius: 12px; padding: 1.5rem; margin-bottom: 1.5rem; }
        .section-title { font-size: 1.15rem; font-weight: 700; color: #1e293b; margin-bottom: 1rem; border-bottom: 1px solid #f1f5f9; padding-bottom: 0.5rem; }
        
        .ingredients-table { width: 100%; border-collapse: collapse; font-size: 0.9rem; }
        .ingredients-table th, .ingredients-table td { padding: 0.6rem 0.5rem; text-align: left; border-bottom: 1px solid #f1f5f9; }
        .ingredients-table th { font-weight: 600; color: var(--text-muted); font-size: 0.78rem; text-transform: uppercase; }
        
        .steps-list { list-style: none; counter-reset: step-counter; }
        .step-item { display: flex; gap: 1rem; margin-bottom: 1rem; }
        .step-num { width: 28px; height: 28px; border-radius: 50%; background: var(--primary-light); color: var(--primary); display: flex; align-items: center; justify-content: center; font-weight: 700; font-size: 0.85rem; flex-shrink: 0; }
        .step-content { flex: 1; font-size: 0.92rem; }
        .step-tip { font-size: 0.8rem; color: #d97706; background: #fffbeb; padding: 4px 8px; border-radius: 4px; margin-top: 4px; display: inline-block; }
        
        .nutrition-card { background: #f8fafc; border: 1px solid var(--border); border-radius: 8px; padding: 1.1rem; }
        .nutrition-row { display: flex; justify-content: space-between; padding: 0.45rem 0; border-bottom: 1px dashed #e2e8f0; font-size: 0.88rem; }
        .nutrition-row.total-kcal { font-size: 1.05rem; font-weight: 800; color: #064e3b; border-bottom: 2px solid var(--primary); padding-bottom: 0.5rem; margin-bottom: 0.5rem; }
        
        .allergens-box { background: #fef2f2; border: 1px solid #fecaca; border-radius: 8px; padding: 1rem; margin-top: 1rem; }
        .allergens-box h4 { color: #991b1b; font-size: 0.88rem; font-weight: 700; margin-bottom: 0.5rem; }
        .allergen-pill { background: #fee2e2; color: #991b1b; font-size: 0.75rem; padding: 2px 8px; border-radius: 999px; font-weight: 600; display: inline-block; margin-right: 4px; margin-bottom: 4px; }
    </style>
</head>
<body>
    <header class="header">
        <div class="logo-area">
            🥗 ClyoNutri II <span class="badge badge-green">Receitas</span>
        </div>
        <ul class="nav-links">
            <li><a href="/dashboard">📊 Dashboard</a></li>
            <li><a href="/planos">🥗 Planos Alimentares</a></li>
            <li><a href="/receitas" class="active">🍳 Receitas Culinárias</a></li>
            <li><a href="/chat">💬 Chat</a></li>
            <li><a href="/logout">🚪 Sair</a></li>
        </ul>
    </header>

    <div class="container">
        <a href="/receitas" class="back-link">← Voltar à Biblioteca de Receitas</a>

        <div class="recipe-header-card">
            <div class="header-top">
                <div>
                    <h1 class="recipe-title"><?= htmlspecialchars($recipe['title']) ?></h1>
                    <?php if (!empty($recipe['summary'])): ?>
                        <p class="recipe-summary"><?= htmlspecialchars($recipe['summary']) ?></p>
                    <?php endif; ?>
                    <div class="badges-bar">
                        <span class="badge badge-green"><?= htmlspecialchars($recipe['meal_type'] ?: $recipe['category']) ?></span>
                        <span class="badge">Dificuldade: <?= htmlspecialchars($recipe['difficulty']) ?></span>
                        <span class="badge">Preparo: <?= (int)$recipe['prep_time_minutes'] ?> min</span>
                        <?php if ((int)$recipe['cook_time_minutes'] > 0): ?>
                            <span class="badge">Cozimento: <?= (int)$recipe['cook_time_minutes'] ?> min</span>
                        <?php endif; ?>
                        <span class="badge">Rendimento: <?= (int)$recipe['servings'] ?> <?= htmlspecialchars($recipe['serving_size_name']) ?></span>
                        <span class="badge">Versão: v<?= (int)$recipe['current_version'] ?></span>
                    </div>
                </div>
            </div>

            <div class="actions-bar">
                <a href="/receitas/<?= (int)$recipe['id'] ?>/prescrever" class="btn btn-primary">📋 Prescrever ao Paciente</a>
                <form method="POST" action="/receitas/<?= (int)$recipe['id'] ?>/duplicar" style="display:inline;" onsubmit="return confirm('Deseja criar uma variante supervisionada desta receita?');">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                    <button type="submit" class="btn btn-outline">📑 Duplicar / Criar Variante</button>
                </form>
            </div>
        </div>

        <div class="grid-details">
            <div>
                <!-- Ingredientes e Medidas Caseiras -->
                <div class="section-box">
                    <h3 class="section-title">Ingredientes & Medidas Caseiras</h3>
                    <table class="ingredients-table">
                        <thead>
                            <tr>
                                <th>Alimento (Tabela TACO)</th>
                                <th>Medida Caseira</th>
                                <th>Peso Líquido</th>
                                <th>Observações</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($recipe['ingredients'] as $ing): ?>
                                <tr>
                                    <td><strong><?= htmlspecialchars($ing['food_name']) ?></strong></td>
                                    <td><?= (float)$ing['amount'] ?> <?= htmlspecialchars($ing['measure_unit']) ?></td>
                                    <td><?= round((float)$ing['net_weight_g'], 1) ?> g</td>
                                    <td style="color: var(--text-muted); font-size: 0.8rem;"><?= htmlspecialchars($ing['notes'] ?? '-') ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <!-- Modo de Preparo Passo a Passo -->
                <div class="section-box">
                    <h3 class="section-title">Modo de Preparo Passo a Passo</h3>
                    <?php if (!empty($recipe['steps'])): ?>
                        <div class="steps-list">
                            <?php foreach ($recipe['steps'] as $st): ?>
                                <div class="step-item">
                                    <div class="step-num"><?= (int)$st['step_number'] ?></div>
                                    <div class="step-content">
                                        <p><?= nl2br(htmlspecialchars($st['instruction'])) ?></p>
                                        <?php if (!empty($st['tip'])): ?>
                                            <span class="step-tip">💡 Dica: <?= htmlspecialchars($st['tip']) ?></span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <p style="white-space: pre-line;"><?= htmlspecialchars($recipe['instructions']) ?></p>
                    <?php endif; ?>
                </div>
            </div>

            <div>
                <!-- Composição Nutricional por Porção -->
                <div class="section-box">
                    <h3 class="section-title">Informação Nutricional</h3>
                    <p style="font-size: 0.8rem; color: var(--text-muted); margin-bottom: 0.75rem;">
                        Valores médios calculados <strong>por porção</strong> (<?= round((float)$recipe['serving_size_g'], 1) ?> g):
                    </p>

                    <div class="nutrition-card">
                        <div class="nutrition-row total-kcal">
                            <span>Energia</span>
                            <span><?= round((float)$recipe['energy_kcal_per_serving'], 1) ?> kcal</span>
                        </div>
                        <div class="nutrition-row">
                            <span>Proteínas</span>
                            <span><?= round((float)$recipe['protein_g_per_serving'], 1) ?> g</span>
                        </div>
                        <div class="nutrition-row">
                            <span>Carboidratos</span>
                            <span><?= round((float)$recipe['carbs_g_per_serving'], 1) ?> g</span>
                        </div>
                        <div class="nutrition-row">
                            <span>Lipídios Totais</span>
                            <span><?= round((float)$recipe['fat_g_per_serving'], 1) ?> g</span>
                        </div>
                        <div class="nutrition-row">
                            <span>Fibras Alimentares</span>
                            <span><?= round((float)$recipe['fiber_g_per_serving'], 1) ?> g</span>
                        </div>
                        <div class="nutrition-row">
                            <span>Sódio</span>
                            <span><?= round((float)$recipe['sodium_mg_per_serving'], 1) ?> mg</span>
                        </div>
                    </div>

                    <?php if (!empty($recipe['allergens'])): ?>
                        <div class="allergens-box">
                            <h4>⚠️ Alergênicos & Restrições</h4>
                            <div>
                                <?php foreach ($recipe['allergens'] as $alg): ?>
                                    <span class="allergen-pill"><?= htmlspecialchars($alg['allergen_group']) ?> (<?= htmlspecialchars($alg['risk_level']) ?>)</span>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php endif; ?>

                    <?php if (!empty($recipe['tags'])): ?>
                        <div style="margin-top: 1rem;">
                            <span style="font-size: 0.8rem; color: var(--text-muted); font-weight: 600;">Tags:</span>
                            <div style="margin-top: 0.35rem; display: flex; gap: 0.35rem; flex-wrap: wrap;">
                                <?php foreach ($recipe['tags'] as $tg): ?>
                                    <span style="font-size: 0.75rem; background: #f1f5f9; padding: 2px 8px; border-radius: 4px; color: #475569;">#<?= htmlspecialchars($tg['tag_name']) ?></span>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</body>
</html>
        <?php
    }

    /**
     * Formulário de Criação de Receita
     */
    public function create(array $params = []): void
    {
        $auth = $this->getAuthInfo();

        // Buscar alimentos do catálogo para seleção rápida
        $stmt = $this->db->query("SELECT id, name, category, energy_kcal, protein_g, carbohydrate_g, lipid_g FROM foods ORDER BY name ASC LIMIT 300");
        $foods = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $csrfToken = Csrf::generateToken();
        header('Content-Type: text/html; charset=utf-8');
        ?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Nova Receita Culinária — ClyoNutri II</title>
    <style>
        :root {
            --primary: #059669;
            --primary-hover: #047857;
            --primary-light: #ecfdf5;
            --surface: #ffffff;
            --bg: #f8fafc;
            --border: #e2e8f0;
            --text-main: #0f172a;
            --text-muted: #64748b;
        }
        * { box-sizing: border-box; margin: 0; padding: 0; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif; }
        body { background: var(--bg); color: var(--text-main); }
        .header { background: var(--surface); border-bottom: 1px solid var(--border); padding: 0.85rem 1.75rem; display: flex; align-items: center; justify-content: space-between; }
        .logo-area { display: flex; align-items: center; gap: 0.75rem; font-weight: 700; font-size: 1.15rem; color: var(--primary); }
        .container { max-width: 900px; margin: 2rem auto; padding: 0 1.5rem; }
        .card { background: #fff; border: 1px solid var(--border); border-radius: 12px; padding: 2rem; margin-bottom: 1.5rem; }
        .card h2 { font-size: 1.5rem; color: #064e3b; margin-bottom: 1.5rem; }
        
        .form-row { display: grid; grid-template-columns: 1fr 1fr; gap: 1rem; margin-bottom: 1rem; }
        @media (max-width: 640px) { .form-row { grid-template-columns: 1fr; } }
        .form-group { display: flex; flex-direction: column; gap: 0.35rem; margin-bottom: 1rem; }
        .form-group label { font-size: 0.85rem; font-weight: 600; color: #334155; }
        .form-group input, .form-group select, .form-group textarea {
            padding: 0.65rem 0.85rem; border: 1px solid var(--border); border-radius: 6px; font-size: 0.9rem;
        }
        
        .btn { display: inline-flex; align-items: center; justify-content: center; gap: 0.5rem; padding: 0.65rem 1.25rem; border-radius: 8px; font-size: 0.9rem; font-weight: 600; cursor: pointer; border: none; text-decoration: none; }
        .btn-primary { background: var(--primary); color: #fff; }
        .btn-primary:hover { background: var(--primary-hover); }
        .btn-outline { background: #fff; border: 1px solid var(--border); color: var(--text-main); }
        
        .item-row { display: grid; grid-template-columns: 3fr 1fr 1.5fr auto; gap: 0.5rem; align-items: center; margin-bottom: 0.5rem; }
        .btn-del { background: #fee2e2; color: #b91c1c; border: none; border-radius: 6px; padding: 0.5rem 0.75rem; cursor: pointer; font-weight: bold; }
    </style>
</head>
<body>
    <header class="header">
        <div class="logo-area">🥗 ClyoNutri II <span style="font-size: 0.8rem; background: var(--primary-light); padding: 2px 8px; border-radius: 999px;">Criador</span></div>
        <a href="/receitas" class="btn btn-outline">Cancelar</a>
    </header>

    <div class="container">
        <div class="card">
            <h2>Criar Nova Receita Culinária</h2>

            <form method="POST" action="/receitas" id="recipeForm">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">

                <div class="form-group">
                    <label>Título da Receita *</label>
                    <input type="text" name="title" required placeholder="Ex: Crepioca Funcional de Frango com Queijo Branco">
                </div>

                <div class="form-group">
                    <label>Resumo / Apresentação</label>
                    <input type="text" name="summary" placeholder="Ex: Opção prática, rica em proteínas e fibras para café da manhã ou lanche.">
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label>Categoria / Refeição *</label>
                        <select name="meal_type" required>
                            <option value="Café da Manhã">Café da Manhã</option>
                            <option value="Almoço">Almoço</option>
                            <option value="Lanche da Tarde">Lanche da Tarde</option>
                            <option value="Jantar">Jantar</option>
                            <option value="Ceia">Ceia</option>
                            <option value="Pré-Treino">Pré-Treino</option>
                            <option value="Pós-Treino">Pós-Treino</option>
                            <option value="Sobremesa">Sobremesa</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Dificuldade</label>
                        <select name="difficulty">
                            <option value="fácil">Fácil</option>
                            <option value="média">Média</option>
                            <option value="difícil">Difícil</option>
                        </select>
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label>Rendimento (Porções) *</label>
                        <input type="number" name="servings" min="1" value="1" required>
                    </div>
                    <div class="form-group">
                        <label>Nome da Porção</label>
                        <input type="text" name="serving_size_name" value="porção" placeholder="Ex: porção, unidade, fatia">
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label>Tempo de Preparo (min)</label>
                        <input type="number" name="prep_time_minutes" min="1" value="15">
                    </div>
                    <div class="form-group">
                        <label>Tempo de Cozimento / Fogo (min)</label>
                        <input type="number" name="cook_time_minutes" min="0" value="10">
                    </div>
                </div>

                <!-- Lista Dinâmica de Ingredientes -->
                <div style="margin: 1.5rem 0; border-top: 1px solid var(--border); padding-top: 1.25rem;">
                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.75rem;">
                        <label style="font-weight: 700; font-size: 1rem; color: #064e3b;">Ingredientes Oficiais (Tabela TACO) *</label>
                        <button type="button" class="btn btn-outline" onclick="addIngredientRow()">➕ Adicionar Ingrediente</button>
                    </div>
                    
                    <div id="ingredientsContainer">
                        <div class="item-row">
                            <select name="ingredients[0][food_id]" required>
                                <option value="">Selecione o alimento...</option>
                                <?php foreach ($foods as $f): ?>
                                    <option value="<?= (int)$f['id'] ?>"><?= htmlspecialchars($f['name']) ?> (<?= htmlspecialchars($f['category'] ?? '') ?>)</option>
                                <?php endforeach; ?>
                            </select>
                            <input type="number" step="0.1" name="ingredients[0][amount]" placeholder="Qtd" value="100" required>
                            <select name="ingredients[0][measure_unit]">
                                <option value="g">g (gramas)</option>
                                <option value="colher de sopa">colher de sopa</option>
                                <option value="colher de chá">colher de chá</option>
                                <option value="xícara de chá">xícara de chá</option>
                                <option value="unidade">unidade</option>
                                <option value="fatia">fatia</option>
                                <option value="ml">ml</option>
                            </select>
                            <button type="button" class="btn-del" onclick="this.parentElement.remove()">✕</button>
                        </div>
                    </div>
                </div>

                <!-- Passos de Preparo -->
                <div style="margin: 1.5rem 0; border-top: 1px solid var(--border); padding-top: 1.25rem;">
                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.75rem;">
                        <label style="font-weight: 700; font-size: 1rem; color: #064e3b;">Modo de Preparo (Passos)</label>
                        <button type="button" class="btn btn-outline" onclick="addStepRow()">➕ Adicionar Passo</button>
                    </div>
                    <div id="stepsContainer">
                        <div style="margin-bottom: 0.5rem; display: flex; gap: 0.5rem;">
                            <input type="text" name="steps[]" placeholder="Passo 1: Em uma tigela, misture todos os ingredientes..." style="flex: 1;" required>
                            <button type="button" class="btn-del" onclick="this.parentElement.remove()">✕</button>
                        </div>
                    </div>
                </div>

                <!-- Tags & Visibilidade -->
                <div class="form-row">
                    <div class="form-group">
                        <label>Tags (separadas por vírgula)</label>
                        <input type="text" name="tags" placeholder="Ex: proteico, rápido, sem glúten, funcional">
                    </div>
                    <div class="form-group">
                        <label>Visibilidade</label>
                        <select name="visibility">
                            <option value="clinic">Privada da Clínica</option>
                            <option value="public">Pública / Compartilhada</option>
                        </select>
                    </div>
                </div>

                <div style="margin-top: 1.5rem; display: flex; justify-content: flex-end; gap: 1rem;">
                    <a href="/receitas" class="btn btn-outline">Cancelar</a>
                    <button type="submit" class="btn btn-primary">Salvar & Calcular Receita</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        let ingredientIndex = 1;
        const foodOptionsHtml = `<?php
            foreach ($foods as $f) {
                echo '<option value="' . (int)$f['id'] . '">' . htmlspecialchars(addslashes($f['name'])) . '</option>';
            }
        ?>`;

        function addIngredientRow() {
            const container = document.getElementById('ingredientsContainer');
            const row = document.createElement('div');
            row.className = 'item-row';
            row.innerHTML = `
                <select name="ingredients[${ingredientIndex}][food_id]" required>
                    <option value="">Selecione o alimento...</option>
                    ${foodOptionsHtml}
                </select>
                <input type="number" step="0.1" name="ingredients[${ingredientIndex}][amount]" placeholder="Qtd" value="100" required>
                <select name="ingredients[${ingredientIndex}][measure_unit]">
                    <option value="g">g (gramas)</option>
                    <option value="colher de sopa">colher de sopa</option>
                    <option value="colher de chá">colher de chá</option>
                    <option value="xícara de chá">xícara de chá</option>
                    <option value="unidade">unidade</option>
                    <option value="fatia">fatia</option>
                    <option value="ml">ml</option>
                </select>
                <button type="button" class="btn-del" onclick="this.parentElement.remove()">✕</button>
            `;
            container.appendChild(row);
            ingredientIndex++;
        }

        function addStepRow() {
            const container = document.getElementById('stepsContainer');
            const stepNum = container.children.length + 1;
            const row = document.createElement('div');
            row.style.marginBottom = '0.5rem';
            row.style.display = 'flex';
            row.style.gap = '0.5rem';
            row.innerHTML = `
                <input type="text" name="steps[]" placeholder="Passo ${stepNum}: ..." style="flex: 1;" required>
                <button type="button" class="btn-del" onclick="this.parentElement.remove()">✕</button>
            `;
            container.appendChild(row);
        }
    </script>
</body>
</html>
        <?php
    }

    /**
     * Salva nova receita (POST)
     */
    public function store(array $params = []): void
    {
        $auth = $this->getAuthInfo();

        $csrfToken = (string)($_POST['csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ''));
        if (!Csrf::validateToken($csrfToken)) {
            http_response_code(403);
            echo "Token CSRF inválido.";
            return;
        }

        $title = trim((string)($_POST['title'] ?? ''));
        $servings = max(1, (int)($_POST['servings'] ?? 1));

        $ingredients = [];
        if (!empty($_POST['ingredients']) && is_array($_POST['ingredients'])) {
            foreach ($_POST['ingredients'] as $ing) {
                if (!empty($ing['food_id']) && (float)($ing['amount'] ?? 0) > 0) {
                    $ingredients[] = [
                        'food_id' => (int)$ing['food_id'],
                        'amount' => (float)$ing['amount'],
                        'measure_unit' => (string)($ing['measure_unit'] ?? 'g'),
                    ];
                }
            }
        }

        if (empty($ingredients)) {
            http_response_code(400);
            echo "A receita precisa de ao menos um ingrediente.";
            return;
        }

        $steps = [];
        if (!empty($_POST['steps']) && is_array($_POST['steps'])) {
            foreach ($_POST['steps'] as $st) {
                $st = trim((string)$st);
                if ($st !== '') {
                    $steps[] = ['instruction' => $st];
                }
            }
        }

        $tags = [];
        if (!empty($_POST['tags'])) {
            $tags = array_filter(array_map('trim', explode(',', (string)$_POST['tags'])));
        }

        $recipeData = [
            'title' => $title,
            'summary' => trim((string)($_POST['summary'] ?? '')),
            'meal_type' => trim((string)($_POST['meal_type'] ?? 'Almoço')),
            'difficulty' => trim((string)($_POST['difficulty'] ?? 'fácil')),
            'prep_time_minutes' => (int)($_POST['prep_time_minutes'] ?? 15),
            'cook_time_minutes' => (int)($_POST['cook_time_minutes'] ?? 0),
            'servings' => $servings,
            'serving_size_name' => trim((string)($_POST['serving_size_name'] ?? 'porção')),
            'visibility' => trim((string)($_POST['visibility'] ?? 'clinic')),
            'author_user_id' => $auth['userId'],
        ];

        try {
            $created = $this->recipeService->createRecipe($recipeData, $ingredients, $steps, $tags);
            header('Location: /receitas/' . (int)$created['id']);
            exit;
        } catch (\Throwable $e) {
            http_response_code(400);
            echo "Erro ao criar receita: " . htmlspecialchars($e->getMessage());
        }
    }

    /**
     * Formulário de Prescrição ao Paciente
     */
    public function prescribeForm(array $params = []): void
    {
        $auth = $this->getAuthInfo();
        $recipeId = (int)($params['id'] ?? 0);

        try {
            $recipe = $this->recipeService->getRecipeDetails($recipeId, $auth['clinicId']);
        } catch (\Throwable $e) {
            http_response_code(404);
            echo "Receita não encontrada.";
            return;
        }

        // Buscar pacientes da clínica
        $pStmt = $this->db->prepare("SELECT id, name FROM patients WHERE clinic_id = :cid ORDER BY name ASC");
        $pStmt->execute(['cid' => $auth['clinicId']]);
        $patients = $pStmt->fetchAll(PDO::FETCH_ASSOC);

        $selectedPatientId = (int)($_GET['patient_id'] ?? ($patients[0]['id'] ?? 0));

        // Buscar planos do paciente selecionado
        $plans = [];
        $patientAllergies = [];
        if ($selectedPatientId > 0) {
            $plStmt = $this->db->prepare("SELECT id, title FROM meal_plans WHERE patient_id = :pid ORDER BY id DESC");
            $plStmt->execute(['pid' => $selectedPatientId]);
            $plans = $plStmt->fetchAll(PDO::FETCH_ASSOC);

            // Verificar histórico de anamnese / alergias
            $anStmt = $this->db->prepare("SELECT allergies, restrictions FROM anamneses WHERE patient_id = :pid ORDER BY id DESC LIMIT 1");
            $anStmt->execute(['pid' => $selectedPatientId]);
            $anamnese = $anStmt->fetch(PDO::FETCH_ASSOC);
            if ($anamnese) {
                $rawAllergies = trim((string)($anamnese['allergies'] ?? '') . ' ' . (string)($anamnese['restrictions'] ?? ''));
                if ($rawAllergies !== '') {
                    $patientAllergies = array_filter(array_map('trim', preg_split('/[,;\n]+/', $rawAllergies)));
                }
            }
        }

        $csrfToken = Csrf::generateToken();
        header('Content-Type: text/html; charset=utf-8');
        ?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Prescrever Receita — <?= htmlspecialchars($recipe['title']) ?></title>
    <style>
        :root {
            --primary: #059669;
            --primary-hover: #047857;
            --primary-light: #ecfdf5;
            --surface: #ffffff;
            --bg: #f8fafc;
            --border: #e2e8f0;
            --text-main: #0f172a;
            --text-muted: #64748b;
        }
        * { box-sizing: border-box; margin: 0; padding: 0; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif; }
        body { background: var(--bg); color: var(--text-main); }
        .header { background: var(--surface); border-bottom: 1px solid var(--border); padding: 0.85rem 1.75rem; display: flex; align-items: center; justify-content: space-between; }
        .logo-area { display: flex; align-items: center; gap: 0.75rem; font-weight: 700; font-size: 1.15rem; color: var(--primary); }
        .container { max-width: 700px; margin: 2rem auto; padding: 0 1.5rem; }
        .card { background: #fff; border: 1px solid var(--border); border-radius: 12px; padding: 2rem; }
        .card h2 { font-size: 1.4rem; color: #064e3b; margin-bottom: 0.5rem; }
        .form-group { display: flex; flex-direction: column; gap: 0.35rem; margin-bottom: 1.1rem; }
        .form-group label { font-size: 0.85rem; font-weight: 600; color: #334155; }
        .form-group input, .form-group select, .form-group textarea {
            padding: 0.65rem 0.85rem; border: 1px solid var(--border); border-radius: 6px; font-size: 0.9rem;
        }
        .btn { display: inline-flex; align-items: center; justify-content: center; gap: 0.5rem; padding: 0.65rem 1.25rem; border-radius: 8px; font-size: 0.9rem; font-weight: 600; cursor: pointer; border: none; text-decoration: none; }
        .btn-primary { background: var(--primary); color: #fff; }
        .btn-outline { background: #fff; border: 1px solid var(--border); color: var(--text-main); }
        .warning-box { background: #fef2f2; border: 1px solid #fecaca; border-radius: 8px; padding: 1rem; margin-bottom: 1rem; color: #991b1b; font-size: 0.85rem; }
    </style>
</head>
<body>
    <header class="header">
        <div class="logo-area">🥗 ClyoNutri II <span style="font-size: 0.8rem; background: var(--primary-light); padding: 2px 8px; border-radius: 999px;">Prescrição</span></div>
        <a href="/receitas/<?= (int)$recipe['id'] ?>" class="btn btn-outline">Cancelar</a>
    </header>

    <div class="container">
        <div class="card">
            <h2>Prescrever Receita ao Paciente</h2>
            <p style="color: var(--text-muted); font-size: 0.9rem; margin-bottom: 1.5rem;">
                Receita selecionada: <strong><?= htmlspecialchars($recipe['title']) ?></strong> (<?= (int)$recipe['energy_kcal_per_serving'] ?> kcal/porção)
            </p>

            <?php if (!empty($patientAllergies)): ?>
                <div class="warning-box">
                    <strong>⚠️ Alerta Clínico de Alergias do Paciente:</strong>
                    <p>O prontuário deste paciente registra: <?= htmlspecialchars(implode(', ', $patientAllergies)) ?>.</p>
                </div>
            <?php endif; ?>

            <form method="POST" action="/receitas/<?= (int)$recipe['id'] ?>/prescrever">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">

                <div class="form-group">
                    <label>Paciente *</label>
                    <select name="patient_id" required onchange="window.location.href='/receitas/<?= (int)$recipe['id'] ?>/prescrever?patient_id=' + this.value">
                        <?php foreach ($patients as $p): ?>
                            <option value="<?= (int)$p['id'] ?>" <?= ((int)$p['id'] === $selectedPatientId) ? 'selected' : '' ?>>
                                <?= htmlspecialchars($p['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group">
                    <label>Plano Alimentar de Destino *</label>
                    <select name="meal_plan_id" required>
                        <?php if (empty($plans)): ?>
                            <option value="0">Plano Ativo Padrão</option>
                        <?php else: ?>
                            <?php foreach ($plans as $pl): ?>
                                <option value="<?= (int)$pl['id'] ?>"><?= htmlspecialchars($pl['title']) ?></option>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </select>
                </div>

                <div class="form-group">
                    <label>Refeição Prescrita *</label>
                    <select name="meal_name" required>
                        <option value="Café da Manhã" <?= ($recipe['meal_type'] === 'Café da Manhã') ? 'selected' : '' ?>>Café da Manhã</option>
                        <option value="Colação">Colação</option>
                        <option value="Almoço" <?= ($recipe['meal_type'] === 'Almoço') ? 'selected' : '' ?>>Almoço</option>
                        <option value="Lanche da Tarde" <?= ($recipe['meal_type'] === 'Lanche da Tarde') ? 'selected' : '' ?>>Lanche da Tarde</option>
                        <option value="Jantar" <?= ($recipe['meal_type'] === 'Jantar') ? 'selected' : '' ?>>Jantar</option>
                        <option value="Ceia" <?= ($recipe['meal_type'] === 'Ceia') ? 'selected' : '' ?>>Ceia</option>
                        <option value="Pré-Treino" <?= ($recipe['meal_type'] === 'Pré-Treino') ? 'selected' : '' ?>>Pré-Treino</option>
                        <option value="Pós-Treino" <?= ($recipe['meal_type'] === 'Pós-Treino') ? 'selected' : '' ?>>Pós-Treino</option>
                    </select>
                </div>

                <div class="form-group">
                    <label>Quantidade de Porções a Consumir *</label>
                    <input type="number" step="0.5" name="servings" value="1.0" min="0.5" max="10.0" required>
                </div>

                <div class="form-group">
                    <label>Orientações Específicas / Observações Clínicas</label>
                    <textarea name="instructions" rows="3" placeholder="Ex: Consumir morno. Pode substituir queijo minas por cottage se preferir."></textarea>
                </div>

                <div style="margin-top: 1.5rem; display: flex; justify-content: flex-end; gap: 1rem;">
                    <a href="/receitas/<?= (int)$recipe['id'] ?>" class="btn btn-outline">Cancelar</a>
                    <button type="submit" class="btn btn-primary">Confirmar Prescrição</button>
                </div>
            </form>
        </div>
    </div>
</body>
</html>
        <?php
    }

    /**
     * Confirma prescrição (POST)
     */
    public function prescribe(array $params = []): void
    {
        $auth = $this->getAuthInfo();
        $recipeId = (int)($params['id'] ?? 0);

        $csrfToken = (string)($_POST['csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ''));
        if (!Csrf::validateToken($csrfToken)) {
            http_response_code(403);
            echo "Token CSRF inválido.";
            return;
        }

        $patientId = (int)($_POST['patient_id'] ?? 0);
        $mealPlanId = (int)($_POST['meal_plan_id'] ?? 0);
        $mealName = trim((string)($_POST['meal_name'] ?? 'Almoço'));
        $servings = (float)($_POST['servings'] ?? 1.0);
        $instructions = trim((string)($_POST['instructions'] ?? ''));

        // Se meal_plan_id for 0, buscar ou criar plano ativo
        if ($mealPlanId <= 0) {
            $stmt = $this->db->prepare("SELECT id FROM meal_plans WHERE patient_id = :pid ORDER BY id DESC LIMIT 1");
            $stmt->execute(['pid' => $patientId]);
            $mealPlanId = (int)$stmt->fetchColumn();

            if ($mealPlanId <= 0) {
                // Criar plano automático
                $cStmt = $this->db->prepare("
                    INSERT INTO meal_plans (clinic_id, patient_id, professional_id, title, status, created_at, updated_at)
                    VALUES (:cid, :pid, :prid, 'Plano Alimentar Individualizado', 'active', CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)
                ");
                $cStmt->execute([
                    'cid' => $auth['clinicId'],
                    'pid' => $patientId,
                    'prid' => $auth['profId'],
                ]);
                $mealPlanId = (int)$this->db->lastInsertId();
            }
        }

        try {
            $this->recipeService->prescribeRecipe(
                $auth['clinicId'],
                $mealPlanId,
                null,
                $patientId,
                $recipeId,
                $servings,
                $instructions,
                $mealName
            );

            header('Location: /receitas/' . $recipeId . '?prescrito=sucesso');
            exit;
        } catch (\Throwable $e) {
            http_response_code(400);
            echo "Erro ao prescrever receita: " . htmlspecialchars($e->getMessage());
        }
    }

    /**
     * Duplica uma receita como variante supervisionada
     */
    public function duplicate(array $params = []): void
    {
        $auth = $this->getAuthInfo();
        $recipeId = (int)($params['id'] ?? 0);

        $csrfToken = (string)($_POST['csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ''));
        if (!Csrf::validateToken($csrfToken)) {
            http_response_code(403);
            echo "Token CSRF inválido.";
            return;
        }

        try {
            $original = $this->recipeService->getRecipeDetails($recipeId, $auth['clinicId']);
            $newTitle = $original['title'] . ' (Variante Adaptada)';

            $variant = $this->recipeService->duplicateAsVariant($recipeId, $newTitle);
            header('Location: /receitas/' . (int)$variant['id']);
            exit;
        } catch (\Throwable $e) {
            http_response_code(400);
            echo "Erro ao duplicar receita: " . htmlspecialchars($e->getMessage());
        }
    }
}
