<?Php
$host_name = "localhost";
$dbname = "oliviera_iot"; // Change your database name
$username = "oliviera_iot";          // Your database user id 
$password = "Iot#Olution1";          // Your password

//error_reporting(0);// With this no error reporting will be there
//////// Do not Edit below /////////

$connection = mysqli_connect($host_name, $username, $password, $dbname);

if (!$connection) {
    echo "Error: Unable to connect to MySQL.<br>";
    echo "<br>Debugging errno: " . mysqli_connect_errno();
    echo "<br>Debugging error: " . mysqli_connect_error();
    exit;
}

// Connexion globale à la base de données
$connection = mysqli_connect($host_name, $username, $password, $dbname);

if (!$connection) {
    die("Erreur : Impossible de se connecter à MySQL.<br>" . mysqli_connect_error());
}

function getSensorData($start_date, $end_date) {
    global $servername, $username, $password, $dbname;

    // Créer une connexion à la base de données
    $conn = new mysqli($servername, $username, $password, $dbname);
    if ($conn->connect_error) {
        die("Connection failed: " . $conn->connect_error);
    }

    // Requête SQL préparée
    $sql = "SELECT id,TempAirInt,TempAirExt,HumidAirInt,HumidAirExt,Pluie,LuminositeA,LuminositeB,LuminositeC,LuminositeD,LuminositeMoy,ServoHB,ServoGD,HumidSol,TempEau,PontDiv,ArrosageManu,SeuilSec,mail,mailNotif,HeureArrosage,resetMode,etatPompe,tempsArrosage,bootCount,reading_time 
            FROM msp1Data 
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

function exportSensorData($start_date, $end_date) {
    global $servername, $username, $password, $dbname;

    // Connexion à la base de données
    $conn = new mysqli($servername, $username, $password, $dbname);
    if ($conn->connect_error) {
        die("Échec de la connexion : " . $conn->connect_error);
    }

    // Requête SQL préparée
    $sql = "SELECT id,TempAirInt,TempAirExt,HumidAirInt,HumidAirExt,Pluie,LuminositeA,LuminositeB,LuminositeC,LuminositeD,LuminositeMoy,ServoHB,ServoGD,HumidSol,TempEau,PontDiv,ArrosageManu,SeuilSec,mail,mailNotif,HeureArrosage,resetMode,etatPompe,tempsArrosage,bootCount,reading_time 
            FROM msp1Data 
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
        fputcsv($output, ['id', 'TempAirInt', 'TempAirExt', 'HumidAirInt', 'HumidAirExt', 'Pluie', 'LuminositeA', 'LuminositeB', 'LuminositeC', 'LuminositeD', 'LuminositeMoy', 'ServoHB', 'ServoGD', 'HumidSol', 'TempEau', 'PontDiv', 'ArrosageManu', 'SeuilSec', 'mail', 'mailNotif', 'HeureArrosage', 'resetMode', 'etatPompe', 'tempsArrosage', 'bootCount', 'reading_time']);

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

// Fonction pour récupérer la dernière date enregistrée
function getLastReadingDate() {
    global $connection;

    $sql = "SELECT MAX(reading_time) AS last_date FROM msp1Data";
    $result = mysqli_query($connection, $sql);

    if ($result && $row = mysqli_fetch_assoc($result)) {
        return $row['last_date'];
    }
    return null;
}

  function getAllReadings() {
    global $servername, $username, $password, $dbname;

    // Create connection
    $conn = new mysqli($servername, $username, $password, $dbname);
    // Check connection
    if ($conn->connect_error) {
      die("Connection failed: " . $conn->connect_error);
    }
    $sql = "SELECT MAX(id) AS max_amount2 FROM (SELECT id FROM msp1Data order by reading_time desc) AS max2";
 if ($result = $conn->query($sql)) {
      return $result->fetch_assoc();
    }
    else {
      return false;
    }
    $conn->close();
  }
  
function getLastReadings($start_date, $end_date) {
    // Inclure les variables globales pour la connexion à la base de données
    global $servername, $username, $password, $dbname;
    
    // Création de la connexion
    $conn = new mysqli($servername, $username, $password, $dbname);
    
    // Vérification de la connexion
    if ($conn->connect_error) {
        die("Échec de la connexion : " . $conn->connect_error);
    }
    
    // Requête SQL de base
    $sql = "SELECT  id, sensor, version, TempAirInt, TempAirExt, HumidAirInt, HumidAirExt, LuminositeA, LuminositeB, LuminositeC, LuminositeD, LuminositeMoy, ServoHB, ServoGD, HumidSol, Pluie, TempEau, ArrosageManu, SeuilSec, HeureArrosage, resetMode, etatPompe, reading_time 
            FROM msp1Data ";
    
    // Ajouter les conditions de date si elles sont fournies
    if ($start_date && $end_date) {
        $sql .= "WHERE reading_time BETWEEN ? AND ? ";
    }
    
    // Ajouter l'ordre et la limite
    $sql .= "ORDER BY reading_time DESC LIMIT 1";
    
    // Préparer la requête
    if ($stmt = $conn->prepare($sql)) {
        // Lier les paramètres si les dates sont fournies
        if ($start_date && $end_date) {
            $stmt->bind_param("ss", $start_date, $end_date);
        }
    
        // Exécuter la requête
        $stmt->execute();
    
        // Obtenir le résultat
        $result = $stmt->get_result();
    
        // Vérifier si des données sont présentes
        if ($result->num_rows > 0) {
            // Renvoyer les données sous forme de tableau associatif
            $data = $result->fetch_assoc();
        } else {
            // Aucun résultat
            $data = false;
        }
    
        // Libérer le résultat
        $stmt->close();
    } else {
        // Erreur dans la préparation de la requête
        $data = false;
    }
    
    // Fermeture de la connexion
    $conn->close();
    
    // Retour des données ou false en cas d'échec
    return $data;
}
  
/*    function getLastReadings2($limit) {
    global $servername, $username, $password, $dbname;

    // Create connection
    $conn = new mysqli($servername, $username, $password, $dbname);
    // Check connection
    if ($conn->connect_error) {
      die("Connection failed: " . $conn->connect_error);
    }

    $sql = "SELECT reading_time AS min_amount2 FROM (SELECT reading_time FROM msp1Data order by reading_time desc limit " . $limit . ") AS min2 order by reading_time ASC limit 1";
    if ($result = $conn->query($sql)) {
      return $result->fetch_assoc();
    }
    else {
      return false;
    }
    $conn->close();
  }*/
  
    function getFirstReadings($limit) {
    global $servername, $username, $password, $dbname;

    // Create connection
    $conn = new mysqli($servername, $username, $password, $dbname);
    // Check connection
    if ($conn->connect_error) {
      die("Connection failed: " . $conn->connect_error);
    }

    $sql = "SELECT reading_time AS min_amount2 FROM (SELECT reading_time FROM msp1Data order by reading_time desc limit " . $limit . ") AS min2 order by reading_time ASC limit 1";
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

    $sql = "SELECT reading_time AS min_amount3 FROM (SELECT reading_time FROM msp1Data ) AS min3 order by reading_time ASC limit 1";
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
            FROM msp1Data 
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
            FROM msp1Data 
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
            FROM msp1Data 
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
            FROM msp1Data 
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

    $sql = "SELECT STDDEV(" . $value . ") AS stddev_amount2 FROM (SELECT " . $value . " FROM msp1Data order by id desc limit 40) AS stddev";
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

    $sql = "SELECT state FROM `msp1Data` WHERE gpio='13'";
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

    $sql = "SELECT state FROM `msp1Data` WHERE gpio='110'";
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

    $sql = "UPDATE msp1Data SET state = '1' WHERE gpio= '13'";
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

    $sql = "UPDATE msp1Data SET state = '0' WHERE gpio= '13'";
    if ($conn->query($sql) === TRUE) {
        echo "Allumage de la pompe ok". '<br />';
    } 
    else {
        echo "Error: " . $sql . "<br>" . $conn->error;
    }
    $conn->close();
    }
    
  function countDatas($var,$thresh) {
    global $servername, $username, $password, $dbname;

    // Create connection
    $conn = new mysqli($servername, $username, $password, $dbname);
    // Check connection
    if ($conn->connect_error) {
      die("Connection failed: " . $conn->connect_error);
    }

    $sql = "SELECT COUNT(*) FROM msp1Data WHERE " . $var . " < " . $thresh . "" ;
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

    $sql = "SELECT COUNT(*) FROM msp1Data WHERE " . $var . " = '25'";
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
    
        $sql = "DELETE FROM `msp1Data` WHERE " . $var . " < " . $thresh . "" ;
        if ($result = $conn->query($sql)) {
          return $result->fetch_assoc();
        }
        else {
          return false;
        }
        $conn->close();
        }
        
    function changeDatas($var,$thresh) {
        global $servername, $username, $password, $dbname;

        // Create connection
        $conn = new mysqli($servername, $username, $password, $dbname);
        // Check connection
        if ($conn->connect_error) {
          die("Connection failed: " . $conn->connect_error);
        }
    
        //$sql = "UPDATE 'ffp3Data' SET " . $var . "= NULL WHERE " . $var . " < " . $thresh . "";
        $sql = "UPDATE msp1Data SET `" . $var . "` = NULL WHERE `" . $var . "` < " . $thresh;
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
    
        $sql = "DELETE FROM `msp1Data` WHERE " . $var . " = '25'" ;
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
    
        $sql = "UPDATE msp1Data SET `" . $var . "` = NULL WHERE `" . $var . "` = 25";
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
    
        $sql = "UPDATE msp1Data SET state = '1' WHERE gpio= '110'";
        if ($conn->query($sql) === TRUE) {
            echo "Changement d'état du reset mode ok". '<br />';
        } 
        else {
            echo "Error: " . $sql . "<br>" . $conn->error;
        }
        $conn->close();
        }
        
function checkData() {
    global $servername, $username, $password, $dbname;
    $lastDataTime = null;

    // Create connection
    $conn = new mysqli($servername, $username, $password, $dbname);
    // Check connection
    if ($conn->connect_error) {
        die("Connection failed: " . $conn->connect_error);
    }


    // Get the timestamp of the last recorded data
    $sql = "SELECT MAX(`reading_time`) as lastDataTime FROM msp1Data";

    if ($result = $conn->query($sql)) {
        if ($row = $result->fetch_assoc()) {
             $lastDataTime = $row['lastDataTime'];
            echo "Valeur du timestamp reading_time : $lastDataTime"; // Affichage de la valeur récupérée
            $lastDataTimeTimestamp = strtotime($lastDataTime);
            echo "Timestamp correspondant : $lastDataTimeTimestamp"; // Affichage du timestamp correspondant
        }
    }

    // If the last recorded data is more than 15 minutes old, send an email
    if ($lastDataTime && time() - $lastDataTime > 9) {
        // Send mail
        $to = "oliv.arn.lau@gmail.com";
        $subject = "No data received for 15 minutes";
        $message = "No data has been recorded in the last 15 minutes.";
        $headers = "From: arnould.svt@gmail.com";
        mail($to, $subject, $message, $headers);
        // Close the connection
        $conn->close();
        return true;
    } else {
        // Close the connection
        $conn->close();
        return false;
    }
}
        
        
function checkDataStatusAndSendEmail() {
    global $servername, $username, $password, $dbname;
    $lastDataTime = null;
    $lastSentTime = null;
    $mailSent = false;
    
    
    // Create connection
    $conn = new mysqli($servername, $username, $password, $dbname);
    // Check connection
    if ($conn->connect_error) {
        die("Connection failed: " . $conn->connect_error);
    }
        
    // Get the timestamp of the last recorded data
    $sql = "SELECT MAX(`reading_time`) as lastDataTime FROM msp1Data";
    if ($result = $conn->query($sql)) {
        if ($row = $result->fetch_assoc()) {
            $lastDataTime = strtotime($row['lastDataTime']);
        }
    }
    
        // If the last recorded data is more than 15 minutes old, send an email
        if ($lastDataTime && time() - $lastDataTime > 900) {
            // Check if a mail has already been sent
            $sql = "SELECT MAX(`reading_time`) as lastSentTime FROM msp1Data WHERE `mailSent` = 1";
            if ($result = $conn->query($sql)) {
                if ($row = $result->fetch_assoc()) {
                    $lastSentTime = strtotime($row['lastSentTime']);
                }
            }
            // If a mail has not been sent recently, send one and update the database
            if (!$lastSentTime || time() - $lastSentTime > 900) {
                // Send mail
                $to = "oliv.arn.lau@gmail.com";
                $subject = "No data received for 15 minutes";
                $message = "No data has been recorded in the last 15 minutes for variable " . $var;
                $headers = "From: ffp3@olution.info";
                mail($to, $subject, $message, $headers);
                // Update the database to mark that a mail has been sent
                $sql = "UPDATE msp1Data SET `mailSent` = 1 WHERE `reading_time` = (SELECT MAX(`reading_time`) FROM msp1Data)";
                $conn->query($sql);
                $mailSent = true;
            }
            else {
                $mailSent = true;
            }
            
         /*   // Get the timestamp of the last recorded data
            $sql = "SELECT MAX(`reading_time`) as lastDataTime FROM ffp3Data";
            if ($result = $conn->query($sql)) {
                if ($row = $result->fetch_assoc()) {
                    $lastDataTime = strtotime($row['lastDataTime']);
                }
            }
            
            // Check if an email has already been sent and get the timestamp
            $sql = "SELECT `reading_time` FROM ffp3Data WHERE `mailSent` = 1 ORDER BY `reading_time` DESC LIMIT 1";
            if ($result = $conn->query($sql)) {
                if ($row = $result->fetch_assoc()) {
                    $lastSentTime = strtotime($row['reading_time']);
                }
            }
            
            // Check if an email has already been sent and get the timestamp
            $sql = "SELECT `reading_time` FROM ffp3Data WHERE `mailSent` = 1 ORDER BY `reading_time` DESC LIMIT 1";
            if ($result = $conn->query($sql)) {
                if ($row = $result->fetch_assoc()) {
                    $lastSentTime = strtotime($row['reading_time']);
                }
            }
        
            // Check if a new email should be sent
            if ($lastDataTime && $lastSentTime > $lastSentTime && time() - $lastDataTime >= 900) {
                // Check if the message has already been sent
                $sql = "SELECT COUNT(*) AS messageCount FROM ffp3Data WHERE `mailSent` = 1 AND `reading_time` > '" . date('Y-m-d H:i:s', $lastSentTime) . "'";
                if ($result = $conn->query($sql)) {
                    if ($row = $result->fetch_assoc()) {
                        $messageCount = $row['messageCount'];
                        if ($messageCount === 0) {
                            // Calculate the time difference
                            $timeDifference = $lastDataTime - $lastSentTime;
        
                            // Send mail
                            $to = "oliv.arn.lau@gmail.com";
                            $subject = "Time elapsed between records";
                            $message = "The time elapsed between records with mailSent=1 and mailSent=0 is " . $timeDifference . " seconds.";
                            $headers = "From: ffp3@olution.info";
                            mail($to, $subject, $message, $headers);
                            $mailSent = true;
                        }
                    }
                }
            }*/
    
            // Close the connection
            $conn->close();
            return $mailSent;
        }
        else {
            // Close the connection
            $conn->close();
            return false;
    }
}

function testEmail() {
    // Paramètres du mail
    $to = "oliv.arn.lau@gmail.com";
    $subject = "Test Email";
    $message = "This is a test email sent from the cron job.";
    $headers = "From: arnould.svt@gmail.com";

    // Envoi du mail
    $result = mail($to, $subject, $message, $headers);
    if ($result) {
        echo "Test email sent successfully.";
    } else {
        echo "Failed to send test email.";
    }
}

?>