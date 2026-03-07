<?php

require __DIR__ . '/../vendor/autoload.php';

// Ici on pourrait faire du routing plus avancé. Pour l'instant, on redirige vers l'ancien tableau de bord.
header('Location: /ffp3/ffp3datas/ffp3-data.php', true, 302);
exit; 