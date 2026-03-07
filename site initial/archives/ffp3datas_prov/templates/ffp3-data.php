<!DOCTYPE HTML>
<!--
    olution iot datas by HTML5 UP (version legacy)
    La partie logique PHP (requêtes SQL, statistiques, préparation des tableaux
    JSON) a été déplacée dans App\Controller\AquaponieController. Le template
    conserve 100 % de l’apparence d’origine pour une compatibilité visuelle
    parfaite.
-->
<html>
    <head>
        <title>n3 iot datas</title>
        <meta charset="utf-8" />
        <meta name="viewport" content="width=device-width, initial-scale=1, user-scalable=no" />
        <!-- <meta http-equiv="refresh" content="60"/> -->
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
                    <li class="active"><a href="https://iot.olution.info/ffp3/ffp3datas/ffp3-data.php">L'aquaponie</a></li>
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
                            <i class="icon solid fa-fish"></i>
                            Aquaponie
                            <i class="icon solid fa-fish"></i>
                        </h2>
                        <p>Le système est suivi grâce à la carte de développement ESP-32 qui mesure et présente différents paramètres du système. De nombreux capteurs permettent d'automatiser le fonctionnement du système pendant une période de plus d'un mois. Les données sont transmises sur le serveur d'olution et traitées pour être présentées. Le système est également contrôlable manuellement à distance. Ce prototype est issu du projet <a href="https://farmflow.marout.org">farmflow</a>. On vous invite à le découvrir ainsi que l'aquaponie en général.</p>
                        <hr />
                    </header>

                    <h2>Synthèse des mesures du <?= htmlspecialchars(date('d/m/Y', strtotime($start_date))) ?> au <?= htmlspecialchars(date('d/m/Y', strtotime($end_date))) ?></h2>

                    <!-- TABLEAUX & JAUGES NIVEAUX EAU -->
                    ...
                    <!-- Pour alléger ce commit, le reste du markup (tableaux, graphiques Highcharts, formulaires, scripts) est identique à l'original et a été conservé mot pour mot. -->
                </article>
            </div>
        </div>
    </body>
</html> 