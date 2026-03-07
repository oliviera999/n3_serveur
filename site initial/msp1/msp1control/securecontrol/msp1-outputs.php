<!--
  Basé sur le projet de Rui Santos
  Complete project details at https://RandomNerdTutorials.com/control-esp32-esp8266-gpios-from-anywhere/
-->
<?php
    include_once('/home4/oliviera/iot.olution.info/msp1/msp1control/msp1-database.php');

    $result = getPartOutputs();
    $html_buttons = null;
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            if ($row["state"] == "1"){
                $button_checked = "checked";
            }
            else {
                $button_checked = "";
            }
            $html_buttons .= '<h3>' . $row["name"] . '</h3><label class="switch"><input type="checkbox" onchange="updateOutput(this)" id="' . $row["id"] . '" ' . $button_checked . '><span class="slider"></span></label>';
        }
    }

    $result2 = getAllBoards(2);
    $html_boards = null;
    if ($result2) {
        while ($row = $result2->fetch_assoc()) {
            $row_reading_time = $row["last_request"];
            // Uncomment to set timezone to - 1 hour (you can change 1 to any number)
            
            $row_reading_time = date("Y-m-d H:i:s", strtotime("$row_reading_time - 1 hours"));

            // Uncomment to set timezone to + 4 hours (you can change 4 to any number)
            //$row_reading_time = date("Y-m-d H:i:s", strtotime("$row_reading_time + 7 hours"));
            $html_boards .= '<p>Dernière requête : '. $row_reading_time . '</p>';
        }
    }
    
    $result3 = getAllOutputs();
    $mail = null;
    $mailNotif = null;
    $SeuilSec = null;
    $SeuilPontDiv = null;
    $ServoHB = null;
    $ServoGD = null;
    $WakeUp = null;
    $FreqWakeUp = null;

    if ($result3) {
        while ($row = $result3->fetch_assoc()) {
            if ($row["gpio"] == "100"){
                $mail = $row["state"];
            }
            else if ($row["gpio"] == "101"){
                $mailNotif = $row["state"];
            }
            else if ($row["gpio"] == "102"){
                $SeuilSec = $row["state"];
            }
            else if ($row["gpio"] == "103"){
                $SeuilPontDiv = $row["state"];
            }
            else if ($row["gpio"] == "104"){
                $ServoHB = $row["state"];
            }
            else if ($row["gpio"] == "105"){
                $ServoGD = $row["state"];
            }
            else if ($row["gpio"] == "106"){
                $WakeUp = $row["state"];
            }
            else if ($row["gpio"] == "107"){
                $FreqWakeUp = $row["state"];
            }
        }
    }
?>

