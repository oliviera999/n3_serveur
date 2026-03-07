<?Php

require_once __DIR__ . '/vendor/autoload.php';

use App\Config\Env;

// Chargement des variables d'environnement (.env)
Env::load();

// Paramètres de connexion récupérés depuis les variables d'environnement
$host_name = $_ENV['DB_HOST'] ?? 'localhost';
$dbname    = $_ENV['DB_NAME'] ?? '';
$username  = $_ENV['DB_USER'] ?? '';
$password  = $_ENV['DB_PASS'] ?? '';

$mailSent = null;
$mailSentBefore = null;
global $mailSentLatest;
$mailSentLatest = $mailSentLatest ?? 0;

$servername = $host_name;


//error_reporting(0);// With this no error reporting will be there
//////// Do not Edit below /////////

$connection = mysqli_connect($host_name, $username, $password, $dbname);

if (!$connection) {
    echo "Error: Unable to connect to MySQL.<br>";
    echo "<br>Debugging errno: " . mysqli_connect_errno();
    echo "<br>Debugging error: " . mysqli_connect_error();
    exit;
}




function getSensorData($start_date, $end_date) {
    global $servername, $username, $password, $dbname;

    // Créer une connexion à la base de données
    $conn = new mysqli($servername, $username, $password, $dbname);
    if ($conn->connect_error) {
        die("Connection failed: " . $conn->connect_error);
    }

    // Requête SQL préparée
    $sql = "SELECT  id,TempAir,Humidite,TempEau,EauPotager,EauAquarium,EauReserve,Luminosite, etatPompeAqua, etatPompeTank, etatHeat, etatUV, bouffePetits, bouffeGros, reading_time  
            FROM ffp3Data 
            WHERE reading_time BETWEEN ? AND ? 
            ORDER BY reading_time DESC";

    // Préparer la requête
    if ($stmt = $conn->prepare($sql)) {
        // Lier les paramètres
        $stmt->bind_param("ss", $start_date, $end_date);
        
        // Exécuter la requête
        $stmt->execute();
        
        // Récupérer les résultats
        $result = $stmt->get_result();
        
        // Stocker les données dans un tableau
        $sensor_data = [];
        while ($row = $result->fetch_assoc()) {
            $sensor_data[] = $row;
        }
        
        // Libérer les ressources et fermer la connexion
        $stmt->close();
        $conn->close();
        
        // Retourner les données
        return $sensor_data;
    } else {
        // En cas d'erreur
        echo "Erreur lors de la préparation de la requête : " . $conn->error;
        exit;
    }
}

// Fonction pour récupérer la dernière date enregistrée
function getLastReadingDate() {
    global $connection;

    $sql = "SELECT MAX(reading_time) AS last_date FROM ffp3Data";
    $result = mysqli_query($connection, $sql);

    if ($result && $row = mysqli_fetch_assoc($result)) {
        return $row['last_date'];
    }
    return null;
}
  
function exportSensorData($start_date, $end_date) {
    global $servername, $username, $password, $dbname;

    // Connexion à la base de données
    $conn = new mysqli($servername, $username, $password, $dbname);
    if ($conn->connect_error) {
        die("Échec de la connexion : " . $conn->connect_error);
    }

    // Requête SQL pour récupérer les données entre les dates sélectionnées
    $sql = "SELECT  id,TempAir,Humidite,TempEau,EauPotager,EauAquarium,EauReserve,Luminosite, etatPompeAqua, etatPompeTank, etatHeat, etatUV, bouffePetits, bouffeGros, reading_time  
            FROM ffp3Data 
            WHERE reading_time BETWEEN ? AND ? 
            ORDER BY reading_time DESC";

    $stmt = $conn->prepare($sql);
    $stmt->bind_param("ss", $start_date, $end_date);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows > 0) {
        // Définir l'en-tête pour le téléchargement du CSV
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename=données_capteurs.csv');

        // Ouvrir la sortie en mode écriture
        $output = fopen('php://output', 'w');

        // Écrire l'en-tête du fich ier CSV
        fputcsv($output, ['id', 'TempAir', 'Humidite', 'TempEau', 'EauPotager', 'EauAquarium', 'EauReserve', 'Luminosite', 'etatPompeAqua', 'etatPompeTank', 'etatHeat', 'etatUV', 'bouffePetits', 'bouffeGros', 'reading_time']);

        // Écrire les lignes de données
        while ($row = $result->fetch_assoc()) {
            fputcsv($output, $row);
        }

        fclose($output);
        exit(); // Arrêter l'exécution après avoir généré le fichier CSV
    } else {
        echo "Aucune donnée disponible pour cette période.";
    }

    $stmt->close();
    $conn->close();
}  
  
