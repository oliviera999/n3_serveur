
<!DOCTYPE HTML>
<!--
	olution iot datas by HTML5 UP
	html5up.net | @ajlkn
	Free for personal and commercial use under the CCA 3.0 license (html5up.net/license)
-->

<?Php
require "n3pp-config.php";// Database connection
//require "n3pp-database.php";
    include_once('n3pp-config.php');

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
    
    $last_reading = getLastReadings();
    $last_reading_begin = getLastReadings();
    
    $last_reading_tempair = $last_reading["TempAir"];
    $last_reading_humi = $last_reading["Humidite"];
    $last_reading_lumi = $last_reading["Luminosite"];
    $last_reading_humidMoy = $last_reading["HumidMoy"];
    $last_reading_humid1 = $last_reading["Humid1"];
    $last_reading_humid2 = $last_reading["Humid2"];
    $last_reading_humid3 = $last_reading["Humid3"];
    $last_reading_humid4 = $last_reading["Humid4"];
    
    $last_reading_time = $last_reading["reading_time"];
 
    $first_reading = getAllReadings2();
    $first_reading_begin = $first_reading ["max_amount2"]; //firstreading2
    
    $first_reading_time = getFirstReadings($readings_count);
    $first_reading_time = $first_reading_time ["min_amount2"];
    // Uncomment to set timezone to - 1 hour (you can change 1 to any number)
    
    $first_reading_time_begin = getFirstReadingsBegin();
    $first_reading_time_begin = $first_reading_time_begin ["min_amount3"];
    
    
    $last_reading_time = date("Y-m-d H:i:s", strtotime("$last_reading_time - 1 hours")); //last_reading_time
    //$last_reading_time = date("Y-m-d H:i:s", strtotime("$last_reading_time"));
    $first_reading_time2 = date("Y-m-d H:i:s", strtotime("$first_reading")); //last_reading_time4
    $first_reading_time_begin = date("Y-m-d H:i:s", strtotime("$first_reading_time_begin")); 
    
    
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

    $min_tempair = minReading($readings_count, 'TempAir');
    $max_tempair = maxReading($readings_count, 'TempAir');
    $avg_tempair = avgReading($readings_count, 'TempAir');
    $stddev_tempair = stddevReading($readings_count, 'TempAir');
    
    $min_humi = minReading($readings_count, 'Humidite');
    $max_humi = maxReading($readings_count, 'Humidite');
    $avg_humi = avgReading($readings_count, 'Humidite');
    $stddev_humi = stddevReading($readings_count, 'Humidite');
    
    $min_lumi = minReading($readings_count, 'Luminosite');
    $max_lumi = maxReading($readings_count, 'Luminosite');
    $avg_lumi = avgReading($readings_count, 'Luminosite');
    $stddev_lumi = stddevReading($readings_count, 'Luminosite');
    
    $avg_humid1 = avgReading($readings_count, 'Humid1');
    $max_humid1 = maxReading($readings_count, 'Humid1');
    $min_humid1 = minReading($readings_count, 'Humid1');
    $stddev_humid1 = stddevReading($readings_count, 'Humid1');
    
    $min_humid2 = minReading($readings_count, 'Humid2');
    $max_humid2 = maxReading($readings_count, 'Humid2');
    $avg_humid2 = avgReading($readings_count, 'Humid2');
    $stddev_humid2 = stddevReading($readings_count, 'Humid2');
    
    $min_humid3 = minReading($readings_count, 'Humid3');
    $max_humid3 = maxReading($readings_count, 'Humid3');
    $avg_humid3 = avgReading($readings_count, 'Humid3');
    $stddev_humid3 = stddevReading($readings_count, 'Humid3');
    
    $min_humid4 = minReading($readings_count, 'Humid4');
    $max_humid4 = maxReading($readings_count, 'Humid4');
    $avg_humid4 = avgReading($readings_count, 'Humid4');
    $stddev_humid4 = stddevReading($readings_count, 'Humid4');
    
    $min_humidMoy = minReading($readings_count, 'HumidMoy');
    $max_humidMoy = maxReading($readings_count, 'HumidMoy');
    $avg_humidMoy = avgReading($readings_count, 'HumidMoy');
    $stddev_humidMoy = stddevReading($readings_count, 'HumidMoy');
    
    // Transfor PHP array to JavaScript two dimensional array 
?>

