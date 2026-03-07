<?php

$servername = "localhost";

// REPLACE with your Database name
$dbname = "oliviera_iot";
// REPLACE with Database user
$username = "oliviera_iot";
// REPLACE with Database user password
$password = "Iot#Olution1";

// Keep this API Key value to be compatible with the ESP32 code provided in the project page. 
// If you change this value, the ESP32 sketch needs to match
$api_key_value = "fdGTMoptd5CD2ert3";

$api_key = $sensor = $version = $TempAir = $Humidite = $Luminosite = $Humid1 = $Humid2 = $Humid3 = $Humid4 = $HumidMoy = $ArrosageManu = $SeuilSec = $mail = $mailNotif = $HeureArrosage = $resetMode = "";

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $api_key = test_input($_POST["api_key"]);
    if($api_key == $api_key_value) {
        $sensor = test_input($_POST["sensor"]);
        $version = test_input($_POST["version"]);
        $TempAir = test_input($_POST["TempAir"]);
        $Humidite = test_input($_POST["Humidite"]);
        $Luminosite = test_input($_POST["Luminosite"]);
        $Humid1 = test_input($_POST["Humid1"]);
        $Humid2 = test_input($_POST["Humid2"]);
        $Humid3 = test_input($_POST["Humid3"]);
        $Humid4 = test_input($_POST["Humid4"]);
        $HumidMoy = test_input($_POST["HumidMoy"]);
        $ArrosageManu = test_input($_POST["ArrosageManu"]);
        $SeuilSec = test_input($_POST["SeuilSec"]);
        $mail = test_input($_POST["mail"]);
	    $mailNotif = test_input($_POST["mailNotif"]);
        $HeureArrosage = test_input($_POST["HeureArrosage"]);
	    $resetMode = test_input($_POST["resetMode"]);
        $etatPompe = test_input($_POST["etatPompe"]);
	    $tempsArrosage = test_input($_POST["tempsArrosage"]);

        // Create connection
        $conn = new mysqli($servername, $username, $password, $dbname);
        // Check connection
        if ($conn->connect_error) {
            die("Connection failed: " . $conn->connect_error);
        } 
        
        $sql = "INSERT INTO n3ppData (sensor, version, TempAir, Humidite, Luminosite, Humid1, Humid2, Humid3, Humid4, HumidMoy, ArrosageManu, SeuilSec, mail, mailNotif, HeureArrosage, resetMode, etatPompe, tempsArrosage)
        VALUES ('" . $sensor . "', '" . $version . "', '" . $TempAir . "', '" . $Humidite . "', '" . $Luminosite . "', '" . $Humid1 . "', '" . $Humid2 . "', '" . $Humid3 . "', '" . $Humid4 . "',  '" . $HumidMoy . "', '" . $ArrosageManu . "', '" . $SeuilSec . "', '" . $mail . "', '" . $mailNotif . "', '" . $HeureArrosage . "', '" . $resetMode . "', '" . $etatPompe . "', '" . $tempsArrosage . "');
        UPDATE n3ppOutputs SET state = '" . $ArrosageManu . "' WHERE gpio= '109';
        UPDATE n3ppOutputs SET state = '" . $resetMode . "' WHERE gpio= '110';
        UPDATE n3ppOutputs SET state = '" . $mail . "' WHERE gpio= '100';
        UPDATE n3ppOutputs SET state = '" . $mailNotif . "' WHERE gpio= '101';
        UPDATE n3ppOutputs SET state = '" . $SeuilSec . "' WHERE gpio= '102';
        UPDATE n3ppOutputs SET state = '" . $HeureArrosage . "' WHERE gpio= '103';
        UPDATE n3ppOutputs SET state = '" . $tempsArrosage . "' WHERE gpio= '104';
        ";
/*$HumidMoy = $WakeUp = $ArrosageManu = $SeuilSec = $HeureArrosage = $FreqWakeUp = $SeuilPontDiv = $bouffePetits = $bouffeGros = $aqThreshold = $tankThreshold = $chauffageThreshold = $mail = $mailNotif = $resetMode = "";

        UPDATE n3ppOutputs SET state = '" . $EtatPompe . "' WHERE gpio= '13';


INSERT INTO ffp3Data (sensor, version, TempAir, Humidite, Humid1, Humid2, Humid3, Humid4, Luminosite, HumidMoy, WakeUp, ArrosageManu, SeuilSec, HeureArrosage, FreqWakeUp, bouffePetits, bouffeGros,  aqTreshold, tankTreshold, chauffageTreshold, mail, mailNotif, resetMode, SeuilPontDiv)
        VALUES ('test', 'test', '2', '3', '53', '353', '1', '1', '23', '1', '1', '0', '0', '10', '12', '1','0',  '147', '10',  '12','test', 'test', '1', '10')*/
        if ($conn->multi_query($sql) === TRUE) {
            echo "New record created successfully";
        } 
        else {
            echo "Error: " . $sql . "<br>" . $conn->error;
        }
    
        $conn->close();
    }
    else {
        echo "Wrong API Key provided.";
    }

}
else {
    echo "No data posted with HTTP POST.";
}

function test_input($data) {
    $data = trim($data);
    $data = stripslashes($data);
    $data = htmlspecialchars($data);
    return $data;
}
?>