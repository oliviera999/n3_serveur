<?Php
require "n3pp-config.php";// Database connection


// Réglage du fuseau horaire sur Casablanca
date_default_timezone_set('Africa/Casablanca');

// Initialiser les variables pour les dernières 24 heures
$last_date = getLastReadingDate(); // Récupérer la dernière date enregistrée
$default_end_date = $last_date ? date("Y-m-d H:i:s", strtotime($last_date)) : date("Y-m-d H:i:s"); // Dernière lecture ou maintenant
$default_start_date = date("Y-m-d H:i:s", strtotime($default_end_date . " -1 day")); // 24 heures avant

// Si le formulaire est soumis, récupérer les dates et heures sélectionnées
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $start_date = $_POST['start_date'] . " " . ($_POST['start_time'] ?? "00:00:00");
    $end_date = $_POST['end_date'] . " " . ($_POST['end_time'] ?? "23:59:59");
} else {
    // Si aucune soumission, utiliser les dernières 24 heures par défaut
    $start_date = $default_start_date;
    $end_date = $default_end_date;
}



// Récupérer les données filtrées pour la période définie
    $readings = getSensorData($start_date, $end_date);

    // Calcul de la durée totale
    $start_timestamp = strtotime($start_date);
    $end_timestamp = strtotime($end_date);
    $duration_seconds = $end_timestamp - $start_timestamp;
    
    $days = floor($duration_seconds / (60 * 60 * 24));
    $hours = floor(($duration_seconds % (60 * 60 * 24)) / (60 * 60));
    $minutes = floor(($duration_seconds % (60 * 60)) / 60);
    
    $duration_str = "$days jours, $hours heures, $minutes minutes";

    // Nombre de mesures analysées
    $measure_count = count($readings);

    $last_reading = getLastReadings($start_date, $end_date);

    $last_reading_tempair = $last_reading["TempAir"];
    $last_reading_humi = $last_reading["Humidite"];
    $last_reading_lumi = $last_reading["Luminosite"];
    $last_reading_humidMoy = $last_reading["HumidMoy"];
    $last_reading_humid1 = $last_reading["Humid1"];
    $last_reading_humid2 = $last_reading["Humid2"];
    $last_reading_humid3 = $last_reading["Humid3"];
    $last_reading_humid4 = $last_reading["Humid4"];
    
    $last_reading_time = $last_reading["reading_time"];
 
    $first_reading = getAllReadings();
    $first_reading_begin = $first_reading ["max_amount2"]; //firstreading2
    
    $first_reading_time_begin = getFirstReadingsBegin();
    $first_reading_time_begin = $first_reading_time_begin ["min_amount3"];
    
    $first_reading_time = null;

    $last_reading_time = date("Y-m-d H:i:s", strtotime("$last_reading_time")); //last_reading_time
    //$last_reading_time = date("Y-m-d H:i:s", strtotime("$last_reading_time"));
    //$first_reading_time2 = date("Y-m-d H:i:s", strtotime("$first_reading")); //last_reading_time4
    $first_reading_time_begin = date("Y-m-d H:i:s", strtotime("$first_reading_time_begin")); 

    $last_reading_time = date("d/m/Y H:i:s", strtotime("$last_reading_time - 1 hours")); //last_reading_time
    //$last_reading_time = date("Y-m-d H:i:s", strtotime("$last_reading_time"));
    //$first_reading_time2 = date("d/m/Y H:i:s", strtotime("$first_reading")); //last_reading_time4
    $first_reading_time = date("d/m/Y H:i:s", strtotime("$first_reading_time - 1 hours")); //last_reading_time4
    
    
    // Uncomment to set timezone to - 1 hour (you can change 1 to any number)
    $last_reading_timestamp = strtotime("$last_reading_time");
    $first_reading_timestamp = strtotime("$first_reading_time");
    $first_reading_begin_timestamp = strtotime("$first_reading_time_begin");

    $heures = "h";
    $minutes = "min";
    $jours = "j";


    
    $timepast = abs(round(($last_reading_timestamp - $first_reading_timestamp)/60,1));
    if($timepast<=60){
        $timepast = (string)$timepast . $minutes;
    }
    elseif($timepast<=(60*24)){
        $timepast = $timepast/(60);
        $timepast = round((string)$timepast,1) . $heures;
    }
    elseif($timepast>(60*24)){
        $timepast = $timepast/(60*24);
        $timepast = round((string)$timepast,1) . $jours;        
    }
    $timepastbegin = round(($last_reading_timestamp - $first_reading_begin_timestamp)/(3600*24),1);

    
   /* echo $last_reading_time;
    echo $last_reading_time4;
    echo $first_reading2;
    echo $last_reading_tempair;*/

    $min_tempair = minReading($start_date, $end_date, 'TempAir');
    $max_tempair = maxReading($start_date, $end_date, 'TempAir');

    $avg_tempair = avgReading($start_date, $end_date, 'TempAir');
    $stddev_tempair = stddevReading($start_date, $end_date, 'TempAir');
    
    $min_humi = minReading($start_date, $end_date, 'Humidite');
    $max_humi = maxReading($start_date, $end_date, 'Humidite');
    $avg_humi = avgReading($start_date, $end_date, 'Humidite');
    $stddev_humi = stddevReading($start_date, $end_date, 'Humidite');
    
    $min_lumi = minReading($start_date, $end_date, 'Luminosite');
    $max_lumi = maxReading($start_date, $end_date, 'Luminosite');
    $avg_lumi = avgReading($start_date, $end_date, 'Luminosite');
    $stddev_lumi = stddevReading($start_date, $end_date, 'Luminosite');
    
    $avg_humid1 = avgReading($start_date, $end_date, 'Humid1');
    $max_humid1 = maxReading($start_date, $end_date, 'Humid1');
    $min_humid1 = minReading($start_date, $end_date, 'Humid1');
    $stddev_humid1 = stddevReading($start_date, $end_date, 'Humid1');
    
    $min_humid2 = minReading($start_date, $end_date, 'Humid2');
    $max_humid2 = maxReading($start_date, $end_date, 'Humid2');
    $avg_humid2 = avgReading($start_date, $end_date, 'Humid2');
    $stddev_humid2 = stddevReading($start_date, $end_date, 'Humid2');
    
    $min_humid3 = minReading($start_date, $end_date, 'Humid3');
    $max_humid3 = maxReading($start_date, $end_date, 'Humid3');
    $avg_humid3 = avgReading($start_date, $end_date, 'Humid3');
    $stddev_humid3 = stddevReading($start_date, $end_date, 'Humid3');
    
    $min_humid4 = minReading($start_date, $end_date, 'Humid4');
    $max_humid4 = maxReading($start_date, $end_date, 'Humid4');
    $avg_humid4 = avgReading($start_date, $end_date, 'Humid4');
    $stddev_humid4 = stddevReading($start_date, $end_date, 'Humid4');
    
    $min_humidMoy = minReading($start_date, $end_date, 'HumidMoy');
    $max_humidMoy = maxReading($start_date, $end_date, 'HumidMoy');
    $avg_humidMoy = avgReading($start_date, $end_date, 'HumidMoy');
    $stddev_humidMoy = stddevReading($start_date, $end_date, 'HumidMoy');
    

