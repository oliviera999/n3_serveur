<?Php
require "msp1-config.php";// Database connection


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

    if ($_GET["readingsCount"]){
      $data = $_GET["readingsCount"];
      $data = trim($data);
      $data = stripslashes($data);
      $data = htmlspecialchars($data);
      $readings_count = $_GET["readingsCount"];
    }
    // default readings count set to 2000
    else {
      $readings_count = 60;
    }
    
    $last_reading = getLastReadings($start_date, $end_date);

    $last_reading_TempAirInt = $last_reading["TempAirInt"];
    $last_reading_TempAirExt = $last_reading["TempAirExt"];
    $last_reading_HumidAirInt = $last_reading["HumidAirInt"];
    $last_reading_TempEau = $last_reading["TempEau"];
    $last_reading_HumidAirExt = $last_reading["HumidAirExt"];
    $last_reading_LuminositeA = $last_reading["LuminositeA"];
    $last_reading_LuminositeB = $last_reading["LuminositeB"];
    $last_reading_LuminositeC = $last_reading["LuminositeC"];
    $last_reading_LuminositeD = $last_reading["LuminositeD"];
    $last_reading_LuminositeMoy = $last_reading["LuminositeMoy"];
    $last_reading_HumidSol = $last_reading["HumidSol"];
    $last_reading_Pluie = $last_reading["Pluie"];
    
    $last_reading_time = $last_reading["reading_time"];
 
    $first_reading = getAllReadings();
    $first_reading_begin = $first_reading ["max_amount2"]; //firstreading2
    
    $first_reading_time = getFirstReadings($readings_count);
    $first_reading_time = $first_reading_time ["min_amount2"];
    // Uncomment to set timezone to - 1 hour (you can change 1 to any number)
    
    $first_reading_time_begin = getFirstReadingsBegin();
    $first_reading_time_begin = $first_reading_time_begin ["min_amount3"];
    
    
    $last_reading_time = date("Y-m-d H:i:s", strtotime("$last_reading_time")); //last_reading_time
    //$last_reading_time = date("Y-m-d H:i:s", strtotime("$last_reading_time"));
    $first_reading_time2 = date("Y-m-d H:i:s", strtotime("$first_reading")); //last_reading_time4
    $first_reading_time_begin = date("Y-m-d H:i:s", strtotime("$first_reading_time_begin")); 
    
    
    // Uncomment to set timezone to - 1 hour (you can change 1 to any number)
    $last_reading_timestamp = strtotime("$last_reading_time");
    $first_reading_timestamp = strtotime("$first_reading_time");
    $first_reading_begin_timestamp = strtotime("$first_reading_time_begin");
    //echo $last_reading_timestamp;
    //echo $first_reading_timestamp;

    
    $heures = "h";
    $minutes = "min";
    $jours = "j";

    $last_reading_time = date("d/m/Y H:i:s", strtotime("$last_reading_time - 1 hours")); //last_reading_time
    //$last_reading_time = date("Y-m-d H:i:s", strtotime("$last_reading_time"));
    $first_reading_time2 = date("d/m/Y H:i:s", strtotime("$first_reading")); //last_reading_time4
    $first_reading_time = date("d/m/Y H:i:s", strtotime("$first_reading_time - 1 hours")); //last_reading_time4
    
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
    echo $last_reading_TempAirInt;*/

    $min_TempAirInt = minReading($start_date, $end_date, 'TempAirInt');
    $max_TempAirInt = maxReading($start_date, $end_date, 'TempAirInt');
    $avg_TempAirInt = avgReading($start_date, $end_date, 'TempAirInt');
    $stddev_TempAirInt = stddevReading($start_date, $end_date, 'TempAirInt');
    
    $min_TempAirExt = minReading($start_date, $end_date, 'TempAirExt');
    $max_TempAirExt = maxReading($start_date, $end_date, 'TempAirExt');
    $avg_TempAirExt = avgReading($start_date, $end_date, 'TempAirExt');
    $stddev_TempAirExt = stddevReading($start_date, $end_date, 'TempAirExt');
    
    $min_HumidAirInt = minReading($start_date, $end_date, 'HumidAirInt');
    $max_HumidAirInt = maxReading($start_date, $end_date, 'HumidAirInt');
    $avg_HumidAirInt = avgReading($start_date, $end_date, 'HumidAirInt');
    $stddev_HumidAirInt = stddevReading($start_date, $end_date, 'HumidAirInt');
    
    $avg_HumidAirExt = avgReading($start_date, $end_date, 'HumidAirExt');
    $max_HumidAirExt = maxReading($start_date, $end_date, 'HumidAirExt');
    $min_HumidAirExt = minReading($start_date, $end_date, 'HumidAirExt');
    $stddev_HumidAirExt = stddevReading($start_date, $end_date, 'HumidAirExt');
    
    $min_LuminositeA = minReading($start_date, $end_date, 'LuminositeA');
    $max_LuminositeA = maxReading($start_date, $end_date, 'LuminositeA');
    $avg_LuminositeA = avgReading($start_date, $end_date, 'LuminositeA');
    $stddev_LuminositeA = stddevReading($start_date, $end_date, 'LuminositeA');

    $min_LuminositeB = minReading($start_date, $end_date, 'LuminositeB');
    $max_LuminositeB = maxReading($start_date, $end_date, 'LuminositeB');
    $avg_LuminositeB = avgReading($start_date, $end_date, 'LuminositeB');
    $stddev_LuminositeB = stddevReading($start_date, $end_date, 'LuminositeB');
    
    $min_LuminositeC = minReading($start_date, $end_date, 'LuminositeC');
    $max_LuminositeC = maxReading($start_date, $end_date, 'LuminositeC');
    $avg_LuminositeC = avgReading($start_date, $end_date, 'LuminositeC');
    $stddev_LuminositeC = stddevReading($start_date, $end_date, 'LuminositeC');
    
    $min_LuminositeD = minReading($start_date, $end_date, 'LuminositeD');
    $max_LuminositeD = maxReading($start_date, $end_date, 'LuminositeD');
    $avg_LuminositeD = avgReading($start_date, $end_date, 'LuminositeD');
    $stddev_LuminositeD = stddevReading($start_date, $end_date, 'LuminositeD');    
    
    $min_LuminositeMoy = minReading($start_date, $end_date, 'LuminositeMoy');
    $max_LuminositeMoy = maxReading($start_date, $end_date, 'LuminositeMoy');
    $avg_LuminositeMoy = avgReading($start_date, $end_date, 'LuminositeMoy');
    $stddev_LuminositeMoy = stddevReading($start_date, $end_date, 'LuminositeMoy');

    $min_HumidSol = minReading($start_date, $end_date, 'HumidSol');
    $max_HumidSol = maxReading($start_date, $end_date, 'HumidSol');
    $avg_HumidSol = avgReading($start_date, $end_date, 'HumidSol');
    $stddev_HumidSol = stddevReading($start_date, $end_date, 'HumidSol');
    
    $min_Pluie = minReading($start_date, $end_date, 'Pluie');
    $max_Pluie = maxReading($start_date, $end_date, 'Pluie');
    $avg_Pluie = avgReading($start_date, $end_date, 'Pluie');
    $stddev_Pluie = stddevReading($start_date, $end_date, 'Pluie');
    
    $min_TempEau = minReading($start_date, $end_date, 'TempEau');
    $max_TempEau = maxReading($start_date, $end_date, 'TempEau');
    $avg_TempEau = avgReading($start_date, $end_date, 'TempEau');
    $stddev_TempEau = stddevReading($start_date, $end_date, 'TempEau');
    
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
		<link rel="stylesheet" href="https://iot.olution.info/assets/css/main.css" />
		<noscript><link rel="stylesheet" href="https://iot.olution.info/assets/css/noscript.css" /></noscript>
		<link rel="shortcut icon" type="image/png" href="https://iot.olution.info/images/favico.png"/"/>
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
						<a href="https://iot.olution.info/index.php" class="logo">n<sup>3</sup> iot datas</a>
					</header>

				 <!-- Nav -->
					<nav id="nav">
						<ul class="links">
							<li><a href="https://iot.olution.info/index.php">Accueil</a></li>
							<li><a href="https://iot.olution.info/ffp3/ffp3datas/ffp3-data.php">L'aquaponie</a></li>							
							<li  class="active"><a href="https://iot.olution.info/msp1/msp1datas/msp1-data.php">Le potager</a></li>
							<li><a href="https://iot.olution.info/n3pp/n3ppdatas/n3pp-data.php">L'élevage d'insectes</a></li>
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
									    <i class="icon solid fa-seedling">
									    </i> 
									    Potager
									    <i class="icon solid fa-seedling">
									    </i> 									    
									</h2>
									<p>Le système est suivi grâce à la carte de développement ESP-32 qui mesure et présente différents paramètres du système. Il est également pouvu d'un tracker solaire associé à des panneaux photovoltaïques qui le rendent autonome en énergie. Les données sont transmises sur le serveur d'olution et traitées pour être présentées.</p>
						           <!-- <a href="https://iot.olution.info/msp1/msp1gallery/msp1-gallery.php?page=1" class="button large">Photos du potager</a>
						            

   									<iframe allow="camera; microphone; fullscreen; display-capture; autoplay" src="https://meet.jit.si/msp1live" style="height: 600; width: 100%; border: 0px;"></iframe> -->
								</header>
								