function getAllReadings() {
    global $connection; // Utiliser la connexion globale existante

    $sql = "SELECT MAX(id) AS max_amount2 FROM ffp3Data";
    $result = mysqli_query($connection, $sql);
    
    return $result ? mysqli_fetch_assoc($result) : false;
}
  
  /*
  function getAllReadings2() {
    global $servername, $username, $password, $dbname;

    // Create connection
    $conn = new mysqli($servername, $username, $password, $dbname);
    // Check connection
    if ($conn->connect_error) {
      die("Connection failed: " . $conn->connect_error);
    }
    $sql = "SELECT MAX(id) AS max_amount2 FROM (SELECT id FROM ffp3Data order by reading_time desc) AS max2";
 if ($result = $conn->query($sql)) {
      return $result->fetch_assoc();
    }
    else {
      return false;
    }
    $conn->close();
  }*/
  
  function getLastReadings() {
    global $servername, $username, $password, $dbname;

    // Create connection
    $conn = new mysqli($servername, $username, $password, $dbname);
    // Check connection
    if ($conn->connect_error) {
      die("Connection failed: " . $conn->connect_error);
    }

    $sql = "SELECT id, sensor, version, TempAir, TempEau, Humidite, Luminosite, EauAquarium, EauReserve, EauPotager, reading_time FROM ffp3Data order by reading_time desc limit 1" ;
    if ($result = $conn->query($sql)) {
      return $result->fetch_assoc();
    }
    else {
      return false;
    }
    $conn->close();
  }
  
    function getFirstReadings($limit) {
    global $servername, $username, $password, $dbname;

    // Create connection
    $conn = new mysqli($servername, $username, $password, $dbname);
    // Check connection
    if ($conn->connect_error) {
      die("Connection failed: " . $conn->connect_error);
    }

    $sql = "SELECT reading_time AS min_amount2 FROM (SELECT reading_time FROM ffp3Data order by reading_time desc limit " . $limit . ") AS min2 order by reading_time ASC limit 1";
    if ($result = $conn->query($sql)) {
      return $result->fetch_assoc();
    }
    else {
      return false;
    }
    $conn->close();
  }
  
  function getFirstReadingsbegin() {
    global $servername, $username, $password, $dbname;

    // Create connection
    $conn = new mysqli($servername, $username, $password, $dbname);
    // Check connection
    if ($conn->connect_error) {
      die("Connection failed: " . $conn->connect_error);
    }

    $sql = "SELECT reading_time AS min_amount3 FROM (SELECT reading_time FROM ffp3Data ) AS min3 order by reading_time ASC limit 1";
    if ($result = $conn->query($sql)) {
      return $result->fetch_assoc();
    }
    else {
      return false;
    }
    $conn->close();
  }
  
function maxReading($start_date, $end_date, $value) {
    global $servername, $username, $password, $dbname;

    // Créer une connexion
    $conn = new mysqli($servername, $username, $password, $dbname);
    if ($conn->connect_error) {
        die("Erreur de connexion : " . $conn->connect_error);
    }

    // Requête SQL avec les dates
    $sql = "SELECT MAX($value) AS max_amount 
            FROM ffp3Data 
            WHERE reading_time BETWEEN ? AND ?";

    if ($stmt = $conn->prepare($sql)) {
        $stmt->bind_param("ss", $start_date, $end_date);
        $stmt->execute();

        $result = $stmt->get_result();
        if ($row = $result->fetch_assoc()) {
            $stmt->close();
            $conn->close();
            return $row['max_amount']; // Retourner la valeur maximale
        } else {
            echo "Aucune donnée trouvée pour la plage de dates donnée.";
        }
    } else {
        echo "Erreur lors de la préparation de la requête : " . $conn->error;
    }

    $conn->close();
    return false; // En cas d'échec
}

