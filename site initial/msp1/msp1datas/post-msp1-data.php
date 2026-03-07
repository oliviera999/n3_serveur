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

$api_key = $sensor = $version = $TempAirInt = $TempAirExt = $HumidAirInt = $HumidAirExt = $LuminositeA = $LuminositeB = $LuminositeC = $LuminositeD = $LuminositeMoy = $ServoHB = $ServoGD = $HumidSol = $Pluie = $TempEau = $PontDiv = $WakeUp = $SeuilSec = $FreqWakeUp = $SeuilPontDiv = $mail = $mailNotif = $resetMode = $bootCount = "";

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $api_key = test_input($_POST["api_key"]);
    if($api_key == $api_key_value) {
        $sensor = test_input($_POST["sensor"]);
        $version = test_input($_POST["version"]);
        $TempAirInt = test_input($_POST["TempAirInt"]);
        $TempAirExt = test_input($_POST["TempAirExt"]);
        $HumidAirInt = test_input($_POST["HumidAirInt"]);
        $HumidAirExt = test_input($_POST["HumidAirExt"]);
        $LuminositeA = test_input($_POST["LuminositeA"]);
        $LuminositeB = test_input($_POST["LuminositeB"]);
        $LuminositeC = test_input($_POST["LuminositeC"]);
        $LuminositeD= test_input($_POST["LuminositeD"]);
        $LuminositeMoy= test_input($_POST["LuminositeMoy"]);
        $ServoHB = test_input($_POST["ServoHB"]);
        $ServoGD = test_input($_POST["ServoGD"]);
        $HumidSol = test_input($_POST["HumidSol"]);
        $Pluie = test_input($_POST["Pluie"]);
        $TempEau = test_input($_POST["TempEau"]);
        $PontDiv = test_input($_POST["PontDiv"]);
        $WakeUp = test_input($_POST["WakeUp"]);
        //$ArrosageManu = test_input($_POST["ArrosageManu"]);
        $SeuilSec = test_input($_POST["SeuilSec"]);
        $FreqWakeUp = test_input($_POST["FreqWakeUp"]);
        $SeuilPontDiv = test_input($_POST["SeuilPontDiv"]);
        $mail = test_input($_POST["mail"]);
	    $mailNotif = test_input($_POST["mailNotif"]);
        //$HeureArrosage = test_input($_POST["HeureArrosage"]);
	    $resetMode = test_input($_POST["resetMode"]);
	    $bootCount = test_input($_POST["bootCount"]);


        // Create connection
        $conn = new mysqli($servername, $username, $password, $dbname);
        // Check connection
        if ($conn->connect_error) {
            die("Connection failed: " . $conn->connect_error);
        } 
        
        $sql = "INSERT INTO msp1Data (sensor, version, TempAirInt, TempAirExt, HumidAirInt, HumidAirExt, LuminositeA, LuminositeB, LuminositeC, LuminositeD, LuminositeMoy, ServoHB, ServoGD, HumidSol, Pluie, TempEau, PontDiv, SeuilSec, FreqWakeUp, SeuilPontDiv, mail, mailNotif, resetMode, bootCount)
        VALUES ('" . $sensor . "', '" . $version . "', '" . $TempAirInt . "', '" . $TempAirExt . "', '" . $HumidAirInt . "', '" . $HumidAirExt . "', '" . $LuminositeA . "', '" . $LuminositeB . "', '" . $LuminositeC . "', '" . $LuminositeD . "', '" . $LuminositeMoy . "', '" . $ServoHB . "', '" . $ServoGD . "', '" . $HumidSol . "', '" . $Pluie . "',  '" . $TempEau . "',  '" . $PontDiv . "', '" . $SeuilSec . "', '" . $FreqWakeUp . "', '" . $SeuilPontDiv . "', '" . $mail . "', '" . $mailNotif . "', '" . $resetMode . "', '" . $bootCount . "');
        UPDATE msp1Outputs SET state = '" . $resetMode . "' WHERE gpio= '110';
        UPDATE msp1Outputs SET state = '" . $mail . "' WHERE gpio= '100';
        UPDATE msp1Outputs SET state = '" . $mailNotif . "' WHERE gpio= '101';
        UPDATE msp1Outputs SET state = '" . $SeuilSec . "' WHERE gpio= '102';
        UPDATE msp1Outputs SET state = '" . $SeuilPontDiv . "' WHERE gpio= '103';        
        UPDATE msp1Outputs SET state = '" . $ServoHB . "' WHERE gpio= '104';
        UPDATE msp1Outputs SET state = '" . $ServoGD . "' WHERE gpio= '105';
        UPDATE msp1Outputs SET state = '" . $WakeUp . "' WHERE gpio= '106';
        UPDATE msp1Outputs SET state = '" . $FreqWakeUp . "' WHERE gpio= '107';
        ";

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