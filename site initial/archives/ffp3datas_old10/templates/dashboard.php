<?php declare(strict_types=1); ?>
<!DOCTYPE HTML>
<!--
    Legacy-compatible dashboard view, extracted from ffp3-data.php.
    All variables it echoes (e.g. $EauAquarium, $avg_tempeau …) sont désormais préparés dans DashboardController.
-->
<html>
    <head>
        <title>n3 iot datas</title>
        <meta charset="utf-8" />
        <meta name="viewport" content="width=device-width, initial-scale=1, user-scalable=no" />
        <link rel="stylesheet" href="https://iot.olution.info/assets/css/main.css" />
        <noscript><link rel="stylesheet" href="https://iot.olution.info/assets/css/noscript.css" /></noscript>
        <link rel="shortcut icon" type="image/png" href="https://iot.olution.info/images/favico.png"/>

        <!-- Highcharts & jQuery -->
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
                <li  class="active"><a href="#">L'aquaponie</a></li>
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
            <article class="post featured">
                <header class="major">
                    <h2><i class="icon solid fa-fish"></i> Aquaponie <i class="icon solid fa-fish"></i></h2>
                    <p>Suivi temps-réel du système. Les données proviennent des capteurs ESP-32 puis sont stockées sur le serveur oLution.</p>
                    <hr />
                </header>

                <!-- Formulaire de filtrage -->
                <center>
                    <form method="POST" action="">
                        <div class="form-container">
                            <div>
                                <label for="start_date">Date de début :</label>
                                <input type="date" id="start_date" name="start_date" value="<?= htmlspecialchars(date('Y-m-d', strtotime($start_date))) ?>" required>
                                <label for="start_time">Heure de début :</label>
                                <input type="time" id="start_time" name="start_time" value="<?= htmlspecialchars(date('H:i', strtotime($start_date))) ?>" required>
                            </div>
                            <div>
                                <label for="end_date">Date de fin :</label>
                                <input type="date" id="end_date" name="end_date" value="<?= htmlspecialchars(date('Y-m-d', strtotime($end_date))) ?>" required>
                                <label for="end_time">Heure de fin :</label>
                                <input type="time" id="end_time" name="end_time" value="<?= htmlspecialchars(date('H:i', strtotime($end_date))) ?>" required>
                            </div>
                            <div>
                                <button type="submit">Afficher les mesures</button>
                            </div>
                        </div>
                    </form>
                </center>

                <h6 style="text-align: center">du <?= $start_date ?> au <?= $end_date ?> (<?= $measure_count ?> enregistrements analysés)</h6>
                <h6 style="text-align: center">Durée depuis le début du fonctionnement : <?= $timepastbegin ?> j (premier enregistrement le <?= $first_reading_time_begin ?>)</h6>
                <form method="POST">
                    <input type="hidden" name="start_date" value="<?= htmlspecialchars($start_date) ?>">
                    <input type="hidden" name="end_date"   value="<?= htmlspecialchars($end_date) ?>">
                    <button type="submit" name="export_csv">Télécharger les données (CSV)</button>
                </form>

                <!-- Tableau synthèse niveaux d'eau -->
                <div id="table-eaux" class="container">
                    <section class="content">
                        <div class="table-wrapper">
                            <table>
                                <tr><h3>Statistiques des niveaux d'eau</h3></tr>
                                <tr>
                                    <td>Mesures actuelles</td>
                                    <td><div class="box gauge--5"><h5>EAU AQUARIUM</h5><div class="mask"><div class="semi-circle"></div><div class="semi-circle--mask"></div></div><p id="eauaqua">--</p></div></td>
                                    <td><div class="box gauge--6"><h5>EAU RESERVE</h5><div class="mask"><div class="semi-circle"></div><div class="semi-circle--mask"></div></div><p id="eaureserve">--</p></div></td>
                                    <td><div class="box gauge--7"><h5>EAU POTAGER</h5><div class="mask"><div class="semi-circle"></div><div class="semi-circle--mask"></div></div><p id="eaupota">--</p></div></td>
                                </tr>
                                <tr><td>Moy</td><td><?= round($avg_eauaqua,0) ?> cm</td><td><?= round($avg_eaureserve,0) ?> cm</td><td><?= round($avg_eaupota,0) ?> cm</td></tr>
                                <tr><td>Min</td><td><?= $min_eauaqua ?> cm</td><td><?= $min_eaureserve ?> cm</td><td><?= $min_eaupota ?> cm</td></tr>
                                <tr><td>Max</td><td><?= $max_eauaqua ?> cm</td><td><?= $max_eaureserve ?> cm</td><td><?= $max_eaupota ?> cm</td></tr>
                                <tr><td>ET</td><td><?= round($stddev_eauaqua,2) ?> cm</td><td><?= round($stddev_eaureserve,2) ?> cm</td><td><?= round($stddev_eaupota,2) ?> cm</td></tr>
                            </table>
                        </div>
                    </section>
                </div>

                <div id="chart-niveauxeaux" style="width:100%; height:400px"></div>

                <!-- Tableau synthèse physiques -->
                <hr />
                <div id="table-parametresphys" class="container">
                    <section class="content">
                        <div class="table-wrapper">
                            <table>
                                <tr><h3>Statistiques des paramètres physiques</h3></tr>
                                <tr>
                                    <td>Mesures actuelles</td>
                                    <td><div class="box gauge--2"><h5>TEMPERATURE EAU</h5><div class="mask"><div class="semi-circle"></div><div class="semi-circle--mask"></div></div><p id="tempeau">--</p></div></td>
                                    <td><div class="box gauge--1"><h5>TEMPERATURE AIR</h5><div class="mask"><div class="semi-circle"></div><div class="semi-circle--mask"></div></div><p id="tempair">--</p></div></td>
                                    <td><div class="box gauge--3"><h5>HUMIDITÉ</h5><div class="mask"><div class="semi-circle"></div><div class="semi-circle--mask"></div></div><p id="humi">--</p></div></td>
                                    <td><div class="box gauge--4"><h5>LUMINOSITÉ</h5><div class="mask"><div class="semi-circle"></div><div class="semi-circle--mask"></div></div><p id="lumi">--</p></div></td>
                                </tr>
                                <tr><td>Moy</td><td><?= round($avg_tempeau,1) ?> °C</td><td><?= round($avg_tempair,1) ?> °C</td><td><?= round($avg_humi,0) ?> %</td><td><?= round($avg_lumi,0) ?> UA</td></tr>
                                <tr><td>Min</td><td><?= round($min_tempeau,1) ?> °C</td><td><?= round($min_tempair,1) ?> °C</td><td><?= round($min_humi,0) ?> %</td><td><?= round($min_lumi,0) ?> UA</td></tr>
                                <tr><td>Max</td><td><?= round($max_tempeau,1) ?> °C</td><td><?= round($max_tempair,1) ?> °C</td><td><?= round($max_humi,0) ?> %</td><td><?= round($max_lumi,0) ?> UA</td></tr>
                                <tr><td>ET</td><td><?= round($stddev_tempeau,2) ?> °C</td><td><?= round($stddev_tempair,2) ?> °C</td><td><?= round($stddev_humi,2) ?> %</td><td><?= round($stddev_lumi,2) ?> UA</td></tr>
                            </table>
                        </div>
                    </section>
                </div>

            </article>
        </div><!-- /main -->
    </div><!-- /wrapper -->

    <!-- Highcharts rendering -->
    <script>
        // Données injectées depuis PHP
        const reading_time   = <?= $reading_time ?>;
        const EauAquarium    = <?= $EauAquarium ?>.map((v,i)=>[reading_time[i],v]);
        const EauReserve     = <?= $EauReserve ?>.map((v,i)=>[reading_time[i],v]);
        const EauPotager     = <?= $EauPotager ?>.map((v,i)=>[reading_time[i],v]);

        Highcharts.chart('chart-niveauxeaux', {
            chart: { zoomType: 'xy' },
            title: { text: 'Les niveaux en eau' },
            xAxis: { type: 'datetime' },
            yAxis: [{ title: { text: 'Distance (cm)' } }],
            series: [
                { name: 'Eau aquarium', data: EauAquarium, type: 'spline' },
                { name: 'Eau réserve',  data: EauReserve,  type: 'spline' },
                { name: 'Eau potager',  data: EauPotager,  type: 'spline' }
            ]
        });

        // Mise à jour des jauges simples (texte)
        document.getElementById('eauaqua').textContent  = EauAquarium[EauAquarium.length-1][1] + ' cm';
        document.getElementById('eaureserve').textContent = EauReserve[EauReserve.length-1][1] + ' cm';
        document.getElementById('eaupota').textContent  = EauPotager[EauPotager.length-1][1] + ' cm';

        const TempEau = <?= $TempEau ?>; const TempAir = <?= $TempAir ?>; const Humidite = <?= $Humidite ?>; const Luminosite = <?= $Luminosite ?>;
        document.getElementById('tempeau').textContent = TempEau[TempEau.length-1] + ' °C';
        document.getElementById('tempair').textContent = TempAir[TempAir.length-1] + ' °C';
        document.getElementById('humi').textContent    = Humidite[Humidite.length-1] + ' %';
        document.getElementById('lumi').textContent    = Luminosite[Luminosite.length-1] + ' UA';
    </script>

    <!-- JS Assets du thème -->
    <script src="https://iot.olution.info/assets/js/jquery.min.js"></script>
    <script src="https://iot.olution.info/assets/js/jquery.scrollex.min.js"></script>
    <script src="https://iot.olution.info/assets/js/jquery.scrolly.min.js"></script>
    <script src="https://iot.olution.info/assets/js/browser.min.js"></script>
    <script src="https://iot.olution.info/assets/js/breakpoints.min.js"></script>
    <script src="https://iot.olution.info/assets/js/util.js"></script>
    <script src="https://iot.olution.info/assets/js/main.js"></script>
    </body>
</html> 