function minReading($start_date, $end_date, $value) {
    global $servername, $username, $password, $dbname;

    // Créer une connexion
    $conn = new mysqli($servername, $username, $password, $dbname);
    if ($conn->connect_error) {
        die("Erreur de connexion : " . $conn->connect_error);
    }

    // Requête SQL sécurisée
    $sql = "SELECT MIN($value) AS min_amount 
            FROM ffp3Data 
            WHERE reading_time BETWEEN ? AND ?";

    if ($stmt = $conn->prepare($sql)) {
        $stmt->bind_param("ss", $start_date, $end_date);
        $stmt->execute();

        $result = $stmt->get_result();
        if ($row = $result->fetch_assoc()) {
            $stmt->close();
            $conn->close();
            return $row['min_amount'];
        }
    } else {
        echo "Erreur lors de la préparation de la requête : " . $conn->error;
    }

    $stmt->close();
    $conn->close();
    return false;
}

function avgReading($start_date, $end_date, $value) {
    global $servername, $username, $password, $dbname;

    // Créer une connexion
    $conn = new mysqli($servername, $username, $password, $dbname);
    if ($conn->connect_error) {
        die("Erreur de connexion : " . $conn->connect_error);
    }

    // Requête SQL sécurisée
    $sql = "SELECT AVG($value) AS avg_amount 
            FROM ffp3Data 
            WHERE reading_time BETWEEN ? AND ?";

    if ($stmt = $conn->prepare($sql)) {
        $stmt->bind_param("ss", $start_date, $end_date);
        $stmt->execute();

        $result = $stmt->get_result();
        if ($row = $result->fetch_assoc()) {
            $stmt->close();
            $conn->close();
            return $row['avg_amount'];
        }
    } else {
        echo "Erreur lors de la préparation de la requête : " . $conn->error;
    }

    $stmt->close();
    $conn->close();
    return false;
}