<?php
$reading_time = array_column($readings, 'reading_time');

// ******* Uncomment to convert readings time array to your timezone ********
$i = 0;
foreach ($reading_time as $reading){
    // Uncomment to set timezone to - 1 hour (you can change 1 to any number)
    $reading_time[$i] = (strtotime(date("Y-m-d H:i:s", strtotime("$reading + 1 hours")))*1000);
                                                
    // Uncomment to set timezone to + 4 hours (you can change 4 to any number)
    //$readings_time[$i] = date("Y-m-d H:i:s", strtotime("$reading + 4 hours"));
    $i += 1;
}
/*
// ******* Uncomment to convert readings time array to your timezone ********
$i = 0;
foreach ($reading_time as $reading){
    $reading_time[$i] = strtotime("$reading");
    $i += 1;
}*/

$LuminositeA = json_encode(array_reverse(array_column($readings, 'LuminositeA')), JSON_NUMERIC_CHECK);
$LuminositeB = json_encode(array_reverse(array_column($readings, 'LuminositeB')), JSON_NUMERIC_CHECK);
$LuminositeC = json_encode(array_reverse(array_column($readings, 'LuminositeC')), JSON_NUMERIC_CHECK);
$LuminositeD = json_encode(array_reverse(array_column($readings, 'LuminositeD')), JSON_NUMERIC_CHECK);
$LuminositeMoy = json_encode(array_reverse(array_column($readings, 'LuminositeMoy')), JSON_NUMERIC_CHECK);

