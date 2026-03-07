<?php
// Inclusion de l'autoloader Composer pour charger automatiquement les classes des dépendances
require_once __DIR__ . '/vendor/autoload.php';
// Inclusion du fichier de compatibilité avec l'ancien code
require_once __DIR__ . '/legacy_bridge.php';

// Réglage du fuseau horaire sur Casablanca
date_default_timezone_set('Africa/Casablanca');

// Fonction pour ajuster une date/heure (string) de Paris à Casablanca (soustrait 1h)
function adjust_to_casablanca($datetime_str) {
    if (empty($datetime_str)) return '';
    $timestamp = strtotime($datetime_str);
    if ($timestamp === false) return $datetime_str;
    $timestamp -= 3600; // Décalage -1h
    return date('Y-m-d H:i:s', $timestamp);
}

// Fonction utilitaire pour sécuriser et récupérer une valeur du POST
function get_post($key, $default = null) {
    return isset($_POST[$key]) ? trim(htmlspecialchars($_POST[$key])) : $default;
}

// Fonction utilitaire pour sécuriser et récupérer une valeur du GET
function get_get($key, $default = null) {
    return isset($_GET[$key]) ? trim(htmlspecialchars($_GET[$key])) : $default;
}

// Récupère la date de la dernière mesure enregistrée
$last_date = getLastReadingDate();
// Définit la date de fin par défaut (maintenant ou dernière mesure)
$default_end_date = $last_date ? date('Y-m-d H:i:s', strtotime($last_date)) : date('Y-m-d H:i:s');
// Définit la date de début par défaut (24h avant la date de fin)
$default_start_date = date('Y-m-d H:i:s', strtotime($default_end_date . ' -1 day'));

// Gestion des dates de début et de fin (POST prioritaire)
$start_date = $default_start_date;
$end_date = $default_end_date;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Si formulaire soumis, on récupère les dates et heures du POST
    $start_date = get_post('start_date', date('Y-m-d')) . ' ' . (get_post('start_time', '00:00:00'));
    $end_date = get_post('end_date', date('Y-m-d')) . ' ' . (get_post('end_time', '23:59:59'));
}

// Nombre de mesures à analyser (GET prioritaire, sinon POST, sinon 60 par défaut)
$readings_count = 60;
if (get_get('readingsCount')) {
    $readings_count = (int)get_get('readingsCount');
} elseif (get_post('readingsCount')) {
    $readings_count = (int)get_post('readingsCount');
}

// Récupère les mesures de capteurs pour la période définie
$readings = getSensorData($start_date, $end_date);

// Calcule la durée totale de la période analysée
$start_timestamp = strtotime($start_date);
$end_timestamp = strtotime($end_date);
$duration_seconds = $end_timestamp - $start_timestamp;

// Conversion de la durée en jours, heures, minutes
$days = floor($duration_seconds / (60 * 60 * 24));
$hours = floor(($duration_seconds % (60 * 60 * 24)) / (60 * 60));
$minutes = floor(($duration_seconds % (60 * 60)) / 60);

// Chaîne lisible pour la durée
$duration_str = "$days jours, $hours heures, $minutes minutes";

// Nombre de mesures analysées
$measure_count = count($readings);

// Récupère la dernière mesure sur la période
$last_reading = getLastReadings($start_date, $end_date);
// Extraction des valeurs des différents capteurs pour la dernière mesure
$last_reading_tempair = $last_reading["TempAir"];
$last_reading_tempeau = $last_reading["TempEau"];
$last_reading_humi = $last_reading["Humidite"];
$last_reading_lumi = $last_reading["Luminosite"];
$last_reading_eauaqua = $last_reading["EauAquarium"];
$last_reading_eaureserve = $last_reading["EauReserve"];
$last_reading_eaupota = $last_reading["EauPotager"];

$last_reading_time = $last_reading["reading_time"];

// Récupère le nombre total d'enregistrements depuis le début
$first_reading = getAllReadings();
$first_reading_begin = $first_reading ["max_amount2"];

// Récupère la date de la première mesure sur la période
$first_reading_time = getFirstReadings($readings_count);
$first_reading_time = $first_reading_time ["min_amount2"];

// Récupère la date de la toute première mesure du système
$first_reading_time_begin = getFirstReadingsBegin();
$first_reading_time_begin = $first_reading_time_begin ["min_amount3"];

// Correction du décalage serveur sur les dates (Europe/Paris -> Africa/Casablanca)
$last_reading_time = adjust_to_casablanca($last_reading_time);
$first_reading_time = adjust_to_casablanca($first_reading_time);
$first_reading_time_begin = adjust_to_casablanca($first_reading_time_begin);