// Préparer les données pour le JavaScript
    $reading_time = array_column($readings, 'reading_time');

// Conversion des temps pour le fuseau horaire souhaité
$i = 0;
foreach ($reading_time as $reading) {
    // Exemple de conversion pour +1 heure (modifiable)
    $reading_time[$i] = strtotime("$reading +1 hours") * 1000; // En millisecondes pour JS
    $i++;
}

$Humid1 = json_encode(array_reverse(array_column($readings, 'Humid1')), JSON_NUMERIC_CHECK);
$Humid2 = json_encode(array_reverse(array_column($readings, 'Humid2')), JSON_NUMERIC_CHECK);
$Humid3= json_encode(array_reverse(array_column($readings, 'Humid3')), JSON_NUMERIC_CHECK);
$Humid4= json_encode(array_reverse(array_column($readings, 'Humid4')), JSON_NUMERIC_CHECK);
$HumidMoy= json_encode(array_reverse(array_column($readings, 'HumidMoy')), JSON_NUMERIC_CHECK);


$TempAir = json_encode(array_reverse(array_column($readings, 'TempAir')), JSON_NUMERIC_CHECK);
$Humidite = json_encode(array_reverse(array_column($readings, 'Humidite')), JSON_NUMERIC_CHECK);
$Luminosite = json_encode(array_reverse(array_column($readings, 'Luminosite')), JSON_NUMERIC_CHECK);

$etatPompe = json_encode(array_reverse(array_column($readings, 'etatPompe')), JSON_NUMERIC_CHECK);
$ArrosageManu = json_encode(array_reverse(array_column($readings, 'ArrosageManu')), JSON_NUMERIC_CHECK);
$resetMode = json_encode(array_reverse(array_column($readings, 'resetMode')), JSON_NUMERIC_CHECK);

$PontDiv = json_encode(array_reverse(array_column($readings, 'PontDiv')), JSON_NUMERIC_CHECK);
$bootCount = json_encode(array_reverse(array_column($readings, 'bootCount')), JSON_NUMERIC_CHECK);

$reading_time = json_encode(array_reverse($reading_time), JSON_NUMERIC_CHECK);

// Vérifier si le bouton a été cliqué
if (isset($_POST['export_csv'])) {
    exportSensorData($_POST['start_date'] . " 00:00:00", $_POST['end_date'] . " 23:59:59");
}

?>

<!DOCTYPE HTML>
<!--
	olution iot datas by HTML5 UP
	html5up.net | @ajlkn
	Free for personal and commercial use under the CCA 3.0 license (html5up.net/license)
-->