$ServoHB = json_encode(array_reverse(array_column($readings, 'ServoHB')), JSON_NUMERIC_CHECK);
$ServoGD = json_encode(array_reverse(array_column($readings, 'ServoGD')), JSON_NUMERIC_CHECK);

$HumidSol= json_encode(array_reverse(array_column($readings, 'HumidSol')), JSON_NUMERIC_CHECK);
$Pluie= json_encode(array_reverse(array_column($readings, 'Pluie')), JSON_NUMERIC_CHECK);
$TempEau= json_encode(array_reverse(array_column($readings, 'TempEau')), JSON_NUMERIC_CHECK);


$TempAirInt = json_encode(array_reverse(array_column($readings, 'TempAirInt')), JSON_NUMERIC_CHECK);
$TempAirExt = json_encode(array_reverse(array_column($readings, 'TempAirExt')), JSON_NUMERIC_CHECK);
$HumidAirInt = json_encode(array_reverse(array_column($readings, 'HumidAirInt')), JSON_NUMERIC_CHECK);
$HumidAirExt = json_encode(array_reverse(array_column($readings, 'HumidAirExt')), JSON_NUMERIC_CHECK);

$etatPompe = json_encode(array_reverse(array_column($readings, 'etatPompe')), JSON_NUMERIC_CHECK);
$ArrosageManu = json_encode(array_reverse(array_column($readings, 'ArrosageManu')), JSON_NUMERIC_CHECK);
$resetMode = json_encode(array_reverse(array_column($readings, 'resetMode')), JSON_NUMERIC_CHECK);

$PontDiv = json_encode(array_reverse(array_column($readings, 'PontDiv')), JSON_NUMERIC_CHECK);
$bootCount = json_encode(array_reverse(array_column($readings, 'bootCount')), JSON_NUMERIC_CHECK);

$reading_time = json_encode(array_reverse($reading_time), JSON_NUMERIC_CHECK);


?>
    <h2>Synthèse des mesures du <?= htmlspecialchars(date("d/m/Y", strtotime($start_date))) ?> au <?= htmlspecialchars(date("d/m/Y", strtotime($end_date))) ?></h2>

<div id="table-light" class="container">
    <section class="content">
        <div class="table-wrapper">
		    <table>
		        <tr>
		            <h3>Statistiques des capteurs de luminosité</h3>
		        </tr>
		        <tr>
		            <td>Mesures actuelles</td>
		            <td>	    
                        <div class="box gauge--8">
                            <h5>Luminosité moyenne</h5>
                            <div class="mask">
                                <div class="semi-circle"></div>
                                <div class="semi-circle--mask"></div>
                            </div>
                            <p style="text-align: center" id="LuminositeMoy">--</p>
                        </div>
		            </td>
                    <td>
                        <div class="box gauge--9">
                            <h5>Capteur 1</h5>
                            <div class="mask">
                                <div class="semi-circle"></div>
                                <div class="semi-circle--mask"></div>
                            </div>
                            <p style="text-align: center" id="LuminositeA">--</p>
	                    </div>
	                 </td>
	                 <td>
                        <div class="box gauge--10">
                            <h5>Capteur 2</h5>
                            <div class="mask">
                                <div class="semi-circle"></div>
                                <div class="semi-circle--mask"></div>
                            </div>
                            <p style="text-align: center" id="LuminositeB">--</p> 
	                    </div>
                    </td>
	                 <td>
                        <div class="box gauge--11">
                            <h5>Capteur 3</h5>
                            <div class="mask">
                                <div class="semi-circle"></div>
                                <div class="semi-circle--mask"></div>
                            </div>
                            <p style="text-align: center" id="LuminositeC">--</p> 
	                    </div>
                    </td>
                     <td>
                        <div class="box gauge--12">
                            <h5>Capteur 4</h5>
                            <div class="mask">
                                <div class="semi-circle"></div>
                                <div class="semi-circle--mask"></div>
                            </div>
                            <p style="text-align: center" id="LuminositeD">--</p> 
	                    </div>
                    </td>                    
                </tr>
		        <tr>
                    <td>Moy</td>
                    <td><?php echo round($avg_LuminositeMoy, 0); ?> UA</td>
                    <td><?php echo round($avg_LuminositeA, 0); ?> UA</td>                                        
                    <td><?php echo round($avg_LuminositeB, 0); ?> UA</td>
                    <td><?php echo round($avg_LuminositeC, 0); ?> UA</td>
                    <td><?php echo round($avg_LuminositeD, 0); ?> UA</td>

                </tr>
		        <tr>
		            <td>Min</td>
                    <td><?php echo $min_LuminositeMoy; ?> UA</td>
                    <td><?php echo $min_LuminositeA; ?> UA</td>
                    <td><?php echo $min_LuminositeB; ?> UA</td>
                    <td><?php echo $min_LuminositeC; ?> UA</td>
                    <td><?php echo $min_LuminositeD; ?> UA</td>

                </tr>
		        <tr>
                    <td>Max</td>
                    <td><?php echo $max_LuminositeMoy; ?> UA</td>
                    <td><?php echo $max_LuminositeA; ?> UA</td>
                    <td><?php echo $max_LuminositeB; ?> UA</td>
                    <td><?php echo $max_LuminositeC; ?> UA</td>
                    <td><?php echo $max_LuminositeD; ?> UA</td>

                </tr>
		        <tr>
                    <td>ET</td>
                    <td><?php echo round($stddev_LuminositeMoy, 2); ?> UA</td>
                    <td><?php echo round($stddev_LuminositeA, 2); ?> UA</td>
                    <td><?php echo round($stddev_LuminositeB, 2); ?> UA</td>
                    <td><?php echo round($stddev_LuminositeC, 2); ?> UA</td>
                    <td><?php echo round($stddev_LuminositeD, 2); ?> UA</td>
                </tr>
            </table>
        </div>
    </section>
