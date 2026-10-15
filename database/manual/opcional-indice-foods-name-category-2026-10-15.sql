-- Opcional e idempotente: índices para a Base de Alimentos paginada.
-- Não é obrigatório na v9; a listagem funciona sem eles. Pode rodar de novo.
-- Banco alvo típico: maic6824_Clyonutri2

SET @db := DATABASE();

-- Índice em name (busca / ORDER BY name, id)
SET @exists := (
  SELECT COUNT(*) FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'foods' AND INDEX_NAME = 'idx_foods_name'
);
SET @sql := IF(@exists = 0,
  'ALTER TABLE foods ADD INDEX idx_foods_name (name)',
  'SELECT ''idx_foods_name já existe'' AS info');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Índice em category (filtro por grupo TACO)
SET @exists := (
  SELECT COUNT(*) FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'foods' AND INDEX_NAME = 'idx_foods_category'
);
SET @sql := IF(@exists = 0,
  'ALTER TABLE foods ADD INDEX idx_foods_category (category)',
  'SELECT ''idx_foods_category já existe'' AS info');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Índice composto útil para tenant + ordenação
SET @exists := (
  SELECT COUNT(*) FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'foods' AND INDEX_NAME = 'idx_foods_clinic_name_id'
);
SET @sql := IF(@exists = 0,
  'ALTER TABLE foods ADD INDEX idx_foods_clinic_name_id (clinic_id, name, id)',
  'SELECT ''idx_foods_clinic_name_id já existe'' AS info');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SELECT 'OK' AS resultado, @db AS banco;
