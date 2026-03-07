
<?Php

require_once __DIR__ . '/vendor/autoload.php';

use App\Config\Env;
Env::load();
include_once __DIR__ . '/ffp3-config.php';

//fonctions d'écriture le fichier de log
    function addLogEvent($event) {
        $time = date("D, d M Y H:i:s");
        $time = "[".$time."] ";
     
        $event = $time.$event."\n";
     
        file_put_contents("cronlog.txt", $event, FILE_APPEND);
        }
    
    function addLogTask($event) {

        $event = $event."\n";
     
        file_put_contents("cronlog.txt", $event, FILE_APPEND);
        }
        
    function addLogName($event) {

        $event = $event;
     
        file_put_contents("cronlog.txt", $event, FILE_APPEND);
        }

//démarrage des actions du cron
    addLogEvent("démarrage cron");


    //témoin bdd de l'état de la pompe aquarium et du mode reset
    
    $etatPompeAqua = etatPompeAqua();
    //echo ("état de la pompe de l'aquarium 2 : ".$etatPompeAqua. '<br />');
    $etatPompeAqua = $etatPompeAqua['state'];
    echo ("état de la pompe de l'aquarium : ".$etatPompeAqua. '<br />');
    addLogName("pompe aquarium : ");
    addLogTask($etatPompeAqua);
    
    $etatPompeTank = etatPompeTank();
    //echo ("état de la pompe de l'aquarium 2 : ".$etatPompeAqua. '<br />');
    $etatPompeTank = $etatPompeTank['state'];
    echo ("état de la pompe de la réserve : ".$etatPompeTank. '<br />');
    addLogName("pompe réserve : ");
    addLogTask($etatPompeTank);

    $etatResetMode = etatResetMode ();
    $etatResetMode = $etatResetMode['state'];
    echo ("état du reset mode : ".$etatResetMode. '<br />');
    addLogName("reset mode : ");
    addLogTask($etatResetMode);

//comptage des valeurs aberrrantes  

    addLogTask("Nombre de valeurs aberrantes : ");

    echo "nombre d'abérations pour". '<br />';

    $countTempEau = countDatasMin('TempEau','3');
   // echo "- nombre d'abérations pour la température de l'eau ".$countTempEau. '<br />';
    $countTempEau = $countTempEau['COUNT(*)'];    
    echo "- nombre d'abérations pour la température de l'eau ".$countTempEau. '<br />';
    
    $countEauAqMin = countDatasMin('EauAquarium','4');
    $countEauAqMin = $countEauAqMin['COUNT(*)'];
    echo "- le niveau d'eau de l'aquarium min : ".$countEauAqMin. '<br />';
    addLogName("Niveau d'eau de l'aquarium min : ");
    addLogTask($countEauAqMin);
    
    $countEauReMin = countDatasMin('EauReserve','4');
    $countEauReMin = $countEauReMin['COUNT(*)'];
    echo "- le niveau d'eau de la réserve min : ".$countEauReMin. '<br />';
    addLogName("Niveau d'eau de la réserve min : ");
    addLogTask($countEauReMin);
    
    $countTempAir = countDatasMin('TempAir','3');
    $countTempAir = $countTempAir['COUNT(*)'];
    echo "- le niveau d'eau de la réserve min : ".$countTempAir. '<br />';
    addLogName("Niveau d'eau de la réserve min : ");
    addLogTask($countTempAir);
    
    $countHumidite = countDatasMin('Humidite','3');
    $countHumidite = $countHumidite['COUNT(*)'];
    echo "- le niveau d'eau de l'aquarium max : ".$countHumidite. '<br />';
    addLogName("Niveau d'eau de l'aquarium max : ");
    addLogTask($countHumidite);
    
    $countEauAqMax = countDatasMax('EauAquarium','70');
    $countEauAqMax = $countEauAqMax['COUNT(*)'];
    echo "- le niveau d'eau de l'aquarium max : ".$countEauAqMax. '<br />';
    addLogName("Niveau d'eau de l'aquarium max : ");
    addLogTask($countEauAqMax);
    
    $countEauReMax = countDatasMax('EauReserve','90');
    $countEauReMax = $countEauReMax['COUNT(*)'];
    echo "- le niveau d'eau de la réserve max : ".$countEauReMax. '<br />';
    addLogName("Niveau d'eau de la réserve max : ");
    addLogTask($countEauReMax);
    
    $countTempEau25 = countDatasTempEau('TempEau');
    $countTempEau25 = $countTempEau25['COUNT(*)'];    
    echo "- nombre d'abérations pour la température de l'eau25 ".$countTempEau25. '<br />';