// Conversion des dates en timestamp UNIX
$last_reading_timestamp = strtotime($last_reading_time);
$first_reading_timestamp = strtotime($first_reading_time);
$first_reading_begin_timestamp = strtotime($first_reading_time_begin);

// Variables pour l'affichage des unités
$heures = "h";
$minutes = "min";
$jours = "j";

// Mise en forme des dates pour affichage
$last_reading_time = date("d/m/Y H:i:s", $last_reading_timestamp);
$first_reading_time = date("d/m/Y H:i:s", $first_reading_timestamp);
$first_reading_time_begin = date("d/m/Y H:i:s", $first_reading_begin_timestamp);

// Calcule le temps écoulé entre la première et la dernière mesure de la période
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
// Calcule le temps écoulé depuis la toute première mesure du système
$timepastbegin = round(($last_reading_timestamp - $first_reading_begin_timestamp)/(3600*24),1);

// Calculs statistiques sur les mesures (min, max, moyenne, écart-type) pour chaque paramètre
$min_tempair = minReading($start_date, $end_date, 'TempAir');
$max_tempair = maxReading($start_date, $end_date, 'TempAir');
$avg_tempair = avgReading($start_date, $end_date, 'TempAir');
$stddev_tempair = stddevReading($start_date, $end_date, 'TempAir');

$avg_tempeau = avgReading($start_date, $end_date, 'TempEau');
$max_tempeau = maxReading($start_date, $end_date, 'TempEau');
$min_tempeau = minReading($start_date, $end_date, 'TempEau');
$stddev_tempeau = stddevReading($start_date, $end_date, 'TempEau');

$min_humi = minReading($start_date, $end_date, 'Humidite');
$max_humi = maxReading($start_date, $end_date, 'Humidite');
$avg_humi = avgReading($start_date, $end_date, 'Humidite');
$stddev_humi = stddevReading($start_date, $end_date, 'Humidite');

$min_lumi = minReading($start_date, $end_date, 'Luminosite');
$max_lumi = maxReading($start_date, $end_date, 'Luminosite');
$avg_lumi = avgReading($start_date, $end_date, 'Luminosite');
$stddev_lumi = stddevReading($start_date, $end_date, 'Luminosite');

$min_eauaqua = minReading($start_date, $end_date, 'EauAquarium');
$max_eauaqua = maxReading($start_date, $end_date, 'EauAquarium');
$avg_eauaqua = avgReading($start_date, $end_date, 'EauAquarium');
$stddev_eauaqua = stddevReading($start_date, $end_date, 'EauAquarium');

$min_eaureserve = minReading($start_date, $end_date, 'EauReserve');
$max_eaureserve = maxReading($start_date, $end_date, 'EauReserve');
$avg_eaureserve = avgReading($start_date, $end_date, 'EauReserve');
$stddev_eaureserve = stddevReading($start_date, $end_date, 'EauReserve');

$min_eaupota = minReading($start_date, $end_date, 'EauPotager');
$max_eaupota = maxReading($start_date, $end_date, 'EauPotager');
$avg_eaupota = avgReading($start_date, $end_date, 'EauPotager');
$stddev_eaupota = stddevReading($start_date, $end_date, 'EauPotager');

// Si l'utilisateur clique sur le bouton d'export CSV, on exporte les données de la période sélectionnée
if (isset($_POST['export_csv'])) {
    exportSensorData($_POST['start_date'] . " 00:00:00", $_POST['end_date'] . " 23:59:59");
}
?>

<!DOCTYPE HTML>
<!--
    olution iot datas by HTML5 UP
    html5up.net | @ajlkn
    Free for personal and commercial use under the CCA 3.0 license (html5up.net/license)
    
    -----------------------------
    Partie HTML et affichage :
    -----------------------------
    - Affiche la page web principale de suivi de l'aquaponie.
    - Affiche les tableaux de synthèse des mesures (eau, température, humidité, luminosité).
    - Affiche les jauges graphiques (gauge) pour les valeurs actuelles.
    - Affiche les graphiques Highcharts pour l'évolution des mesures.
    - Affiche un formulaire pour filtrer la période d'analyse.
    - Permet de télécharger les données au format CSV.
    - Affiche des graphiques Google Sheets pour les paramètres chimiques (analyses manuelles).
    - Charge les scripts JS nécessaires à l'affichage dynamique et interactif.
    - Les valeurs PHP sont injectées dans le JS pour affichage dynamique.
    - Les commentaires dans le code JS expliquent chaque transformation et affichage.
-->

