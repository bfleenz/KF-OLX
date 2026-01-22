<?php
require_once 'config.php';

try {
    // Create locations table if it doesn't exist
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS locations (
            id INT AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(100) NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");

    // Insert sample locations if the table is empty
    $stmt = $pdo->query("SELECT COUNT(*) as count FROM locations");
    $count = $stmt->fetch()['count'];

    if ($count == 0) {
        $locations = [
            'Jakarta', 'Surabaya', 'Bandung', 'Bekasi', 'Medan',
            'Depok', 'Tangerang', 'Semarang', 'Palembang', 'Makassar',
            'Tangerang Selatan', 'Bogor', 'Batam', 'Pekanbaru', 'Bandar Lampung',
            'Malang', 'Padang', 'Denpasar', 'Samarinda', 'Serang'
        ];

        $stmt = $pdo->prepare("INSERT INTO locations (name) VALUES (?)");
        foreach ($locations as $location) {
            $stmt->execute([$location]);
        }

        echo "Successfully created locations table and inserted sample data.";
    } else {
        echo "Locations table already exists with $count entries.";
    }

} catch (PDOException $e) {
    die("Error: " . $e->getMessage());
}