</div>
<br>

<div id="chart-lights" class="container"></div>
<hr />

<div id="table-eaux" class="container">
    <section class="content">
        <div class="table-wrapper">
		    <table>
		        <tr>
		            <h3>Statistiques des capteurs d'humidité et de température du sol</h3>
		        </tr>
		        <tr>
		            <td>Mesures actuelles</td>
	                 <td>
                        <div class="box gauge--7">
                            <h5>Humidité du sol</h5>
                            <div class="mask">
                                <div class="semi-circle"></div>
                                <div class="semi-circle--mask"></div>
                            </div>
                            <p style="text-align: center" id="HumidSol">--</p> 
	                    </div>
                    </td>
		            <td>	    
                        <div class="box gauge--5">
                            <h5>Température du sol</h5>
                            <div class="mask">
                                <div class="semi-circle"></div>
                                <div class="semi-circle--mask"></div>
                            </div>
                            <p style="text-align: center" id="TempEau">--</p>
                        </div>
		            </td>
                </tr>
		        <tr>
                    <td>Moy</td>
                    <td><?php echo round($avg_HumidSol, 0); ?> UA</td>
                    <td><?php echo round($avg_TempEau, 0); ?> °C</td>

                </tr>
		        <tr>
		            <td>Min</td>
                    <td><?php echo $min_HumidSol; ?> UA</td>
                    <td><?php echo $min_TempEau; ?> °C</td>

                </tr>
		        <tr>
                    <td>Max</td>
                    <td><?php echo $max_HumidSol; ?> UA</td>
                    <td><?php echo $max_TempEau; ?> °C</td>

                </tr>
		        <tr>
                    <td>ET</td>
                    <td><?php echo round($stddev_HumidSol, 2); ?> UA</td>
                    <td><?php echo round($stddev_TempEau, 2); ?> °C</td>
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
		            <h3>Statistiques des températures et humidités de l'air</h3>
		        </tr>
		        <tr>
		            <td>Mesures actuelles</td>
                    <td>
		                <div class="box gauge--1">
	                        <h5>TEMPERATURE AIR INT</h5>
                            <div class="mask">
			                    <div class="semi-circle"></div>
			                    <div class="semi-circle--mask"></div>
			                </div>
		                    <p style="text-align: center" id="TempAirInt">--</p>
	                    </div>
	                 </td>
	                 <td>
                        <div class="box gauge--2">
                            <h5>TEMPERATURE AIR EXT</h5>
                            <div class="mask">
                                <div class="semi-circle"></div>
                                <div class="semi-circle--mask"></div>
                            </div>
                            <p style=" text-align: center" id="TempAirExt">--</p>
	                    </div>
                    </td>
                    <td>
                        <div class="box gauge--3">
                            <h5>HUMIDITE INTERIEUR</h5>
                            <div class="mask">
                                <div class="semi-circle"></div>
                                <div class="semi-circle--mask"></div>
                            </div>
                            <p style="text-align: center" id="HumidAirInt">--</p>
                        </div>
                    </td>
                     <td>
                        <div class="box gauge--4">
                            <h5>HUMIDITE EXTERIEUR</h5>
                            <div class="mask">
                                <div class="semi-circle"></div>
                                <div class="semi-circle--mask"></div>
                            </div>
                            <p style="text-align: center" id="HumidAirExt">--</p> 
	                    </div>
                    </td>
                </tr>
		        <tr>
                    <td>Moy</td>
                    <td><?php echo round($avg_TempAirInt,1); ?>&deg;C</td>
                    <td><?php echo round($avg_TempAirExt, 1); ?>&deg;C</td>
                    <td><?php echo round($avg_HumidAirInt, 0); ?> %</td>
                    <td><?php echo round($avg_HumidAirExt, 0); ?> %</td>
                </tr>
		        <tr>
		            <td>Min</td>
                    <td><?php echo round($min_TempAirInt, 1); ?>&deg;C</td>
                    <td><?php echo round($min_TempAirExt, 1); ?>&deg;C</td>
                    <td><?php echo $min_HumidAirInt; ?> %</td>
                    <td><?php echo $min_HumidAirExt; ?> %</td>
                </tr>
		        <tr>
                    <td>Max</td>
                    <td><?php echo round ($max_TempAirInt, 1); ?>&deg;C</td>
                    <td><?php echo round ($max_TempAirExt, 1); ?>&deg;C</td>
                    <td><?php echo $max_HumidAirInt; ?> %</td>
                    <td><?php echo $max_HumidAirExt; ?> %</td>
                </tr>
		        <tr>
                    <td>ET</td>
                    <td><?php echo round($stddev_TempAirInt, 2); ?>&deg;C</td>
                    <td><?php echo round($stddev_TempAirExt, 2); ?>&deg;C</td>
                    <td><?php echo round($stddev_HumidAirInt, 0); ?> %</td>
                    <td><?php echo round($stddev_HumidAirExt, 0); ?> %</td>
                </tr>
            </table>
        </div>
    </section>
</div>
<br>

<div id="chart-temperatures" class="container"></div>
<hr />
		            <h3>Niveau batterie, cycles allumage, orientation panneau solaire</h3>

