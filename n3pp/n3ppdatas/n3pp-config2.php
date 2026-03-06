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
  function getAllReadings($limit) {
    global $servername, $username, $password, $dbname;

    // Create connection
    $conn = new mysqli($servername, $username, $password, $dbname);
    // Check connection
    if ($conn->connect_error) {
      die("Connection failed: " . $conn->connect_error);
    }

    $sql = "SELECT id, sensor, version, TempAir, Humidite, Luminosite, Humid1, Humid2, Humid3, Humid4, HumidMoy, ArrosageManu, SeuilSec, HeureArrosage, resetMode, etatPompe, reading_time FROM n3ppData order by reading_time desc limit " . $limit;
    if ($result = $conn->query($sql)) {
      return $result;
    }
    else {
      return false;
    }
    $conn->close();
  }
  
  function getAllReadings2() {
    global $servername, $username, $password, $dbname;

    // Create connection
    $conn = new mysqli($servername, $username, $password, $dbname);
    // Check connection
    if ($conn->connect_error) {
      die("Connection failed: " . $conn->connect_error);
    }
    $sql = "SELECT MAX(id) AS max_amount2 FROM (SELECT id FROM n3ppData order by reading_time desc) AS max2";
 if ($result = $conn->query($sql)) {
      return $result->fetch_assoc();
    }
    else {
      return false;
    }
    $conn->close();
  }
  
  function getLastReadings() {
    global $servername, $username, $password, $dbname;

    // Create connection
    $conn = new mysqli($servername, $username, $password, $dbname);
    // Check connection
    if ($conn->connect_error) {
      die("Connection failed: " . $conn->connect_error);
    }

    $sql = "SELECT id, sensor, version, TempAir, Humidite, Luminosite, Humid1, Humid2, Humid3, Humid4, HumidMoy, ArrosageManu, SeuilSec, HeureArrosage, resetMode, etatPompe, reading_time FROM n3ppData order by reading_time desc limit 1" ;
    if ($result = $conn->query($sql)) {
      return $result->fetch_assoc();
    }
    else {
      return false;
    }
    $conn->close();
  }
  