function stddevReading($start_date, $end_date, $value) {
    global $servername, $username, $password, $dbname;

    // Créer une connexion
    $conn = new mysqli($servername, $username, $password, $dbname);
    if ($conn->connect_error) {
        die("Erreur de connexion : " . $conn->connect_error);
    }

    // Requête SQL sécurisée
    $sql = "SELECT STDDEV($value) AS stddev_amount 
            FROM ffp3Data 
            WHERE reading_time BETWEEN ? AND ?";

    if ($stmt = $conn->prepare($sql)) {
        $stmt->bind_param("ss", $start_date, $end_date);
        $stmt->execute();

        $result = $stmt->get_result();
        if ($row = $result->fetch_assoc()) {
            $stmt->close();
            $conn->close();
            return $row['stddev_amount'];
        }
    } else {
        echo "Erreur lors de la préparation de la requête : " . $conn->error;
    }

    $stmt->close();
    $conn->close();
    return false;
}
    
  function stddevReading2 ($value) {
     global $servername, $username, $password, $dbname;

    // Create connection
    $conn = new mysqli($servername, $username, $password, $dbname);
    // Check connection
    if ($conn->connect_error) {
      die("Connection failed: " . $conn->connect_error);
    }

    $sql = "SELECT STDDEV(" . $value . ") AS stddev_amount2 FROM (SELECT " . $value . " FROM ffp3Data order by id desc limit 40) AS stddev";
    if ($result = $conn->query($sql)) {
      return $result->fetch_assoc();
    }
    else {
      return false;
    }
    $conn->close();
    }
    
  function etatPompeAqua () {
     global $servername, $username, $password, $dbname;

    // Create connection
    $conn = new mysqli($servername, $username, $password, $dbname);
    // Check connection
    if ($conn->connect_error) {
      die("Connection failed: " . $conn->connect_error);
    }

    $sql = "SELECT state FROM `ffp3Outputs` WHERE gpio='16'";
    if ($result = $conn->query($sql)) {
      return $result->fetch_assoc();
    }
    else {
      return false;
    }
    $conn->close();
    }
    
  function etatPompeTank () {
     global $servername, $username, $password, $dbname;

    // Create connection
    $conn = new mysqli($servername, $username, $password, $dbname);
    // Check connection
    if ($conn->connect_error) {
      die("Connection failed: " . $conn->connect_error);
    }

    $sql = "SELECT state FROM `ffp3Outputs` WHERE gpio='18'";
    if ($result = $conn->query($sql)) {
      return $result->fetch_assoc();
    }
    else {
      return false;
    }
    $conn->close();
    }
    
  function etatResetMode () {
     global $servername, $username, $password, $dbname;

    // Create connection
    $conn = new mysqli($servername, $username, $password, $dbname);
    // Check connection
    if ($conn->connect_error) {
      die("Connection failed: " . $conn->connect_error);
    }

    $sql = "SELECT state FROM `ffp3Outputs` WHERE gpio='110'";
    if ($result = $conn->query($sql)) {
      return $result->fetch_assoc();
    }
    else {
      return false;
    }
    $conn->close();
    }
    
  function stopPompeAqua () {
     global $servername, $username, $password, $dbname;

    // Create connection
    $conn = new mysqli($servername, $username, $password, $dbname);
    // Check connection
    if ($conn->connect_error) {
      die("Connection failed: " . $conn->connect_error);
    }

    $sql = "UPDATE ffp3Outputs SET state = '0' WHERE gpio= '16'";
    if ($conn->query($sql) === TRUE) {
        echo "Mise hors tension de la pompe ok". '<br />';
    } 
    else {
        echo "Error: " . $sql . "<br>" . $conn->error;
    }
    $conn->close();
    }
    
  function runPompeAqua () {
     global $servername, $username, $password, $dbname;

    // Create connection
    $conn = new mysqli($servername, $username, $password, $dbname);
    // Check connection
    if ($conn->connect_error) {
      die("Connection failed: " . $conn->connect_error);
    }

    $sql = "UPDATE ffp3Outputs SET state = '1' WHERE gpio= '16'";
    if ($conn->query($sql) === TRUE) {
        echo "Allumage de la pompe ok". '<br />';
    } 
    else {
        echo "Error: " . $sql . "<br>" . $conn->error;
    }
    $conn->close();
    }
    
  function stopPompeTank () {
     global $servername, $username, $password, $dbname;

    // Create connection
    $conn = new mysqli($servername, $username, $password, $dbname);
    // Check connection
    if ($conn->connect_error) {
      die("Connection failed: " . $conn->connect_error);
    }

    $sql = "UPDATE ffp3Outputs SET state = '1' WHERE gpio= '18'";
    if ($conn->query($sql) === TRUE) {
        echo "Mise hors tension de la pompe de la réserve ok". '<br />';
    } 
    else {
        echo "Error: " . $sql . "<br>" . $conn->error;
    }
    $conn->close();
    }
    
  function runPompeTank () {
     global $servername, $username, $password, $dbname;

    // Create connection
    $conn = new mysqli($servername, $username, $password, $dbname);
    // Check connection
    if ($conn->connect_error) {
      die("Connection failed: " . $conn->connect_error);
    }

    $sql = "UPDATE ffp3Outputs SET state = '0' WHERE gpio= '18'";
    if ($conn->query($sql) === TRUE) {
        echo "Allumage de la pompe réserve". '<br />';
    } 
    else {
        echo "Error: " . $sql . "<br>" . $conn->error;
    }
    $conn->close();
    }
    
/*function countDatas() {
    global $servername, $username, $password, $dbname;

    // Create connection
    $conn = new mysqli($servername, $username, $password, $dbname);
    // Check connection
    if ($conn->connect_error) {
      die("Connection failed: " . $conn->connect_error);
    }

    $sql = "SELECT COUNT(*) FROM ffp3Data WHERE TempEau < 3" ;
    if ($result = $conn->query($sql)) {
      return $result->fetch_assoc();
    }
    else {
      return false;
    }
    $conn->close();
        }*/
    
    
