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

        // Buscar Nome da Clínica
        $cStmt = $this->db->prepare("SELECT name FROM clinics WHERE id = :cid LIMIT 1");
        $cStmt->execute(['cid' => $clinicId]);
        $clinicName = (string)($cStmt->fetchColumn() ?: ($_SESSION['clinic_name'] ?? 'Clínica Principal'));

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
            'clinicName' => $clinicName,
            'profId' => $profId > 0 ? $profId : 1,
        ];
    }

    /**
     * Biblioteca e Catálogo de Receitas Culinárias (Design System ClyoNutri)
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

        // Parâmetros para o layout padrão ClyoNutri
        $title = 'Receitas Culinárias & Composição Nutricional';
        $activeNav = 'receitas';
        $clinicName = $auth['clinicName'];
        $userName = $auth['userName'];
        $csrfToken = Csrf::token();

        ob_start();
        ?>
        <!-- Alertas de Feedback -->
        <?php if (!empty($_GET['prescrito'])): ?>
          <div style="background: var(--semantic-success-light); border-left: 4px solid var(--semantic-success); padding: 12px 16px; border-radius: var(--radius-input); margin-bottom: var(--space-4); color: var(--semantic-success); font-weight: 500;">
            ✓ Receita prescrita com sucesso ao paciente!
          </div>
        <?php endif; ?>

        <!-- Cabeçalho do Design System ClyoNutri -->
        <div style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: var(--space-6); flex-wrap: wrap; gap: 16px;">
          <div>
            <h1 style="font-family: var(--font-editorial); font-size: 1.85rem; color: var(--brand-navy-900); margin-bottom: var(--space-1);">Biblioteca de Receitas Culinárias</h1>
            <p style="color: var(--neutral-gray-500); margin: 0; font-size: 0.95rem;">Receitas padronizadas, cálculos nutricionais oficiais TACO, medidas caseiras e prescrição ao paciente.</p>
          </div>
          <div>
            <a href="/receitas/nova" class="btn btn-accent">
              <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
              Nova Receita
            </a>
          </div>
        </div>

        <!-- Painel de Filtros e Busca -->
        <div class="card" style="padding: var(--space-4); margin-bottom: var(--space-6);">
          <form method="GET" action="/receitas" style="display: flex; gap: var(--space-4); flex-wrap: wrap; align-items: flex-end;">
            <div style="flex: 2; min-width: 220px; display: flex; flex-direction: column; gap: var(--space-1);">
              <label for="rc1-q" style="font-size: 0.8rem; font-weight: 600; color: var(--brand-navy-900);">Buscar Receita ou Ingrediente</label>
              <input id="rc1-q" type="text" name="q" value="<?= htmlspecialchars($search) ?>" placeholder="Ex: Omelete, Mingau de Aveia, Frango..." style="padding: 10px 14px; border: 1px solid var(--neutral-gray-300); border-radius: var(--radius-input); font-size: 0.9rem; font-family: var(--font-body); width: 100%;">
            </div>
            <div style="flex: 1; min-width: 160px; display: flex; flex-direction: column; gap: var(--space-1);">
              <label for="rc2-meal_type" style="font-size: 0.8rem; font-weight: 600; color: var(--brand-navy-900);">Refeição</label>
              <select id="rc2-meal_type" name="meal_type" style="padding: 10px 14px; border: 1px solid var(--neutral-gray-300); border-radius: var(--radius-input); font-size: 0.9rem; font-family: var(--font-body); width: 100%; background: #fff;">
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
            <div style="flex: 1; min-width: 140px; display: flex; flex-direction: column; gap: var(--space-1);">
              <label for="rc3-difficulty" style="font-size: 0.8rem; font-weight: 600; color: var(--brand-navy-900);">Dificuldade</label>
              <select id="rc3-difficulty" name="difficulty" style="padding: 10px 14px; border: 1px solid var(--neutral-gray-300); border-radius: var(--radius-input); font-size: 0.9rem; font-family: var(--font-body); width: 100%; background: #fff;">
                <option value="">Qualquer dificuldade</option>
                <option value="fácil" <?= $difficulty === 'fácil' ? 'selected' : '' ?>>Fácil</option>
                <option value="média" <?= $difficulty === 'média' ? 'selected' : '' ?>>Média</option>
                <option value="difícil" <?= $difficulty === 'difícil' ? 'selected' : '' ?>>Difícil</option>
              </select>
            </div>
            <div style="flex: 1; min-width: 140px; display: flex; flex-direction: column; gap: var(--space-1);">
              <label for="rc4-max_kcal" style="font-size: 0.8rem; font-weight: 600; color: var(--brand-navy-900);">Kcal Máxima / Porção</label>
              <input id="rc4-max_kcal" type="number" step="10" name="max_kcal" value="<?= $maxKcal !== null ? (int)$maxKcal : '' ?>" placeholder="Ex: 400" style="padding: 10px 14px; border: 1px solid var(--neutral-gray-300); border-radius: var(--radius-input); font-size: 0.9rem; font-family: var(--font-body); width: 100%;">
            </div>
            <div>
              <button type="submit" class="btn btn-secondary" style="min-height: 42px;">Filtrar</button>
            </div>
          </form>
        </div>

        <!-- Grade de Receitas -->
        <?php if (empty($recipes)): ?>
          <div class="card" style="text-align: center; padding: var(--space-12) var(--space-4);">
            <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" style="color: var(--brand-copper-500); margin: 0 auto var(--space-3);"><path d="M6 13.87A4 4 0 0 1 7.41 6a5.11 5.11 0 0 1 1.05-1.54 5 5 0 0 1 7.08 0A5.11 5.11 0 0 1 16.59 6 4 4 0 0 1 18 13.87V21H6Z"/><line x1="6" y1="17" x2="18" y2="17"/></svg>
            <h3 style="color: var(--brand-navy-900); margin-bottom: var(--space-2);">Nenhuma receita encontrada</h3>
            <p style="color: var(--neutral-gray-500); margin-bottom: var(--space-4); max-width: 480px; margin-left: auto; margin-right: auto;">
              Crie receitas culinárias personalizadas com cálculo automatizado de macronutrientes da Tabela TACO e medidas caseiras.
            </p>
            <a href="/receitas/nova" class="btn btn-accent">Criar Primeira Receita</a>
          </div>
        <?php else: ?>
          <div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(320px, 1fr)); gap: var(--space-6);">
            <?php foreach ($recipes as $r): ?>
              <div class="card" style="display: flex; flex-direction: column; padding: 0; overflow: hidden; margin-bottom: 0;">
                <div style="padding: var(--space-5); border-bottom: 1px solid var(--neutral-gray-200);">
                  <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: var(--space-2);">
                    <span style="font-size: 0.72rem; font-weight: 700; text-transform: uppercase; background: var(--neutral-warm-50); color: var(--brand-copper-600); border: 1px solid #EAD8C7; padding: 2px 8px; border-radius: var(--radius-pill);">
                      <?= htmlspecialchars($r['meal_type'] ?: $r['category']) ?>
                    </span>
                    <span style="font-size: 0.72rem; font-weight: 600; text-transform: uppercase; color: var(--neutral-gray-500);">
                      <?= htmlspecialchars(\ClyoNutri\Core\I18n\LabelMap::recipeDifficulty($r['difficulty'] ?? null)) ?>
                    </span>
                  </div>
                  <h3 style="font-size: 1.15rem; color: var(--brand-navy-900); margin-bottom: var(--space-1); line-height: 1.3;">
                    <a href="/receitas/<?= (int)$r['id'] ?>" style="color: inherit; text-decoration: none;">
                      <?= htmlspecialchars($r['title']) ?>
                    </a>
                  </h3>
                  <?php if (!empty($r['summary'])): ?>
                    <p style="font-size: 0.85rem; color: var(--neutral-gray-500); margin: 0; display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden;">
                      <?= htmlspecialchars($r['summary']) ?>
                    </p>
                  <?php endif; ?>
                </div>

                <!-- Painel de Macronutrientes por Porção -->
                <div style="background: var(--neutral-warm-50); padding: var(--space-3) var(--space-4); display: grid; grid-template-columns: repeat(4, 1fr); text-align: center; border-bottom: 1px solid var(--neutral-gray-200);">
                  <div>
                    <span style="display: block; font-size: 1rem; font-weight: 700; color: var(--brand-navy-900);"><?= (int)round((float)$r['energy_kcal_per_serving']) ?></span>
                    <span style="font-size: 0.68rem; text-transform: uppercase; color: var(--neutral-gray-500); font-weight: 600;">kcal</span>
                  </div>
                  <div>
                    <span style="display: block; font-size: 1rem; font-weight: 700; color: var(--brand-navy-900);"><?= round((float)$r['protein_g_per_serving'], 1) ?>g</span>
                    <span style="font-size: 0.68rem; text-transform: uppercase; color: var(--neutral-gray-500); font-weight: 600;">Prot</span>
                  </div>
                  <div>
                    <span style="display: block; font-size: 1rem; font-weight: 700; color: var(--brand-navy-900);"><?= round((float)$r['carbs_g_per_serving'], 1) ?>g</span>
                    <span style="font-size: 0.68rem; text-transform: uppercase; color: var(--neutral-gray-500); font-weight: 600;">Carb</span>
                  </div>
                  <div>
                    <span style="display: block; font-size: 1rem; font-weight: 700; color: var(--brand-navy-900);"><?= round((float)$r['fat_g_per_serving'], 1) ?>g</span>
                    <span style="font-size: 0.68rem; text-transform: uppercase; color: var(--neutral-gray-500); font-weight: 600;">Gord</span>
                  </div>
                </div>

                <div style="padding: var(--space-4); flex-grow: 1; display: flex; flex-direction: column; justify-content: space-between; gap: var(--space-3);">
                  <div style="display: flex; justify-content: space-between; font-size: 0.8rem; color: var(--neutral-gray-500);">
                    <span>⏱️ <?= (int)$r['prep_time_minutes'] ?> min preparo</span>
                    <span>🍽️ <?= (int)$r['servings'] ?> porção(ões)</span>
                  </div>

                  <?php
                  $alStmt = $this->db->prepare("SELECT allergen_group FROM recipe_allergens WHERE recipe_id = :rid");
                  $alStmt->execute(['rid' => (int)$r['id']]);
                  $alList = array_unique(array_map([\ClyoNutri\Core\I18n\LabelMap::class, 'allergen'], $alStmt->fetchAll(PDO::FETCH_COLUMN)));
                  ?>
                  <?php if (!empty($alList)): ?>
                    <div style="display: flex; gap: var(--space-1); flex-wrap: wrap;">
                      <?php foreach ($alList as $alg): ?>
                        <span style="font-size: 0.7rem; background: var(--semantic-error-light); color: var(--semantic-error); padding: 2px 6px; border-radius: 4px; font-weight: 600;">
                          ⚠️ <?= htmlspecialchars($alg) ?>
                        </span>
                      <?php endforeach; ?>
                    </div>
                  <?php endif; ?>

                  <div style="display: flex; gap: var(--space-2); margin-top: var(--space-2);">
                    <a href="/receitas/<?= (int)$r['id'] ?>" class="btn btn-secondary" style="flex: 1; min-height: 38px; font-size: 0.85rem;">Detalhes</a>
                    <a href="/receitas/<?= (int)$r['id'] ?>/prescrever" class="btn btn-primary" style="flex: 1; min-height: 38px; font-size: 0.85rem;">Prescrever</a>
                  </div>
                </div>
              </div>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
        <?php
        $content = ob_get_clean();
        require __DIR__ . '/../../../views/layouts/professional-shell.php';
    }

    /**
     * Visualização Detalhada da Receita (Design System ClyoNutri)
     */
    public function show(array $params = []): void
    {
        $auth = $this->getAuthInfo();
        $recipeId = (int)($params['id'] ?? 0);

        try {
            $recipe = $this->recipeService->getRecipeDetails($recipeId, $auth['clinicId']);
        } catch (\Throwable $e) {
            http_response_code(404);
            echo "<h1>Receita não encontrada ou sem permissão de acesso.</h1>";
            return;
        }

        $title = $recipe['title'] . ' — Receita';
        $activeNav = 'receitas';
        $clinicName = $auth['clinicName'];
        $userName = $auth['userName'];
        $csrfToken = Csrf::token();

        ob_start();
        ?>
        <!-- Breadcrumb / Voltar -->
        <div style="font-size: 0.85rem; color: var(--neutral-gray-500); margin-bottom: var(--space-4);">
          <a href="/receitas" style="color: var(--brand-copper-600); text-decoration: none; font-weight: 600;">← Voltar à Biblioteca de Receitas</a>
        </div>

        <!-- Cartão Superior de Apresentação -->
        <div class="card" style="margin-bottom: var(--space-6);">
          <div style="display: flex; justify-content: space-between; align-items: flex-start; flex-wrap: wrap; gap: var(--space-4);">
            <div>
              <span style="font-size: 0.75rem; font-weight: 700; text-transform: uppercase; background: var(--neutral-warm-50); color: var(--brand-copper-600); border: 1px solid #EAD8C7; padding: 3px 10px; border-radius: var(--radius-pill); display: inline-block; margin-bottom: var(--space-2);">
                <?= htmlspecialchars($recipe['meal_type'] ?: $recipe['category']) ?>
              </span>
              <h1 style="font-family: var(--font-editorial); font-size: 2rem; color: var(--brand-navy-900); margin-bottom: var(--space-2);"><?= htmlspecialchars($recipe['title']) ?></h1>
              <?php if (!empty($recipe['summary'])): ?>
                <p style="color: var(--neutral-gray-500); font-size: 1rem; margin-bottom: var(--space-3);"><?= htmlspecialchars($recipe['summary']) ?></p>
              <?php endif; ?>

              <div style="display: flex; gap: var(--space-2); flex-wrap: wrap; font-size: 0.8rem; color: var(--neutral-gray-500);">
                <span style="background: var(--neutral-gray-100); padding: 4px 10px; border-radius: var(--radius-input);">Dificuldade: <strong><?= htmlspecialchars(\ClyoNutri\Core\I18n\LabelMap::recipeDifficulty($recipe['difficulty'])) ?></strong></span>
                <span style="background: var(--neutral-gray-100); padding: 4px 10px; border-radius: var(--radius-input);">Preparo: <strong><?= (int)$recipe['prep_time_minutes'] ?> min</strong></span>
                <?php if ((int)$recipe['cook_time_minutes'] > 0): ?>
                  <span style="background: var(--neutral-gray-100); padding: 4px 10px; border-radius: var(--radius-input);">Cozimento: <strong><?= (int)$recipe['cook_time_minutes'] ?> min</strong></span>
                <?php endif; ?>
                <span style="background: var(--neutral-gray-100); padding: 4px 10px; border-radius: var(--radius-input);">Rendimento: <strong><?= (int)$recipe['servings'] ?> <?= htmlspecialchars($recipe['serving_size_name'] === 'porção' && (int)$recipe['servings'] !== 1 ? 'porções' : $recipe['serving_size_name']) ?></strong></span>
                <span style="background: var(--neutral-gray-100); padding: 4px 10px; border-radius: var(--radius-input);">Versão: <strong>v<?= (int)$recipe['current_version'] ?></strong></span>
              </div>
            </div>

            <div style="display: flex; gap: var(--space-3); align-items: center; flex-wrap: wrap;">
              <?php if (!empty($recipe['clinic_id']) && (int)$recipe['clinic_id'] === (int)$auth['clinicId']): ?>
                <a href="/receitas/<?= (int)$recipe['id'] ?>/editar" class="btn btn-secondary">
                  <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 20h9"/><path d="M16.5 3.5a2.121 2.121 0 0 1 3 3L7 19l-4 1 1-4L16.5 3.5z"/></svg>
                  Editar Receita
                </a>
              <?php endif; ?>
              <a href="/receitas/<?= (int)$recipe['id'] ?>/prescrever" class="btn btn-primary">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><line x1="19" y1="8" x2="19" y2="14"/><line x1="22" y1="11" x2="16" y2="11"/></svg>
                Prescrever ao Paciente
              </a>
              <form method="POST" action="/receitas/<?= (int)$recipe['id'] ?>/duplicar" style="display:inline;" data-confirm="Deseja criar uma variante supervisionada desta receita?">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                <button type="submit" class="btn btn-secondary">Duplicar Variante</button>
              </form>
            </div>
          </div>
        </div>

        <!-- Grade Detalhada: Ingredientes vs Informação Nutricional -->
        <div style="display: grid; grid-template-columns: 2fr 1fr; gap: var(--space-6);">
          <div>
            <!-- Ingredientes & Medidas Caseiras -->
            <div class="card" style="margin-bottom: var(--space-6);">
              <div class="card__header">
                <h3 style="margin: 0; color: var(--brand-navy-900);">Ingredientes & Medidas Caseiras</h3>
              </div>
              <table style="width: 100%; border-collapse: collapse; font-size: 0.9rem;">
                <thead>
                  <tr style="border-bottom: 2px solid var(--neutral-gray-200); text-align: left;">
                    <th style="padding: 10px 8px; color: var(--neutral-gray-500); font-weight: 600; font-size: 0.8rem; text-transform: uppercase;">Alimento (Tabela TACO)</th>
                    <th style="padding: 10px 8px; color: var(--neutral-gray-500); font-weight: 600; font-size: 0.8rem; text-transform: uppercase;">Medida Caseira</th>
                    <th style="padding: 10px 8px; color: var(--neutral-gray-500); font-weight: 600; font-size: 0.8rem; text-transform: uppercase;">Peso Líquido</th>
                    <th style="padding: 10px 8px; color: var(--neutral-gray-500); font-weight: 600; font-size: 0.8rem; text-transform: uppercase;">Notas</th>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach ($recipe['ingredients'] as $ing): ?>
                    <tr style="border-bottom: 1px solid var(--neutral-gray-100);">
                      <td style="padding: 12px 8px; font-weight: 600; color: var(--brand-navy-900);"><?= htmlspecialchars($ing['food_name']) ?></td>
                      <td style="padding: 12px 8px; color: var(--neutral-ink-900);"><?= \ClyoNutri\Domain\Nutrition\QuantityFormatter::amount($ing['amount']) ?> <?= htmlspecialchars($ing['measure_unit']) ?></td>
                      <td style="padding: 12px 8px; color: var(--neutral-gray-500);"><?= \ClyoNutri\Domain\Nutrition\QuantityFormatter::amount($ing['net_weight_g']) ?> g<?php if (!empty($ing['weight_is_estimated'])): ?><span data-testid="recipe-estimated" style="display: block; font-size: 0.75rem; color: #92400e;">peso estimado (medida sem peso cadastrado para o alimento)</span><?php endif; ?></td>
                      <td style="padding: 12px 8px; color: var(--neutral-gray-500); font-size: 0.85rem;"><?= htmlspecialchars($ing['notes'] ?? '-') ?></td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>

            <!-- Modo de Preparo Passo a Passo -->
            <div class="card">
              <div class="card__header">
                <h3 style="margin: 0; color: var(--brand-navy-900);">Modo de Preparo Passo a Passo</h3>
              </div>
              <?php if (!empty($recipe['steps'])): ?>
                <div style="display: flex; flex-direction: column; gap: var(--space-4);">
                  <?php foreach ($recipe['steps'] as $st): ?>
                    <div style="display: flex; gap: var(--space-4);">
                      <div style="width: 32px; height: 32px; border-radius: var(--radius-pill); background: var(--brand-navy-900); color: #fff; display: flex; align-items: center; justify-content: center; font-weight: 700; font-size: 0.85rem; flex-shrink: 0;">
                        <?= (int)$st['step_number'] ?>
                      </div>
                      <div style="flex-grow: 1;">
                        <p style="margin-bottom: 4px; font-size: 0.95rem; color: var(--neutral-ink-900); line-height: 1.5;"><?= nl2br(htmlspecialchars($st['instruction'])) ?></p>
                        <?php if (!empty($st['tip'])): ?>
                          <div style="font-size: 0.8rem; background: var(--semantic-warning-light); color: var(--semantic-warning); padding: 4px 10px; border-radius: var(--radius-input); display: inline-block;">
                            💡 <strong>Dica do Chef:</strong> <?= htmlspecialchars($st['tip']) ?>
                          </div>
                        <?php endif; ?>
                      </div>
                    </div>
                  <?php endforeach; ?>
                </div>
              <?php else: ?>
                <p style="white-space: pre-line; color: var(--neutral-ink-900);"><?= htmlspecialchars($recipe['instructions']) ?></p>
              <?php endif; ?>
            </div>
          </div>

          <!-- Coluna Direita: Informação Nutricional e Alérgenos -->
          <div>
            <div class="card" style="margin-bottom: var(--space-6);">
              <div class="card__header">
                <h3 style="margin: 0; color: var(--brand-navy-900);">Informação Nutricional</h3>
              </div>
              <p style="font-size: 0.8rem; color: var(--neutral-gray-500); margin-bottom: var(--space-3);">
                Valores calculados <strong>por porção</strong> (<?= number_format((float)$recipe['serving_size_g'], 1, ',', '.') ?> g):
              </p>

              <div style="background: var(--neutral-warm-50); border: 1px solid #EAD8C7; border-radius: var(--radius-card); padding: var(--space-4); margin-bottom: var(--space-4);">
                <div style="display: flex; justify-content: space-between; align-items: center; padding-bottom: var(--space-2); border-bottom: 2px solid var(--brand-navy-900); margin-bottom: var(--space-2);">
                  <strong style="color: var(--brand-navy-900); font-size: 1.1rem;">Valor Energético</strong>
                  <strong style="color: var(--brand-copper-600); font-size: 1.3rem;"><?= number_format((float)$recipe['energy_kcal_per_serving'], 1, ',', '.') ?> kcal</strong>
                </div>
                <div style="display: flex; justify-content: space-between; padding: 6px 0; border-bottom: 1px dashed var(--neutral-gray-300); font-size: 0.9rem;">
                  <span>Proteínas</span>
                  <strong><?= number_format((float)$recipe['protein_g_per_serving'], 1, ',', '.') ?> g</strong>
                </div>
                <div style="display: flex; justify-content: space-between; padding: 6px 0; border-bottom: 1px dashed var(--neutral-gray-300); font-size: 0.9rem;">
                  <span>Carboidratos</span>
                  <strong><?= number_format((float)$recipe['carbs_g_per_serving'], 1, ',', '.') ?> g</strong>
                </div>
                <div style="display: flex; justify-content: space-between; padding: 6px 0; border-bottom: 1px dashed var(--neutral-gray-300); font-size: 0.9rem;">
                  <span>Lipídios Totais</span>
                  <strong><?= number_format((float)$recipe['fat_g_per_serving'], 1, ',', '.') ?> g</strong>
                </div>
                <div style="display: flex; justify-content: space-between; padding: 6px 0; border-bottom: 1px dashed var(--neutral-gray-300); font-size: 0.9rem;">
                  <span>Fibras Alimentares</span>
                  <strong><?= number_format((float)$recipe['fiber_g_per_serving'], 1, ',', '.') ?> g</strong>
                </div>
                <div style="display: flex; justify-content: space-between; padding: 6px 0; font-size: 0.9rem;">
                  <span>Sódio</span>
                  <?php /* NI2-74: o catálogo (TACO importada) não traz sódio; mostrar 0 seria informação falsa. */ ?>
                  <strong data-testid="recipe-sodium" style="font-weight: 500; color: var(--neutral-gray-500);">Não disponível no catálogo</strong>
                </div>
                <?php
                  $rcUnknown = [];
                  foreach ($recipe['ingredients'] ?? [] as $ri) {
                      foreach ($ri['unknown_nutrients'] ?? [] as $un) { $rcUnknown[$un][$ri['food_name']] = true; }
                  }
                  $rcEstimated = array_filter($recipe['ingredients'] ?? [], static fn($ri) => !empty($ri['weight_is_estimated']));
                ?>
                <?php if ($rcUnknown || $rcEstimated): ?>
                  <div role="note" data-testid="recipe-nutrition-caveats" style="margin-top: 8px; font-size: 0.8rem; color: #78350f; background: #fffbeb; border: 1px solid #fde68a; border-radius: 8px; padding: 8px 10px;">
                    <?php if ($rcEstimated): ?><p style="margin: 0 0 4px;"><strong>Peso estimado</strong> em <?= count($rcEstimated) ?> ingrediente(s): a medida usada não tem peso cadastrado para o alimento.</p><?php endif; ?>
                    <?php if ($rcUnknown): ?><p style="margin: 0;"><strong>Valores incompletos:</strong> sem análise na TACO de <?= htmlspecialchars(implode('; ', array_map(static fn($n, $f) => ['energy_kcal' => 'energia', 'protein_g' => 'proteína', 'carbohydrate_g' => 'carboidratos', 'lipid_g' => 'lipídios', 'fiber_g' => 'fibra'][$n] . ' (' . implode(', ', array_keys($f)) . ')', array_keys($rcUnknown), $rcUnknown))) ?>; entram como zero.</p><?php endif; ?>
                  </div>
                <?php endif; ?>
              </div>

              <?php if (!empty($recipe['allergens'])): ?>
                <div style="background: var(--semantic-error-light); border: 1px solid #FECACA; border-radius: var(--radius-input); padding: var(--space-3); margin-bottom: var(--space-4);">
                  <strong style="color: var(--semantic-error); font-size: 0.85rem; display: block; margin-bottom: 6px;">⚠️ Alergênicos & Restrições</strong>
                  <div style="display: flex; gap: 4px; flex-wrap: wrap;">
                    <?php $shownAllergens = []; foreach ($recipe['allergens'] as $alg): $key = \ClyoNutri\Core\I18n\LabelMap::allergen($alg['allergen_group']).':'.$alg['risk_level']; if (isset($shownAllergens[$key])) continue; $shownAllergens[$key] = true; ?>
                      <span style="background: #FEE2E2; color: var(--semantic-error); font-size: 0.75rem; padding: 2px 8px; border-radius: var(--radius-pill); font-weight: 600;">
                        <?= htmlspecialchars(\ClyoNutri\Core\I18n\LabelMap::allergen($alg['allergen_group'])) ?> (<?= htmlspecialchars(\ClyoNutri\Core\I18n\LabelMap::allergenRisk($alg['risk_level'])) ?>)
                      </span>
                    <?php endforeach; ?>
                  </div>
                </div>
              <?php endif; ?>

              <?php if (!empty($recipe['tags'])): ?>
                <div>
                  <span style="font-size: 0.75rem; color: var(--neutral-gray-500); font-weight: 700; text-transform: uppercase;">Marcadores:</span>
                  <div style="margin-top: 6px; display: flex; gap: 4px; flex-wrap: wrap;">
                    <?php foreach ($recipe['tags'] as $tg): ?>
                      <span style="font-size: 0.75rem; background: var(--neutral-gray-100); color: var(--neutral-gray-500); padding: 2px 8px; border-radius: var(--radius-input);">#<?= htmlspecialchars($tg) ?></span>
                    <?php endforeach; ?>
                  </div>
                </div>
              <?php endif; ?>
            </div>
          </div>
        </div>
        <?php
        $content = ob_get_clean();
        require __DIR__ . '/../../../views/layouts/professional-shell.php';
    }

    /**
     * Formulário de Criação de Receita (Design System ClyoNutri)
     */
    public function create(array $params = []): void
    {
        $auth = $this->getAuthInfo();

        // NI2-73: os ingredientes são escolhidos pela busca do catálogo (/catalogo/alimentos/busca), que já aplica
        // F02 (globais + da própria clínica); o servidor confere de novo cada food_id ao salvar.

        $title = 'Nova Receita Culinária';
        $activeNav = 'receitas';
        $clinicName = $auth['clinicName'];
        $userName = $auth['userName'];
        $csrfToken = Csrf::token();

        ob_start();
        ?>
        <div style="font-size: 0.85rem; color: var(--neutral-gray-500); margin-bottom: var(--space-4);">
          <a href="/receitas" style="color: var(--brand-copper-600); text-decoration: none; font-weight: 600;">← Voltar à Biblioteca</a>
        </div>

        <div class="card" style="max-width: 960px; margin: 0 auto;">
          <div class="card__header">
            <div>
              <h2 style="font-family: var(--font-editorial); color: var(--brand-navy-900); margin-bottom: 4px;">Criar Nova Receita Culinária</h2>
              <p style="color: var(--neutral-gray-500); font-size: 0.9rem; margin: 0;">Cadastre os ingredientes da Tabela TACO e o modo de preparo. O sistema calculará automaticamente o valor nutricional por porção.</p>
            </div>
          </div>

          <form method="POST" action="/receitas" id="recipeForm" style="margin-top: var(--space-4);">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">

            <div style="margin-bottom: var(--space-4);">
              <label for="rc5-title" style="display: block; font-size: 0.85rem; font-weight: 600; color: var(--brand-navy-900); margin-bottom: 6px;">Título da Receita *</label>
              <input id="rc5-title" type="text" name="title" required placeholder="Ex: Crepioca Funcional de Frango com Queijo Minas" style="padding: 10px 14px; border: 1px solid var(--neutral-gray-300); border-radius: var(--radius-input); font-size: 0.95rem; width: 100%; font-family: var(--font-body);">
            </div>

            <div style="margin-bottom: var(--space-4);">
              <label for="rc6-summary" style="display: block; font-size: 0.85rem; font-weight: 600; color: var(--brand-navy-900); margin-bottom: 6px;">Resumo / Descrição Clínica</label>
              <input id="rc6-summary" type="text" name="summary" placeholder="Ex: Opção prática e rica em proteínas para pré-treino ou café da manhã." style="padding: 10px 14px; border: 1px solid var(--neutral-gray-300); border-radius: var(--radius-input); font-size: 0.95rem; width: 100%; font-family: var(--font-body);">
            </div>

            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: var(--space-4); margin-bottom: var(--space-4);">
              <div>
                <label for="rc7-meal_type" style="display: block; font-size: 0.85rem; font-weight: 600; color: var(--brand-navy-900); margin-bottom: 6px;">Refeição / Categoria *</label>
                <select id="rc7-meal_type" name="meal_type" required style="padding: 10px 14px; border: 1px solid var(--neutral-gray-300); border-radius: var(--radius-input); font-size: 0.95rem; width: 100%; font-family: var(--font-body); background: #fff;">
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
              <div>
                <label for="rc8-difficulty" style="display: block; font-size: 0.85rem; font-weight: 600; color: var(--brand-navy-900); margin-bottom: 6px;">Dificuldade</label>
                <select id="rc8-difficulty" name="difficulty" style="padding: 10px 14px; border: 1px solid var(--neutral-gray-300); border-radius: var(--radius-input); font-size: 0.95rem; width: 100%; font-family: var(--font-body); background: #fff;">
                  <option value="fácil">Fácil</option>
                  <option value="média">Média</option>
                  <option value="difícil">Difícil</option>
                </select>
              </div>
            </div>

            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: var(--space-4); margin-bottom: var(--space-4);">
              <div>
                <label for="rc9-servings" style="display: block; font-size: 0.85rem; font-weight: 600; color: var(--brand-navy-900); margin-bottom: 6px;">Rendimento (Porções) *</label>
                <input id="rc9-servings" type="number" name="servings" min="1" value="1" required style="padding: 10px 14px; border: 1px solid var(--neutral-gray-300); border-radius: var(--radius-input); font-size: 0.95rem; width: 100%; font-family: var(--font-body);">
              </div>
              <div>
                <label for="rc10-serving_size_name" style="display: block; font-size: 0.85rem; font-weight: 600; color: var(--brand-navy-900); margin-bottom: 6px;">Unidade da Porção</label>
                <input id="rc10-serving_size_name" type="text" name="serving_size_name" value="porção" placeholder="Ex: fatia, unidade, tigela" style="padding: 10px 14px; border: 1px solid var(--neutral-gray-300); border-radius: var(--radius-input); font-size: 0.95rem; width: 100%; font-family: var(--font-body);">
              </div>
            </div>

            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: var(--space-4); margin-bottom: var(--space-6);">
              <div>
                <label for="rc11-prep_time_minutes" style="display: block; font-size: 0.85rem; font-weight: 600; color: var(--brand-navy-900); margin-bottom: 6px;">Tempo de Preparo (min)</label>
                <input id="rc11-prep_time_minutes" type="number" name="prep_time_minutes" min="1" value="15" style="padding: 10px 14px; border: 1px solid var(--neutral-gray-300); border-radius: var(--radius-input); font-size: 0.95rem; width: 100%; font-family: var(--font-body);">
              </div>
              <div>
                <label for="rc12-cook_time_minutes" style="display: block; font-size: 0.85rem; font-weight: 600; color: var(--brand-navy-900); margin-bottom: 6px;">Tempo de Cozimento / Fogo (min)</label>
                <input id="rc12-cook_time_minutes" type="number" name="cook_time_minutes" min="0" value="10" style="padding: 10px 14px; border: 1px solid var(--neutral-gray-300); border-radius: var(--radius-input); font-size: 0.95rem; width: 100%; font-family: var(--font-body);">
              </div>
            </div>

            <!-- Lista Dinâmica de Ingredientes TACO -->
            <div style="border-top: 1px solid var(--neutral-gray-200); padding-top: var(--space-4); margin-bottom: var(--space-6);">
              <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: var(--space-3);">
                <div>
                  <h4 style="color: var(--brand-navy-900); margin: 0;">Ingredientes Oficiais (Tabela TACO) *</h4>
                  <span style="font-size: 0.8rem; color: var(--neutral-gray-500);">Selecione os alimentos e a medida caseira correspondente.</span>
                </div>
                <button type="button" class="btn btn-secondary" data-call="addIngredientRow">+ Adicionar Ingrediente</button>
              </div>

              <div id="ingredientsContainer" style="display: flex; flex-direction: column; gap: 8px;"></div>
              <noscript><p style="color: var(--semantic-error);">Ative o JavaScript para buscar e adicionar ingredientes.</p></noscript>
            </div>

            <!-- Passos de Preparo -->
            <div style="border-top: 1px solid var(--neutral-gray-200); padding-top: var(--space-4); margin-bottom: var(--space-6);">
              <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: var(--space-3);">
                <div>
                  <h4 style="color: var(--brand-navy-900); margin: 0;">Modo de Preparo (Passos Ordenados)</h4>
                  <span style="font-size: 0.8rem; color: var(--neutral-gray-500);">Descreva as etapas para orientação do paciente.</span>
                </div>
                <button type="button" class="btn btn-secondary" data-call="addStepRow">+ Adicionar Passo</button>
              </div>

              <div id="stepsContainer" style="display: flex; flex-direction: column; gap: 8px;">
                <div style="display: flex; gap: 8px;">
                  <input type="text" name="steps[]" placeholder="Passo 1: Em uma tigela, misture os ingredientes até homogenizar..." required style="padding: 10px 14px; border: 1px solid var(--neutral-gray-300); border-radius: var(--radius-input); font-size: 0.9rem; flex: 1; font-family: var(--font-body);">
                  <button type="button" class="btn" style="background: var(--semantic-error-light); color: var(--semantic-error); border: none; min-height: 38px; padding: 0 12px;" data-remove-parent>✕</button>
                </div>
              </div>
            </div>

            <!-- Tags e Visibilidade -->
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: var(--space-4); margin-bottom: var(--space-6);">
              <div>
                <label for="rc13-tags" style="display: block; font-size: 0.85rem; font-weight: 600; color: var(--brand-navy-900); margin-bottom: 6px;">Marcadores (separados por vírgula)</label>
                <input id="rc13-tags" type="text" name="tags" placeholder="Ex: rico em proteínas, rápido, low carb, sem lactose" style="padding: 10px 14px; border: 1px solid var(--neutral-gray-300); border-radius: var(--radius-input); font-size: 0.95rem; width: 100%; font-family: var(--font-body);">
              </div>
              <div>
                <label for="rc14-visibility" style="display: block; font-size: 0.85rem; font-weight: 600; color: var(--brand-navy-900); margin-bottom: 6px;">Visibilidade</label>
                <select id="rc14-visibility" name="visibility" style="padding: 10px 14px; border: 1px solid var(--neutral-gray-300); border-radius: var(--radius-input); font-size: 0.95rem; width: 100%; font-family: var(--font-body); background: #fff;">
                  <option value="clinic">Privada da Clínica</option>
                  <option value="public">Pública / Compartilhada</option>
                </select>
              </div>
            </div>

            <div style="display: flex; justify-content: flex-end; gap: var(--space-3); border-top: 1px solid var(--neutral-gray-200); padding-top: var(--space-4);">
              <a href="/receitas" class="btn btn-secondary">Cancelar</a>
              <button type="submit" class="btn btn-accent">Salvar & Calcular Receita</button>
            </div>
          </form>
        </div>

        <script nonce="<?= \ClyoNutri\Core\Http\SecurityHeaders::nonce() ?>">
          let ingredientIndex = 0;
          // NI2-73: cada ingrediente usa a mesma busca do plano (catálogo inteiro, termos, porção e origem),
          // em vez de um <select> com os 300 primeiros alimentos em ordem alfabética.
          function addIngredientRow() {
              const container = document.getElementById('ingredientsContainer');
              const i = ingredientIndex++;
              const row = document.createElement('div');
              row.className = 'item-row recipe-ingredient-row';
              row.innerHTML = `
                  <div data-food-picker class="recipe-ingredient-row__food">
                      <label class="form-label" for="rc-food-q-${i}">Buscar alimento</label>
                      <input class="form-control" id="rc-food-q-${i}" data-food-query type="search" maxlength="80" placeholder="Ex.: ovo, arroz integral, banana">
                      <p role="status" aria-live="polite" class="recipe-ingredient-row__status">Busque e selecione o alimento.</p>
                      <label class="form-label" data-food-results-label for="rc-food-${i}">Resultados da busca</label>
                      <select id="rc-food-${i}" name="ingredients[${i}][food_id]" required><option value="">Selecione o alimento</option></select>
                      <button type="button" class="btn btn-secondary" data-food-next hidden>Mais resultados</button>
                      <button type="button" class="btn btn-secondary" data-food-retry hidden>Tentar busca novamente</button>
                  </div>
                  <div>
                      <label class="form-label" for="rc-amount-${i}">Quantidade</label>
                      <input id="rc-amount-${i}" type="number" step="0.01" min="0.01" name="ingredients[${i}][amount]" value="100" required class="form-control">
                  </div>
                  <div>
                      <label class="form-label" for="rc-unit-${i}">Medida</label>
                      <select id="rc-unit-${i}" name="ingredients[${i}][measure_unit]" class="form-control"><?= UnitCheckView::unitOptions() ?></select>
                  </div>
                  <button type="button" class="btn recipe-ingredient-row__remove" aria-label="Remover ingrediente" data-remove-parent>✕</button>
                  <div class="recipe-ingredient-row__check"><?= str_replace(['__ID__', '__NAME__'], ['rc-confirm-${i}', 'ingredients[${i}][confirm_estimated]'], UnitCheckView::block('__ID__', '__NAME__')) ?></div>
              `;
              row.setAttribute('data-unit-scope', '');
              container.appendChild(row);
              if (window.ClyoFoodPicker) window.ClyoFoodPicker.init(row.querySelector('[data-food-picker]'));
              if (window.ClyoUnitCheck) window.ClyoUnitCheck.init(row);
              return row;
          }
          addIngredientRow(); // o seletor (food-picker.js, carregado com defer) inicia esta primeira linha

          function addStepRow() {
              const container = document.getElementById('stepsContainer');
              const stepNum = container.children.length + 1;
              const row = document.createElement('div');
              row.style.cssText = 'display: flex; gap: 8px;';
              row.innerHTML = `
                  <input type="text" name="steps[]" placeholder="Passo ${stepNum}: ..." required style="padding: 10px 14px; border: 1px solid var(--neutral-gray-300); border-radius: var(--radius-input); font-size: 0.9rem; flex: 1; font-family: var(--font-body);">
                  <button type="button" class="btn" style="background: var(--semantic-error-light); color: var(--semantic-error); border: none; min-height: 38px; padding: 0 12px;" data-remove-parent>✕</button>
              `;
              container.appendChild(row);
          }
        </script>
        <script src="/assets/js/food-picker.js" defer></script>
        <script src="/assets/js/unit-check.js" defer></script>
        <?php
        $content = ob_get_clean();
        require __DIR__ . '/../../../views/layouts/professional-shell.php';
    }

    /**
     * Salva nova receita (POST)
     */
    public function store(array $params = []): void
    {
        $auth = $this->getAuthInfo();

        $csrfToken = (string)($_POST['csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ''));
        if (!Csrf::validate($csrfToken)) {
            http_response_code(403);
            echo "Token CSRF inválido ou expirado.";
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
                        'confirm_estimated' => !empty($ing['confirm_estimated']), // NI2-74
                    ];
                }
            }
        }

        if (empty($ingredients)) {
            http_response_code(400);
            echo "A receita precisa de ao menos um ingrediente da Tabela TACO.";
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
     * Formulário de Prescrição ao Paciente (Design System ClyoNutri)
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

        // V2-01 (reauditoria): o paciente é validado contra a clínica da sessão ANTES de qualquer
        // leitura clínica. Paciente de outra clínica e ID inexistente recebem a MESMA resposta 404.
        $plans = [];
        $patientAllergies = [];
        if ($selectedPatientId > 0) {
            try {
                $context = $this->recipeService->getPrescriptionPatientContext($selectedPatientId, (int)$auth['clinicId']);
            } catch (\ClyoNutri\Core\Security\TenantSecurityException $e) {
                http_response_code(404);
                echo "Paciente não encontrado.";
                return;
            }
            $plans = $context['plans'];
            $patientAllergies = $context['allergies'];
        }

        $title = 'Prescrever Receita — ' . $recipe['title'];
        $activeNav = 'receitas';
        $clinicName = $auth['clinicName'];
        $userName = $auth['userName'];
        $csrfToken = Csrf::token();

        ob_start();
        ?>
        <div style="font-size: 0.85rem; color: var(--neutral-gray-500); margin-bottom: var(--space-4);">
          <a href="/receitas/<?= (int)$recipe['id'] ?>" style="color: var(--brand-copper-600); text-decoration: none; font-weight: 600;">← Voltar à Receita</a>
        </div>

        <div class="card" style="max-width: 680px; margin: 0 auto;">
          <div class="card__header">
            <div>
              <h2 style="font-family: var(--font-editorial); color: var(--brand-navy-900); margin-bottom: 4px;">Prescrever Receita ao Paciente</h2>
              <p style="color: var(--neutral-gray-500); font-size: 0.9rem; margin: 0;">
                Receita selecionada: <strong><?= htmlspecialchars($recipe['title']) ?></strong> (<?= (int)$recipe['energy_kcal_per_serving'] ?> kcal/porção)
              </p>
            </div>
          </div>

          <?php if (!empty($patientAllergies)): ?>
            <div style="background: var(--semantic-error-light); border: 1px solid #FECACA; border-radius: var(--radius-input); padding: var(--space-3); margin-top: var(--space-4); margin-bottom: var(--space-4); color: var(--semantic-error); font-size: 0.85rem;">
              <strong>⚠️ Alerta Clínico de Incompatibilidade Alérgica:</strong>
              <p style="margin: 4px 0 0 0;">O prontuário deste paciente registra: <?= htmlspecialchars(implode(', ', $patientAllergies)) ?>.</p>
            </div>
          <?php endif; ?>

          <form method="POST" action="/receitas/<?= (int)$recipe['id'] ?>/prescrever" style="margin-top: var(--space-4);">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">

            <div style="margin-bottom: var(--space-4);">
              <label for="rc15-patient_id" style="display: block; font-size: 0.85rem; font-weight: 600; color: var(--brand-navy-900); margin-bottom: 6px;">Paciente *</label>
              <select id="rc15-patient_id" name="patient_id" required data-nav-prefix="/receitas/<?= (int)$recipe['id'] ?>/prescrever?patient_id=" style="padding: 10px 14px; border: 1px solid var(--neutral-gray-300); border-radius: var(--radius-input); font-size: 0.95rem; width: 100%; font-family: var(--font-body); background: #fff;">
                <?php foreach ($patients as $p): ?>
                  <option value="<?= (int)$p['id'] ?>" <?= ((int)$p['id'] === $selectedPatientId) ? 'selected' : '' ?>>
                    <?= htmlspecialchars($p['name']) ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </div>

            <div style="margin-bottom: var(--space-4);">
              <label for="rc16-meal_plan_id" style="display: block; font-size: 0.85rem; font-weight: 600; color: var(--brand-navy-900); margin-bottom: 6px;">Plano Alimentar de Destino *</label>
              <select id="rc16-meal_plan_id" name="meal_plan_id" required style="padding: 10px 14px; border: 1px solid var(--neutral-gray-300); border-radius: var(--radius-input); font-size: 0.95rem; width: 100%; font-family: var(--font-body); background: #fff;">
                <?php if (empty($plans)): ?>
                  <option value="0">Plano Ativo Padrão</option>
                <?php else: ?>
                  <?php foreach ($plans as $pl): ?>
                    <option value="<?= (int)$pl['id'] ?>"><?= htmlspecialchars($pl['title']) ?></option>
                  <?php endforeach; ?>
                <?php endif; ?>
              </select>
            </div>

            <div style="margin-bottom: var(--space-4);">
              <label for="rc17-meal_name" style="display: block; font-size: 0.85rem; font-weight: 600; color: var(--brand-navy-900); margin-bottom: 6px;">Refeição Prescrita *</label>
              <select id="rc17-meal_name" name="meal_name" required style="padding: 10px 14px; border: 1px solid var(--neutral-gray-300); border-radius: var(--radius-input); font-size: 0.95rem; width: 100%; font-family: var(--font-body); background: #fff;">
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

            <div style="margin-bottom: var(--space-4);">
              <label for="rc18-servings" style="display: block; font-size: 0.85rem; font-weight: 600; color: var(--brand-navy-900); margin-bottom: 6px;">Quantidade de Porções a Consumir *</label>
              <input id="rc18-servings" type="number" step="0.5" name="servings" value="1.0" min="0.5" max="10.0" required style="padding: 10px 14px; border: 1px solid var(--neutral-gray-300); border-radius: var(--radius-input); font-size: 0.95rem; width: 100%; font-family: var(--font-body);">
            </div>

            <div style="margin-bottom: var(--space-6);">
              <label for="rc19-instructions" style="display: block; font-size: 0.85rem; font-weight: 600; color: var(--brand-navy-900); margin-bottom: 6px;">Orientações Clínicas Personalizadas</label>
              <textarea id="rc19-instructions" name="instructions" rows="3" placeholder="Ex: Consumir morno. Substituir sal por ervas finas desidratadas." style="padding: 10px 14px; border: 1px solid var(--neutral-gray-300); border-radius: var(--radius-input); font-size: 0.95rem; width: 100%; font-family: var(--font-body);"></textarea>
            </div>

            <div style="display: flex; justify-content: flex-end; gap: var(--space-3); border-top: 1px solid var(--neutral-gray-200); padding-top: var(--space-4);">
              <a href="/receitas/<?= (int)$recipe['id'] ?>" class="btn btn-secondary">Cancelar</a>
              <button type="submit" class="btn btn-accent">Confirmar Prescrição</button>
            </div>
          </form>
        </div>
        <?php
        $content = ob_get_clean();
        require __DIR__ . '/../../../views/layouts/professional-shell.php';
    }

    /**
     * Confirma prescrição (POST)
     */
    public function prescribe(array $params = []): void
    {
        $auth = $this->getAuthInfo();
        $recipeId = (int)($params['id'] ?? 0);

        $csrfToken = (string)($_POST['csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ''));
        if (!Csrf::validate($csrfToken)) {
            http_response_code(403);
            echo "Token CSRF inválido ou expirado.";
            return;
        }

        $patientId = (int)($_POST['patient_id'] ?? 0);
        $mealPlanId = (int)($_POST['meal_plan_id'] ?? 0);
        $mealName = trim((string)($_POST['meal_name'] ?? 'Almoço'));
        $servings = (float)($_POST['servings'] ?? 1.0);
        $instructions = trim((string)($_POST['instructions'] ?? ''));

        // F01: autorização de paciente/profissional/plano + transação ficam no serviço.
        $status = 303;
        try {
            $this->recipeService->prescribeRecipeWithPlan(
                (int)$auth['clinicId'],
                (int)$auth['profId'],
                $patientId,
                $mealPlanId,
                $recipeId,
                $servings,
                $instructions,
                $mealName
            );
        } catch (\Throwable $e) {
            $status = self::httpStatusForException($e);
            http_response_code($status);
            echo $status === 403
                ? "Acesso negado: paciente ou plano não encontrado nesta clínica."
                : "Erro ao prescrever receita: " . htmlspecialchars($e->getMessage());
            return;
        }

        header('Location: /receitas?prescrito=1');
        exit;
    }

    /**
     * Mapeia exceções de domínio para status HTTP (F01). Público para teste.
     */
    public static function httpStatusForException(\Throwable $e): int
    {
        if ($e instanceof \ClyoNutri\Core\Security\TenantSecurityException) {
            return 403;
        }
        if ($e instanceof \InvalidArgumentException && stripos($e->getMessage(), 'não encontrad') !== false) {
            return 404;
        }
        return 400;
    }

    /**
     * Duplica uma receita como variante supervisionada
     */
    public function duplicate(array $params = []): void
    {
        $auth = $this->getAuthInfo();
        $recipeId = (int)($params['id'] ?? 0);

        $csrfToken = (string)($_POST['csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ''));
        if (!Csrf::validate($csrfToken)) {
            http_response_code(403);
            echo "Token CSRF inválido ou expirado.";
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

    /**
     * Tela de Edição de Receita no Runtime Web (PX-27 / NI2-320)
     */
    public function edit(array $params = []): void
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

        if (empty($recipe['clinic_id']) || (int)$recipe['clinic_id'] !== (int)$auth['clinicId']) {
            http_response_code(403);
            echo "Receitas padrão da biblioteca pública não podem ser editadas diretamente. Use 'Duplicar como Variante' para personalizar.";
            return;
        }


        $title = 'Editar Receita — ' . $recipe['title'];
        $activeNav = 'receitas';
        $clinicName = $auth['clinicName'];
        $userName = $auth['userName'];
        $csrfToken = Csrf::token();

        ob_start();
        ?>
        <div style="font-size: 0.85rem; color: var(--neutral-gray-500); margin-bottom: var(--space-4);">
          <a href="/receitas/<?= (int)$recipe['id'] ?>" style="color: var(--brand-copper-600); text-decoration: none; font-weight: 600;">← Voltar aos Detalhes da Receita</a>
        </div>

        <div class="card" style="max-width: 960px; margin: 0 auto;">
          <div class="card__header">
            <div>
              <h2 style="font-family: var(--font-editorial); color: var(--brand-navy-900); margin-bottom: 4px;">Editar Receita (Nova Versão Supervisionada)</h2>
              <p style="color: var(--neutral-gray-500); font-size: 0.9rem; margin: 0;">A edição gera uma nova versão auditada (v<?= ((int)($recipe['current_version'] ?? 1)) + 1 ?>) e preserva prescrições já emitidas.</p>
            </div>
          </div>

          <form method="POST" action="/receitas/<?= (int)$recipe['id'] ?>/editar" id="editRecipeForm" style="margin-top: var(--space-4);">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">

            <div style="margin-bottom: var(--space-4);">
              <label for="rc20-title" style="display: block; font-size: 0.85rem; font-weight: 600; color: var(--brand-navy-900); margin-bottom: 6px;">Título da Receita *</label>
              <input id="rc20-title" type="text" name="title" required value="<?= htmlspecialchars($recipe['title']) ?>" style="padding: 10px 14px; border: 1px solid var(--neutral-gray-300); border-radius: var(--radius-input); font-size: 0.95rem; width: 100%; font-family: var(--font-body);">
            </div>

            <div style="margin-bottom: var(--space-4);">
              <label for="rc21-summary" style="display: block; font-size: 0.85rem; font-weight: 600; color: var(--brand-navy-900); margin-bottom: 6px;">Resumo / Descrição Clínica</label>
              <input id="rc21-summary" type="text" name="summary" value="<?= htmlspecialchars($recipe['summary'] ?? '') ?>" style="padding: 10px 14px; border: 1px solid var(--neutral-gray-300); border-radius: var(--radius-input); font-size: 0.95rem; width: 100%; font-family: var(--font-body);">
            </div>

            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: var(--space-4); margin-bottom: var(--space-4);">
              <div>
                <label for="rc22-meal_type" style="display: block; font-size: 0.85rem; font-weight: 600; color: var(--brand-navy-900); margin-bottom: 6px;">Refeição / Categoria *</label>
                <select id="rc22-meal_type" name="meal_type" required style="padding: 10px 14px; border: 1px solid var(--neutral-gray-300); border-radius: var(--radius-input); font-size: 0.95rem; width: 100%; font-family: var(--font-body); background: #fff;">
                  <?php foreach (['Café da Manhã', 'Almoço', 'Lanche da Tarde', 'Jantar', 'Ceia', 'Pré-Treino', 'Pós-Treino', 'Sobremesa'] as $mt): ?>
                    <option value="<?= $mt ?>" <?= ($recipe['meal_type'] ?? '') === $mt ? 'selected' : '' ?>><?= $mt ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div>
                <label for="rc23-difficulty" style="display: block; font-size: 0.85rem; font-weight: 600; color: var(--brand-navy-900); margin-bottom: 6px;">Dificuldade</label>
                <select id="rc23-difficulty" name="difficulty" style="padding: 10px 14px; border: 1px solid var(--neutral-gray-300); border-radius: var(--radius-input); font-size: 0.95rem; width: 100%; font-family: var(--font-body); background: #fff;">
                  <?php foreach (['fácil', 'média', 'difícil'] as $df): ?>
                    <option value="<?= $df ?>" <?= ($recipe['difficulty'] ?? '') === $df ? 'selected' : '' ?>><?= ucfirst($df) ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
            </div>

            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: var(--space-4); margin-bottom: var(--space-4);">
              <div>
                <label for="rc24-servings" style="display: block; font-size: 0.85rem; font-weight: 600; color: var(--brand-navy-900); margin-bottom: 6px;">Rendimento (Porções) *</label>
                <input id="rc24-servings" type="number" name="servings" min="1" value="<?= (int)($recipe['servings'] ?? 1) ?>" required style="padding: 10px 14px; border: 1px solid var(--neutral-gray-300); border-radius: var(--radius-input); font-size: 0.95rem; width: 100%; font-family: var(--font-body);">
              </div>
              <div>
                <label for="rc25-serving_size_name" style="display: block; font-size: 0.85rem; font-weight: 600; color: var(--brand-navy-900); margin-bottom: 6px;">Unidade da Porção</label>
                <input id="rc25-serving_size_name" type="text" name="serving_size_name" value="<?= htmlspecialchars($recipe['serving_size_name'] ?? 'porção') ?>" style="padding: 10px 14px; border: 1px solid var(--neutral-gray-300); border-radius: var(--radius-input); font-size: 0.95rem; width: 100%; font-family: var(--font-body);">
              </div>
            </div>

            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: var(--space-4); margin-bottom: var(--space-6);">
              <div>
                <label for="rc26-prep_time_minutes" style="display: block; font-size: 0.85rem; font-weight: 600; color: var(--brand-navy-900); margin-bottom: 6px;">Tempo de Preparo (min)</label>
                <input id="rc26-prep_time_minutes" type="number" name="prep_time_minutes" min="1" value="<?= (int)($recipe['prep_time_minutes'] ?? 15) ?>" style="padding: 10px 14px; border: 1px solid var(--neutral-gray-300); border-radius: var(--radius-input); font-size: 0.95rem; width: 100%; font-family: var(--font-body);">
              </div>
              <div>
                <label for="rc27-cook_time_minutes" style="display: block; font-size: 0.85rem; font-weight: 600; color: var(--brand-navy-900); margin-bottom: 6px;">Tempo de Cozimento / Fogo (min)</label>
                <input id="rc27-cook_time_minutes" type="number" name="cook_time_minutes" min="0" value="<?= (int)($recipe['cook_time_minutes'] ?? 0) ?>" style="padding: 10px 14px; border: 1px solid var(--neutral-gray-300); border-radius: var(--radius-input); font-size: 0.95rem; width: 100%; font-family: var(--font-body);">
              </div>
            </div>

            <!-- Instruções de Preparo -->
            <div style="margin-bottom: var(--space-6);">
              <label for="rc28-instructions" style="display: block; font-size: 0.85rem; font-weight: 600; color: var(--brand-navy-900); margin-bottom: 6px;">Modo de Preparo / Instruções *</label>
              <textarea id="rc28-instructions" name="instructions" required rows="6" style="padding: 12px 14px; border: 1px solid var(--neutral-gray-300); border-radius: var(--radius-input); font-size: 0.95rem; width: 100%; font-family: var(--font-body); line-height: 1.5;"><?= htmlspecialchars($recipe['instructions'] ?? '') ?></textarea>
            </div>

            <div style="display: flex; justify-content: flex-end; gap: var(--space-3); border-top: 1px solid var(--neutral-gray-200); padding-top: var(--space-4);">
              <a href="/receitas/<?= (int)$recipe['id'] ?>" class="btn btn-secondary">Cancelar</a>
              <button type="submit" class="btn btn-primary">Salvar Nova Versão da Receita</button>
            </div>
          </form>
        </div>
        <?php
        $content = ob_get_clean();
        require __DIR__ . '/../../../views/layouts/professional-shell.php';
    }

    /**
     * Atualiza receita (POST) gerando versão auditada (PX-27 / NI2-320)
     */
    public function update(array $params = []): void
    {
        $auth = $this->getAuthInfo();
        $recipeId = (int)($params['id'] ?? 0);

        $csrfToken = (string)($_POST['csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ''));
        if (!Csrf::validate($csrfToken)) {
            http_response_code(403);
            echo "Token CSRF inválido ou expirado.";
            return;
        }

        $title = trim((string)($_POST['title'] ?? ''));
        $servings = max(1, (int)($_POST['servings'] ?? 1));

        $recipeData = [
            'title' => $title,
            'summary' => trim((string)($_POST['summary'] ?? '')),
            'meal_type' => trim((string)($_POST['meal_type'] ?? 'Almoço')),
            'difficulty' => trim((string)($_POST['difficulty'] ?? 'fácil')),
            'prep_time_minutes' => (int)($_POST['prep_time_minutes'] ?? 15),
            'cook_time_minutes' => (int)($_POST['cook_time_minutes'] ?? 0),
            'servings' => $servings,
            'serving_size_name' => trim((string)($_POST['serving_size_name'] ?? 'porção')),
            'instructions' => trim((string)($_POST['instructions'] ?? '')),
        ];

        try {
            $updated = $this->recipeService->updateRecipe($recipeId, $recipeData, $auth['clinicId'], $auth['userId']);
            header('Location: /receitas/' . (int)$recipeId . '?success=receita_atualizada');
            exit;
        } catch (\Throwable $e) {
            http_response_code(400);
            echo "Erro ao atualizar receita: " . htmlspecialchars($e->getMessage());
        }
    }
}
