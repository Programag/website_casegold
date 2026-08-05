<?php
// TYMCZASOWY skrypt - uruchamia schema.sql przez to samo działające
// połączenie PDO co reszta strony (phpMyAdmin ma teraz własny problem
// z połączeniem, więc omijamy go). USUNĄĆ po jednorazowym użyciu.
require_once __DIR__ . '/../inc/db.php';

header('Content-Type: text/plain; charset=utf-8');

$sql = file_get_contents(__DIR__ . '/../schema.sql');
// Usuń linie komentarzy PRZED dzieleniem na zapytania - niektóre komentarze
// w schema.sql zawierają średnik w treści zdania, co psuło naiwny split.
$lines = array_filter(explode("\n", $sql), fn($l) => !str_starts_with(trim($l), '--'));
$sql = implode("\n", $lines);
$statements = array_filter(array_map('trim', explode(';', $sql)));

try {
    $pdo = db();
    foreach ($statements as $stmt) {
        if ($stmt === '') continue;
        $pdo->exec($stmt);
    }
    echo "OK - tabele utworzone.\n\n";
    $tables = $pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
    echo "Tabele w bazie:\n" . implode("\n", $tables);
} catch (Throwable $e) {
    echo "BŁĄD: " . get_class($e) . ': ' . $e->getMessage();
}
