
<?Php
    include_once('msp1-config.php');

//fonctions d'écriture le fichier de log
    function addLogEvent($event) {
        $time = date("D, d M Y H:i:s");
        $time = "[".$time."] ";
     
        $event = $time.$event."\n";
     
        file_put_contents("cronlogmsp1.txt", $event, FILE_APPEND);
        }
    
    function addLogTask($event) {

        $event = $event."\n";
     
        file_put_contents("cronlogmsp1.txt", $event, FILE_APPEND);
        }
        
    function addLogName($event) {

        $event = $event;
     
        file_put_contents("cronlogmsp1.txt", $event, FILE_APPEND);
        }

//démarrage des actions du cron
    addLogEvent("démarrage cron");

//comptage des valeurs aberrrantes  

    addLogTask("Nombre de valeurs aberrantes : ");

    echo "nombre d'abérations pour". '<br />';
    
    $countHumidSol = countDatas('HumidSol','3');
    $countHumidSol = $countHumidSol['COUNT(*)'];    
    echo "- nombre d'abérations pour sol ".$countHumidSol. '<br />';    
    
    $countPluie = countDatas('Pluie','3');
    $countPluie = $countPluie['COUNT(*)'];    
    echo "- nombre d'abérations pour pluie ".$countPluie. '<br />';    
    
    $countTempEau = countDatas('TempEau','3');
    $countTempEau = $countTempEau['COUNT(*)'];    
    echo "- nombre d'abérations pour la température de l'eau ".$countTempEau. '<br />';

    $countTempEau25 = countDatasTempEau('TempEau');
    $countTempEau25 = $countTempEau25['COUNT(*)'];    
    echo "- nombre d'abérations pour la température de l'eau25 ".$countTempEau25. '<br />';

    $countTempAirInt = countDatas('TempAirInt','3');
    $countTempAirInt = $countTempAirInt['COUNT(*)'];    
    echo "- nombre d'abérations pour la TempAirInt ".$countTempAirInt. '<br />';

    $countTempAirExt = countDatas('TempAirExt','3');
    $countTempAirExt = $countTempAirExt['COUNT(*)'];    
    echo "- nombre d'abérations pour la TempAirExt ".$countTempAirExt. '<br />';
    
    $countHumidAirInt = countDatas('HumidAirInt','3');
    $countHumidAirInt = $countHumidAirInt['COUNT(*)'];
    echo "- le niveau d'eau de la réserve min : ".$countHumidAirInt. '<br />';
    addLogName("Niveau d'eau de la réserve min : ");
    addLogTask($countHumidAirInt);
    
    $countHumidAirExt = countDatas('HumidAirExt','3');
    $countHumidAirExt = $countHumidAirExt['COUNT(*)'];
    echo "- le niveau d'eau de l'aquarium max : ".$countHumidAirExt. '<br />';
    addLogName("Niveau d'eau de l'aquarium max : ");
    addLogTask($countHumidAirExt);
    
    $countPontDiv = countDatas('PontDiv','50');
    $countPontDiv = $countPontDiv['COUNT(*)'];
    echo "- le niveau d'eau pontdiv : ".$countPontDiv. '<br />';
    addLogName("Niveau d'eau de l'aquarium max : ");
    addLogTask($countPontDiv);
    
//suprression des valeurs aberrantes (sauf niveaux d'eau)

 /*   if ($countHumidSol > 0){
        echo ("suppression valeur(s) abérrante(s) sol". '<br />');
        addLogName($countHumidSol);
        addLogTask(" valeurs supprimées pour sol");
        changeDatas ('HumidSol','3');  
    }
    
    if ($countPluie > 0){
        echo ("suppression valeur(s) abérrante(s) pluie". '<br />');
        addLogName($countPluie);
        addLogTask(" valeurs supprimées pour pluie");
        changeDatas ('Pluie','3');  
    }*/
    
    if ($countTempEau > 0){
        echo ("suppression valeur(s) abérrante(s) température eau". '<br />');
        addLogName($countTempEau);
        addLogTask(" valeurs supprimées pour la température de l'eau");
        changeDatas ('TempEau','3');  
    }
    
    if ($countTempEau25 > 0){
        echo ("suppression valeur(s) abérrante(s) température eau 25". '<br />');
        addLogName($countTempEau25);
        addLogTask(" valeurs supprimées pour la température de l'eau 25");
        changeDatasTempEau ('TempEau');  
    }
    
    if ($countTempAirInt > 0){
        echo ("suppression valeur(s) abérrante(s) température eau". '<br />');
        addLogName($countTempAirInt);
        addLogTask(" valeurs supprimées pour la température de l'eau");
        changeDatas ('TempAirInt','3');  
    }
    
    if ($countTempAirExt > 0){
        echo ("suppression valeur(s) abérrante(s) température eau 25". '<br />');
        addLogName($countTempAirExt);
        addLogTask(" valeurs supprimées pour la température de l'eau 25");
        changeDatas ('TempAirExt','3');  
    }
    
    if ($countHumidAirExt > 0){
        echo ("suppression valeur(s) abérrante(s) température air". '<br />');
        addLogName($countHumidAirExt);
        addLogTask(" valeurs supprimées pour la température de l'air");
        changeDatas ('HumidAirExt','3');  
    }

    if ($countHumidAirInt > 0){
        echo ("suppression valeur(s) abérrante(s) du capteur à  ultrasons de la réserve ". '<br />');
        addLogName($countHumidAirInt);
        addLogTask(" valeurs supprimées minimum pour le niveau d'eau de réserve");
        changeDatas ('HumidAirInt','3');
    }
    
/*    if ($countPontDiv > 0){
        echo ("suppression valeur(s) abérrante(s) du pontdiv ". '<br />');
        addLogName($countPontDiv);
        addLogTask(" valeurs supprimées minimum pour le pontdiv");
        changeDatas ('PontDiv','50');
    }*/

    addLogTask("Cron correctement executé.");
    addLogTask(" ");
?>



	    
	    