<html>
	<head>
		<title>n3 iot datas</title>
		<meta charset="utf-8" />
		<meta name="viewport" content="width=device-width, initial-scale=1, user-scalable=no" />
        <!-- <meta http-equiv="refresh" content="60";/> -->
		<link rel="stylesheet" href="/assets/css/main.css" />
		<noscript><link rel="stylesheet" href="/assets/css/noscript.css" /></noscript>
		<link rel="shortcut icon" type="image/png" href="/images/favico.png"/>
        <script src="https://ajax.googleapis.com/ajax/libs/jquery/3.4.1/jquery.min.js"></script>
	    <script src="https://code.highcharts.com/highcharts.js"></script>
	    <script src="https://code.highcharts.com/modules/boost.js"></script>
        <script src="https://code.highcharts.com/stock/modules/data.js"></script>
        <script src="https://code.highcharts.com/stock/modules/exporting.js"></script>
        <script src="https://code.highcharts.com/stock/modules/export-data.js"></script>
        <script src="https://code.highcharts.com/modules/accessibility.js"></script>
        
	</head>
	<body class="is-preload">

		 <!-- Wrapper -->
			<div id="wrapper" class="fade-in">
				 <!-- Header -->
					<header id="header">
						<a href="https://iot.olution.info/index.php" class="logo">n3 iot datas</a>
					</header>

				 <!-- Nav -->
					<nav id="nav">
						<ul class="links">
							<li><a href="https://iot.olution.info/index.php">Accueil</a></li>
							<li><a href="https://iot.olution.info/ffp3/ffp3datas/ffp3-data.php">L'aquaponie</a></li>
							<li><a href="https://iot.olution.info/msp1/msp1datas/msp1-data.php">Le potager</a></li>
							<li class="active"><a href="https://iot.olution.info/n3pp/n3ppdatas/n3pp-data.php">L'élevage d'insectes</a></li>
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
									<h2>
									    <i class="icon solid fa-bug">
									    </i> 										    
									    Insectes
									    <i class="icon solid fa-bug">
									    </i> 										
									</h2>
									<p>Le système est suivi grâce à la carte de développement ESP-32 qui mesure différents paramètres du système et contrôle l'arrosage automatique de l'élevage. Il est conçu pour fonctionner en autonomie énergétique durant les période congés scolaires. Les données sont transmises sur le serveur d'olution et traitées pour être présentées.</p>
						         <!--   <a href="https://iot.olution.info/n3pp/n3ppgallery/n3pp-gallery.php?page=1" class="button large">Photos du potager</a>
						            
                                  <hr />
   								<!--	<iframe allow="camera; microphone; fullscreen; display-capture; autoplay" src="https://meet.jit.si/ffp3live" style="height: 600; width: 100%; border: 0px;"></iframe> -->

                                    <hr />
								</header>
								


    <h2>Synthèse des mesures du <?= htmlspecialchars(date("d/m/Y", strtotime($start_date))) ?> au <?= htmlspecialchars(date("d/m/Y", strtotime($end_date))) ?></h2>

<div id="table-eaux" class="container">
    <section class="content">
        <div class="table-wrapper">
		    <table>
		        <tr>
		            <h3>Statistiques des capteurs d'humidité</h3>
		        </tr>
		        <tr>
		            <td>Mesures actuelles</td>
		            <td>	    
                        <div class="box gauge--4">
                            <h5>Humidité moyenne</h5>
                            <div class="mask">
                                <div class="semi-circle"></div>
                                <div class="semi-circle--mask"></div>
                            </div>
                            <p style="text-align: center" id="humidMoy">--</p>
                        </div>
		            </td>
                    <td>
                        <div class="box gauge--5">
                            <h5>Capteur 1</h5>
                            <div class="mask">
                                <div class="semi-circle"></div>
                                <div class="semi-circle--mask"></div>
                            </div>
                            <p style="text-align: center" id="humid1">--</p>
	                    </div>
	                 </td>
	                 <td>
                        <div class="box gauge--6">
                            <h5>Capteur 2</h5>
                            <div class="mask">
                                <div class="semi-circle"></div>
                                <div class="semi-circle--mask"></div>
                            </div>
                            <p style="text-align: center" id="humid2">--</p> 
	                    </div>
                    </td>
	                 <td>
                        <div class="box gauge--7">
                            <h5>Capteur 3</h5>
                            <div class="mask">
                                <div class="semi-circle"></div>
                                <div class="semi-circle--mask"></div>
                            </div>
                            <p style="text-align: center" id="humid3">--</p> 
	                    </div>
                    </td>
                     <td>
                        <div class="box gauge--8">
                            <h5>Capteur 4</h5>
                            <div class="mask">
                                <div class="semi-circle"></div>
                                <div class="semi-circle--mask"></div>
                            </div>
                            <p style="text-align: center" id="humid4">--</p> 
	                    </div>
                    </td>
                </tr>
		        <tr>
                    <td>Moy</td>
                    <td><?php echo round($avg_humidMoy, 0); ?> UA</td>
                    <td><?php echo round($avg_humid1, 0); ?> UA</td>                                        
                    <td><?php echo round($avg_humid2, 0); ?> UA</td>
                    <td><?php echo round($avg_humid3, 0); ?> UA</td>
                    <td><?php echo round($avg_humid4, 0); ?> UA</td>

                </tr>
		        <tr>
		            <td>Min</td>
                    <td><?php echo $min_humidMoy; ?> UA</td>
                    <td><?php echo $min_humid1; ?> UA</td>
                    <td><?php echo $min_humid2; ?> UA</td>
                    <td><?php echo $min_humid3; ?> UA</td>
                    <td><?php echo $min_humid4; ?> UA</td>

                </tr>
		        <tr>
                    <td>Max</td>
                    <td><?php echo $max_humidMoy; ?> UA</td>
                    <td><?php echo $max_humid1; ?> UA</td>
                    <td><?php echo $max_humid2; ?> UA</td>
                    <td><?php echo $max_humid3; ?> UA</td>
                    <td><?php echo $max_humid4; ?> UA</td>

                </tr>
		        <tr>
                    <td>ET</td>
                    <td><?php echo round($stddev_humidMoy, 2); ?> UA</td>
                    <td><?php echo round($stddev_humid1, 2); ?> UA</td>
                    <td><?php echo round($stddev_humid2, 2); ?> UA</td>
                    <td><?php echo round($stddev_humid3, 2); ?> UA</td>
                    <td><?php echo round($stddev_humid4, 2); ?> UA</td>
                </tr>
            </table>
        </div>
    </section>
