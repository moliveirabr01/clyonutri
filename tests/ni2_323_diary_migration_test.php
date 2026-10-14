<?php
require __DIR__ . '/../database/migrations/2026_10_10_000029_enforce_food_diary_idempotency.php';
$db = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$db->exec('CREATE TABLE food_diary_entries(id INTEGER PRIMARY KEY, patient_id INTEGER, clinic_id INTEGER, idempotency_key TEXT)');
$db->exec("INSERT INTO food_diary_entries VALUES (1,1,1,'same'),(2,1,1,'same'),(3,1,2,'same'),(4,1,1,NULL),(5,1,1,NULL)");
(new EnforceFoodDiaryIdempotency())->up($db);
if ((int)$db->query('SELECT COUNT(*) FROM food_diary_entries')->fetchColumn() !== 5) throw new RuntimeException('Migration lost entries');
if ($db->query('SELECT idempotency_key FROM food_diary_entries WHERE id=2')->fetchColumn() !== null) throw new RuntimeException('Duplicate key not cleared');
try { $db->exec("INSERT INTO food_diary_entries VALUES (6,1,1,'same')"); throw new RuntimeException('Duplicate allowed'); }
catch (PDOException $e) {}
$db->exec("INSERT INTO food_diary_entries VALUES (7,2,1,'same')");
echo "PASS migration preserves entries and enforces uniqueness per patient and clinic.\n";
