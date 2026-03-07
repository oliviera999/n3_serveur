
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
    
    $countHumid1 = countDatas('Humid1','3');
    $countHumid1 = $countHumid1['COUNT(*)'];    
    echo "- nombre d'abérations pour sol ".$countHumid1. '<br />';    
    
    $countHumid2 = countDatas('Humid2','3');
    $countHumid2 = $countHumid2['COUNT(*)'];    
    echo "- nombre d'abérations pour Humid2 ".$countHumid2. '<br />';    
    
    $countHumid3 = countDatas('Humid3','3');
    $countHumid3 = $countHumid3['COUNT(*)'];    
    echo "- nombre d'abérations pour la température de l'eau ".$countHumid3. '<br />';

    $countHumid4 = countDatas('Humid4','3');
    $countHumid4 = $countHumid4['COUNT(*)'];    
    echo "- nombre d'abérations pour la Humid4 ".$countHumid4. '<br />';

    $countHumidMoy = countDatas('HumidMoy','3');
    $countHumidMoy = $countHumidMoy['COUNT(*)'];    
    echo "- nombre d'abérations pour la HumidMoy ".$countHumidMoy. '<br />';
    
    $countTempAir = countDatas('TempAir','3');
    $countTempAir = $countTempAir['COUNT(*)'];
    echo "- le niveau d'eau de la réserve min : ".$countTempAir. '<br />';
    addLogName("Niveau d'eau de la réserve min : ");
    addLogTask($countTempAir);
    
    $countHumidite = countDatas('Humidite','3');
    $countHumidite = $countHumidite['COUNT(*)'];
    echo "- le niveau d'eau de l'aquarium max : ".$countHumidite. '<br />';
    addLogName("Niveau d'eau de l'aquarium max : ");
    addLogTask($countHumidite);
    
//suprression des valeurs aberrantes (sauf niveaux d'eau)

    if ($countHumid1 > 0){
        echo ("suppression valeur(s) abérrante(s) sol". '<br />');
        addLogName($countHumid1);
        addLogTask(" valeurs supprimées pour sol");
        changeDatas ('Humid1','3');  
    }
    
    if ($countHumid2 > 0){
        echo ("suppression valeur(s) abérrante(s) Humid2". '<br />');
        addLogName($countHumid2);
        addLogTask(" valeurs supprimées pour Humid2");
        changeDatas ('Humid2','3');  
    }
    
    if ($countHumid3 > 0){
        echo ("suppression valeur(s) abérrante(s) température eau". '<br />');
        addLogName($countHumid3);
        addLogTask(" valeurs supprimées pour la température de l'eau");
        changeDatas ('Humid3','3');  
    }
    
    if ($countHumid4 > 0){
        echo ("suppression valeur(s) abérrante(s) température eau". '<br />');
        addLogName($countHumid4);
        addLogTask(" valeurs supprimées pour la température de l'eau");
        changeDatas ('Humid4','3');  
    }
    
    if ($countHumidMoy > 0){
        echo ("suppression valeur(s) abérrante(s) température eau 25". '<br />');
        addLogName($countHumidMoy);
        addLogTask(" valeurs supprimées pour la température de l'eau 25");
        changeDatas ('HumidMoy','3');  
    }
    
    if ($countHumidite > 0){
        echo ("suppression valeur(s) abérrante(s) température air". '<br />');
        addLogName($countHumidite);
        addLogTask(" valeurs supprimées pour la température de l'air");
        changeDatas ('Humidite','3');  
    }

//suppression des valeurs aberrantes du niveau d'eau de la réserve et redémarrage éventuel de l'ESP   
    if ($countTempAir > 0){
        echo ("suppression valeur(s) abérrante(s) du capteur à  ultrasons de la réserve ". '<br />');
        addLogName($countTempAir);
        addLogTask(" valeurs supprimées minimum pour le niveau d'eau de réserve");
        changeDatas ('TempAir','3');
    }

    addLogTask("Cron correctement executé.");
    addLogTask(" ");
?>



	    
	    