</div>
<br>

<div id="chart-niveauxeaux" class="container"></div>
<hr />


<div id="table-parametresphys" class="container">
    <section class="content">
        <div class="table-wrapper">
		    <table>
		        <tr>
		            <h3>Statistiques des paramètres physiques</h3>
		        </tr>
		        <tr>
		            <td>Mesures actuelles</td>
                    <td>
		                <div class="box gauge--1">
	                        <h5>TEMPERATURE AIR</h5>
                            <div class="mask">
			                    <div class="semi-circle"></div>
			                    <div class="semi-circle--mask"></div>
			                </div>
		                    <p style="text-align: center" id="tempair">--</p>
	                    </div>
	                 </td>
	                 <td>
                        <div class="box gauge--2">
                            <h5>HUMIDITE</h5>
                            <div class="mask">
                                <div class="semi-circle"></div>
                                <div class="semi-circle--mask"></div>
                            </div>
                            <p style=" text-align: center" id="humi">--</p>
	                    </div>
                    </td>
                    <td>
                        <div class="box gauge--3">
                            <h5>LUMINOSITE</h5>
                            <div class="mask">
                                <div class="semi-circle"></div>
                                <div class="semi-circle--mask"></div>
                            </div>
                            <p style="text-align: center" id="lumi">--</p>
                        </div>
                    </td>
                </tr>
		        <tr>
                    <td>Moy</td>
                    <td><?php echo round($avg_tempair,1); ?>&deg;C</td>
                    <td><?php echo round($avg_humi, 0); ?> %</td>
                    <td><?php echo round($avg_lumi, 0); ?> UA</td>
                </tr>
		        <tr>
		            <td>Min</td>
                    <td><?php echo round($min_tempair, 1); ?>&deg;C</td>
                    <td><?php echo round($min_humi, 0); ?> %</td>
                    <td><?php echo $min_lumi; ?> UA</td>
                </tr>
		        <tr>
                    <td>Max</td>
                    <td><?php echo round ($max_tempair, 1); ?>&deg;C</td>
                    <td><?php echo round ($max_humi, 0); ?> %</td>
                    <td><?php echo $max_lumi; ?> UA</td>
                </tr>
		        <tr>
                    <td>ET</td>
                    <td><?php echo round($stddev_tempair, 2); ?>&deg;C</td>
                    <td><?php echo round($stddev_humi, 0); ?> %</td>
                    <td><?php echo round($stddev_lumi, 0); ?> UA</td>
                </tr>
            </table>
        </div>
    </section>
</div>
<br>

<div id="chart-temperatures" class="container"></div>
<hr />
		            <h3>Autonomie énergétique du système</h3>

<div id="chart-cycles" class="container"></div>
<hr />
    <!-- Formulaire pour sélectionner la période -->
                                    <h5 style="text-align: center"> durée d'analyse des données : <?php echo $duration_str; ?></h5>

<style>
    .form-container {
        display: flex;
        flex-direction: column;  /* Alignement vertical des éléments */
        gap: 10px;  /* Espacement entre les éléments */
        max-width: 400px;  /* Limite la largeur du formulaire */
    }

    .form-container label {
        margin-bottom: 5px;  /* Espacement sous le label */
    }

    .form-container input,
    .form-container button {
        padding: 5px;
        width: 100%;  /* Prend toute la largeur disponible */
    }