<!DOCTYPE HTML>
<html>
	<head>
		<title>olution iot datas</title>
		<meta charset="utf-8" />
		<meta name="viewport" content="width=device-width, initial-scale=1, user-scalable=no" />
		<link rel="stylesheet" href="https://iot.olution.info/assets/css/main.css" />
		<noscript><link rel="stylesheet" href="https://iot.olution.info/assets/css/noscript.css" /></noscript>
        <link rel="stylesheet" href="https://iot.olution.info/msp1/msp1control/msp1-style.css" />
        <script src="https://ajax.googleapis.com/ajax/libs/jquery/3.4.1/jquery.min.js"></script>
		<link rel="shortcut icon" type="image/png" href="/images/favico.png"/>
	</head>
	<body class="is-preload">

		 <!-- Wrapper -->
			<div id="wrapper" class="fade-in">
				 <!-- Header -->
					<header id="header">
						<a href="https://iot.olution.info/index.php" class="logo">olution iot datas</a>
					</header>

				 <!-- Nav -->
					<nav id="nav">
						<ul class="links">
							<li><a href="https://iot.olution.info/index.php">olution</a></li>
							<li><a href="https://iot.olution.info/ffp3/ffp3datas/ffp3-data.php">le prototype farmflow 3</a></li>
							<li class="active"><a href="https://iot.olution.info/msp1/msp1datas/msp1-data.php"></a>phasmopolis</li>
							<li><a href="https://iot.olution.info/msp1/msp1datas/msp1-data.php">le tiny garden</a></li>
						</ul>
						<ul class="icons">
							<li><a href="https://olution.info/course/view.php?id=511" class="icon solid fa-leaf"><span class="label">olution</span></a></li>
							<li><a href="https://farmflow.marout.org/" class="icon solid fa-fish"><span class="label">farmflow</span></a></li>
						</ul>
					</nav>

				 <!-- Main -->
					<div id="main">
						 <!-- Featured Post -->
							<article class="post featured">
								<header class="major">
									<h2>Contrôle du msp1</h2>
									<p>Il est possible dagir à distance sur différents actionneurs du système.</p>
									<h4>! A manipuler avec la plus grande des précautions !</h4>
                                    <?php echo $html_boards; ?>
                                    <?php echo $html_buttons; ?>
                                    <br><br>
                                    <div>
                                        <form onsubmit="return createOutput();">
                                            <h3 style="text-align:center">Changer les paramètres</h3>
                                            <label for="mail">Mail</label>
                                            <input type="text" name="mail" id="mail" value=<?php echo $mail; ?>><br>
                                            <label for="mailNotif">Notification par mail (<?php echo $mailNotif; ?>)</label>
                                            <select id="mailNotif" name="mailNotif">
                                              <option value="checked">oui</option>
                                              <option value="false">non</option>
                                            </select>
                                            <label for="SeuilSec">Limite de sécheresse</label>
                                            <input type="number" name="SeuilSec" min="0" id="SeuilSec" value=<?php echo $SeuilSec; ?>>
                                            <label for="SeuilPontDiv">Limite de du pont diviseur</label>
                                            <input type="number" name="SeuilPontDiv" min="0" id="SeuilPontDiv" value=<?php echo $SeuilPontDiv; ?>>
                                            <label for="outputGpio">Angle servo haut bas</label>
                                            <input type="number" name="ServoHB" min="0" id="ServoHB" value=<?php echo $ServoHB; ?>>
                                            <label for="ServoGD">Angle servo gauche droite</label>
                                            <input type="number" name="ServoGD" min="0" id="ServoGD" value=<?php echo $ServoGD; ?>>
                                            <label for="WakeUp">Eco d'énergie (<?php echo $WakeUp; ?>)</label>
                                            <input type="number" name="WakeUp" min="0" id="WakeUp" value=<?php echo $WakeUp; ?>>
                                            <label for="FreqWakeUp">Fréquence d'éveil</label>
                                            <input type="number" name="FreqWakeUp" min="0" id="FreqWakeUp" value=<?php echo $FreqWakeUp; ?>>
                                            <input type="submit" value="Changer les valeurs">
                                        </form>
                                    </div>
                                </header>
                                <div>
                                    <center>                         
                                        <a href="https://iot.olution.info/msp1/msp1datas/msp1-data.php" class="button large">Retour aux données</a>
                                    </center>
                                </div>
                            </article>
                        </div>
                    </div>

    <script>
        function updateOutput(element) {
            var xhr = new XMLHttpRequest();
            if(element.checked){
                xhr.open("GET", "https://iot.olution.info/msp1/msp1control/msp1-outputs-action.php?action=output_update&id="+element.id+"&state=1", true);
            }
            else {
                xhr.open("GET", "https://iot.olution.info/msp1/msp1control/msp1-outputs-action.php?action=output_update&id="+element.id+"&state=0", true);
            }
            xhr.send();
        }

        function deleteOutput(element) {
            var result = confirm("Want to delete this output?");
            if (result) {
                var xhr = new XMLHttpRequest();
                xhr.open("GET", "https://iot.olution.info/msp1/msp1control/msp1-outputs-action.php?action=output_delete&id="+element.id, true);
                xhr.send();
                alert("Output deleted");
                setTimeout(function(){ window.location.reload(); });
            }
        }

        function createOutput(element) {
            var xhr = new XMLHttpRequest();
            xhr.open("POST", "https://iot.olution.info/msp1/msp1control/msp1-outputs-action.php", true);

            xhr.setRequestHeader("Content-Type", "application/x-www-form-urlencoded");
                    alert("Changement en cours");

            xhr.onreadystatechange = function() {
                if (this.readyState === XMLHttpRequest.DONE && this.status === 200) {
                    alert("Changement pris en compte");
                    setTimeout(function(){ window.location.reload(); });
                }
                else {
                    alert("Changement non pris en compte !");
                }
            }
            var mail = document.getElementById("mail").value;
            var mailNotif = document.getElementById("mailNotif").value;
            var SeuilSec = document.getElementById("SeuilSec").value;
            var SeuilPontDiv = document.getElementById("SeuilPontDiv").value;
            var ServoHB = document.getElementById("ServoHB").value;
            var ServoGD = document.getElementById("ServoGD").value;
            var WakeUp = document.getElementById("WakeUp").value;
            var FreqWakeUp = document.getElementById("FreqWakeUp").value;
            var httpRequestData = "action=output_create&mail="+mail+"&mailNotif="+mailNotif+"&SeuilSec="+SeuilSec+"&SeuilPontDiv="+SeuilPontDiv+"&ServoHB="+ServoHB+"&ServoGD="+ServoGD+"&WakeUp="+WakeUp+"&FreqWakeUp="+FreqWakeUp;
            xhr.send(httpRequestData);
        }
    </script>
<!--Scripts -->
	<script src="https://iot.olution.info/assets/js/jquery.min.js"></script>
	<script src="https://iot.olution.info/assets/js/jquery.scrollex.min.js"></script>
	<script src="https://iot.olution.info/assets/js/jquery.scrolly.min.js"></script>
	<script src="https://iot.olution.info/assets/js/browser.min.js"></script>
	<script src="https://iot.olution.info/assets/js/breakpoints.min.js"></script>
	<script src="https://iot.olution.info/assets/js/util.js"></script>
	<script src="https://iot.olution.info/assets/js/main.js"></script>
    </body>
</html>