<div id="chart-cycles" class="container"></div>
<hr />

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
    

		            <h2>Mesures manuelles</h2>

    <div>Ces analyses sont réalisées manuellement par les élèves.</div>
    <iframe width="600" height="371" seamless frameborder="0" scrolling="no" src="https://docs.google.com/spreadsheets/d/e/2PACX-1vT5i0I15n-Nef5J1LlY-MGYkPNQtmyzJJ08yObEc4dI_YCQrHFyEwxBx6vmwX-6MnqHwHVzDvupD_Qv/pubchart?oid=982960825&amp;format=interactive"></iframe>
    <iframe width="600" height="371" seamless frameborder="0" scrolling="no" src="https://docs.google.com/spreadsheets/d/e/2PACX-1vT5i0I15n-Nef5J1LlY-MGYkPNQtmyzJJ08yObEc4dI_YCQrHFyEwxBx6vmwX-6MnqHwHVzDvupD_Qv/pubchart?oid=553509194&amp;format=interactive"></iframe>
    <iframe width="600" height="371" seamless frameborder="0" scrolling="no" src="https://docs.google.com/spreadsheets/d/e/2PACX-1vT5i0I15n-Nef5J1LlY-MGYkPNQtmyzJJ08yObEc4dI_YCQrHFyEwxBx6vmwX-6MnqHwHVzDvupD_Qv/pubchart?oid=1803715348&amp;format=interactive"></iframe>
    <iframe width="600" height="371" seamless frameborder="0" scrolling="no" src="https://docs.google.com/spreadsheets/d/e/2PACX-1vT5i0I15n-Nef5J1LlY-MGYkPNQtmyzJJ08yObEc4dI_YCQrHFyEwxBx6vmwX-6MnqHwHVzDvupD_Qv/pubchart?oid=1846523563&amp;format=interactive"></iframe>