/*    function getLastReadings2($limit) {
    global $servername, $username, $password, $dbname;

    // Create connection
    $conn = new mysqli($servername, $username, $password, $dbname);
    // Check connection
    if ($conn->connect_error) {
      die("Connection failed: " . $conn->connect_error);
    }

    $sql = "SELECT reading_time AS min_amount2 FROM (SELECT reading_time FROM n3ppData order by reading_time desc limit " . $limit . ") AS min2 order by reading_time ASC limit 1";
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

    $sql = "SELECT reading_time AS min_amount2 FROM (SELECT reading_time FROM n3ppData order by reading_time desc limit " . $limit . ") AS min2 order by reading_time ASC limit 1";
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

    $sql = "SELECT reading_time AS min_amount3 FROM (SELECT reading_time FROM n3ppData ) AS min3 order by reading_time ASC limit 1";
    if ($result = $conn->query($sql)) {
      return $result->fetch_assoc();
    }
    else {
      return false;
    }
    $conn->close();
  }
  
  function minReading($limit, $value) {
     global $servername, $username, $password, $dbname;
    // Create connection
    $conn = new mysqli($servername, $username, $password, $dbname);
    // Check connection
    if ($conn->connect_error) {
      die("Connection failed: " . $conn->connect_error);
    }

    //$sql = "SELECT MIN(" . $value . ") AS min_amount FROM (SELECT " . $value . " FROM n3ppData limit " . $limit . ") AS min";
    $sql = "SELECT MIN(" . $value . ") AS min_amount FROM (SELECT " . $value . " FROM n3ppData order by reading_time desc limit " . $limit . ") AS min";
    
    if ($result = $conn->query($sql)) {
      return $result->fetch_assoc();
    }
    else {
      return false;
    }
    $conn->close();
  }

  function maxReading($limit, $value) {
     global $servername, $username, $password, $dbname;

    // Create connection
    $conn = new mysqli($servername, $username, $password, $dbname);
    // Check connection
    if ($conn->connect_error) {
      die("Connection failed: " . $conn->connect_error);
    }

    //$sql = "SELECT MAX(" . $value . ") AS max_amount FROM (SELECT " . $value . " FROM n3ppData order by reading_time desc limit " . $limit . ") AS max";
    $sql = "SELECT MAX(" . $value . ") AS max_amount FROM (SELECT " . $value . " FROM n3ppData order by reading_time desc limit " . $limit . ") AS max";
    
    if ($result = $conn->query($sql)) {
      return $result->fetch_assoc();
    }
    else {
      return false;
    }
    $conn->close();
  }

  function avgReading($limit, $value) {
     global $servername, $username, $password, $dbname;

    // Create connection
    $conn = new mysqli($servername, $username, $password, $dbname);
    // Check connection
    if ($conn->connect_error) {
      die("Connection failed: " . $conn->connect_error);
    }

    $sql = "SELECT AVG(" . $value . ") AS avg_amount FROM (SELECT " . $value . " FROM n3ppData order by reading_time desc limit " . $limit . ") AS avg";
    if ($result = $conn->query($sql)) {
      return $result->fetch_assoc();
    }
    else {
      return false;
    }
    $conn->close();
  }
  
  function stddevReading ($limit, $value) {
     global $servername, $username, $password, $dbname;

    // Create connection
    $conn = new mysqli($servername, $username, $password, $dbname);
    // Check connection
    if ($conn->connect_error) {
      die("Connection failed: " . $conn->connect_error);
    }

    $sql = "SELECT STDDEV(" . $value . ") AS stddev_amount FROM (SELECT " . $value . " FROM n3ppData order by reading_time desc limit " . $limit . ") AS stddev";
    if ($result = $conn->query($sql)) {
      return $result->fetch_assoc();
    }
    else {
      return false;
    }
    $conn->close();
    }
    
    function stddevReading2 ($value) {
     global $servername, $username, $password, $dbname;

    // Create connection
    $conn = new mysqli($servername, $username, $password, $dbname);
    // Check connection
    if ($conn->connect_error) {
      die("Connection failed: " . $conn->connect_error);
    }

    $sql = "SELECT STDDEV(" . $value . ") AS stddev_amount2 FROM (SELECT " . $value . " FROM n3ppData order by id desc limit 40) AS stddev";
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

    $sql = "SELECT state FROM `n3ppData` WHERE gpio='13'";
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

    $sql = "SELECT state FROM `n3ppData` WHERE gpio='110'";
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

    $sql = "UPDATE n3ppData SET state = '1' WHERE gpio= '13'";
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

    $sql = "UPDATE n3ppData SET state = '0' WHERE gpio= '13'";
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

    $sql = "SELECT COUNT(*) FROM n3ppData WHERE " . $var . " < " . $thresh . "" ;
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

    $sql = "SELECT COUNT(*) FROM n3ppData WHERE " . $var . " = '25'";
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
    
        $sql = "DELETE FROM `n3ppData` WHERE " . $var . " < " . $thresh . "" ;
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
        $sql = "UPDATE n3ppData SET `" . $var . "` = NULL WHERE `" . $var . "` < " . $thresh;
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
    
        $sql = "DELETE FROM `n3ppData` WHERE " . $var . " = '25'" ;
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
    
        $sql = "UPDATE n3ppData SET `" . $var . "` = NULL WHERE `" . $var . "` = 25";
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
    
        $sql = "UPDATE n3ppData SET state = '1' WHERE gpio= '110'";
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
    $sql = "SELECT MAX(`reading_time`) as lastDataTime FROM n3ppData";

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
    $sql = "SELECT MAX(`reading_time`) as lastDataTime FROM n3ppData";
    if ($result = $conn->query($sql)) {
        if ($row = $result->fetch_assoc()) {
            $lastDataTime = strtotime($row['lastDataTime']);
        }
    }
    
        // If the last recorded data is more than 15 minutes old, send an email
        if ($lastDataTime && time() - $lastDataTime > 900) {
            // Check if a mail has already been sent
            $sql = "SELECT MAX(`reading_time`) as lastSentTime FROM n3ppData WHERE `mailSent` = 1";
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
                $sql = "UPDATE n3ppData SET `mailSent` = 1 WHERE `reading_time` = (SELECT MAX(`reading_time`) FROM n3ppData)";
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