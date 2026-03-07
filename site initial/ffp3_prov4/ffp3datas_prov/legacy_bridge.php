{% extends 'base.twig' %}

{% block title %}Aquaponie - {{ parent() }}{% endblock %}

{% block content %}
    <h1>Aquaponie</h1>

    <div class="d-flex justify-content-between align-items-center mb-3">
        <h2>Données des capteurs</h2>
        <a href="{{ path('ffp3_data') }}" class="btn btn-primary">Voir le graphique</a>
    </div>

    <div class="card mb-4">
        <div class="card-header">
            <h3>Dernières lectures</h3>
        </div>
        <div class="card-body">
            <table class="table table-striped table-hover">
                <thead>
                    <tr>
                        <th>Date</th>
                        <th>Température</th>
                        <th>Humidité</th>
                        <th>pH</th>
                        <th>Conductivité</th>
                        <th>Oxygène</th>
                        <th>Poids</th>
                    </tr>
                </thead>
                <tbody>
                    {% for reading in last_readings %}
                        <tr>
                            <td>{{ reading.reading_time|date('H:i:s') }}</td>
                            <td>{{ reading.temperature }}</td>
                            <td>{{ reading.humidity }}</td>
                            <td>{{ reading.ph }}</td>
                            <td>{{ reading.conductivity }}</td>
                            <td>{{ reading.oxygen }}</td>
                            <td>{{ reading.weight }}</td>
                        </tr>
                    {% endfor %}
                </tbody>
            </table>
        </div>
    </div>

    <div class="card mb-4">
        <div class="card-header">
            <h3>Statistiques</h3>
        </div>
        <div class="card-body">
            <div class="row">
                <div class="col-md-6">
                    <h4>Température</h4>
                    <p>Min: {{ min_temperature }}°C</p>
                    <p>Max: {{ max_temperature }}°C</p>
                    <p>Moyenne: {{ avg_temperature }}°C</p>
                    <p>Écart-type: {{ stddev_temperature }}°C</p>
                </div>
                <div class="col-md-6">
                    <h4>Humidité</h4>
                    <p>Min: {{ min_humidity }}%</p>
                    <p>Max: {{ max_humidity }}%</p>
                    <p>Moyenne: {{ avg_humidity }}%</p>
                    <p>Écart-type: {{ stddev_humidity }}%</p>
                </div>
            </div>
            <div class="row mt-4">
                <div class="col-md-6">
                    <h4>pH</h4>
                    <p>Min: {{ min_ph }}</p>
                    <p>Max: {{ max_ph }}</p>
                    <p>Moyenne: {{ avg_ph }}</p>
                    <p>Écart-type: {{ stddev_ph }}</p>
                </div>
                <div class="col-md-6">
                    <h4>Conductivité</h4>
                    <p>Min: {{ min_conductivity }} µS/cm</p>
                    <p>Max: {{ max_conductivity }} µS/cm</p>
                    <p>Moyenne: {{ avg_conductivity }} µS/cm</p>
                    <p>Écart-type: {{ stddev_conductivity }} µS/cm</p>
                </div>
            </div>
            <div class="row mt-4">
                <div class="col-md-6">
                    <h4>Oxygène</h4>
                    <p>Min: {{ min_oxygen }} mg/L</p>
                    <p>Max: {{ max_oxygen }} mg/L</p>
                    <p>Moyenne: {{ avg_oxygen }} mg/L</p>
                    <p>Écart-type: {{ stddev_oxygen }} mg/L</p>
                </div>
                <div class="col-md-6">
                    <h4>Poids</h4>
                    <p>Min: {{ min_weight }} kg</p>
                    <p>Max: {{ max_weight }} kg</p>
                    <p>Moyenne: {{ avg_weight }} kg</p>
                    <p>Écart-type: {{ stddev_weight }} kg</p>
                </div>
            </div>
        </div>
    </div>

    <div class="card mb-4">
        <div class="card-header">
            <h3>Commandes</h3>
        </div>
        <div class="card-body">
            <div class="row">
                <div class="col-md-6">
                    <button class="btn btn-danger" onclick="stopPompeAqua()">Arrêter la pompe Aqua</button>
                    <button class="btn btn-success" onclick="runPompeAqua()">Démarrer la pompe Aqua</button>
                </div>
                <div class="col-md-6">
                    <button class="btn btn-danger" onclick="stopPompeTank()">Arrêter la pompe Tank</button>
                    <button class="btn btn-success" onclick="runPompeTank()">Démarrer la pompe Tank</button>
                </div>
            </div>
            <div class="row mt-3">
                <div class="col-md-6">
                    <button class="btn btn-warning" onclick="rebootEsp()">Redémarrer l'ESP</button>
                </div>
            </div>
        </div>
    </div>

    <div class="card mb-4">
        <div class="card-header">
            <h3>États des GPIO</h3>
        </div>
        <div class="card-body">
            <div class="row">
                <div class="col-md-6">
                    <p>Pompe Aqua: {{ etatPompeAqua.state }}</p>
                    <p>Pompe Tank: {{ etatPompeTank.state }}</p>
                </div>
                <div class="col-md-6">
                    <p>Mode Reset: {{ etatResetMode.state }}</p>
                </div>
            </div>
        </div>
    </div>

    <div class="card mb-4">
        <div class="card-header">
            <h3>Données brutes ({{ start_date|date('d/m/Y') }} … {{ end_date|date('d/m/Y') }})</h3>
        </div>
        <div class="card-body">
            <table class="table table-striped table-hover">
                <thead>
                    <tr>
                        <th>Date</th>
                        <th>Température</th>
                        <th>Humidité</th>
                        <th>pH</th>
                        <th>Conductivité</th>
                        <th>Oxygène</th>
                        <th>Poids</th>
                    </tr>
                </thead>
                <tbody>
                    {% for reading in sensor_data %}
                        <tr>
                            <td>{{ reading.reading_time|date('H:i:s') }}</td>
                            <td>{{ reading.temperature }}</td>
                            <td>{{ reading.humidity }}</td>
                            <td>{{ reading.ph }}</td>
                            <td>{{ reading.conductivity }}</td>
                            <td>{{ reading.oxygen }}</td>
                            <td>{{ reading.weight }}</td>
                        </tr>
                    {% endfor %}
                </tbody>
            </table>
        </div>
    </div>

    <div class="card mb-4">
        <div class="card-header">
            <h3>Export des données</h3>
        </div>
        <div class="card-body">
            <form method="post" action="{{ path('export_sensor_data') }}">
                <div class="form-group">
                    <label for="start_date">Date de début:</label>
                    <input type="date" class="form-control" id="start_date" name="start_date" value="{{ start_date|date('Y-m-d') }}">
                </div>
                <div class="form-group">
                    <label for="end_date">Date de fin:</label>
                    <input type="date" class="form-control" id="end_date" name="end_date" value="{{ end_date|date('Y-m-d') }}">
                </div>
                <button type="submit" class="btn btn-primary">Exporter les données</button>
            </form>
        </div>
    </div>
{% endblock %}