//suprression des valeurs aberrantes (sauf niveaux d'eau)

    
    if ($countTempEau > 0){
        echo ("suppression valeur(s) abérrante(s) température eau". '<br />');
        addLogName($countTempEau);
        addLogTask(" valeurs supprimées pour la température de l'eau");
        changeDatasMin ('TempEau','3');  
    }
    
    if ($countTempEau25 > 0){
        echo ("suppression valeur(s) abérrante(s) température eau 25". '<br />');
        addLogName($countTempEau25);
        addLogTask(" valeurs supprimées pour la température de l'eau 25");
        changeDatasTempEau ('TempEau');  
    }
    
    if ($countTempAir > 0){
        echo ("suppression valeur(s) abérrante(s) température air". '<br />');
        addLogName($countTempAir);
        addLogTask(" valeurs supprimées pour la température de l'air");
        changeDatasMin ('TempAir','3');  
    }

    
    if ($countHumidite > 0){
        echo ("suppression valeur(s) abérrante(s) humidité". '<br />');
        addLogName($countHumidite);
        addLogTask(" valeurs supprimées pour l'humidité");        
        changeDatasMin ('Humidite','3');  
    }

//suppression des valeurs aberrantes du niveau d'eau de l'aquarium et redémarrage éventuel de l'ESP   
    if ($countEauAqMin > 0 || $countEauAqMax > 0 ){
        echo ("suppression valeur(s) abérrante(s) du capteur à  ultrasons de l'aquarium' ". '<br />');
        addLogName($countEauAqMin);
        addLogTask(" valeurs supprimées minimum pour le niveau d'eau de l'aquarium");
        addLogName($countEauReMax);
        addLogTask(" valeurs supprimées maximum pour le niveau d'eau de l'aquarium");        
        rebootEsp();
        changeDatasMin ('EauAquarium','4');  
        changeDatasMax ('EauAquarium','70');  

    }

/*
//suppression des valeurs aberrantes du niveau d'eau de la réserve et redémarrage éventuel de l'ESP   
    if ($countEauReMin > 0 || $countEauReMax > 0 ){
        echo ("suppression valeur(s) abérrante(s) du capteur à  ultrasons de la réserve ". '<br />');
        addLogName($countEauReMin);
        addLogTask(" valeurs supprimées minimum pour le niveau d'eau de réserve");
        addLogName($countEauReMax);
        addLogTask(" valeurs supprimées maximum pour le niveau d'eau de la réserve");        
        changeDatasMin ('EauReserve','10');
        changeDatasMax ('EauReserve','90');
    }*/
    
//suppression des valeurs aberrantes du niveau d'eau de la réserve et redémarrage éventuel de l'ESP   
    if ($countEauReMin > 0){
        echo ("suppression valeur(s) abérrante(s) du capteur à  ultrasons de la réserve ". '<br />');
        addLogName($countEauReMin);
        addLogTask(" valeurs supprimées minimum pour le niveau d'eau de réserve");
        changeDatasMin ('EauReserve','10');
    }

/*// Appel de la fonction pour vérifier l'état des données et envoyer l'e-mail si nécessaire
    checkDataStatusAndSendEmail();*/

//pause de la pompe de réserve si l'aquarium est trop rempli
    $last_reading = getLastReadings();
    $last_reading_eauaqua = $last_reading["EauAquarium"];
  
    addLogName("dernière valeur du niveau de l'aquarium : ");
    addLogTask($last_reading_eauaqua);
    echo "valeur minimale de l'aquarium : ".$last_reading_eauaqua. '<br />';

    if($last_reading_eauaqua<7){
        stopPompeTank ();
        $etatPompeTank = etatPompeTank();
        $etatPompeTank = $etatPompeTank['state'];
        echo ("état de la pompe de l'aquarium auto : ".$etatPompeTank. '<br />');
        addLogName("pompe réserve : ");
        addLogTask($etatPompeTank);
        mailFlood();
    }

//pause auto de la pompe si la déviation standard des valeurs mesurées pour l'aquarium est trop faible
    $temoin_aquarium = stddevReading2 ('EauAquarium');
    $temoin_aquarium = round($temoin_aquarium['stddev_amount2'], 2);
    addLogName("déviation standard sur les 60 dernières mesures : ");
    addLogTask($temoin_aquarium);
    echo "déviation standard sur les 60 dernières mesures : ".$temoin_aquarium. '<br />';

    if($temoin_aquarium<1){
        stopPompeAqua();
        $etatPompeAqua = etatPompeAqua();
        $etatPompeAqua = $etatPompeAqua['state'];
        echo ("état de la pompe de l'aquarium auto : ".$etatPompeAqua. '<br />');
        addLogName("pompe aquarium : ");
        addLogTask($etatPompeAqua);
        sleep(300);
        runPompeAqua();
        addLogTask("pompe mise en pause et redémarrée");
        mailMarees();
    }
    
//à détailler    
checkOnline();

//à détailler
checkTankLevel();

    addLogTask("Cron correctement executé.");
    addLogTask(" ");
?>



	    
	    