</style>
<center>
    <form method="POST" action="">
        <div class="form-container">
            <div>
                <label for="start_date">Date de début :</label>
                <input type="date" id="start_date" name="start_date" value="<?= htmlspecialchars(date("Y-m-d", strtotime($start_date))) ?>" required>
                <label for="start_time">Heure de début :</label>
                <input type="time" id="start_time" name="start_time" value="<?= htmlspecialchars(date("H:i", strtotime($start_date))) ?>" required>
            </div>

            <div>
                <label for="end_date">Date de fin :</label>
                <input type="date" id="end_date" name="end_date" value="<?= htmlspecialchars(date("Y-m-d", strtotime($end_date))) ?>" required>
                <label for="end_time">Heure de fin :</label>
                <input type="time" id="end_time" name="end_time" value="<?= htmlspecialchars(date("H:i", strtotime($end_date))) ?>" required>
            </div>

            <div>
                <button type="submit">Afficher les mesures</button>
            </div>
        </div>
    </form>
</center>
    
    <h6 style="text-align: center">du <?php echo $start_date; ?> au <?php echo $end_date; ?> (<?php echo $measure_count; ?> enregistrements analys&eacute;s sur <?php echo $first_reading_begin; ?>) </h4>
<h6 style="text-align: center"> durée depuis le debut du fonctionnement : <?php echo $timepastbegin; ?>j (premier enregistrement le <?php echo $first_reading_time_begin; ?>)</h6>    

<form method="POST">
    <input type="hidden" name="start_date" value="<?= htmlspecialchars($start_date) ?>">
    <input type="hidden" name="end_date" value="<?= htmlspecialchars($end_date) ?>">
    <button type="submit" name="export_csv">Télécharger les données (CSV)</button>
</form>
    <hr>
		            <h3>Vidéo prise en accélérée d'un accouplement de mantes religieuses</h3>