<html>
    <head>
        <title>n3 iot datas</title>
        <meta charset="utf-8" />
        <meta name="viewport" content="width=device-width, initial-scale=1, user-scalable=no" />
        <!-- <meta http-equiv="refresh" content="60";/> -->
        <link rel="stylesheet" href="https://iot.olution.info/assets/css/main.css" />
        <noscript><link rel="stylesheet" href="https://iot.olution.info/assets/css/noscript.css" /></noscript>
        <link rel="shortcut icon" type="image/png" href="https://iot.olution.info/images/favico.png"/>
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
                            <li  class="active"><a href="https://iot.olution.info/ffp3/ffp3datas/ffp3-data.php">L'aquaponie</a></li>
                            <li><a href="https://iot.olution.info/msp1/msp1datas/msp1-data.php">Le potager</a></li>
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
                                        <i class="icon solid fa-fish">
                                        </i> 									    
                                        Aquaponie
                                        <i class="icon solid fa-fish">
                                        </i> 									    
                                    </h2>
                                    <p>Le système est suivi grâce à la carte de développement ESP-32 qui mesure et présente différents paramètres du système. De nombreux capteurs permettent d'automatiser le fonctionnement du système pendant une période de plus d'un mois. Les données sont transmises sur le serveur d'olution et traitées pour être présentées. Le système est également contrôlable manuellement à distance. Ce prototype est issu du projet <a href="https://farmflow.marout.org">farmflow</a>. On vous invite à le découvrir ainsi que l'aquaponie en général.</p>
                                    
                                    <!--  <a href="https://www.youtube.com/channel/UCDaTFYdksyvBTOt0EolfOOw" class="button large">Live des poissons</a>
                                    <br>
                                    <br> 
                                    <a href="https://iot.olution.info/ffp3/ffp3gallery/ffp3-gallery.php?page=1" class="button large">Photos du potager</a>-->
                                   <hr />
                                <!--	<iframe allow="camera; microphone; fullscreen; display-capture; autoplay" src="https://meet.jit.si/ffp3live" style="height: 600; width: 100%; border: 0px;"></iframe> -->

                                </header>
                                
<?php
$reading_time = array_column($readings, 'reading_time');

// ******* Uncomment to convert readings time array to your timezone ********
// Correction : ne pas ajouter d'heure, juste convertir en timestamp ms pour Highcharts
$i = 0;
foreach ($reading_time as $reading){
    $reading_time[$i] = strtotime($reading) * 1000;
    $i += 1;
}
/*
// ******* Uncomment to convert readings time array to your timezone ********
$i = 0;
foreach ($reading_time as $reading){
    $reading_time[$i] = strtotime("$reading");
    $i += 1;
}*/

$EauAquarium = json_encode(array_reverse(array_column($readings, 'EauAquarium')), JSON_NUMERIC_CHECK);
$EauReserve= json_encode(array_reverse(array_column($readings, 'EauReserve')), JSON_NUMERIC_CHECK);
$EauPotager= json_encode(array_reverse(array_column($readings, 'EauPotager')), JSON_NUMERIC_CHECK);


$TempAir = json_encode(array_reverse(array_column($readings, 'TempAir')), JSON_NUMERIC_CHECK);
$TempEau = json_encode(array_reverse(array_column($readings, 'TempEau')), JSON_NUMERIC_CHECK);
$Humidite = json_encode(array_reverse(array_column($readings, 'Humidite')), JSON_NUMERIC_CHECK);
$Luminosite = json_encode(array_reverse(array_column($readings, 'Luminosite')), JSON_NUMERIC_CHECK);

$etatPompeAqua = json_encode(array_reverse(array_column($readings, 'etatPompeAqua')), JSON_NUMERIC_CHECK);
$etatPompeTank = json_encode(array_reverse(array_column($readings, 'etatPompeTank')), JSON_NUMERIC_CHECK);
$etatHeat = json_encode(array_reverse(array_column($readings, 'etatHeat')), JSON_NUMERIC_CHECK);
$etatUV = json_encode(array_reverse(array_column($readings, 'etatUV')), JSON_NUMERIC_CHECK);

$bouffePetits = json_encode(array_reverse(array_column($readings, 'bouffePetits')), JSON_NUMERIC_CHECK);
$bouffeGros = json_encode(array_reverse(array_column($readings, 'bouffeGros')), JSON_NUMERIC_CHECK);

$reading_time = json_encode(array_reverse($reading_time), JSON_NUMERIC_CHECK);

?>

    <!-- Titre de synthèse de la période analysée -->
    <h2>Synthèse des mesures du <?= htmlspecialchars(date("d/m/Y", strtotime($start_date))) ?> au <?= htmlspecialchars(date("d/m/Y", strtotime($end_date))) ?></h2>


