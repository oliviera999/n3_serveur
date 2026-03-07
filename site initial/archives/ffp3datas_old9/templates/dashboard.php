<?php
/** @var array $readings */
/** @var array $stats */
/** @var array|null $lastReading */
/** @var string $duration */
/** @var string $startDate */
/** @var string $endDate */
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <title>Tableau de bord Aquaponie</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 2rem; }
        table { border-collapse: collapse; width: 100%; margin-top: 1rem; }
        th, td { border: 1px solid #ccc; padding: 0.5rem; text-align: center; }
        th { background: #f5f5f5; }
    </style>
</head>
<body>
<h1>Tableau de bord Aquaponie</h1>

<section>
    <h2>Dernière lecture</h2>
    <?php if ($lastReading): ?>
        <ul>
            <li>Date : <?= htmlspecialchars($lastReading['reading_time']) ?></li>
            <li>Température eau : <?= htmlspecialchars($lastReading['TempEau'] ?? 'N/A') ?> °C</li>
            <li>Température air : <?= htmlspecialchars($lastReading['TempAir'] ?? 'N/A') ?> °C</li>
            <li>Humidité : <?= htmlspecialchars($lastReading['Humidite'] ?? 'N/A') ?> %</li>
            <li>Luminosité : <?= htmlspecialchars($lastReading['Luminosite'] ?? 'N/A') ?></li>
            <li>Niveau aquarium : <?= htmlspecialchars($lastReading['EauAquarium'] ?? 'N/A') ?></li>
            <li>Niveau réserve : <?= htmlspecialchars($lastReading['EauReserve'] ?? 'N/A') ?></li>
        </ul>
    <?php else: ?>
        <p>Aucune donnée disponible.</p>
    <?php endif; ?>
</section>

<section>
    <h2>Statistiques sur la période (<?= htmlspecialchars($duration) ?>)</h2>
    <table>
        <tr>
            <th>Capteur</th>
            <th>Min</th>
            <th>Max</th>
            <th>Moyenne</th>
            <th>Écart-type</th>
        </tr>
        <?php foreach ($stats as $sensor => $values): ?>
            <tr>
                <td><?= htmlspecialchars($sensor) ?></td>
                <td><?= $values['min'] !== null ? htmlspecialchars((string)$values['min']) : 'N/A' ?></td>
                <td><?= $values['max'] !== null ? htmlspecialchars((string)$values['max']) : 'N/A' ?></td>
                <td><?= $values['avg'] !== null ? htmlspecialchars((string)$values['avg']) : 'N/A' ?></td>
                <td><?= $values['stddev'] !== null ? htmlspecialchars((string)$values['stddev']) : 'N/A' ?></td>
            </tr>
        <?php endforeach; ?>
    </table>
</section>

<section>
    <h2>Exporter les données</h2>
    <form method="post">
        <input type="hidden" name="start_date" value="<?= htmlspecialchars(substr($startDate, 0, 10)) ?>">
        <input type="hidden" name="end_date" value="<?= htmlspecialchars(substr($endDate, 0, 10)) ?>">
        <button type="submit" name="export_csv" value="1">Exporter en CSV</button>
    </form>
</section>

</body>
</html> 