<iframe width="560" height="315" src="https://www.youtube.com/embed/qFw1xZfgYtg?si=4Z_OvBczclMcft4u" title="YouTube video player" frameborder="0" allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture; web-share" referrerpolicy="strict-origin-when-cross-origin" allowfullscreen></iframe>

    <script>
               
    var HumidMoy = <?php echo $HumidMoy; ?>;
    var Humid1 = <?php echo $Humid1; ?>;    
    var Humid2 = <?php echo $Humid2; ?>;
    var Humid3 = <?php echo $Humid3; ?>;
    var Humid4 = <?php echo $Humid4; ?>;
    
    var TempAir = <?php echo $TempAir; ?>;
    var Humidite = <?php echo $Humidite; ?>;
    var Luminosite = <?php echo $Luminosite; ?>;
    
    var etatPompe = <?php echo $etatPompe; ?>;
    var resetMode = <?php echo $resetMode; ?>;

    var PontDiv = <?php echo $PontDiv; ?>;    
    var bootCount = <?php echo $bootCount; ?>;

    var reading_time = <?php echo $reading_time; ?>;

    for(var i=0, l=HumidMoy.length; i<l; i++) {
        HumidMoy[i] = [ reading_time[i], HumidMoy[i] ]
    }
    for(var i=0, l=Humid1.length; i<l; i++) {
      Humid1[i] = [ reading_time[i], Humid1[i] ]
    }
    for(var i=0, l=Humid2.length; i<l; i++) {
      Humid2[i] = [ reading_time[i], Humid2[i] ]
    }
    for(var i=0, l=Humid3.length; i<l; i++) {
      Humid3[i] = [ reading_time[i], Humid3[i] ]
    }
    for(var i=0, l=Humid4.length; i<l; i++) {
      Humid4[i] = [ reading_time[i], Humid4[i] ]
    }
    for(var i=0, l=etatPompe.length; i<l; i++) {
      etatPompe[i] = [ reading_time[i], etatPompe[i] ]
    }
    for(var i=0, l=resetMode.length; i<l; i++) {
      resetMode[i] = [ reading_time[i], resetMode[i] ]
    }   

    for(var i=0, l=TempAir.length; i<l; i++) {
      TempAir[i] = [ reading_time[i], TempAir[i] ]
    }
    for(var i=0, l=Humidite.length; i<l; i++) {
      Humidite[i] = [ reading_time[i], Humidite[i] ]
    }
    for(var i=0, l=bootCount.length; i<l; i++) {
      bootCount[i] = [ reading_time[i], bootCount[i] ]
    }
    for(var i=0, l=PontDiv.length; i<l; i++) {
      PontDiv[i] = [ reading_time[i], PontDiv[i] ]
    }
    for(var i=0, l=Luminosite.length; i<l; i++) {
      Luminosite[i] = [ reading_time[i], Luminosite[i] ]
    }
    
    //reading_time = reading_time.map(function(d) { return new Date(d) } );
    
    /*document.write('    reading_time:  ');
    document.write(reading_time);*/
                               
    Highcharts.chart('chart-niveauxeaux', {
        chart: {
            zoomType: 'xy'
        },
        title: {
            text: 'Les niveaux humidité',
            align: 'left'
        },
        subtitle: {
            text: 'n3pp',
            align: 'left'
        },
        xAxis: [{
            type : 'datetime',
            crosshair: true
        }],
        yAxis: [ { // Primary yAxis
            gridLineWidth: 0,
            title: {
                text: 'Humidité du sol',
                                     
                style: {
                    color:['#00B794']
                }
            },
            labels: {
                format: '{value} UA',
                style: {
                    color:['#00B794']
                }
            }
    
        }, { // Secondary yAxis
            gridLineWidth: 0,
            max: 10,
            title: {
                text: '',
                style: {
                    color: ['#27BDA0']
                }
            },
            labels: {
                format: '',
                style: {
                    color: ['#FFFFFF']
                }
            },
            opposite: true
        }],
        tooltip: {
            shared: true
        },
        legend: {
            layout: 'horizontal',
            align: 'left',
            x: 300,
            verticalAlign: 'top',
            y: 0,
            floating: true,
            backgroundColor:
                Highcharts.defaultOptions.legend.backgroundColor || // theme
                'rgba(255,255,255,0.25)'
        },
        series: [{
            name: 'Humidité moyenne',
            type: 'spline',
            yAxis: 0,
            data: HumidMoy,
            zIndex:9,
            color:'#FF6300',
            tooltip: {
                valueSuffix: ' UA'
            }
        },{
    name: 'Capteur 1',
            type: 'spline',
            lineWidth:1,
            yAxis: 0,
            data: Humid1,
            zIndex:5,
            color:'#00B794',
            tooltip: {
                valueSuffix: ' UA'
            }
        },{
    name: 'Capteur 2',
            type: 'spline',
            lineWidth:1,
            yAxis: 0,
            data: Humid2,
            zIndex:5,
            color:'#008E72',
            tooltip: {
                valueSuffix: ' UA'
            }
        },{
    name: 'Capteur 3',
            type: 'spline',
            yAxis: 0,
            data: Humid3,
            zIndex:4,
            color: '#00B794',
            marker: {
                enabled: false
            },
            dashStyle: 'shortdot',
            tooltip: {
                valueSuffix: ' UA'
            }
        },{
    name: 'Capteur 4',
            type: 'spline',
            yAxis: 0,
            data: Humid4,
            zIndex:5,
            color:'#008E72',
            marker: {
                enabled: false
            },
            dashStyle: 'shortdot',
            tooltip: {
                valueSuffix: ' UA'
            }
        }, {
            name: 'Etat de la pompe',
            type: 'column',
            yAxis: 1,
            data: etatPompe,
            zIndex:2,
            color:'#FF6300',
            tooltip: {
                valueSuffix: ' on-off'
            }
        }, {
            name: 'Mode reset',
            type: 'column',
            yAxis: 1,
            data: resetMode,
            zIndex:1,
            color:'#27BDA0',
            tooltip: {
                valueSuffix: ' on-off'
            }
        }],
        responsive: {
            rules: [{
                condition: {
                    maxWidth: 500
                },
                chartOptions: {
                    legend: {
                        floating: false,
                        layout: 'horizontal',
                        align: 'center',
                        verticalAlign: 'bottom',
                        x: 0,
                        y: 0
                    },
                    yAxis: [{
                        labels: {
                            align: 'right',
                            x: 0,
                            y: -6
                        },
                        showLastLabel: false
                    }, {
                        labels: {
                            align: 'left',
                            x: 0,
                            y: -6
                        },
                        showLastLabel: false
                    }, {
                        visible: false
                    }]
                }
            }]
        }
    });
    
    Highcharts.chart('chart-temperatures', {
        chart: {
            zoomType: 'xy'
        },
        title: {
            text: 'Les paramètres physiques du système',
            align: 'left'
        },
        subtitle: {
            text: 'n3pp',
            align: 'left'
        },
        xAxis: [{
            type:'datetime',                   
            crosshair: true
        }],
        yAxis: [{ // Primary yAxis
            labels: {
                format: '{value} °C',
                style: {
            	color:['#FF6300'],
                }
            },
            title: {
                text: 'Température air',
                style: {
    	    color:['#007E61']           
    	 }
            },
            labels: {
                format: '{value} °C',
                style: {
    		color:['#007E61']            
    		}
            }
    
        }, { // Secondary yAxis
            gridLineWidth: 0,
            title: {
                text: 'Humidité',
                style: {
            	color:['#00B794'],
                }
            },
            labels: {
                format: '{value} %',
                style: {
            	color:['#00B794'],
                }
            }
    
        }, { // Tertiary yAxis
            gridLineWidth: 0,
            title: {
                text: 'Luminosité',
                style: {
            	color:['#FF6300'],
                }
            },
            labels: {
                format: '{value} UA',
                style: {
            	color:['#FF6300'],
                }
            },
            opposite: true
        }],
        tooltip: {
            shared: true
        },
        legend: {
            layout: 'horizontal',
            align: 'left',
            x: 350,
            verticalAlign: 'top',
            y: 20,
            floating: true,
            backgroundColor:
                Highcharts.defaultOptions.legend.backgroundColor || // theme
                'rgba(255,255,255,0.25)'
        },
        series: [{
            name: 'Température air',
            type: 'spline',
            yAxis: 0,
            data: TempAir,
            zIndex: 9,
            color: '#007E61',
            tooltip: {
                valueSuffix: ' °C'
            }
        }, {
            name: 'Humidité',
            type: 'spline',
            yAxis: 1,
            data: Humidite,
            zIndex: 8,
            color: '#00B794',
            tooltip: {
                valueSuffix: ' %'
            }
        }, {
            name: 'Luminosité',
            type: 'spline',
            yAxis: 2,
            data: Luminosite,
            zIndex:7,
            color: '#FF6300',
            marker: {
                enabled: false
            },
            dashStyle: 'shortdot',            
            tooltip: {
                valueSuffix: ' UA'
            }
        }],
        responsive: {
            rules: [{
                condition: {
                    maxWidth: 500
                },
                chartOptions: {
                    legend: {
                        floating: false,
                        layout: 'horizontal',
                        align: 'center',
                        verticalAlign: 'bottom',
                        x: 0,
                        y: 0
                    },
                    yAxis: [{
                        labels: {
                            align: 'right',
                            x: 0,
                            y: -6
                        },
                        showLastLabel: false
                    }, {
                        labels: {
                            align: 'left',
                            x: 0,
                            y: -6
                        },
                        showLastLabel: false
                    }, {
                        visible: false
                    }]
                }
            }]
        }
    });
    Highcharts.chart('chart-cycles', {
        chart: {
            zoomType: 'xy'
        },
        title: {
            text: 'Nombre de cycles de veille et batterie',
            align: 'left'
        },
        subtitle: {
            text: 'n3pp',
            align: 'left'
        },
        xAxis: [{
            type:'datetime',                   
            crosshair: true
        }],
        yAxis: [{ // Primary yAxis
            labels: {
                format: '{value}',
                style: {
            	color:['#FF6300'],
                }
            },
            title: {
                text: 'cycles',
                style: {
    	    color:['#007E61']           
    	 }
            },
            labels: {
                format: '{value}',
                style: {
    		color:['#007E61']            
    		}
            }
        }, { // Tertiary yAxis
            gridLineWidth: 0,
            title: {
                text: 'Niveau de la batterie',
                style: {
            	color:['#FF6300'],
                }
            },
            labels: {
                format: '{value} UA',
                style: {
            	color:['#FF6300'],
                }
            },
            opposite: true
        }],
        tooltip: {
            shared: true
        },
        legend: {
            layout: 'horizontal',
            align: 'left',
            x: 350,
            verticalAlign: 'top',
            y: 0,
            floating: true,
            backgroundColor:
                Highcharts.defaultOptions.legend.backgroundColor || // theme
                'rgba(255,255,255,0.25)'
        },
        series: [{
            name: 'Nombre de cyles',
            type: 'spline',
            yAxis: 0,
            data: bootCount,
            zIndex: 9,
            color: '#007E61',
        }, {
            name: 'Etat de la batterie',
            type: 'spline',
            yAxis: 1,
            data: PontDiv,
            zIndex:7,
            color: '#FF6300',
            tooltip: {
                valueSuffix: ' UA'
            }
        }],
        responsive: {
            rules: [{
                condition: {
                    maxWidth: 500
                },
                chartOptions: {
                    legend: {
                        floating: false,
                        layout: 'horizontal',
                        align: 'center',
                        verticalAlign: 'bottom',
                        x: 0,
                        y: 0
                    },
                    yAxis: [{
                        labels: {
                            align: 'right',
                            x: 0,
                            y: -6
                        },
                        showLastLabel: false
                    }, {
                        labels: {
                            align: 'left',
                            x: 0,
                            y: -6
                        },
                        showLastLabel: false
                    }, {
                        visible: false
                    }]
                }
            }]
        }
    });    
    </script>
    
    <script>
        var HumidMoy = <?php echo $last_reading_humidMoy; ?>;        
        var Humid1 = <?php echo $last_reading_humid1; ?>;
        var Humid2 = <?php echo $last_reading_humid2; ?>;
        var Humid3 = <?php echo $last_reading_humid3; ?>;
        var Humid4 = <?php echo $last_reading_humid4; ?>;
        var TemperatureAir = <?php echo $last_reading_tempair; ?>;        
        var Humidite = <?php echo $last_reading_humi; ?>;
        var Luminosite = <?php echo $last_reading_lumi; ?>;
        
        setHumidMoy(HumidMoy);
        setHumid1(Humid1);
        setHumid2(Humid2);
        setHumid3(Humid3);
        setHumid4(Humid4);        
        setTempAir(TemperatureAir);
        setHumidite(Humidite);
        setLuminosite(Luminosite);

        function setTempAir(curVal){
        	//set range for Temperature in Celsius -5 Celsius to 38 Celsius
        	var minTempAir = 0.0;
        	var maxTempAir = 35.0;
            //set range for Temperature in Fahrenheit 23 Fahrenheit to 100 Fahrenheit
        	//var minTemp = 23;
        	//var maxTemp = 100;
         
         
    
        	var newVal = scaleValue(curVal, [minTempAir, maxTempAir], [0, 180]);
        	$('.gauge--1 .semi-circle--mask').attr({
        		style: '-webkit-transform: rotate(' + newVal + 'deg);' +
        		'-moz-transform: rotate(' + newVal + 'deg);' +
        		'transform: rotate(' + newVal + 'deg);'
        	});
        	$("#tempair").text(curVal + ' ºC');
        }
        
        function setHumidite(curVal){
        	//set range for Humidity percentage 0 % to 100 %
        	var minHumidite = 0;
        	var maxHumidite = 100;
    
        	var newVal = scaleValue(curVal, [minHumidite, maxHumidite], [0, 180]);
        	$('.gauge--2 .semi-circle--mask').attr({
        		style: '-webkit-transform: rotate(' + newVal + 'deg);' +
        		'-moz-transform: rotate(' + newVal + 'deg);' +
        		'transform: rotate(' + newVal + 'deg);'
        	});
        	$("#humi").text(curVal + ' %');
        }
        
        function setLuminosite(curVal){
        	//set range for Humidity percentage 0 % to 100 %
        	var minLuminosite = 0;
        	var maxLuminosite = 4095;
    
        	var newVal = scaleValue(curVal, [minLuminosite, maxLuminosite], [0, 180]);
        	$('.gauge--3 .semi-circle--mask').attr({
        		style: '-webkit-transform: rotate(' + newVal + 'deg);' +
        		'-moz-transform: rotate(' + newVal + 'deg);' +
        		'transform: rotate(' + newVal + 'deg);'
        	});
        	$("#lumi").text(curVal + ' UA');
        }

        function setHumidMoy(curVal){
        	//set range for Temperature in Celsius -5 Celsius to 38 Celsius
        	var minHumidMoy = 0;
        	var maxHumidMoy = 4095;
            //set range for Temperature in Fahrenheit 23 Fahrenheit to 100 Fahrenheit
        	//var minTemp = 23;
        	//var maxTemp = 100;
    
        	var newVal = scaleValue(curVal, [minHumidMoy, maxHumidMoy], [0, 180]);
        	$('.gauge--4 .semi-circle--mask').attr({
        		style: '-webkit-transform: rotate(' + newVal + 'deg);' +
        		'-moz-transform: rotate(' + newVal + 'deg);' +
        		'transform: rotate(' + newVal + 'deg);'
        	});
        	$("#humidMoy").text(curVal + ' UA');
        }
        
        function setHumid1(curVal){
        	//set range for Temperature in Celsius -5 Celsius to 38 Celsius
        	var minHumid1 = 0;
        	var maxHumid1 = 4095;
            //set range for Temperature in Fahrenheit 23 Fahrenheit to 100 Fahrenheit
        	//var minTemp = 23;
        	//var maxTemp = 100;
    
        	var newVal = scaleValue(curVal, [minHumid1, maxHumid1], [0, 180]);
        	$('.gauge--5 .semi-circle--mask').attr({
        		style: '-webkit-transform: rotate(' + newVal + 'deg);' +
        		'-moz-transform: rotate(' + newVal + 'deg);' +
        		'transform: rotate(' + newVal + 'deg);'
        	});
        	$("#humid1").text(curVal + ' UA');
        }

        function setHumid2(curVal){
        	//set range for Humidity percentage 0 % to 100 %
        	var minHumid2 = 0;
        	var maxHumid2 = 4095;
    
        	var newVal = scaleValue(curVal, [minHumid2, maxHumid2], [0, 180]);
        	$('.gauge--6 .semi-circle--mask').attr({
        		style: '-webkit-transform: rotate(' + newVal + 'deg);' +
        		'-moz-transform: rotate(' + newVal + 'deg);' +
        		'transform: rotate(' + newVal + 'deg);'
        	});
        	$("#humid2").text(curVal + ' UA');
        }
        
        function setHumid3(curVal){
        	//set range for Humidity percentage 0 % to 100 %
        	var minHumid3 = 0;
        	var maxHumid3 = 4095;
    
        	var newVal = scaleValue(curVal, [minHumid3, maxHumid3], [0, 180]);
        	$('.gauge--7 .semi-circle--mask').attr({
        		style: '-webkit-transform: rotate(' + newVal + 'deg);' +
        		'-moz-transform: rotate(' + newVal + 'deg);' +
        		'transform: rotate(' + newVal + 'deg);'
        	});
        	$("#humid3").text(curVal + ' UA');
        }
        
        function setHumid4(curVal){
        	//set range for Humidity percentage 0 % to 100 %
        	var minHumid4 = 0;
        	var maxHumid4 = 4095;
    
        	var newVal = scaleValue(curVal, [minHumid4, maxHumid4], [0, 180]);
        	$('.gauge--8 .semi-circle--mask').attr({
        		style: '-webkit-transform: rotate(' + newVal + 'deg);' +
        		'-moz-transform: rotate(' + newVal + 'deg);' +
        		'transform: rotate(' + newVal + 'deg);'
        	});
        	$("#humid4").text(curVal + ' UA');
        }
    
        function scaleValue(value, from, to) {
            var scale = (to[1] - to[0]) / (from[1] - from[0]);
            var capped = Math.min(from[1], Math.max(from[0], value)) - from[0];
            return ~~(capped * scale + to[0]);
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