function countDatasMin($var,$thresh) {
    global $servername, $username, $password, $dbname;

    // Create connection
    $conn = new mysqli($servername, $username, $password, $dbname);
    // Check connection
    if ($conn->connect_error) {
      die("Connection failed: " . $conn->connect_error);
    }

    $sql = "SELECT COUNT(*) FROM ffp3Data WHERE " . $var . " < " . $thresh . "" ;
    if ($result = $conn->query($sql)) {
      return $result->fetch_assoc();
    }
    else {
      return false;
    }
    $conn->close();
}

function countDatasMax($var,$thresh) {
    global $servername, $username, $password, $dbname;

    // Create connection
    $conn = new mysqli($servername, $username, $password, $dbname);
    // Check connection
    if ($conn->connect_error) {
      die("Connection failed: " . $conn->connect_error);
    }

    $sql = "SELECT COUNT(*) FROM ffp3Data WHERE " . $var . " > " . $thresh . "" ;
    if ($result = $conn->query($sql)) {
      return $result->fetch_assoc();
    }
    else {
      return false;
    }
    $conn->close();
        }

  function countDatasTempEau($var) {
    global $servername, $username, $password, $dbname;

    // Create connection
    $conn = new mysqli($servername, $username, $password, $dbname);
    // Check connection
    if ($conn->connect_error) {
      die("Connection failed: " . $conn->connect_error);
    }

    $sql = "SELECT COUNT(*) FROM ffp3Data WHERE " . $var . " = '25'";
    if ($result = $conn->query($sql)) {
      return $result->fetch_assoc();
    }
    else {
      return false;
    }
    $conn->close();
        }
    
    function delDatas($var,$thresh) {
        global $servername, $username, $password, $dbname;

        // Create connection
        $conn = new mysqli($servername, $username, $password, $dbname);
        // Check connection
        if ($conn->connect_error) {
          die("Connection failed: " . $conn->connect_error);
        }
    
        $sql = "DELETE FROM `ffp3Data` WHERE " . $var . " < " . $thresh . "" ;
        if ($result = $conn->query($sql)) {
          return $result->fetch_assoc();
        }
        else {
          return false;
        }
        $conn->close();
        }
       
    function changeDatasMin($var,$thresh) {
        global $servername, $username, $password, $dbname;

        // Create connection
        $conn = new mysqli($servername, $username, $password, $dbname);
        // Check connection
        if ($conn->connect_error) {
          die("Connection failed: " . $conn->connect_error);
        }
    
        //$sql = "UPDATE 'ffp3Data' SET " . $var . "= NULL WHERE " . $var . " < " . $thresh . "";
        $sql = "UPDATE ffp3Data SET `" . $var . "` = NULL WHERE `" . $var . "` < " . $thresh;
        if ($conn->query($sql) === TRUE) {
            return true;
        } else {
            return false;
        }
        $conn->close();
        }

    function changeDatasMax($var,$thresh) {
        global $servername, $username, $password, $dbname;

        // Create connection
        $conn = new mysqli($servername, $username, $password, $dbname);
        // Check connection
        if ($conn->connect_error) {
          die("Connection failed: " . $conn->connect_error);
        }
    
        //$sql = "UPDATE 'ffp3Data' SET " . $var . "= NULL WHERE " . $var . " < " . $thresh . "";
        $sql = "UPDATE ffp3Data SET `" . $var . "` = NULL WHERE `" . $var . "` > " . $thresh;
        if ($conn->query($sql) === TRUE) {
            return true;
        } else {
            return false;
        }
        $conn->close();
        }

        
    function delDatasTempEau($var) {
        global $servername, $username, $password, $dbname;

        // Create connection
        $conn = new mysqli($servername, $username, $password, $dbname);
        // Check connection
        if ($conn->connect_error) {
          die("Connection failed: " . $conn->connect_error);
        }
    
        $sql = "DELETE FROM `ffp3Data` WHERE " . $var . " = '25'" ;
        if ($result = $conn->query($sql)) {
          return $result->fetch_assoc();
        }
        else {
          return false;
        }
        $conn->close();
        }
        
    function changeDatasTempEau($var) {
        global $servername, $username, $password, $dbname;

        // Create connection
        $conn = new mysqli($servername, $username, $password, $dbname);
        // Check connection
        if ($conn->connect_error) {
          die("Connection failed: " . $conn->connect_error);
        }
    
        $sql = "UPDATE ffp3Data SET `" . $var . "` = NULL WHERE `" . $var . "` = 25";
        if ($conn->query($sql) === TRUE) {
            return true;
        } else {
            return false;
        }
        $conn->close();
        }
   
    function rebootEsp() {
        global $servername, $username, $password, $dbname;

        // Create connection
        $conn = new mysqli($servername, $username, $password, $dbname);
        // Check connection
        if ($conn->connect_error) {
          die("Connection failed: " . $conn->connect_error);
        }
    
        $sql = "UPDATE ffp3Outputs SET state = '1' WHERE gpio= '110'";
        if ($conn->query($sql) === TRUE) {
            echo "Changement d'état du reset mode ok". '<br />';
        } 
        else {
            echo "Error: " . $sql . "<br>" . $conn->error;
        }
        $conn->close();
    }
        