<html>
	<head>
		<title>olution iot datas</title>
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
						<a href="https://iot.olution.info/index.php" class="logo">olution iot datas</a>
					</header>

				 <!-- Nav -->
					<nav id="nav">
						<ul class="links">
							<li><a href="https://iot.olution.info/index.php">olution</a></li>
							<li><a href="https://iot.olution.info/n3pp/n3ppdatas/n3pp-data.php">le prototype farmflow 3</a></li>
							<li class="active"><a href="https://iot.olution.info/n3pp/n3ppdatas/n3pp-data.php">phasmopolis</a></li>
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
									<h2>Le suivi du n3pp
									</h2>
									<p>Le système est suivi grâce à la carte de développement ESP-32 qui mesure et présente différents paramètres du système.</p>
						            <a href="https://iot.olution.info/n3pp/n3ppgallery/n3pp-gallery.php?page=1" class="button large">Photos du potager</a>
						            
                                   <hr />
   								<!--	<iframe allow="camera; microphone; fullscreen; display-capture; autoplay" src="https://meet.jit.si/n3pplive" style="height: 600; width: 100%; border: 0px;"></iframe> -->

                                    <h3 style="text-align: center"> durée d'analyse des données : <?php echo $timepast; ?></h3>
                                     </h4>
                                    <h4 style="text-align: center">du <?php echo $first_reading_time; ?> au <?php echo $last_reading_time; ?> (<?php echo $readings_count; ?> enregistrements analys&eacute;s sur <?php echo $first_reading_begin; ?>) </h4>
  
                                   
                                    <h5 style="text-align: center"> durée depuis le debut du fonctionnement : <?php echo $timepastbegin; ?>j (premier enregistrement le <?php echo $first_reading_time_begin; ?>)</h5>                                  
                                    <form method="get">
                                        <input type="number" name="readingsCount" min="1" placeholder="Enregistrements à analyser (<?php echo $readings_count; ?>)">
                                        <input type="submit" value="Mettre à jour">
                                    </form>
									<p>Il est possible de zoomer sur les graphs.</p>
								</header>
								
<?php


$servername = "localhost";

// REPLACE with your Database name
$dbname = "oliviera_iot";
// REPLACE with Database user
$username = "oliviera_iot";
// REPLACE with Database user password
$password = "Iot#Olution1";

// Create connection
$conn = new mysqli($servername, $username, $password, $dbname);
// Check connection
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
} 

$sql = "SELECT id,TempAir,Humidite,Luminosite,Humid1,Humid4,Humid2,Humid3,HumidMoy,ArrosageManu,SeuilSec,mail,mailNotif,HeureArrosage,resetMode,etatPompe,tempsArrosage,reading_time FROM n3ppData order by reading_time desc limit " . $readings_count . " ";

$result = $conn->query($sql);

while ($data = $result->fetch_assoc()){
    $sensor_data[] = $data;
}

$reading_time = array_column($sensor_data, 'reading_time');

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

$Humid1 = json_encode(array_reverse(array_column($sensor_data, 'Humid1')), JSON_NUMERIC_CHECK);
$Humid2 = json_encode(array_reverse(array_column($sensor_data, 'Humid2')), JSON_NUMERIC_CHECK);
$Humid3= json_encode(array_reverse(array_column($sensor_data, 'Humid3')), JSON_NUMERIC_CHECK);
$Humid4= json_encode(array_reverse(array_column($sensor_data, 'Humid4')), JSON_NUMERIC_CHECK);
$HumidMoy= json_encode(array_reverse(array_column($sensor_data, 'HumidMoy')), JSON_NUMERIC_CHECK);


$TempAir = json_encode(array_reverse(array_column($sensor_data, 'TempAir')), JSON_NUMERIC_CHECK);
$Humidite = json_encode(array_reverse(array_column($sensor_data, 'Humidite')), JSON_NUMERIC_CHECK);
$Luminosite = json_encode(array_reverse(array_column($sensor_data, 'Luminosite')), JSON_NUMERIC_CHECK);

$etatPompe = json_encode(array_reverse(array_column($sensor_data, 'etatPompe')), JSON_NUMERIC_CHECK);
$ArrosageManu = json_encode(array_reverse(array_column($sensor_data, 'ArrosageManu')), JSON_NUMERIC_CHECK);
$resetMode = json_encode(array_reverse(array_column($sensor_data, 'resetMode')), JSON_NUMERIC_CHECK);

$reading_time = json_encode(array_reverse($reading_time), JSON_NUMERIC_CHECK);

                                      
                                    
