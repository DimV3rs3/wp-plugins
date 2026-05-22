document.addEventListener('DOMContentLoaded', function () {
    const charts = window.wscitiesCityCharts || {};
    const labels = Array.isArray(charts.labels) ? charts.labels : [];

    const population = Array.isArray(charts.population) ? charts.population : [];
    const builtArea = Array.isArray(charts.builtArea) ? charts.builtArea : [];
    const urbanExtent = Array.isArray(charts.urbanExtent) ? charts.urbanExtent : [];
    const density = Array.isArray(charts.density) ? charts.density : [];
    const fragmentationLabels = Array.isArray(charts.fragmentationLabels) ? charts.fragmentationLabels : [];
    const fragmentationValues = Array.isArray(charts.fragmentationValues) ? charts.fragmentationValues : [];

    const commonChartOptions = {
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
            legend: { display: false },
            tooltip: {
                backgroundColor: 'rgba(0, 0, 0, 0.8)',
                titleColor: '#fff',
                bodyColor: '#fff',
                borderColor: '#999',
                borderWidth: 1,
                padding: 12,
                displayColors: false,
                callbacks: {
                    title: function (context) {
                        return 'Период: ' + context[0].label;
                    },
                    label: function (context) {
                        const v = context.parsed.y;
                        return context.dataset.label + ': ' + (typeof v === 'number' ? v.toLocaleString() : v);
                    }
                }
            }
        },
        scales: {
            y: {
                beginAtZero: true,
                grid: {
                    color: '#f0f0f0',
                    drawBorder: true,
                    lineWidth: 1
                },
                ticks: {
                    font: { size: 11, family: "'Inter', sans-serif" },
                    color: '#999999',
                    padding: 10
                },
                border: { color: '#e0e0e0' }
            },
            x: {
                grid: { display: false, drawBorder: false },
                ticks: {
                    font: { size: 11, family: "'Inter', sans-serif" },
                    color: '#999999'
                },
                border: { color: '#e0e0e0' }
            }
        }
    };

    // Colors (blue palette)
    const c = {
        line: '#1d4ed8',
        fillPop: 'rgba(37, 99, 235, 0.08)',
        green: '#16a34a'
    };

    function renderCharts() {
        if (typeof Chart === 'undefined') return false;

        // 1) Population
        const ctx1 = document.getElementById('chartPopulation');
        if (ctx1 && population.length) {
            new Chart(ctx1, {
                type: 'line',
                data: {
                    labels: labels,
                    datasets: [{
                        label: 'Население (чел.)',
                        data: population,
                        borderColor: c.line,
                        backgroundColor: c.fillPop,
                        borderWidth: 3,
                        fill: true,
                        tension: 0.4,
                        pointRadius: 5,
                        pointBackgroundColor: c.line,
                        pointBorderColor: '#fff',
                        pointBorderWidth: 2
                    }]
                },
                options: commonChartOptions
            });
        }

        // 2) Built-up area
        const ctx2 = document.getElementById('chartBuiltArea');
        if (ctx2 && builtArea.length) {
            new Chart(ctx2, {
                type: 'bar',
                data: {
                    labels: labels,
                    datasets: [{
                        label: 'Застроенная площадь (га)',
                        data: builtArea,
                        borderColor: c.line,
                        backgroundColor: 'rgba(37, 99, 235, 0.10)',
                        borderWidth: 0,
                        fill: true,
                        tension: 0.4,
                        pointRadius: 5,
                        pointBackgroundColor: c.line,
                        pointBorderColor: '#fff',
                        pointBorderWidth: 2
                    }]
                },
                options: commonChartOptions
            });
        }

        // 3) Urban extent
        const ctx3 = document.getElementById('chartUrbanExtent');
        if (ctx3 && urbanExtent.length) {
            new Chart(ctx3, {
                type: 'line',
                data: {
                    labels: labels,
                    datasets: [{
                        label: 'Городская территория (га)',
                        data: urbanExtent,
                        borderColor: c.line,
                        backgroundColor: c.fillPop,
                        borderWidth: 3,
                        fill: true,
                        tension: 0.4,
                        pointRadius: 5,
                        pointBackgroundColor: c.line,
                        pointBorderColor: '#fff',
                        pointBorderWidth: 2
                    }]
                },
                options: commonChartOptions
            });
        }

        // 4) Density (fallback to fragmentation chart if data is not usable)
        const ctx4 = document.getElementById('chartDensity');
        if (ctx4) {
            const densitySeries = density.map(v => Number(v)).filter(v => Number.isFinite(v));
            const densityHasData = densitySeries.some(v => v > 0);

            if (densityHasData) {
                new Chart(ctx4, {
                    type: 'bar',
                    data: {
                        labels: labels,
                        datasets: [{
                            label: 'Плотность застройки (чел./га)',
                            data: density,
                            borderColor: c.line,
                            backgroundColor: 'rgba(37, 99, 235, 0.10)',
                            borderWidth: 0,
                            fill: false,
                        }]
                    },
                    options: commonChartOptions
                });
            } else {
                const fragSeries = fragmentationValues.map(v => Number(v)).filter(v => Number.isFinite(v) && v > 0);
                if (fragmentationLabels.length && fragSeries.length) {
                    new Chart(ctx4, {
                        type: 'bar',
                        data: {
                            labels: fragmentationLabels,
                            datasets: [{
                                label: 'Фрагментация городской формы (T3)',
                                data: fragmentationValues,
                                borderColor: '#16a34a',
                                backgroundColor: 'rgba(22, 163, 74, 0.12)',
                                borderWidth: 0,
                                fill: false,
                            }]
                        },
                        options: commonChartOptions
                    });
                } else {
                    const container = ctx4.closest('.chart-container');
                    if (container) {
                        container.innerHTML = '<div style="height:100%;display:flex;align-items:center;justify-content:center;color:#64748b;font-size:14px;text-align:center;padding:12px;">Нет данных для графика плотности. Добавьте данные T1/T2/T3 или показатели фрагментации.</div>';
                    }
                }
            }
        }

        return true;
    }

    let tries = 0;
    const maxTries = 40;
    (function tryRender() {
        if (renderCharts()) return;
        tries++;
        if (tries < maxTries) {
            setTimeout(tryRender, 150);
        }
    })();
});

