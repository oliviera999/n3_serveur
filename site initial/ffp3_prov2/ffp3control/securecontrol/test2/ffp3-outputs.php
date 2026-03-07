<!--
  Basé sur le projet de Rui Santos
  Complete project details at https://RandomNerdTutorials.com/control-esp32-esp8266-gpios-from-anywhere/
-->
<?php
    include_once('/home4/oliviera/iot.olution.info/ffp3test/ffp3control/ffp3-database.php');

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

    $result2 = getAllBoards();
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
    $aqThr = null;
    $taThr = null;
    $chThr = null;
    $boMat = null;
    $boMid = null;
    $boSoi = null;
    $tGros = null;
    $tPetits = null;


    if ($result3) {
        while ($row = $result3->fetch_assoc()) {
            if ($row["gpio"] == "100"){
                $mail = $row["state"];
            }
            else if ($row["gpio"] == "101"){
                $mailNotif = $row["state"];
            }
            else if ($row["gpio"] == "102"){
                $aqThr = $row["state"];
            }
            else if ($row["gpio"] == "103"){
                $taThr = $row["state"];
            }
            else if ($row["gpio"] == "104"){
                $chThr = $row["state"];
            }
            else if ($row["gpio"] == "105"){
                $boMat = $row["state"];
            }
            else if ($row["gpio"] == "106"){
                $boMid = $row["state"];
            }
            else if ($row["gpio"] == "107"){
                $boSoi = $row["state"];
            }
            else if ($row["gpio"] == "111"){
                $tGros = $row["state"];
            }
            else if ($row["gpio"] == "112"){
                $tPetits = $row["state"];
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
        <link rel="stylesheet" href="https://iot.olution.info/ffp3/ffp3control/ffp3-style.css" />
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
							<li><a href="https://iot.olution.info/n3pp/n3ppdatas/n3pp-data.php">phasmopolis</a></li>
							<li class="active"><a href="https://iot.olution.info/msp1/msp1datas/msp1-data.php">le tiny garden</a></li>
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
									<h2>Contrôle du ffp3</h2>
									<p>Il est possible d'agir à distance sur différents actionneurs du système.</p>
									<h4>! A manipuler avec la plus grande des précautions !</h4>
                                    <?php echo $html_boards; ?>
                                    <?php echo $html_buttons; ?>
                                    <br><br>
                                    <div>
                                        <form onsubmit="return createOutput();">
                                            <h3 style="text-align:center">Changer les paramètres</h3>
                                            <label for="outputName">Mail</label>
                                            <input type="text" name="name" id="outputName" value=<?php echo $mail; ?>><br>
                                            <label for="outputState">Notification par mail (<?php echo $mailNotif; ?>)</label>
                                            <select id="outputState" name="state">
                                              <option value="checked">oui</option>
                                              <option value="false">non</option>
                                            </select>
                                            <label for="outputBoard">Limite aquarium</label>
                                            <input type="number" name="board" min="0" id="outputBoard" value=<?php echo $aqThr; ?>>
                                            <label for="outputGpio">Limite réserve</label>
                                            <input type="number" name="gpio" min="0" id="outputGpio" value=<?php echo $taThr; ?>>
                                            <label for="chauff">Limite chauffage</label>
                                            <input type="number" name="chauff" min="0" id="chauff" value=<?php echo $chThr; ?>>
                                            <label for="bouffeMat">Heure de nourriture le matin</label>
                                            <input type="number" name="bouffeMat" min="0" id="bouffeMat" value=<?php echo $boMat; ?>>
                                            <label for="bouffeMid">Heure de nourriture le midi</label>
                                            <input type="number" name="bouffeMid" min="0" id="bouffeMid" value=<?php echo $boMid; ?>>
                                            <label for="bouffeSoir">Heure de nourriture le soir</label>
                                            <input type="number" name="bouffeSoir" min="0" id="bouffeSoir" value=<?php echo $boSoi; ?>>
                                            <label for="tempsGros">Temps de nourrissage des gros poissons</label>
                                            <input type="number" name="tempsGros" min="0" id="tempsGros" value=<?php echo $tGros; ?>>
                                            <label for="tempsPetits">Temps de nourrissage des petits poissons</label>
                                            <input type="number" name="tempsPetits" min="0" id="tempsPetits" value=<?php echo $tPetits; ?>>
                                            <input type="submit" value="Changer les valeurs">
                                        </form>
                                    </div>
                                </header>
                                <div>
                                    <center>
                                  		<a href="https://iot.olution.info/ffp3test/ffp3datas/cronpompe.php" class="button small">cron manuel</a>
                        		        <a href="https://iot.olution.info/ffp3test/ffp3datas/cronlog.txt" class="button small">journal du cron</a>
                        		        <br><br>
                                        <a href="https://iot.olution.info/ffp3test/ffp3datas/ffp3-data.php" class="button large">Retour aux données</a>
                                    </center>
                                </div>
                            </article>
                        </div>
                    </div>

    <script>
        function updateOutput(element) {
            var xhr = new XMLHttpRequest();
            if(element.checked){
                xhr.open("GET", "https://iot.olution.info/ffp3test/ffp3control/ffp3-outputs-action.php?action=output_update&id="+element.id+"&state=1", true);
            }
            else {
                xhr.open("GET", "https://iot.olution.info/ffp3test/ffp3control/ffp3-outputs-action.php?action=output_update&id="+element.id+"&state=0", true);
            }
            xhr.send();
        }

        function deleteOutput(element) {
            var result = confirm("Want to delete this output?");
            if (result) {
                var xhr = new XMLHttpRequest();
                xhr.open("GET", "https://iot.olution.info/ffp3test/ffp3control/ffp3-outputs-action.php?action=output_delete&id="+element.id, true);
                xhr.send();
                alert("Output deleted");
                setTimeout(function(){ window.location.reload(); });
            }
        }

        function createOutput(element) {
            var xhr = new XMLHttpRequest();
            xhr.open("POST", "https://iot.olution.info/ffp3test/ffp3control/ffp3-outputs-action.php", true);

            xhr.setRequestHeader("Content-Type", "application/x-www-form-urlencoded");

            xhr.onreadystatechange = function() {
                if (this.readyState === XMLHttpRequest.DONE && this.status === 200) {
                    alert("Changement pris en compte");
                    setTimeout(function(){ window.location.reload(); });
                }
                else {
                    alert("Changement non pris en compte !");
                }
            }
            var outputName = document.getElementById("outputName").value;
            var outputBoard = document.getElementById("outputBoard").value;
            var outputGpio = document.getElementById("outputGpio").value;
            var outputState = document.getElementById("outputState").value;
            var chauff = document.getElementById("chauff").value;
            var bouffeMat = document.getElementById("bouffeMat").value;
            var bouffeMid = document.getElementById("bouffeMid").value;
            var bouffeSoir = document.getElementById("bouffeSoir").value;
            var tempsGros = document.getElementById("tempsGros").value;
            var tempsPetits = document.getElementById("tempsPetits").value;
            var httpRequestData = "action=output_create&name="+outputName+"&board="+outputBoard+"&gpio="+outputGpio+"&state="+outputState+"&chauff="+chauff+"&bouffeMat="+bouffeMat+"&bouffeMid="+bouffeMid+"&bouffeSoir="+bouffeSoir+"&tempsGros="+tempsGros+"&tempsPetits="+tempsPetits;
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
