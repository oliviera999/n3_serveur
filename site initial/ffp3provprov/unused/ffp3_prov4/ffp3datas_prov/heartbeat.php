<?php
// --- heartbeat.php ---
// Réception battement de coeur de l'ESP32 (uptime, mémoire, reboot count)
// Exemple de requête envoyée par l'ESP32 :
//   uptime=157&free=191600&min=178404&reboots=12&crc=F7AB59BB
// NB : le CRC32 (polynôme 0xEDB88320) est calculé sur la portion AVANT le « &crc »

$servername = "localhost";
$dbname     = "oliviera_iot";
$username   = "oliviera_iot";
$password   = "Iot#Olution1";

$uptime = $free = $min = $reboots = $crc = "";

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    // --- Lecture & nettoyage des champs POST ---
    $uptime  = test_input($_POST["uptime"]  ?? "");
    $free    = test_input($_POST["free"]    ?? "");
    $min     = test_input($_POST["min"]     ?? "");
    $reboots = test_input($_POST["reboots"] ?? "");
    $crc     = strtoupper(test_input($_POST["crc"] ?? ""));

    // --- Construction de la chaîne brute pour vérification CRC ---
    $raw = "uptime={$uptime}&free={$free}&min={$min}&reboots={$reboots}";
    $calc_crc = strtoupper(hash('crc32b', $raw));

    if ($crc !== $calc_crc) {
        http_response_code(400);
        echo "CRC mismatch (calc={$calc_crc}, posted={$crc})";
        exit();
    }

    // --- Insertion base de données ---
    $conn = new mysqli($servername, $username, $password, $dbname);
    if ($conn->connect_error) {
        http_response_code(500);
        die("DB connection failed: " . $conn->connect_error);
    }

    $uptime  = (int)$uptime;
    $free    = (int)$free;
    $min     = (int)$min;
    $reboots = (int)$reboots;

    $sql = "INSERT INTO ffp3Heartbeat (uptime, freeHeap, minHeap, reboots) VALUES ($uptime, $free, $min, $reboots)";

    if ($conn->query($sql) === TRUE) {
        echo "OK";
    } else {
        http_response_code(500);
        echo "SQL error: " . $conn->error;
    }
    $conn->close();
} else {
    echo "No data posted";
}

function test_input($data) {
    $data = trim($data);
    $data = stripslashes($data);
    $data = htmlspecialchars($data);
    return $data;
}
?> 