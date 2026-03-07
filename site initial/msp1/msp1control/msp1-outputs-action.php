<?php
    include_once('msp1-database.php');

    $action = $id = $mail = $mailNotif = $SeuilSec = $SeuilPontDiv = $ServoHB = $ServoGD = $WakeUp = $FreqWakeUp;

    if ($_SERVER["REQUEST_METHOD"] == "POST") {
        $action = test_input($_POST["action"]);
        if ($action == "output_create") {
            $mail = test_input($_POST["mail"]);
            $mailNotif = test_input($_POST["mailNotif"]);
            $SeuilSec = test_input($_POST["SeuilSec"]);
            $SeuilPontDiv = test_input($_POST["SeuilPontDiv"]);
            $ServoHB = test_input($_POST["ServoHB"]);
            $ServoGD = test_input($_POST["ServoGD"]);
            $WakeUp = test_input($_POST["WakeUp"]);
            $FreqWakeUp = test_input($_POST["FreqWakeUp"]);
            $result = createOutput($mail, $mailNotif, $SeuilSec, $SeuilPontDiv, $ServoHB, $ServoGD, $WakeUp, $FreqWakeUp);

            /*$result2 = getBoard($board);
            if(!$result2->fetch_assoc()) {
                createBoard($board);
            }
            echo $result;*/
        }
        else {
            echo "No data posted with HTTP POST.";
        }
    }

    if ($_SERVER["REQUEST_METHOD"] == "GET") {
        $action = test_input($_GET["action"]);
        if ($action == "outputs_state") {
            $board = test_input($_GET["board"]);
            $result = getAllOutputStates($board);
            if ($result) {
                while ($row = $result->fetch_assoc()) {
                    $rows[$row["gpio"]] = $row["state"];
                }
            }
            echo json_encode($rows);
            $result = getBoard($board);
            if($result->fetch_assoc()) {
                updateLastBoardTime($board);
            }
        }
        else if ($action == "output_update") {
            $id = test_input($_GET["id"]);
            $state = test_input($_GET["state"]);
            $result = updateOutput($id, $state);
            echo $result;
        }
        else if ($action == "output_delete") {
            $id = test_input($_GET["id"]);
            $board = getOutputBoardById($id);
            if ($row = $board->fetch_assoc()) {
                $board_id = $row["board"];
            }
            $result = deleteOutput($id);
            $result2 = getAllOutputStates($board_id);
            if(!$result2->fetch_assoc()) {
                deleteBoard($board_id);
            }
            echo $result;
        }
        else {
            echo "Invalid HTTP request.";
        }
    }

    function test_input($data) {
        $data = trim($data);
        $data = stripslashes($data);
        $data = htmlspecialchars($data);
        return $data;
    }
?>