/*
echo $etatPompe;
echo $resetMode;
echo $Humid1;
echo $reading_time;
*/
$result->free();
$conn->close();
?>

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
                            <h5>Capteur pluie</h5>
                            <div class="mask">
                                <div class="semi-circle"></div>
                                <div class="semi-circle--mask"></div>
                            </div>
                            <p style="text-align: center" id="humid1">--</p>
	                    </div>
	                 </td>
	                 <td>
                        <div class="box gauge--6">
                            <h5>Humidité du sol</h5>
                            <div class="mask">
                                <div class="semi-circle"></div>
                                <div class="semi-circle--mask"></div>
                            </div>
                            <p style="text-align: center" id="humid2">--</p> 
	                    </div>
                    </td>
	                 <td>
                        <div class="box gauge--7">
                            <h5>Niveau eau 1</h5>
                            <div class="mask">
                                <div class="semi-circle"></div>
                                <div class="semi-circle--mask"></div>
                            </div>
                            <p style="text-align: center" id="humid3">--</p> 
	                    </div>
                    </td>
                     <td>
                        <div class="box gauge--8">
                            <h5>Niveau eau 2</h5>
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
                    <td><?php echo round($avg_humidMoy['avg_amount'], 0); ?> UA</td>
                    <td><?php echo round($avg_humid1['avg_amount'], 0); ?> UA</td>                                        
                    <td><?php echo round($avg_humid2['avg_amount'], 0); ?> UA</td>
                    <td><?php echo round($avg_humid3['avg_amount'], 0); ?> UA</td>
                    <td><?php echo round($avg_humid4['avg_amount'], 0); ?> UA</td>

                </tr>
		        <tr>
		            <td>Min</td>
                    <td><?php echo $min_humidMoy['min_amount']; ?> UA</td>
                    <td><?php echo $min_humid1['min_amount']; ?> UA</td>
                    <td><?php echo $min_humid2['min_amount']; ?> UA</td>
                    <td><?php echo $min_humid3['min_amount']; ?> UA</td>
                    <td><?php echo $min_humid4['min_amount']; ?> UA</td>

                </tr>
		        <tr>
                    <td>Max</td>
                    <td><?php echo $max_humidMoy['max_amount']; ?> UA</td>
                    <td><?php echo $max_humid1['max_amount']; ?> UA</td>
                    <td><?php echo $max_humid2['max_amount']; ?> UA</td>
                    <td><?php echo $max_humid3['max_amount']; ?> UA</td>
                    <td><?php echo $max_humid4['max_amount']; ?> UA</td>

                </tr>
		        <tr>
                    <td>ET</td>
                    <td><?php echo round($stddev_humidMoy['stddev_amount'], 2); ?> UA</td>
                    <td><?php echo round($stddev_humid1['stddev_amount'], 2); ?> UA</td>
                    <td><?php echo round($stddev_humid2['stddev_amount'], 2); ?> UA</td>
                    <td><?php echo round($stddev_humid3['stddev_amount'], 2); ?> UA</td>
                    <td><?php echo round($stddev_humid4['stddev_amount'], 2); ?> UA</td>
                </tr>
            </table>
        </div>
    </section>
</div>

<div id="chart-niveauxeaux" class="container"></div>

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
                    <td><?php echo round($avg_tempair['avg_amount'],1); ?>&deg;C</td>
                    <td><?php echo round($avg_humi['avg_amount'], 0); ?> %</td>
                    <td><?php echo round($avg_lumi['avg_amount'], 0); ?> UA</td>
                </tr>
		        <tr>
		            <td>Min</td>
                    <td><?php echo round($min_tempair['min_amount'], 1); ?>&deg;C</td>
                    <td><?php echo round($min_humi['min_amount'], 0); ?> %</td>
                    <td><?php echo $min_lumi['min_amount']; ?> UA</td>
                </tr>
		        <tr>
                    <td>Max</td>
                    <td><?php echo round ($max_tempair['max_amount'], 1); ?>&deg;C</td>
                    <td><?php echo round ($max_humi['max_amount'], 0); ?> %</td>
                    <td><?php echo $max_lumi['max_amount']; ?> UA</td>
                </tr>
		        <tr>
                    <td>ET</td>
                    <td><?php echo round($stddev_tempair['stddev_amount'], 2); ?>&deg;C</td>
                    <td><?php echo round($stddev_humi['stddev_amount'], 0); ?> %</td>
                    <td><?php echo round($stddev_lumi['stddev_amount'], 0); ?> UA</td>
                </tr>
            </table>
        </div>
    </section>
</div>

<div id="chart-temperatures" class="container"></div>

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
                text: 'Humidité',
                                     
                style: {
                    color:['#FF6300']
                }
            },
            labels: {
                format: '{value} UA',
                style: {
                    color:['#FF6300']
                }
            }
    
        }, { // Secondary yAxis
            gridLineWidth: 0,
            title: {
                text: 'état de la pompe et reset mode',
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
            y: 0,
            floating: true,
            backgroundColor:
                Highcharts.defaultOptions.legend.backgroundColor || // theme
                'rgba(255,255,255,0.25)'
        },
        series: [{
            name: 'Moyenne humidité',
            type: 'spline',
            lineWidth:1,
            yAxis: 0,
            data: HumidMoy,
            zIndex:9,
            color:'#FF6300',
            tooltip: {
                valueSuffix: ' cm'
            }
        },{
    name: 'Capteur de pluie',
            type: 'spline',
            lineWidth:1,
            yAxis: 0,
            data: Humid1,
            zIndex:5,
            color:'#00B794',
            tooltip: {
                valueSuffix: ' cm'
            }
        },{
    name: 'Capteur humidité du sol',
            type: 'spline',
            lineWidth:1,
            yAxis: 0,
            data: Humid2,
            zIndex:5,
            color:'#008E72',
            tooltip: {
                valueSuffix: ' cm'
            }
        },{
    name: 'Capteur de niveau eau 1',
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
    name: 'Capteur de niveau eau 2',
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
                valueSuffix: ' cm'
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
            	color:['#000000'],
                }
            },
            labels: {
                format: '{value} %',
                style: {
            	color:['#000000'],
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
            y: 0,
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
            color: '#000000',
            marker: {
                enabled: false
            },
            dashStyle: 'shortdot',
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