function checkOnline() {
    global $servername, $username, $password, $dbname;
    
    $mailSentLatest = $mailSentLatest ?? 0;
    
    // Création de la connexion à la base de données
    $conn = new mysqli($servername, $username, $password, $dbname);
    
    // Vérification de la connexion
    if ($conn->connect_error) {
        die("Connection failed: " . $conn->connect_error);
    }

    // Obtention du timestamp du dernier enregistrement
    $sql = "SELECT MAX(`reading_time`) as lastDataTime FROM ffp3Data";
    if ($result = $conn->query($sql)) {
        if ($row = $result->fetch_assoc()) {
            $lastDataTime = strtotime($row['lastDataTime']);
            echo "Valeur du timestamp reading_time : $lastDataTime<br />"; // Affichage de la valeur récupérée
        }
    }

    // Obtention de l'info pour savoir si un mail a déjà été envoyé
    $sql = "SELECT mailSent FROM ffp3Data WHERE reading_time = (SELECT MAX(reading_time) FROM ffp3Data) LIMIT 1";
    if ($result = $conn->query($sql)) {
        if ($row = $result->fetch_assoc()) {
            $mailSent = $row['mailSent'];
            echo "Valeur mailSent : $mailSent <br />"; // Affichage de la valeur récupérée
        }
    }

    // Si le dernier enregistrement a plus de 15 minutes et le mail n'a pas encore été envoyé
    if (isset($lastDataTime) && time() - $lastDataTime > 900 && $mailSent == 0) {
        // Envoyer un e-mail
        $to = "oliv.arn.lau@gmail.com";
        $subject = "Pas de données reçues depuis longtemps";
        $message = "Cela fait 15 minutes ou plus qu'auncunes données n'a été reçues du ffp3.";
        $headers = "From: arnould.svt@gmail.com";
        if (mail($to, $subject, $message, $headers)) {
            // Mettre à jour la colonne 'mailSent' dans la base de données
            $sql = "UPDATE ffp3Data SET mailSent = 1 WHERE reading_time = (SELECT MAX(reading_time) FROM ffp3Data)";
            if ($conn->query($sql) === TRUE) {
                echo "E-mail envoyé avec succès.'<br />'";
            } else {
                echo "Erreur lors de la mise à jour de la base de données : <br />" . $conn->error;
            }
        } else {
            echo "Erreur lors de l'envoi de l'e-mail.<br />" ;
        }
    }
    
    // Obtention de l'info pour savoir si un mail a déjà été envoyé dans l'avant-dernier enregistrement
    //    $sql = "SELECT mailSent FROM ffp3Data WHERE reading_time = (SELECT MAX(reading_time) FROM ffp3Data) ORDER BY reading_time DESC LIMIT 2 OFFSET 1";
    //    $sql = "SELECT mailSent FROM ffp3Data WHERE reading_time = (SELECT MAX(reading_time) FROM ffp3Data) ORDER BY reading_time ASC LIMIT 2 OFFSET 1";
    $sql = "SELECT mailSent FROM ffp3Data ORDER BY reading_time DESC LIMIT 1 OFFSET 1";
    if ($result = $conn->query($sql)) {
        if ($row = $result->fetch_assoc()) {
            $mailSentBefore = $row['mailSent'];
            echo "Valeur avant dernier enenregistrement mail envoyé : $mailSentBefore <br />"; // Affichage de la valeur récupérée
        }
    }
    
    // Obtention de l'info pour savoir si un mail a déjà été envoyé dans le dernier enregistrement
    $sql = "SELECT mailSent FROM ffp3Data WHERE reading_time = (SELECT MAX(reading_time) FROM ffp3Data) LIMIT 1";
    if ($result = $conn->query($sql)) {
        if ($row = $result->fetch_assoc()) {
            $mailSentLatest = $row['mailSent'];
        }
    }
    
    // Si un mail a été envoyé dans l'avant-dernier enregistrement et un mail n'a pas été envoyé dans le dernier enregistrement
    if ($mailSentBefore == 1 && $mailSentLatest == 0) {
        //changer la valeur de l'avant dernier enregistrement pour éviter le spam de mail de connexion rétablie
        //$sql = "SELECT mailSent FROM ffp3Data ORDER BY reading_time DESC LIMIT 1 OFFSET 1";
        $sql = "UPDATE ffp3Data SET mailSent = 0 WHERE reading_time = (SELECT reading_time FROM ffp3Data ORDER BY reading_time DESC LIMIT 1 OFFSET 1)";
        $result = $conn->query($sql);
        // Envoyer un autre e-mail
        $to = "oliv.arn.lau@gmail.com";
        $subject = "Connexion ffp3 rétablie";
        $message = "La connexion avec le module ffp3 a été rétablie.";
        $headers = "From: arnould.svt@gmail.com";
        if (mail($to, $subject, $message, $headers)) {
            echo "Another email sent successfully.'<br />'";
        } else {
            echo "Error sending another email.<br />";
        }
    }

    // Fermer la connexion à la base de données
    $conn->close();
    
    return false;
}   

