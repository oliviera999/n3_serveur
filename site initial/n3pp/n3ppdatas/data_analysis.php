<?php
// Configuration de la base de données
$host = 'localhost';
$dbname = 'oliviera_iot';
$user = 'oliviera_iot';
$password = 'Iot#Olution1';

try {
    // Connexion à la base de données
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8", $user, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // Vérification des paramètres
    if (isset($_GET['start_date'], $_GET['end_date'])) {
        $start_date = $_GET['start_date'];
        $end_date = $_GET['end_date'];

        // Validation des dates
        if (strtotime($start_date) && strtotime($end_date)) {
            // Ajout des heures pour couvrir toute la journée
            $start_datetime = $start_date . " 00:00:00";
            $end_datetime = $end_date . " 23:59:59";

            // Requête SQL
            $query = $pdo->prepare("SELECT * FROM ffp3Data WHERE reading_time BETWEEN :start_date AND :end_date");
            $query->bindParam(':start_date', $start_datetime);
            $query->bindParam(':end_date', $end_datetime);
            $query->execute();

            // Résultats
            $results = $query->fetchAll(PDO::FETCH_ASSOC);

            if ($results) {
                echo "<h2>Résultats des mesures entre $start_datetime et $end_datetime :</h2>";
                echo "<table border='1'>";
                echo "<tr><th>ID</th><th>Mesure</th><th>Date</th></tr>";
                foreach ($results as $row) {
                    echo "<tr>";
                    echo "<td>{$row['id']}</td>";
                    echo "<td>{$row['luminosite']}</td>";
                    echo "<td>{$row['reading_time']}</td>";
                    echo "</tr>";
                }
                echo "</table>";
            } else {
                echo "<p>Aucune donnée trouvée pour cette période.</p>";
            }
        } else {
            echo "<p>Dates invalides. Veuillez réessayer.</p>";
        }
    } else {
        echo "<p>Veuillez spécifier une période.</p>";
    }
} catch (PDOException $e) {
    echo "Erreur : " . $e->getMessage();
}
?>
