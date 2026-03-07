<?php
$servername = "localhost";

// REPLACE with your Database name
$dbname = "oliviera_iot";
// REPLACE with Database user
$username = "oliviera_iot";
// REPLACE with Database user password
$password = "Iot#Olution1";

    function createOutput($mail, $mailNotif, $aqThr, $taThr, $tempsRemplissageSec, $limFlood, $chauff, $bouffeMat, $bouffeMid, $bouffeSoir, $tempsGros, $tempsPetits, $WakeUp, $FreqWakeUp) {
        global $servername, $username, $password, $dbname;

        // Create connection
        $conn = new mysqli($servername, $username, $password, $dbname);
        // Check connection
        if ($conn->connect_error) {
            die("Connection failed: " . $conn->connect_error);
        }

        $sql = "UPDATE ffp3Outputs SET state = '" . $mail . "' WHERE gpio= '100';
        UPDATE ffp3Outputs SET state = '" . $mailNotif . "' WHERE gpio= '101';
        UPDATE ffp3Outputs SET state = '" . $aqThr . "' WHERE gpio= '102';
        UPDATE ffp3Outputs SET state = '" . $taThr . "' WHERE gpio= '103';
        UPDATE ffp3Outputs SET state = '" . $tempsRemplissageSec . "' WHERE gpio= '113';
        UPDATE ffp3Outputs SET state = '" . $limFlood . "' WHERE gpio= '114';
        UPDATE ffp3Outputs SET state = '" . $chauff . "' WHERE gpio= '104';
        UPDATE ffp3Outputs SET state = '" . $bouffeMat . "' WHERE gpio= '105';
        UPDATE ffp3Outputs SET state = '" . $bouffeMid . "' WHERE gpio= '106';
        UPDATE ffp3Outputs SET state = '" . $bouffeSoir . "' WHERE gpio= '107';
        UPDATE ffp3Outputs SET state = '" . $tempsGros . "' WHERE gpio= '111';
        UPDATE ffp3Outputs SET state = '" . $tempsPetits . "' WHERE gpio= '112';
        UPDATE ffp3Outputs SET state = '" . $WakeUp . "' WHERE gpio= '115';
        UPDATE ffp3Outputs SET state = '" . $FreqWakeUp . "' WHERE gpio= '116';

        ";
        
       if ($conn->multi_query($sql) === TRUE) {
            return "New output created successfully";
        }
        else {
            return "Error: " . $sql . "<br>" . $conn->error;
        }
        $conn->close();
    }
    
    function deleteOutput($id) {
        global $servername, $username, $password, $dbname;

        // Create connection
        $conn = new mysqli($servername, $username, $password, $dbname);
        // Check connection
        if ($conn->connect_error) {
            die("Connection failed: " . $conn->connect_error);
        }

        $sql = "DELETE FROM ffp3Outputs WHERE id='". $id .  "'";

       if ($conn->query($sql) === TRUE) {
            return "Output deleted successfully";
        }
        else {
            return "Error: " . $sql . "<br>" . $conn->error;
        }
        $conn->close();
    }

    function updateOutput($id, $state) {
        global $servername, $username, $password, $dbname;

        // Create connection
        $conn = new mysqli($servername, $username, $password, $dbname);
        // Check connection
        if ($conn->connect_error) {
            die("Connection failed: " . $conn->connect_error);
        }

        $sql = "UPDATE ffp3Outputs SET state='" . $state . "' WHERE id='". $id .  "'";

       if ($conn->query($sql) === TRUE) {
            return "Output state updated successfully";
        }
        else {
            return "Error: " . $sql . "<br>" . $conn->error;
        }
        $conn->close();
    }

    function getAllOutputs() {
        global $servername, $username, $password, $dbname;

        // Create connection
        $conn = new mysqli($servername, $username, $password, $dbname);
        // Check connection
        if ($conn->connect_error) {
            die("Connection failed: " . $conn->connect_error);
        }

        $sql = "SELECT id, name, board, gpio, state FROM ffp3Outputs ORDER BY id";
        if ($result = $conn->query($sql)) {
            return $result;
        }
        else {
            return false;
        }
        $conn->close();
    }
    
    function getPartOutputs() {
        global $servername, $username, $password, $dbname;

        // Create connection
        $conn = new mysqli($servername, $username, $password, $dbname);
        // Check connection
        if ($conn->connect_error) {
            die("Connection failed: " . $conn->connect_error);
        }

        $sql = "SELECT id, name, board, gpio, state FROM ffp3Outputs ORDER BY id limit 7";
        if ($result = $conn->query($sql)) {
            return $result;
        }
        else {
            return false;
        }
        $conn->close();
    }

    function getAllOutputStates($board) {
        global $servername, $username, $password, $dbname;

        // Create connection
        $conn = new mysqli($servername, $username, $password, $dbname);
        // Check connection
        if ($conn->connect_error) {
            die("Connection failed: " . $conn->connect_error);
        }

        $sql = "SELECT gpio, state FROM ffp3Outputs WHERE board='" . $board . "'";
        if ($result = $conn->query($sql)) {
            return $result;
        }
        else {
            return false;
        }
        $conn->close();
    }

    function getOutputBoardById($id) {
        global $servername, $username, $password, $dbname;

        // Create connection
        $conn = new mysqli($servername, $username, $password, $dbname);
        // Check connection
        if ($conn->connect_error) {
            die("Connection failed: " . $conn->connect_error);
        }

        $sql = "SELECT board FROM ffp3Outputs WHERE id='" . $id . "'";
        if ($result = $conn->query($sql)) {
            return $result;
        }
        else {
            return false;
        }
        $conn->close();
    }

    function updateLastBoardTime($board) {
        global $servername, $username, $password, $dbname;

        // Create connection
        $conn = new mysqli($servername, $username, $password, $dbname);
        // Check connection
        if ($conn->connect_error) {
            die("Connection failed: " . $conn->connect_error);
        }

        $sql = "UPDATE Boards SET last_request=now() WHERE board='". $board .  "'";

       if ($conn->query($sql) === TRUE) {
            return "Output state updated successfully";
        }
        else {
            return "Error: " . $sql . "<br>" . $conn->error;
        }
        $conn->close();
    }

    function getAllBoards($board) {
        global $servername, $username, $password, $dbname;

        // Create connection
        $conn = new mysqli($servername, $username, $password, $dbname);
        // Check connection
        if ($conn->connect_error) {
            die("Connection failed: " . $conn->connect_error);
        }

        $sql = "SELECT board, last_request FROM Boards WHERE board='" . $board . "'";
        if ($result = $conn->query($sql)) {
            return $result;
        }
        else {
            return false;
        }
        $conn->close();
    }

    function getBoard($board) {
        global $servername, $username, $password, $dbname;

        // Create connection
        $conn = new mysqli($servername, $username, $password, $dbname);
        // Check connection
        if ($conn->connect_error) {
            die("Connection failed: " . $conn->connect_error);
        }

        $sql = "SELECT board, last_request FROM Boards WHERE board='" . $board . "'";
        if ($result = $conn->query($sql)) {
            return $result;
        }
        else {
            return false;
        }
        $conn->close();
    }

    function createBoard($board) {
        global $servername, $username, $password, $dbname;

        // Create connection
        $conn = new mysqli($servername, $username, $password, $dbname);
        // Check connection
        if ($conn->connect_error) {
            die("Connection failed: " . $conn->connect_error);
        }

        $sql = "INSERT INTO Boards (board) VALUES ('" . $board . "')";

       if ($conn->query($sql) === TRUE) {
            return "New board created successfully";
        }
        else {
            return "Error: " . $sql . "<br>" . $conn->error;
        }
        $conn->close();
    }

    function deleteBoard($board) {
        global $servername, $username, $password, $dbname;

        // Create connection
        $conn = new mysqli($servername, $username, $password, $dbname);
        // Check connection
        if ($conn->connect_error) {
            die("Connection failed: " . $conn->connect_error);
        }

        $sql = "DELETE FROM Boards WHERE board='". $board .  "'";

       if ($conn->query($sql) === TRUE) {
            return "Board deleted successfully";
        }
        else {
            return "Error: " . $sql . "<br>" . $conn->error;
        }
        $conn->close();
    }

?>
