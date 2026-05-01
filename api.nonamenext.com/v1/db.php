<?php

function getDB() {
    $host = 'localhost';
    $db   = '{no......db}';
    $user = '{no......db}';
    $pass = '{Fo......dN}';
    $charset = 'utf8mb4';

    $dsn = "mysql:host=$host;dbname=$db;charset=$charset";
    try {
        return new PDO($dsn, $user, $pass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
    } catch (\PDOException $e) {
        echo res_json(['error' => 'Database connection failed'], 500);
    }
}