<br>
<hr />
</div>
    <script>
    var LuminositeMoy = <?php echo $LuminositeMoy; ?>;
    var LuminositeA = <?php echo $LuminositeA; ?>;    
    var LuminositeB = <?php echo $LuminositeB; ?>;
    var LuminositeC = <?php echo $LuminositeC; ?>;
    var LuminositeD = <?php echo $LuminositeD; ?>;
    
    var ServoHB = <?php echo $ServoHB; ?>;
    var ServoGD = <?php echo $ServoGD; ?>;
               
    var TempEau = <?php echo $TempEau; ?>;
    var HumidSol = <?php echo $HumidSol; ?>;
    var Pluie = <?php echo $Pluie; ?>;
    
    var TempAirInt = <?php echo $TempAirInt; ?>;
    var TempAirExt = <?php echo $TempAirExt; ?>;
    var HumidAirInt = <?php echo $HumidAirInt; ?>;
    var HumidAirExt = <?php echo $HumidAirExt; ?>;    

    
    var etatPompe = <?php echo $etatPompe; ?>;
    var resetMode = <?php echo $resetMode; ?>;

    var PontDiv = <?php echo $PontDiv; ?>;    
    var bootCount = <?php echo $bootCount; ?>;

    var reading_time = <?php echo $reading_time; ?>;

    for(var i=0, l=LuminositeMoy.length; i<l; i++) {
        LuminositeMoy[i] = [ reading_time[i], LuminositeMoy[i] ]
    }
    for(var i=0, l=LuminositeA.length; i<l; i++) {
      LuminositeA[i] = [ reading_time[i], LuminositeA[i] ]
    }
    for(var i=0, l=LuminositeB.length; i<l; i++) {
      LuminositeB[i] = [ reading_time[i], LuminositeB[i] ]
    }
    for(var i=0, l=LuminositeC.length; i<l; i++) {
      LuminositeC[i] = [ reading_time[i], LuminositeC[i] ]
    }
    for(var i=0, l=LuminositeD.length; i<l; i++) {
      LuminositeD[i] = [ reading_time[i], LuminositeD[i] ]
    }

    for(var i=0, l=ServoHB.length; i<l; i++) {
      ServoHB[i] = [ reading_time[i], ServoHB[i] ]
    }
    for(var i=0, l=ServoGD.length; i<l; i++) {
      ServoGD[i] = [ reading_time[i], ServoGD[i] ]
    }

    for(var i=0, l=TempEau.length; i<l; i++) {
        TempEau[i] = [ reading_time[i], TempEau[i] ]
    }
    for(var i=0, l=HumidAirExt.length; i<l; i++) {
      HumidAirExt[i] = [ reading_time[i], HumidAirExt[i] ]
    }
    for(var i=0, l=HumidSol.length; i<l; i++) {
      HumidSol[i] = [ reading_time[i], HumidSol[i] ]
    }
    for(var i=0, l=Pluie.length; i<l; i++) {
      Pluie[i] = [ reading_time[i], Pluie[i] ]
    }
    for(var i=0, l=etatPompe.length; i<l; i++) {
      etatPompe[i] = [ reading_time[i], etatPompe[i] ]
    }
    for(var i=0, l=resetMode.length; i<l; i++) {
      resetMode[i] = [ reading_time[i], resetMode[i] ]
    }   

    for(var i=0, l=TempAirInt.length; i<l; i++) {
      TempAirInt[i] = [ reading_time[i], TempAirInt[i] ]
    }
    for(var i=0, l=TempAirExt.length; i<l; i++) {
      TempAirExt[i] = [ reading_time[i], TempAirExt[i] ]
    }
    for(var i=0, l=bootCount.length; i<l; i++) {
      bootCount[i] = [ reading_time[i], bootCount[i] ]
    }
    for(var i=0, l=PontDiv.length; i<l; i++) {
      PontDiv[i] = [ reading_time[i], PontDiv[i] ]
    }
    for(var i=0, l=HumidAirInt.length; i<l; i++) {
      HumidAirInt[i] = [ reading_time[i], HumidAirInt[i] ]
    }
    
    //reading_time = reading_time.map(function(d) { return new Date(d) } );
    
    /*document.write('    reading_time:  ');
    document.write(reading_time);*/
    Highcharts.chart('chart-lights', {
        chart: {
            zoomType: 'xy'
        },
        title: {
            text: 'Les niveaux de luminosité',
            align: 'left'
        },
        subtitle: {
            text: 'msp1',
            align: 'left'
        },
        xAxis: [{
            type : 'datetime',
            crosshair: true
        }],
        yAxis: [ { // Primary yAxis
            gridLineWidth: 0,
            title: {
                text: 'Luminosité',
                                     
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
            name: 'Luminosité moyenne',
            type: 'spline',
            yAxis: 0,
            data: LuminositeMoy,
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
            data: LuminositeA,
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
            data: LuminositeB,
            zIndex:5,
            color:'#008E72',
            tooltip: {
                valueSuffix: ' UA'
            }
        },{
    name: 'Capteur 3',
            type: 'spline',
            yAxis: 0,
            data: LuminositeC,
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
            data: LuminositeD,
            zIndex:5,
            color:'#008E72',
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
                               
    Highcharts.chart('chart-niveauxeaux', {
        chart: {
            zoomType: 'xy'
        },
        title: {
            text: 'Température et humidité du sol',
            align: 'left'
        },
        subtitle: {
            text: 'msp1',
            align: 'left'
        },
        xAxis: [{
            type : 'datetime',
            crosshair: true
        }],
        yAxis: [ { // Primary yAxis
            gridLineWidth: 0,
            title: {
                text: 'Température du sol',
                                     
                style: {
                    color:['#FF6300']
                }
            },
            labels: {
                format: '{value} °C',
                style: {
                    color:['#FF6300']
                }
            }
    
        },{ // Primary yAxis
            gridLineWidth: 0,
            title: {
                text: 'Luminosité et capteurs ',
                                     
                style: {
                    color:['#008E72']
                }
            },
            labels: {
                format: '{value} UA',
                style: {
                    color:['#008E72']
                }
            }
    
        }, { // Secondary yAxis
            gridLineWidth: 0,
            title: {
                text: '',
                style: {
                    color: ['#27BDA0']
                }
            },
            labels: {
                format: '{value} on-off',
                style: {
                    color: ['#27BDA0']
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
            y: 20,
            floating: true,
            backgroundColor:
                Highcharts.defaultOptions.legend.backgroundColor || // theme
                'rgba(255,255,255,0.25)'
        },
        series: [{
    name: 'Humidité du sol',
            type: 'spline',
            lineWidth:1,
            yAxis: 1,
            data: HumidSol,
            zIndex:5,
            color:'#008E72',
            tooltip: {
                valueSuffix: ' UA'
            }
        },/*{
    name: 'Capteur de pluie',
            type: 'spline',
            lineWidth:1,
            yAxis: 1,
            data: Pluie,
            zIndex:4,
            color: '#00B794',
            tooltip: {
                valueSuffix: ' UA'
            }
        },*/{
            name: 'Température du sol',
            type: 'spline',
            lineWidth:1,
            yAxis: 0,
            data: TempEau,
            zIndex:9,
            color:'#FF6300',
            marker: {
                enabled: false
            },
            dashStyle: 'shortdot',
            tooltip: {
                valueSuffix: ' °C'
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
            text: 'Les capteurs humidité/température',
            align: 'left'
        },
        subtitle: {
            text: 'msp1',
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
                text: 'Températures',
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
                text: 'Humidités',
                style: {
            	color:['#007E61'],
                }
            },
            labels: {
                format: '{value} %',
                style: {
            	color:['#007E61'],
                }
            }
    
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
            name: 'Température air intérieure',
            type: 'spline',
            lineWidth:1,
            yAxis: 0,
            data: TempAirInt,
            zIndex: 9,
            color: '#00B794',
            tooltip: {
                valueSuffix: ' °C'
            }
        }, {
            name: 'Température air extérieure',
            type: 'spline',
            lineWidth:1,
            yAxis: 0,
            data: TempAirExt,
            zIndex: 9,
            color: '#007E61',
            tooltip: {
                valueSuffix: ' °C'
            }
        }, {
            name: 'Humidité air intérieure',
            type: 'spline',
            lineWidth:1,
            yAxis: 1,
            data: HumidAirInt,
            zIndex: 8,
            color: '#007E61',
            marker: {
                enabled: false
            },
            dashStyle: 'shortdot',
            tooltip: {
                valueSuffix: ' %'
            }
        }, {
            name: 'Humidité air extérieure',
            type: 'spline',
            lineWidth:1,
            yAxis: 1,
            data: HumidAirExt,
            zIndex:7,
            color: '#FF6300',
            marker: {
                enabled: false
            },
            dashStyle: 'shortdot',
            tooltip: {
                valueSuffix: ' %'
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
            text: 'msp1',
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
        }, { // Tertiary yAxis
            gridLineWidth: 0,
            title: {
                text: 'Angles moteurs',
                style: {
            	color:['#00B794'],
                }
            },
            labels: {
                format: '{value} °',
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
        }, {
            name: 'Moteur haut bas',
            type: 'spline',
            lineWidth:1,
            yAxis: 2,
            data: ServoHB,
            zIndex:7,
            color: '#007E61',
            dashStyle: 'shortdot',
            tooltip: {
                valueSuffix: ' °'
            }
        }, {
            name: 'Moteur gauche droite',
            type: 'spline',
            lineWidth:1,
            yAxis: 2,
            data: ServoGD,
            zIndex:7,
            color: '#00B794',
            dashStyle: 'shortdot',
            tooltip: {
                valueSuffix: ' °'
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
        var LuminositeMoy = <?php echo $last_reading_LuminositeMoy; ?>;        
        var LuminositeA = <?php echo $last_reading_LuminositeA; ?>;
        var LuminositeB = <?php echo $last_reading_LuminositeB; ?>;
        var LuminositeC = <?php echo $last_reading_LuminositeC; ?>;
        var LuminositeD = <?php echo $last_reading_LuminositeD; ?>;
        var TempEau = <?php echo $last_reading_TempEau; ?>;        
        var HumidSol = <?php echo $last_reading_HumidSol; ?>;
        var Pluie = <?php echo $last_reading_Pluie; ?>;
        var TempAirInt = <?php echo $last_reading_TempAirInt; ?>;        
        var TempAirExt = <?php echo $last_reading_TempAirExt; ?>;
        var HumidAirInt = <?php echo $last_reading_HumidAirInt; ?>;
        var HumidAirExt = <?php echo $last_reading_HumidAirExt; ?>;

        setLuminositeMoy(LuminositeMoy);
        setLuminositeA(LuminositeA);
        setLuminositeB(LuminositeB);
        setLuminositeC(LuminositeC);
        setLuminositeD(LuminositeD);             
        setTempEau(TempEau);
        setHumidSol(HumidSol);
        setPluie(Pluie);        
        setTempAirInt(TempAirInt);
        setTempAirExt(TempAirExt);
        setHumidAirInt(HumidAirInt);
        setHumidAirExt(HumidAirExt);


        function setTempAirInt(curVal){
        	//set range for Temperature in Celsius -5 Celsius to 38 Celsius
        	var minTempAirInt = 0.0;
        	var maxTempAirInt = 50.0;
            //set range for Temperature in Fahrenheit 23 Fahrenheit to 100 Fahrenheit
        	//var minTemp = 23;
        	//var maxTemp = 100;
         
        	var newVal = scaleValue(curVal, [minTempAirInt, maxTempAirInt], [0, 180]);
        	$('.gauge--1 .semi-circle--mask').attr({
        		style: '-webkit-transform: rotate(' + newVal + 'deg);' +
        		'-moz-transform: rotate(' + newVal + 'deg);' +
        		'transform: rotate(' + newVal + 'deg);'
        	});
        	$("#TempAirInt").text(curVal + ' ºC');
        }
        
        function setTempAirExt(curVal){
        	//set range for Humidity percentage 0 % to 100 %
        	var minTempAirExt = 0.0;
        	var maxTempAirExt = 50.0;
    
        	var newVal = scaleValue(curVal, [minTempAirExt, maxTempAirExt], [0, 180]);
        	$('.gauge--2 .semi-circle--mask').attr({
        		style: '-webkit-transform: rotate(' + newVal + 'deg);' +
        		'-moz-transform: rotate(' + newVal + 'deg);' +
        		'transform: rotate(' + newVal + 'deg);'
        	});
        	$("#TempAirExt").text(curVal + ' ºC');
        }
        
        function setHumidAirInt(curVal){
        	//set range for Humidity percentage 0 % to 100 %
        	var minHumidAirInt = 0;
        	var maxHumidAirInt = 100;
    
        	var newVal = scaleValue(curVal, [minHumidAirInt, maxHumidAirInt], [0, 180]);
        	$('.gauge--3 .semi-circle--mask').attr({
        		style: '-webkit-transform: rotate(' + newVal + 'deg);' +
        		'-moz-transform: rotate(' + newVal + 'deg);' +
        		'transform: rotate(' + newVal + 'deg);'
        	});
        	$("#HumidAirInt").text(curVal + ' %');
        }

        function setHumidAirExt(curVal){
        	//set range for Temperature in Celsius -5 Celsius to 38 Celsius
        	var minHumidAirExt = 0;
        	var maxHumidAirExt = 100;
            //set range for Temperature in Fahrenheit 23 Fahrenheit to 100 Fahrenheit
        	//var minTemp = 23;
        	//var maxTemp = 100;
    
        	var newVal = scaleValue(curVal, [minHumidAirExt, maxHumidAirExt], [0, 180]);
        	$('.gauge--4 .semi-circle--mask').attr({
        		style: '-webkit-transform: rotate(' + newVal + 'deg);' +
        		'-moz-transform: rotate(' + newVal + 'deg);' +
        		'transform: rotate(' + newVal + 'deg);'
        	});
        	$("#HumidAirExt").text(curVal + ' %');
        }

        function setTempEau(curVal){
        	//set range for Temperature in Celsius -5 Celsius to 38 Celsius
        	var minTempEau = 0;
        	var maxTempEau = 40;
            //set range for Temperature in Fahrenheit 23 Fahrenheit to 100 Fahrenheit
        	//var minTemp = 23;
        	//var maxTemp = 100;
    
        	var newVal = scaleValue(curVal, [minTempEau, maxTempEau], [0, 180]);
        	$('.gauge--5 .semi-circle--mask').attr({
        		style: '-webkit-transform: rotate(' + newVal + 'deg);' +
        		'-moz-transform: rotate(' + newVal + 'deg);' +
        		'transform: rotate(' + newVal + 'deg);'
        	});
        	$("#TempEau").text(curVal + ' ºC');
        }
        
        function setPluie(curVal){
        	//set range for Humidity percentage 0 % to 100 %
        	var minPluie = 0;
        	var maxPluie = 4095;
    
        	var newVal = scaleValue(curVal, [minPluie, maxPluie], [0, 180]);
        	$('.gauge--6.semi-circle--mask').attr({
        		style: '-webkit-transform: rotate(' + newVal + 'deg);' +
        		'-moz-transform: rotate(' + newVal + 'deg);' +
        		'transform: rotate(' + newVal + 'deg);'
        	});
        	$("#Pluie").text(curVal + ' UA');
        }
        
        function setHumidSol(curVal){
        	//set range for Humidity percentage 0 % to 100 %
        	var minHumidSol = 0;
        	var maxHumidSol = 4095;
    
        	var newVal = scaleValue(curVal, [minHumidSol, maxHumidSol], [0, 180]);
        	$('.gauge--7 .semi-circle--mask').attr({
        		style: '-webkit-transform: rotate(' + newVal + 'deg);' +
        		'-moz-transform: rotate(' + newVal + 'deg);' +
        		'transform: rotate(' + newVal + 'deg);'
        	});
        	$("#HumidSol").text(curVal + ' UA');
        }
        

        function setLuminositeMoy(curVal){
        	//set range for Temperature in Celsius -5 Celsius to 38 Celsius
        	var minLuminositeMoy = 0;
        	var maxLuminositeMoy = 4095;
            //set range for Temperature in Fahrenheit 23 Fahrenheit to 100 Fahrenheit
        	//var minTemp = 23;
        	//var maxTemp = 100;
    
        	var newVal = scaleValue(curVal, [minLuminositeMoy, maxLuminositeMoy], [0, 180]);
        	$('.gauge--8 .semi-circle--mask').attr({
        		style: '-webkit-transform: rotate(' + newVal + 'deg);' +
        		'-moz-transform: rotate(' + newVal + 'deg);' +
        		'transform: rotate(' + newVal + 'deg);'
        	});
        	$("#LuminositeMoy").text(curVal + ' UA');
        }
        
        function setLuminositeA(curVal){
        	//set range for Temperature in Celsius -5 Celsius to 38 Celsius
        	var minLuminositeA = 0;
        	var maxLuminositeA = 4095;
            //set range for Temperature in Fahrenheit 23 Fahrenheit to 100 Fahrenheit
        	//var minTemp = 23;
        	//var maxTemp = 100;
    
        	var newVal = scaleValue(curVal, [minLuminositeA, maxLuminositeA], [0, 180]);
        	$('.gauge--9 .semi-circle--mask').attr({
        		style: '-webkit-transform: rotate(' + newVal + 'deg);' +
        		'-moz-transform: rotate(' + newVal + 'deg);' +
        		'transform: rotate(' + newVal + 'deg);'
        	});
        	$("#LuminositeA").text(curVal + ' UA');
        }

        function setLuminositeB(curVal){
        	//set range for Humidity percentage 0 % to 100 %
        	var minLuminositeB = 0;
        	var maxLuminositeB = 4095;
    
        	var newVal = scaleValue(curVal, [minLuminositeB, maxLuminositeB], [0, 180]);
        	$('.gauge--10 .semi-circle--mask').attr({
        		style: '-webkit-transform: rotate(' + newVal + 'deg);' +
        		'-moz-transform: rotate(' + newVal + 'deg);' +
        		'transform: rotate(' + newVal + 'deg);'
        	});
        	$("#LuminositeB").text(curVal + ' UA');
        }
        
        function setLuminositeC(curVal){
        	//set range for Humidity percentage 0 % to 100 %
        	var minLuminositeC = 0;
        	var maxLuminositeC = 4095;
    
        	var newVal = scaleValue(curVal, [minLuminositeC, maxLuminositeC], [0, 180]);
        	$('.gauge--11 .semi-circle--mask').attr({
        		style: '-webkit-transform: rotate(' + newVal + 'deg);' +
        		'-moz-transform: rotate(' + newVal + 'deg);' +
        		'transform: rotate(' + newVal + 'deg);'
        	});
        	$("#LuminositeC").text(curVal + ' UA');
        }
        
        function setLuminositeD(curVal){
        	//set range for Humidity percentage 0 % to 100 %
        	var minLuminositeD = 0;
        	var maxLuminositeD = 4095;
    
        	var newVal = scaleValue(curVal, [minLuminositeD, maxLuminositeD], [0, 180]);
        	$('.gauge--12 .semi-circle--mask').attr({
        		style: '-webkit-transform: rotate(' + newVal + 'deg);' +
        		'-moz-transform: rotate(' + newVal + 'deg);' +
        		'transform: rotate(' + newVal + 'deg);'
        	});
        	$("#LuminositeD").text(curVal + ' UA');
        }
        
        function scaleValue(value, from, to) {
            var scale = (to[1] - to[0]) / (from[1] - from[0]);
            var capped = Math.min(from[1], Math.max(from[0], value)) - from[0];
            return ~~(capped * scale + to[0]);
        }
    </script>
    <br>
</div>
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