<!-- Tableau de synthèse des niveaux d'eau (Aquarium, Réserve, Potager) -->
<div id="table-eaux" class="container">
    <section class="content">
        <div class="table-wrapper">
            <table>
                <tr>
                    <h3>Statistiques des niveaux d'eau</h3> <!-- Titre du tableau -->
                </tr>
                <tr>
                    <td>Mesures actuelles</td>
                    <td>    
                        <!-- Jauge graphique pour l'aquarium -->
                        <div class="box gauge--5">
                            <h5>EAU AQUARIUM</h5>
                            <div class="mask">
                                <div class="semi-circle"></div>
                                <div class="semi-circle--mask"></div>
                            </div>
                            <p style="text-align: center" id="eauaqua">--</p>
                        </div>
                    </td>
                    <td>
                        <!-- Jauge graphique pour la réserve -->
                        <div class="box gauge--6">
                            <h5>EAU RESERVE</h5>
                            <div class="mask">
                                <div class="semi-circle"></div>
                                <div class="semi-circle--mask"></div>
                            </div>
                            <p style="text-align: center" id="eaureserve">--</p>
                        </div>
                     </td>
                     <td>
                        <!-- Jauge graphique pour le potager -->
                        <div class="box gauge--7">
                            <h5>EAU POTAGER</h5>
                            <div class="mask">
                                <div class="semi-circle"></div>
                                <div class="semi-circle--mask"></div>
                            </div>
                            <p style="text-align: center" id="eaupota">--</p> 
                        </div>
                    </td>
                </tr>
                <tr>
                    <td>Moy</td>
                    <td><?php echo round($avg_eauaqua, 0); ?> cm</td> <!-- Moyenne -->
                    <td><?php echo round($avg_eaureserve, 0); ?> cm</td>
                    <td><?php echo round($avg_eaupota, 0); ?> cm</td>
                </tr>
                <tr>
                    <td>Min</td>
                    <td><?php echo $min_eauaqua; ?> cm</td> <!-- Minimum -->
                    <td><?php echo $min_eaureserve; ?> cm</td>
                    <td><?php echo $min_eaupota; ?> cm</td>
                </tr>
                <tr>
                    <td>Max</td>
                    <td><?php echo $max_eauaqua; ?> cm</td> <!-- Maximum -->
                    <td><?php echo $max_eaureserve; ?> cm</td>
                    <td><?php echo $max_eaupota; ?> cm</td>
                </tr>
                <tr>
                    <td>ET</td>
                    <td><?php echo round($stddev_eauaqua, 2); ?> cm</td> <!-- Ecart-type -->
                    <td><?php echo round($stddev_eaureserve, 2); ?> cm</td>
                    <td><?php echo round($stddev_eaupota, 2); ?> cm</td>
                </tr>
            </table>
        </div>
    </section>
</div>

<div id="chart-niveauxeaux" class="container"></div>
<hr />

<!-- Tableau de synthèse des paramètres physiques (température, humidité, luminosité) -->
<div id="table-parametresphys" class="container">
    <section class="content">
        <div class="table-wrapper">
            <table>
                <tr>
                    <h3>Statistiques des paramètres physiques</h3> <!-- Titre du tableau -->
                </tr>
                <tr>
                    <td>Mesures actuelles</td>
                    <td>    
                        <!-- Jauge graphique pour la température de l'eau -->
                        <div class="box gauge--2">
                            <h5>TEMPERATURE EAU</h5>
                            <div class="mask">
                                <div class="semi-circle"></div>
                                <div class="semi-circle--mask"></div>
                            </div>
                            <p style="text-align: center" id="tempeau">--</p>
                        </div>
                    </td>
                    <td>
                        <!-- Jauge graphique pour la température de l'air -->
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
                        <!-- Jauge graphique pour l'humidité -->
                        <div class="box gauge--3">
                            <h5>HUMIDITE</h5>
                            <div class="mask">
                                <div class="semi-circle"></div>
                                <div class="semi-circle--mask"></div>
                            </div>
                            <p style=" text-align: center" id="humi">--</p>
                        </div>
                    </td>
                    <td>
                        <!-- Jauge graphique pour la luminosité -->
                        <div class="box gauge--4">
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
                    <td><?php echo round($avg_tempeau, 1); ?>&deg;C</td> <!-- Moyenne -->
                    <td><?php echo round($avg_tempair,1); ?>&deg;C</td>
                    <td><?php echo round($avg_humi, 0); ?> %</td>
                    <td><?php echo round($avg_lumi, 0); ?> UA</td>
                </tr>
                <tr>
                    <td>Min</td>
                    <td><?php echo round($min_tempeau, 1); ?>&deg;C</td> <!-- Minimum -->
                    <td><?php echo round($min_tempair, 1); ?>&deg;C</td>
                    <td><?php echo round($min_humi, 0); ?> %</td>
                    <td><?php echo $min_lumi; ?> UA</td>
                </tr>
                <tr>
                    <td>Max</td>
                    <td><?php echo round($max_tempeau, 1); ?>&deg;C</td> <!-- Maximum -->
                    <td><?php echo round ($max_tempair, 1); ?>&deg;C</td>
                    <td><?php echo round ($max_humi, 0); ?> %</td>
                    <td><?php echo $max_lumi; ?> UA</td>
                </tr>
                <tr>
                    <td>ET</td>
                    <td><?php echo round($stddev_tempeau, 2); ?>&deg;C</td> <!-- Ecart-type -->
                    <td><?php echo round($stddev_tempair, 2); ?>&deg;C</td>
                    <td><?php echo round($stddev_humi, 0); ?> %</td>
                    <td><?php echo round($stddev_lumi, 0); ?> UA</td>
                </tr>
            </table>
        </div>
    </section>