{% block javascripts %}
    {{ parent() }}
    <script>
        function stopPompeAqua() {
            fetch('{{ path('stop_pompe_aqua') }}', { method: 'POST' })
                .then(response => response.json())
                .then(data => {
                    alert('Pompe Aqua arrêtée avec succès.');
                    // Mettre à jour l'état de la pompe
                    document.querySelector('.btn-danger').textContent = 'Arrêter la pompe Aqua';
                    document.querySelector('.btn-success').textContent = 'Démarrer la pompe Aqua';
                })
                .catch(error => {
                    alert('Erreur lors de l\'arrêt de la pompe Aqua: ' + error);
                });
        }

        function runPompeAqua() {
            fetch('{{ path('run_pompe_aqua') }}', { method: 'POST' })
                .then(response => response.json())
                .then(data => {
                    alert('Pompe Aqua démarrée avec succès.');
                    // Mettre à jour l'état de la pompe
                    document.querySelector('.btn-danger').textContent = 'Arrêter la pompe Aqua';
                    document.querySelector('.btn-success').textContent = 'Démarrer la pompe Aqua';
                })
                .catch(error => {
                    alert('Erreur lors du démarrage de la pompe Aqua: ' + error);
                });
        }

        function stopPompeTank() {
            fetch('{{ path('stop_pompe_tank') }}', { method: 'POST' })
                .then(response => response.json())
                .then(data => {
                    alert('Pompe Tank arrêtée avec succès.');
                    // Mettre à jour l'état de la pompe
                    document.querySelector('.btn-danger').textContent = 'Arrêter la pompe Tank';
                    document.querySelector('.btn-success').textContent = 'Démarrer la pompe Tank';
                })
                .catch(error => {
                    alert('Erreur lors de l\'arrêt de la pompe Tank: ' + error);
                });
        }

        function runPompeTank() {
            fetch('{{ path('run_pompe_tank') }}', { method: 'POST' })
                .then(response => response.json())
                .then(data => {
                    alert('Pompe Tank démarrée avec succès.');
                    // Mettre à jour l'état de la pompe
                    document.querySelector('.btn-danger').textContent = 'Arrêter la pompe Tank';
                    document.querySelector('.btn-success').textContent = 'Démarrer la pompe Tank';
                })
                .catch(error => {
                    alert('Erreur lors du démarrage de la pompe Tank: ' + error);
                });
        }

        function rebootEsp() {
            if (confirm('Êtes-vous sûr de vouloir redémarrer l\'ESP ? Cela peut perturber le système.')) {
                fetch('{{ path('reboot_esp') }}', { method: 'POST' })
                    .then(response => response.json())
                    .then(data => {
                        alert('Redémarrage de l\'ESP effectué avec succès.');
                    })
                    .catch(error => {
                        alert('Erreur lors du redémarrage de l\'ESP: ' + error);
                    });
            }
        }
    </script>
{% endblock %} 