function checkTankLevel() {
    global $servername, $username, $password, $dbname;
    
    // Création de la connexion à la base de données
    $conn = new mysqli($servername, $username, $password, $dbname);
    
    // Vérification de la connexion
    if ($conn->connect_error) {
        die("Connection failed: " . $conn->connect_error);
    }

    // Obtention de l'info pour savoir si un mail a déjà été envoyé
    $sql = "SELECT EauReserve FROM ffp3Data WHERE reading_time = (SELECT MAX(reading_time) FROM ffp3Data) LIMIT 1";
        if ($result = $conn->query($sql)) {
            if ($row = $result->fetch_assoc()) {
                $lastTankLevel = $row['EauReserve'];
                echo "Valeur lastTankLevel : $lastTankLevel<br />"; // Affichage de la valeur récupérée
            }
        }
    
        // Obtention de l'info pour savoir si un mail a déjà été envoyé dans l'avant-dernier enregistrement
//        $sql = "SELECT EauReserve FROM ffp3Data WHERE reading_time = (SELECT MAX(reading_time) FROM ffp3Data) ORDER BY reading_time DESC LIMIT 2 OFFSET 1";
        $sql = "SELECT EauReserve FROM ffp3Data ORDER BY reading_time DESC LIMIT 1 OFFSET 1";

        if ($result = $conn->query($sql)) {
            if ($row = $result->fetch_assoc()) {
                $beforeTankLevel = $row['EauReserve'];
                echo "Valeur beforeTankLevel : $beforeTankLevel<br />"; // Affichage de la valeur récupérée
            }
        }
        
        $diffTankLevels = $lastTankLevel - $beforeTankLevel;
        echo "différence de niveau entre les deux dernières mesures de la réserve : $diffTankLevels<br />";

        
        if ($diffTankLevels > 15) {
            $valeurRectifiee = $beforeTankLevel + 1;
            //$sql = "DELETE EauReserve FROM ffp3Data WHERE reading_time = (SELECT MAX(reading_time) FROM ffp3Data) LIMIT 1";
            $sql = "UPDATE ffp3Data SET EauReserve = " . $valeurRectifiee . " WHERE reading_time = (SELECT MAX(reading_time) FROM ffp3Data) LIMIT 1";
            echo "Valeur aberrante ($lastTankLevel) sur le niveau d'eau de la réserve changée par celle rectifiée<br />";
            if ($conn->query($sql) === TRUE) {
                return true;
            } else {
                return false;
            }
        }
        else {
                echo "Pas de valeur aberrante sur le niveau d'eau de la réserve<br />";
            }

        // Obtention de l'info pour savoir si un mail a déjà été envoyé dans l'avant-dernier enregistrement
//        $sql = "SELECT EauReserve FROM ffp3Data WHERE reading_time = (SELECT MAX(reading_time) FROM ffp3Data) ORDER BY reading_time DESC LIMIT 2 OFFSET 1";
        $sql = "SELECT EauReserve FROM ffp3Data ORDER BY reading_time DESC LIMIT 2 OFFSET 2";

        if ($result = $conn->query($sql)) {
            if ($row = $result->fetch_assoc()) {
                $beforeTankLevel = $row['EauReserve'];
                echo "Valeur beforeTankLevel : $beforeTankLevel<br />"; // Affichage de la valeur récupérée
            }
        }
        
        $diffTankLevels = $lastTankLevel - $beforeTankLevel;
        echo "différence de niveau entre les deux dernières mesures de la réserve : $diffTankLevels<br />";

        
        if ($diffTankLevels > 15) {
            $valeurRectifiee = $beforeTankLevel + 1;
            //$sql = "DELETE EauReserve FROM ffp3Data WHERE reading_time = (SELECT MAX(reading_time) FROM ffp3Data) LIMIT 1";
            $sql = "UPDATE ffp3Data SET EauReserve = " . $valeurRectifiee . " WHERE reading_time = (SELECT MAX(reading_time) FROM ffp3Data) LIMIT 1";
            echo "Valeur aberrante ($lastTankLevel) sur le niveau d'eau de la réserve changée par celle rectifiée<br />";
            if ($conn->query($sql) === TRUE) {
                return true;
            } else {
                return false;
            }
        }
        else {
                echo "Pas de valeur aberrante sur le niveau d'eau de la réserve<br />";
            }

/*    
        // Si un mail a été envoyé dans l'avant-dernier enregistrement et un mail n'a pas été envoyé dans le dernier enregistrement
        if (($lastTankLevel - $beforeTankLevel)>20 ) {
        $sql = "DELETE EauReserve FROM ffp3Data WHERE reading_time = (SELECT MAX(reading_time) FROM ffp3Data) LIMIT 1";
        if ($result = $conn->query($sql)) {
            if ($row = $result->fetch_assoc()) {
                $lastTankLevel = $row['EauReserve'];
                echo "Valeur aberrantes ($lastTankLevel) sur le niveau d'eau de la réserve supprimée'<br />'"; // Affichage de la valeur récupérée
            }
        }
        else {
            echo "Pas de valeur aberrantes sur le niveau d'eau de la réserve'<br />'";
        }
    }*/
    // Fermer la connexion à la base de données
    $conn->close();
    
    return false;
}

function mailMarees() {
    $mailSentLatest = $mailSentLatest ?? 0;
   if ($mailSentLatest == 0){
        // Paramètres du mail
        $to = "oliv.arn.lau@gmail.com";
        $subject = "Marées arrêtées";
        $message = "Les marées ne se font pas correctement.";
        $headers = "From: arnould.svt@gmail.com";
        // Envoi du mail
        $result = mail($to, $subject, $message, $headers);
        if ($result) {
            echo "Test email sent successfully.";
        } else {
            echo "Failed to send test email.";
        }
   }
}

function mailFlood() {
    // Paramètres du mail
    $mailSentLatest = $mailSentLatest ?? 0;
   if ($mailSentLatest == 0){
        $to = "oliv.arn.lau@gmail.com";
        $subject = "Niveau de l'aquarium élevé";
        $message = "L'aquarium va déborder.";
        $headers = "From: arnould.svt@gmail.com";
        // Envoi du mail
        $result = mail($to, $subject, $message, $headers);
        if ($result) {
            echo "Test email sent successfully.";
        } else {
            echo "Failed to send test email.";
        }
   }
}

?>