</div>

<div id="chart-temperatures" class="container"></div>
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

<!-- Formulaire de filtrage de la période d'analyse -->
<center>
    <form method="POST" action="">
        <div class="form-container">
            <div>
                <label for="start_date">Date de début :</label>
                <!-- Champ de sélection de la date de début -->
                <input type="date" id="start_date" name="start_date" value="<?= htmlspecialchars(date("Y-m-d", strtotime(adjust_to_casablanca($start_date)))) ?>" required>
                <label for="start_time">Heure de début :</label>
                <!-- Champ de sélection de l'heure de début -->
                <input type="time" id="start_time" name="start_time" value="<?= htmlspecialchars(date("H:i", strtotime(adjust_to_casablanca($start_date)))) ?>" required>
            </div>

            <div>
                <label for="end_date">Date de fin :</label>
                <!-- Champ de sélection de la date de fin -->
                <input type="date" id="end_date" name="end_date" value="<?= htmlspecialchars(date("Y-m-d", strtotime(adjust_to_casablanca($end_date)))) ?>" required>
                <label for="end_time">Heure de fin :</label>
                <!-- Champ de sélection de l'heure de fin -->
                <input type="time" id="end_time" name="end_time" value="<?= htmlspecialchars(date("H:i", strtotime(adjust_to_casablanca($end_date)))) ?>" required>
            </div>

            <div>
                <!-- Bouton pour afficher les mesures -->
                <button type="submit">Afficher les mesures</button>
            </div>
        </div>
    </form>
</center>
    
    <h6 style="text-align: center">du <?php echo $start_date; ?> au <?php echo $end_date; ?> (<?php echo $measure_count; ?> enregistrements analys&eacute;s sur <?php echo $first_reading_begin; ?>) </h4>
<h6 style="text-align: center"> durée depuis le debut du fonctionnement : <?php echo $timepastbegin; ?>j (premier enregistrement le <?php echo $first_reading_time_begin; ?>)</h6>    

<!-- Formulaire pour télécharger les données au format CSV -->
<form method="POST">
    <input type="hidden" name="start_date" value="<?= htmlspecialchars($start_date) ?>">
    <input type="hidden" name="end_date" value="<?= htmlspecialchars($end_date) ?>">
    <button type="submit" name="export_csv">Télécharger les données (CSV)</button>
</form>
    <hr>
    <br>

    <!-- Section affichage des paramètres chimiques de l'eau (analyses manuelles) -->
    <h2>Suivi des paramètres chimiques de l'eau</h2>
    <div>Ces analyses sont réalisées manuellement par les élèves grâce à un kit dédié à l'aquariophilie.</div>
    <!-- Graphiques Google Sheets intégrés pour le suivi manuel -->
    <iframe width="600" height="371" seamless frameborder="0" scrolling="no" src="https://docs.google.com/spreadsheets/d/e/2PACX-1vTrF6mLbo5-qLN-uhd7J2LeeV7LvE9LEr9nkyXgh7KUUh7lL-Houdx3XpIQ_-3vJk50e7dTuUAvQ-dR/pubchart?oid=209032696&amp;format=interactive"></iframe>
    <iframe width="600" height="371" seamless frameborder="0" scrolling="no" src="https://docs.google.com/spreadsheets/d/e/2PACX-1vTrF6mLbo5-qLN-uhd7J2LeeV7LvE9LEr9nkyXgh7KUUh7lL-Houdx3XpIQ_-3vJk50e7dTuUAvQ-dR/pubchart?oid=699943208&amp;format=interactive"></iframe>
    <iframe width="600" height="371" seamless frameborder="0" scrolling="no" src="https://docs.google.com/spreadsheets/d/e/2PACX-1vTrF6mLbo5-qLN-uhd7J2LeeV7LvE9LEr9nkyXgh7KUUh7lL-Houdx3XpIQ_-3vJk50e7dTuUAvQ-dR/pubchart?oid=1338338691&amp;format=interactive"></iframe>
