<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <title>Dashboard Capteurs</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 2rem; background: #f7f7f7; }
        h1 { color: #2c3e50; }
        table { border-collapse: collapse; width: 100%; margin-bottom: 2rem; }
        th, td { border: 1px solid #ccc; padding: 8px 12px; text-align: center; }
        th { background: #eaeaea; }
    </style>
</head>
<body>
    <h1>Dashboard des capteurs</h1>

    <h2>Période analysée</h2>
    <p><?= htmlspecialchars($startDate) ?> &nbsp;→&nbsp; <?= htmlspecialchars($endDate) ?></p>
    <p>Durée : <?= htmlspecialchars($duration) ?> — Nombre de mesures : <?= $readingsCount ?></p>

    <h2>Dernière lecture</h2>
    <?php if ($lastReading): ?>
        <table>
            <thead>
                <tr><?php foreach ($lastReading as $k => $_): ?><th><?= htmlspecialchars($k) ?></th><?php endforeach; ?></tr>
            </thead>
            <tbody>
                <tr><?php foreach ($lastReading as $v): ?><td><?= htmlspecialchars((string)$v) ?></td><?php endforeach; ?></tr>
            </tbody>
        </table>
    <?php else: ?>
        <p>Aucune donnée disponible.</p>
    <?php endif; ?>

    <h2>Statistiques</h2>
    <table>
        <thead>
            <tr>
                <th>Capteur</th><th>Min</th><th>Max</th><th>Moyenne</th><th>Écart-type</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($stats as $sensor => $s): ?>
                <tr>
                    <td><?= htmlspecialchars($sensor) ?></td>
                    <td><?= htmlspecialchars((string)$s['min']) ?></td>
                    <td><?= htmlspecialchars((string)$s['max']) ?></td>
                    <td><?= htmlspecialchars((string)$s['avg']) ?></td>
                    <td><?= htmlspecialchars((string)$s['stddev']) ?></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</body>
</html> 