</div>
<script>
               
    
    // Récupération des données PHP (tableaux) injectées dans le JS pour affichage graphique
    var EauAquarium = <?php echo $EauAquarium; ?>; // Niveaux d'eau de l'aquarium
    var EauReserve = <?php echo $EauReserve; ?>;   // Niveaux d'eau de la réserve
    var EauPotager = <?php echo $EauPotager; ?>;   // Niveaux d'eau du potager
    
    var TempAir = <?php echo $TempAir; ?>;         // Température de l'air
    var TempEau = <?php echo $TempEau; ?>;         // Température de l'eau
    var Humidite = <?php echo $Humidite; ?>;       // Humidité
    var Luminosite = <?php echo $Luminosite; ?>;   // Luminosité
    
    var etatPompeAqua = <?php echo $etatPompeAqua; ?>; // Etat pompe aquarium (on/off)
    var etatPompeTank = <?php echo $etatPompeTank; ?>; // Etat pompe réserve (on/off)
    var etatHeat = <?php echo $etatHeat; ?>;           // Etat chauffage (on/off)
    var etatUV = <?php echo $etatUV; ?>;               // Etat LEDs UV (on/off)
    
    var bouffePetits = <?php echo $bouffePetits; ?>;   // Nourriture petits poissons (on/off)
    var bouffeGros = <?php echo $bouffeGros; ?>;       // Nourriture gros poissons (on/off)
    
    var reading_time = <?php echo $reading_time; ?>;   // Horodatage des mesures (ms)
    
    // On associe chaque valeur à son horodatage pour Highcharts (tableaux [timestamp, valeur])
    for(var i=0, l=EauAquarium.length; i<l; i++) {
      EauAquarium[i] = [ reading_time[i], EauAquarium[i] ]; // [date, valeur]
    }
    for(var i=0, l=EauReserve.length; i<l; i++) {
      EauReserve[i] = [ reading_time[i], EauReserve[i] ];
    }
    for(var i=0, l=EauPotager.length; i<l; i++) {
      EauPotager[i] = [ reading_time[i], EauPotager[i] ];
    }
    for(var i=0, l=etatPompeAqua.length; i<l; i++) {
      etatPompeAqua[i] = [ reading_time[i], etatPompeAqua[i] ];
    }
    for(var i=0, l=etatPompeTank.length; i<l; i++) {
      etatPompeTank[i] = [ reading_time[i], etatPompeTank[i] ];
    }
    for(var i=0, l=etatHeat.length; i<l; i++) {
      etatHeat[i] = [ reading_time[i], etatHeat[i] ];
    }
    
    for(var i=0, l=TempEau.length; i<l; i++) {
      TempEau[i] = [ reading_time[i], TempEau[i] ];
    }
    for(var i=0, l=TempAir.length; i<l; i++) {
      TempAir[i] = [ reading_time[i], TempAir[i] ];
    }
    for(var i=0, l=Humidite.length; i<l; i++) {
      Humidite[i] = [ reading_time[i], Humidite[i] ];
    }
    for(var i=0, l=Luminosite.length; i<l; i++) {
      Luminosite[i] = [ reading_time[i], Luminosite[i] ];
    }
    for(var i=0, l=etatUV.length; i<l; i++) {
      etatUV[i] = [ reading_time[i], etatUV[i] ];
    }
    for(var i=0, l=bouffePetits.length; i<l; i++) {
      bouffePetits[i] = [ reading_time[i], bouffePetits[i] ];
    }
    for(var i=0, l=bouffeGros.length; i<l; i++) {
      bouffeGros[i] = [ reading_time[i], bouffeGros[i] ];
    }
    
    // (optionnel) Pour debug : afficher les timestamps dans la console
    //console.log('reading_time:', reading_time);
                               
    Highcharts.chart('chart-niveauxeaux', {
        chart: {
            zoomType: 'xy'
        },
        title: {
            text: 'Les niveaux en eau',
            align: 'left'
        },
        subtitle: {
            text: 'ffp3',
            align: 'left'
        },
        xAxis: [{
            type : 'datetime',
            crosshair: true
        }],
        yAxis: [ { // Secondary yAxis
            gridLineWidth: 0,
            tickInterval: 1,
            title: {
                text: 'Distance eau aquarium et potager',
                tickInterval: 1,
                style: {
                    color:['#FF6300']
                }
            },
            labels: {
                format: '{value} cm',
                style: {
                    color:['#FF6300']
                }
            }
    
        },{ // Primary yAxis
            max: 80, 
            min: 0, 
            tickInterval: 5,
            opposite: true,
            labels: {
                format: '{value} cm',
                style: {
                    color:['#007E61']
                }
            },
            title: {
                text: 'Distance eau réserve',
                style: {
                    color:['#007E61']
                }            },
                             
    
        }, { // Quaternary yAxis
            gridLineWidth: 0,
            max: 10,
            title: {
                text: ' ',
                style: {
                color:['#27BDA0'],
                }
            },
            labels: {
                format: '',
                style: {
                color:['#FFFFFF'],
                }
            },
            opposite: true,
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
            name: 'Eau aquarium',
            type: 'spline',
            yAxis: 0,
            data: EauAquarium,
            zIndex:9,
            color:'#FF6300',
            tooltip: {
                valueSuffix: ' cm'
            }
        }, {
            name: 'Eau du potager',
            type: 'spline',
            lineWidth:0.5,
            yAxis: 0,
            data: EauPotager,
            zIndex:5,
            color: '#27BDA0',
            tooltip: {
                valueSuffix: ' cm'
            }
    
        }, {
            name: 'Eau de la réserve',
            type: 'spline',
            yAxis: 1,
            data: EauReserve,
            zIndex:3,
            color:'#007E61',
            marker: {
                enabled: false
            },
            dashStyle: 'shortdot',
            tooltip: {
                valueSuffix: ' cm'
            }
        }, {
            name: 'Pompe réserve',
            type: 'column',
            yAxis: 2,
            data: etatPompeTank,
            zIndex:6,
            color:'#FF9F64',
            tooltip: {
                valueSuffix: ' on-off'
            }
        }, {
            name: 'Pompe aquarium',
            type: 'column',
            yAxis: 2,
            data: etatPompeAqua,
            zIndex:1,
            color:'#27BDA0',
            tooltip: {
                valueSuffix: ' on-off'
            }
        }, {
            name: 'Chauffage',
            type: 'column',
            yAxis: 1,
            data: etatHeat,
            zIndex: 2,
            color:'#008E72',
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
            text: 'Les paramètres physiques',
            align: 'left'
        },
        subtitle: {
            text: 'ffp3',
            align: 'left'
        },
        xAxis: [{
            type:'datetime',                   
            crosshair: true
        }],
        yAxis: [{ // Primary yAxis
            labels: {
                format: '{value} °C',
                tickInterval: 1,
                style: {
                color:['#FF6300'],
                }
            },
            title: {
                text: 'Températures eau et air',
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
                text: 'Humidité air',
                tickInterval: 1,
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
        }, { // Quaternary yAxis
            gridLineWidth: 0,
            max: 10,
            title: {
                text: ' ',
                style: {
                color:['#27BDA0'],
                }
            },
            labels: {
                format: '',
                style: {
                color:['#FFFFFF'],
                }
            },
            opposite: true,
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
            name: 'Température eau',
            type: 'spline',
            yAxis: 0,
            data: TempEau,
            zIndex: 10,
            color: '#00B794',        
            tooltip: {
                valueSuffix: ' °C'
            }
    
        }, {
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
        }, {
            name: 'Etat LEDs',
            type: 'column',
            yAxis: 3,
            data: etatUV,
            zIndex:6,
            color: '#FF9F64',
            tooltip: {
                valueSuffix: ' on-off'
            }
        }, {
            name: 'Nourriture des gros poissons',
            type: 'column',
            yAxis: 3,
            data: bouffeGros,
            zIndex:6,
            color: '#27BDA0',
            tooltip: {
                valueSuffix: ' on-off'
            }
        }, {
            name: 'Nourriture des gros poissons',
            type: 'column',
            yAxis: 3,
            data: bouffePetits,
            zIndex:6,
            color: '#008E72',
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
    
    </script>
    
    <script>
        // Variables JS pour afficher la dernière valeur de chaque capteur sur les jauges
        var TemperatureAir = <?php echo $last_reading_tempair; ?>;   // Dernière température de l'air
        var TemperatureEau = <?php echo $last_reading_tempeau; ?>;   // Dernière température de l'eau
        var Humidite = <?php echo $last_reading_humi; ?>;            // Dernière humidité
        var Luminosite = <?php echo $last_reading_lumi; ?>;          // Dernière luminosité
        var EauAquarium = <?php echo $last_reading_eauaqua; ?>;      // Dernier niveau d'eau aquarium
        var EauReserve = <?php echo $last_reading_eaureserve; ?>;    // Dernier niveau d'eau réserve
        var EauPotager = <?php echo $last_reading_eaupota; ?>;       // Dernier niveau d'eau potager
        
        // Affichage des valeurs sur les jauges (appel des fonctions ci-dessous)
        setTempAir(TemperatureAir);
        setTempEau(TemperatureEau);
        setHumidite(Humidite);
        setLuminosite(Luminosite);
        setEauAquarium(EauAquarium);
        setEauReserve(EauReserve);
        setEauPotager(EauPotager);
    
        // Fonction pour afficher la température de l'air sur la jauge
        function setTempAir(curVal){
            // Plage de température en °C (pour l'affichage de la jauge)
            var minTempAir = 5.0;
            var maxTempAir = 35.0;
            // Conversion de la valeur dans l'angle de la jauge (0 à 180°)
            var newVal = scaleValue(curVal, [minTempAir, maxTempAir], [0, 180]);
            $('.gauge--1 .semi-circle--mask').attr({
                style: '-webkit-transform: rotate(' + newVal + 'deg);' +
                '-moz-transform: rotate(' + newVal + 'deg);' +
                'transform: rotate(' + newVal + 'deg);'
            });
            $("#tempair").text(curVal + ' ºC');
        }
        
        // Fonction pour afficher la température de l'eau sur la jauge
        function setTempEau(curVal){
            var minTempEau = 5.0;
            var maxTempEau = 35.0;
            var newVal = scaleValue(curVal, [minTempEau, maxTempEau], [0, 180]);
            $('.gauge--2 .semi-circle--mask').attr({
                style: '-webkit-transform: rotate(' + newVal + 'deg);' +
                '-moz-transform: rotate(' + newVal + 'deg);' +
                'transform: rotate(' + newVal + 'deg);'
            });
            $("#tempeau").text(curVal + ' ºC');
        }
    
        // Fonction pour afficher l'humidité sur la jauge
        function setHumidite(curVal){
            var minHumidite = 0;
            var maxHumidite = 100;
            var newVal = scaleValue(curVal, [minHumidite, maxHumidite], [0, 180]);
            $('.gauge--3 .semi-circle--mask').attr({
                style: '-webkit-transform: rotate(' + newVal + 'deg);' +
                '-moz-transform: rotate(' + newVal + 'deg);' +
                'transform: rotate(' + newVal + 'deg);'
            });
            $("#humi").text(curVal + ' %');
        }
        
        // Fonction pour afficher la luminosité sur la jauge
        function setLuminosite(curVal){
            var minLuminosite = 0;
            var maxLuminosite = 4095;
            var newVal = scaleValue(curVal, [minLuminosite, maxLuminosite], [0, 180]);
            $('.gauge--4 .semi-circle--mask').attr({
                style: '-webkit-transform: rotate(' + newVal + 'deg);' +
                '-moz-transform: rotate(' + newVal + 'deg);' +
                'transform: rotate(' + newVal + 'deg);'
            });
            $("#lumi").text(curVal + ' UA');
        }
        
        // Fonction pour afficher le niveau d'eau de l'aquarium sur la jauge
        function setEauAquarium(curVal){
            var minEauAquarium = 5;
            var maxEauAquarium = 25;
            var newVal = scaleValue(curVal, [minEauAquarium, maxEauAquarium], [0, 180]);
            $('.gauge--5 .semi-circle--mask').attr({
                style: '-webkit-transform: rotate(' + newVal + 'deg);' +
                '-moz-transform: rotate(' + newVal + 'deg);' +
                'transform: rotate(' + newVal + 'deg);'
            });
            $("#eauaqua").text(curVal + ' cm');
        }
        
        // Fonction pour afficher le niveau d'eau de la réserve sur la jauge
        function setEauReserve(curVal){
            var minEauReserve = 5;
            var maxEauReserve = 75;
            var newVal = scaleValue(curVal, [minEauReserve, maxEauReserve], [0, 180]);
            $('.gauge--6 .semi-circle--mask').attr({
                style: '-webkit-transform: rotate(' + newVal + 'deg);' +
                '-moz-transform: rotate(' + newVal + 'deg);' +
                'transform: rotate(' + newVal + 'deg);'
            });
            $("#eaureserve").text(curVal + ' cm');
        }
        
        // Fonction pour afficher le niveau d'eau du potager sur la jauge
        function setEauPotager(curVal){
            var minEauPotager = 0;
            var maxEauPotager = 25;
            var newVal = scaleValue(curVal, [minEauPotager, maxEauPotager], [0, 180]);
            $('.gauge--7 .semi-circle--mask').attr({
                style: '-webkit-transform: rotate(' + newVal + 'deg);' +
                '-moz-transform: rotate(' + newVal + 'deg);' +
                'transform: rotate(' + newVal + 'deg);'
            });
            $("#eaupota").text(curVal + ' cm');
        }
    
        // Fonction utilitaire pour convertir une valeur dans une plage en angle de jauge
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