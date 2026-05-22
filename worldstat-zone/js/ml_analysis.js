const fs = require('fs');
const path = require('path');

const COLUMN_MAP = {
    zone_name: 'name',
    name: 'name',
    category: 'category',
    zone_type: 'room_type',
    room_type: 'room_type',
    room: 'room_type',
    room_type_ru: 'room_type',
    area_sqm: 'area',
    area: 'area',
    temp: 'temp',
    temperature: 'temp',
    humidity: 'humidity',
    noise: 'noise_level',
    noise_level: 'noise_level',
    light: 'lighting',
    lighting: 'lighting',
    co2: 'co2',
    furniture_type: 'furniture_type',
    furniture_style: 'furniture_style',
    furniture_material: 'furniture_material',
    ergonomics: 'ergonomics',
    city_name: 'city_name',
    country_name: 'country_name',
};

const CATEGORICAL_FIELDS = [
    'category',
    'room_type',
    'furniture_type',
    'furniture_style',
    'furniture_material',
    'city_name',
    'country_name',
];

const NUMERIC_FIELDS = [
    'ergonomics',
    'lighting',
    'temp',
    'humidity',
    'noise_level',
    'co2',
];

const TARGET_FIELDS = [
    'ergonomics',
    'comfort',
    'safety',
    'functionality',
];

const OUTPUT_FILES = {
    elbow: 'elbow.svg',
    clusters: 'clusters.svg',
    feature_importance: 'feature_importance.svg',
    classification_accuracy: 'classification_accuracy.svg',
    result: 'analysis_result.json',
};

function parseArgs() {
    const args = process.argv.slice(2);
    const result = {};
    args.forEach((item, index) => {
        if (item === '--input' || item === '--out-dir') {
            result[item.slice(2).replace('-', '_')] = args[index + 1];
        }
    });
    return result;
}

function normalizeHeader(header) {
    return header.trim().toLowerCase();
}

function detectDelimiter(text) {
    const firstLine = text.split(/\r?\n/)[0] || '';
    if (firstLine.includes('|') && !firstLine.includes('\t')) {
        return '|';
    }
    if (firstLine.includes('\t')) {
        return '\t';
    }
    return ',';
}

function parseRow(row, delimiter) {
    const values = [];
    let current = '';
    let inQuotes = false;
    for (let i = 0; i < row.length; i++) {
        const char = row[i];
        if (char === '"') {
            if (inQuotes && i + 1 < row.length && row[i + 1] === '"') {
                current += '"';
                i += 1;
            } else {
                inQuotes = !inQuotes;
            }
            continue;
        }
        if (char === delimiter && !inQuotes) {
            values.push(current);
            current = '';
            continue;
        }
        current += char;
    }
    values.push(current);
    return values;
}

function readCsv(filePath) {
    const raw = fs.readFileSync(filePath, 'utf8');
    const delimiter = detectDelimiter(raw);
    const lines = raw.split(/\r?\n/).filter(line => line.length > 0);
    if (lines.length === 0) {
        throw new Error('CSV file is empty');
    }
    const headerRow = parseRow(lines[0], delimiter);
    const headers = headerRow.map(normalizeHeader);
    const rows = [];
    for (let i = 1; i < lines.length; i++) {
        if (!lines[i].trim()) continue;
        const values = parseRow(lines[i], delimiter);
        const row = {};
        headers.forEach((header, index) => {
            row[header] = values[index] !== undefined ? values[index].trim() : '';
        });
        rows.push(row);
    }
    return rows;
}

function mapRow(row) {
    const mapped = {};
    Object.entries(row).forEach(([key, value]) => {
        const normalized = normalizeHeader(key);
        if (COLUMN_MAP[normalized]) {
            mapped[COLUMN_MAP[normalized]] = value;
        } else {
            mapped[normalized] = value;
        }
    });
    return mapped;
}

function ensureRows(rows) {
    return rows
        .map(mapRow)
        .filter(row => Object.keys(row).length > 0);
}

function parseNumber(value) {
    if (value === null || value === undefined) return NaN;
    const normalized = String(value).replace(/,/g, '.').trim();
    return normalized === '' ? NaN : Number(normalized);
}

function median(values) {
    const valid = values.filter(v => !Number.isNaN(v)).sort((a, b) => a - b);
    if (valid.length === 0) return 0;
    const mid = Math.floor(valid.length / 2);
    return valid.length % 2 === 0 ? (valid[mid - 1] + valid[mid]) / 2 : valid[mid];
}

function buildFeatures(rows) {
    const numericColumns = NUMERIC_FIELDS.filter(field => rows.some(row => field in row));
    const categoricalColumns = CATEGORICAL_FIELDS.filter(field => rows.some(row => field in row));

    const numericValues = {};
    numericColumns.forEach(col => {
        numericValues[col] = rows.map(row => parseNumber(row[col]));
    });

    const medians = {};
    numericColumns.forEach(col => {
        medians[col] = median(numericValues[col]);
    });

    const categoryValues = {};
    categoricalColumns.forEach(col => {
        categoryValues[col] = Array.from(new Set(rows.map(row => (row[col] || 'unknown').toString().toLowerCase()))).sort();
    });

    const featureNames = [...numericColumns];
    categoricalColumns.forEach(col => {
        categoryValues[col].forEach(category => {
            featureNames.push(`${col}=${category}`);
        });
    });

    const matrix = rows.map(row => {
        const featureRow = [];
        numericColumns.forEach(col => {
            let value = parseNumber(row[col]);
            if (Number.isNaN(value)) {
                value = medians[col];
            }
            featureRow.push(value);
        });
        categoricalColumns.forEach(col => {
            const actual = (row[col] || 'unknown').toString().toLowerCase();
            categoryValues[col].forEach(category => {
                featureRow.push(actual === category ? 1 : 0);
            });
        });
        return featureRow;
    });

    return { matrix, featureNames, numericColumns, categoricalColumns, categoryValues };
}

function normalizeMatrix(matrix) {
    const columns = matrix[0].length;
    const means = new Array(columns).fill(0);
    const stds = new Array(columns).fill(0);
    const n = matrix.length;
    matrix.forEach(row => {
        row.forEach((value, index) => {
            means[index] += value;
        });
    });
    for (let j = 0; j < columns; j++) {
        means[j] /= n;
    }
    matrix.forEach(row => {
        row.forEach((value, index) => {
            stds[index] += (value - means[index]) ** 2;
        });
    });
    for (let j = 0; j < columns; j++) {
        stds[j] = Math.sqrt(stds[j] / n) || 1;
    }
    const normalized = matrix.map(row => row.map((value, index) => (value - means[index]) / stds[index]));
    return { normalized, means, stds };
}

function distance(a, b) {
    let sum = 0;
    for (let i = 0; i < a.length; i++) {
        sum += (a[i] - b[i]) ** 2;
    }
    return Math.sqrt(sum);
}

function randomChoice(array) {
    return array[Math.floor(Math.random() * array.length)];
}

function kmeans(points, k, maxIter = 100) {
    const centroids = [];
    const used = new Set();
    while (centroids.length < k) {
        const index = Math.floor(Math.random() * points.length);
        if (!used.has(index)) {
            used.add(index);
            centroids.push([...points[index]]);
        }
    }

    let labels = new Array(points.length).fill(0);
    for (let iter = 0; iter < maxIter; iter++) {
        let changed = false;
        for (let i = 0; i < points.length; i++) {
            let bestIndex = 0;
            let bestDistance = Infinity;
            for (let j = 0; j < centroids.length; j++) {
                const dist = distance(points[i], centroids[j]);
                if (dist < bestDistance) {
                    bestDistance = dist;
                    bestIndex = j;
                }
            }
            if (labels[i] !== bestIndex) {
                changed = true;
                labels[i] = bestIndex;
            }
        }
        if (!changed) {
            break;
        }
        const counts = new Array(k).fill(0);
        const sums = Array.from({ length: k }, () => new Array(points[0].length).fill(0));
        for (let i = 0; i < points.length; i++) {
            const label = labels[i];
            counts[label] += 1;
            points[i].forEach((value, index) => {
                sums[label][index] += value;
            });
        }
        for (let j = 0; j < k; j++) {
            if (counts[j] === 0) {
                centroids[j] = [...points[Math.floor(Math.random() * points.length)]];
            } else {
                centroids[j] = sums[j].map(total => total / counts[j]);
            }
        }
    }

    const inertia = points.reduce((sum, point, index) => {
        const centroid = centroids[labels[index]];
        return sum + (distance(point, centroid) ** 2);
    }, 0);

    return { labels, centroids, inertia };
}

function silhouetteScore(points, labels) {
    const n = points.length;
    const uniqueLabels = Array.from(new Set(labels));
    if (uniqueLabels.length < 2 || n < 2) return NaN;

    const clusterPoints = uniqueLabels.map(label => []);
    labels.forEach((label, index) => {
        const clusterIndex = uniqueLabels.indexOf(label);
        clusterPoints[clusterIndex].push(index);
    });

    const scores = [];
    for (let i = 0; i < n; i++) {
        const ownCluster = uniqueLabels.indexOf(labels[i]);
        const a = clusterPoints[ownCluster].length === 1
            ? 0
            : clusterPoints[ownCluster].reduce((sum, j) => i === j ? sum : sum + distance(points[i], points[j]), 0) / (clusterPoints[ownCluster].length - 1);
        let b = Infinity;
        for (let other = 0; other < uniqueLabels.length; other++) {
            if (other === ownCluster) continue;
            const avg = clusterPoints[other].reduce((sum, j) => sum + distance(points[i], points[j]), 0) / clusterPoints[other].length;
            if (avg < b) b = avg;
        }
        if (b === Infinity) {
            scores.push(0);
        } else {
            scores.push(a < b ? 1 - a / b : b / a - 1);
        }
    }
    return scores.reduce((sum, value) => sum + value, 0) / n;
}

function chooseBestK(points) {
    const maxK = Math.min(6, points.length - 1);
    if (maxK < 2) {
        return { bestK: 1, ks: [1], inertias: [], silhouettes: [] };
    }
    const ks = [];
    const inertias = [];
    const silhouettes = [];
    for (let k = 2; k <= maxK; k++) {
        const { labels, inertia } = kmeans(points.map(p => [...p]), k);
        ks.push(k);
        inertias.push(inertia);
        silhouettes.push(silhouetteScore(points, labels));
    }
    let bestIndex = 0;
    let bestScore = silhouettes[0];
    for (let i = 1; i < silhouettes.length; i++) {
        if (Number.isFinite(silhouettes[i]) && silhouettes[i] > bestScore) {
            bestScore = silhouettes[i];
            bestIndex = i;
        }
    }
    return { bestK: ks[bestIndex], ks, inertias, silhouettes };
}

function toCategories(values) {
    return values.map(value => {
        const num = parseNumber(value);
        if (Number.isNaN(num)) return 'unknown';
        if (num < 40) return 'low';
        if (num < 70) return 'medium';
        return 'high';
    });
}

function prepareTargets(rows) {
    const targets = {};
    TARGET_FIELDS.forEach(field => {
        if (!rows.some(row => field in row)) return;
        const labels = toCategories(rows.map(row => row[field] || ''));
        const valid = labels.filter(label => label !== 'unknown');
        const unique = Array.from(new Set(valid));
        if (valid.length >= 10 && unique.length > 1) {
            targets[field] = labels;
        }
    });
    return targets;
}

function shuffle(array) {
    for (let i = array.length - 1; i > 0; i--) {
        const j = Math.floor(Math.random() * (i + 1));
        [array[i], array[j]] = [array[j], array[i]];
    }
}

function trainTestSplit(matrix, labels) {
    const indices = matrix.map((_, index) => index);
    shuffle(indices);
    const split = Math.max(1, Math.floor(matrix.length * 0.8));
    const trainIdx = indices.slice(0, split);
    const testIdx = indices.slice(split);
    const xTrain = trainIdx.map(index => matrix[index]);
    const yTrain = trainIdx.map(index => labels[index]);
    const xTest = testIdx.map(index => matrix[index]);
    const yTest = testIdx.map(index => labels[index]);
    return { xTrain, yTrain, xTest, yTest };
}

function majority(values) {
    const counts = values.reduce((acc, value) => {
        acc[value] = (acc[value] || 0) + 1;
        return acc;
    }, {});
    return Object.entries(counts).sort((a, b) => b[1] - a[1])[0][0];
}

function knnPredict(xTrain, yTrain, point, k = 5) {
    const distances = xTrain.map((row, index) => ({ dist: distance(row, point), label: yTrain[index] }));
    distances.sort((a, b) => a.dist - b.dist);
    return majority(distances.slice(0, Math.min(k, distances.length)).map(item => item.label));
}

function accuracyScore(yTrue, yPred) {
    if (yTrue.length === 0) return 0;
    let correct = 0;
    for (let i = 0; i < yTrue.length; i++) {
        if (yTrue[i] === yPred[i]) correct += 1;
    }
    return correct / yTrue.length;
}

function evaluateFeatureImportance(matrix, labels, featureNames) {
    const { xTrain, yTrain, xTest, yTest } = trainTestSplit(matrix, labels);
    const baseline = accuracyScore(yTest, xTest.map(point => knnPredict(xTrain, yTrain, point)));
    const importances = featureNames.map((name, featureIndex) => {
        const permuted = xTest.map(row => row.slice());
        const values = permuted.map(row => row[featureIndex]);
        for (let i = values.length - 1; i > 0; i--) {
            const j = Math.floor(Math.random() * (i + 1));
            [values[i], values[j]] = [values[j], values[i]];
        }
        for (let i = 0; i < permuted.length; i++) {
            permuted[i][featureIndex] = values[i];
        }
        const permutedPred = permuted.map(point => knnPredict(xTrain, yTrain, point));
        const permutedScore = accuracyScore(yTest, permutedPred);
        return Math.max(0, baseline - permutedScore);
    });
    return { baseline, importances };
}

function svgHeader(width, height) {
    return `<?xml version="1.0" encoding="UTF-8"?>\n<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ${width} ${height}" width="${width}" height="${height}">`;
}

function escapeHtml(text) {
    return String(text)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#39;');
}

function saveSvg(filePath, content) {
    fs.writeFileSync(filePath, content, 'utf8');
}

function saveLineChart(outputPath, title, xLabels, series) {
    const width = 900;
    const height = 520;
    const padding = 70;
    const chartWidth = width - padding * 2;
    const chartHeight = height - padding * 2;
    const allValues = series.reduce((acc, item) => acc.concat(item.values.filter(v => Number.isFinite(v))), []);
    const minValue = Math.min(0, ...allValues);
    const maxValue = Math.max(...allValues, 1);

    const points = series.map(item => item.values.map((value, index) => {
        const x = padding + (chartWidth * index) / Math.max(1, xLabels.length - 1);
        const y = padding + chartHeight - ((Number.isFinite(value) ? value : minValue) - minValue) / Math.max(1, maxValue - minValue) * chartHeight;
        return { x, y };
    }));

    let svg = svgHeader(width, height);
    svg += `<style>text{font-family:Arial,Helvetica,sans-serif;font-size:12px;fill:#333;} .label{font-weight:700;} .axis line,.axis path{stroke:#ccc;stroke-width:1;} .grid line{stroke:#e4e4e4;stroke-dasharray:2,2;}</style>`;
    svg += `<rect x="0" y="0" width="${width}" height="${height}" fill="#fff"/>`;
    svg += `<text x="${width / 2}" y="30" text-anchor="middle" class="label">${escapeHtml(title)}</text>`;
    for (let i = 0; i <= 5; i++) {
        const y = padding + (chartHeight * i) / 5;
        const value = maxValue - ((maxValue - minValue) * i) / 5;
        svg += `<line x1="${padding}" y1="${y}" x2="${width - padding}" y2="${y}" class="grid"/>`;
        svg += `<text x="${padding - 10}" y="${y + 4}" text-anchor="end">${value.toFixed(2)}</text>`;
    }
    svg += `<g class="axis"><line x1="${padding}" y1="${padding}" x2="${padding}" y2="${padding + chartHeight}"/><line x1="${padding}" y1="${padding + chartHeight}" x2="${width - padding}" y2="${padding + chartHeight}"/></g>`;

    series.forEach((item, seriesIndex) => {
        const color = item.color;
        svg += `<polyline fill="none" stroke="${color}" stroke-width="3" points="${points[seriesIndex].map(pt => `${pt.x},${pt.y}`).join(' ')}"/>`;
        points[seriesIndex].forEach(pt => {
            if (Number.isFinite(item.values[points[seriesIndex].indexOf(pt)])) {
                svg += `<circle cx="${pt.x}" cy="${pt.y}" r="4" fill="${color}"/>`;
            }
        });
    });

    const labelY = padding + chartHeight + 30;
    xLabels.forEach((label, index) => {
        const x = padding + (chartWidth * index) / Math.max(1, xLabels.length - 1);
        svg += `<text x="${x}" y="${labelY}" text-anchor="middle">${escapeHtml(String(label))}</text>`;
    });

    const legendX = padding;
    let legendY = padding - 20;
    series.forEach(item => {
        svg += `<rect x="${legendX}" y="${legendY - 10}" width="14" height="14" fill="${item.color}"/>`;
        svg += `<text x="${legendX + 20}" y="${legendY + 2}" text-anchor="start">${escapeHtml(item.label)}</text>`;
        legendY += 20;
    });

    svg += '</svg>';
    saveSvg(outputPath, svg);
}

function saveScatterPlot(outputPath, title, points, labels) {
    const width = 900;
    const height = 520;
    const padding = 70;
    const chartWidth = width - padding * 2;
    const chartHeight = height - padding * 2;
    const xs = points.map(p => p[0]);
    const ys = points.map(p => p[1]);
    const minX = Math.min(...xs);
    const maxX = Math.max(...xs);
    const minY = Math.min(...ys);
    const maxY = Math.max(...ys);
    const uniqueLabels = Array.from(new Set(labels));
    const palette = ['#10b981', '#3b82f6', '#f59e0b', '#ef4444', '#8b5cf6', '#14b8a6'];

    let svg = svgHeader(width, height);
    svg += `<style>text{font-family:Arial,Helvetica,sans-serif;font-size:12px;fill:#333;} .label{font-weight:700;} .axis line,.axis path{stroke:#ccc;stroke-width:1;} .grid line{stroke:#e4e4e4;stroke-dasharray:2,2;}</style>`;
    svg += `<rect x="0" y="0" width="${width}" height="${height}" fill="#fff"/>`;
    svg += `<text x="${width / 2}" y="30" text-anchor="middle" class="label">${escapeHtml(title)}</text>`;

    for (let i = 0; i <= 5; i++) {
        const y = padding + (chartHeight * i) / 5;
        svg += `<line x1="${padding}" y1="${y}" x2="${width - padding}" y2="${y}" class="grid"/>`;
    }

    svg += `<g class="axis"><line x1="${padding}" y1="${padding}" x2="${padding}" y2="${padding + chartHeight}"/><line x1="${padding}" y1="${padding + chartHeight}" x2="${width - padding}" y2="${padding + chartHeight}"/></g>`;

    points.forEach((point, index) => {
        const x = padding + ((point[0] - minX) / Math.max(1, maxX - minX)) * chartWidth;
        const y = padding + chartHeight - ((point[1] - minY) / Math.max(1, maxY - minY)) * chartHeight;
        const color = palette[uniqueLabels.indexOf(labels[index]) % palette.length];
        svg += `<circle cx="${x}" cy="${y}" r="6" fill="${color}" opacity="0.75"/>`;
    });

    uniqueLabels.forEach((label, index) => {
        const x = width - padding - 180;
        const y = padding + index * 22;
        svg += `<rect x="${x}" y="${y - 12}" width="14" height="14" fill="${palette[index % palette.length]}"/>`;
        svg += `<text x="${x + 20}" y="${y}" text-anchor="start">Кластер ${index + 1} (${escapeHtml(String(label))})</text>`;
    });

    svg += '</svg>';
    saveSvg(outputPath, svg);
}

function saveFeatureImportancePlot(outputPath, title, featureNames, importanceTable) {
    const width = 1000;
    const rowHeight = 28;
    const titleHeight = 50;
    const columns = importanceTable.length > 0 ? importanceTable[0].values.length : 0;
    const height = titleHeight + (featureNames.length + 1) * rowHeight + 40;
    const cellWidth = Math.floor((width - 200) / Math.max(1, columns));

    let svg = svgHeader(width, height);
    svg += `<style>text{font-family:Arial,Helvetica,sans-serif;font-size:12px;fill:#333;} .header{font-weight:700;}</style>`;
    svg += `<rect width="${width}" height="${height}" fill="#fff"/>`;
    svg += `<text x="${width / 2}" y="24" text-anchor="middle" class="header">${escapeHtml(title)}</text>`;

    const startX = 180;
    const startY = titleHeight;
    svg += `<text x="${startX - 10}" y="${startY + rowHeight / 2 + 5}" text-anchor="end" class="header">Признак</text>`;
    importanceTable.forEach((row, rowIndex) => {
        const y = startY + (rowIndex + 1) * rowHeight;
        svg += `<text x="${startX - 10}" y="${y + 5}" text-anchor="end">${escapeHtml(row.label)}</text>`;
        row.values.forEach((value, colIndex) => {
            const x = startX + colIndex * cellWidth;
            const intensity = Math.round(255 - Math.min(200, value * 240));
            svg += `<rect x="${x}" y="${y - rowHeight + 4}" width="${cellWidth - 2}" height="${rowHeight - 6}" fill="rgb(255, ${intensity}, ${intensity})"/>`;
            svg += `<text x="${x + cellWidth / 2}" y="${y + 5}" text-anchor="middle">${value.toFixed(2)}</text>`;
        });
    });
    const labelY = startY + (importanceTable.length + 1) * rowHeight + 20;
    importanceTable.forEach((row, rowIndex) => {
        const x = startX + rowIndex * cellWidth + cellWidth / 2;
        svg += `<text x="${x}" y="${labelY}" text-anchor="middle" class="header">${escapeHtml(row.series)}</text>`;
    });
    svg += '</svg>';
    saveSvg(outputPath, svg);
}

function saveAccuracyPlot(outputPath, accuracyMap) {
    const width = 900;
    const height = 520;
    const padding = 100;
    const chartWidth = width - padding * 2;
    const chartHeight = height - padding * 2;
    const keys = Object.keys(accuracyMap);
    const values = keys.map(key => accuracyMap[key]);
    const maxValue = Math.max(0.4, ...values);
    const barWidth = Math.max(40, chartWidth / keys.length - 20);

    let svg = svgHeader(width, height);
    svg += `<style>text{font-family:Arial,Helvetica,sans-serif;font-size:12px;fill:#333;} .label{font-weight:700;} .axis line,.axis path{stroke:#ccc;stroke-width:1;} .grid line{stroke:#e4e4e4;stroke-dasharray:2,2;}</style>`;
    svg += `<rect width="${width}" height="${height}" fill="#fff"/>`;
    svg += `<text x="${width / 2}" y="30" text-anchor="middle" class="label">Точность классификации по критериям</text>`;

    for (let i = 0; i <= 5; i++) {
        const y = padding + (chartHeight * i) / 5;
        const value = maxValue - ((maxValue - 0) * i) / 5;
        svg += `<line x1="${padding}" y1="${y}" x2="${width - padding}" y2="${y}" class="grid"/>`;
        svg += `<text x="${padding - 10}" y="${y + 4}" text-anchor="end">${value.toFixed(2)}</text>`;
    }
    svg += `<g class="axis"><line x1="${padding}" y1="${padding}" x2="${padding}" y2="${padding + chartHeight}"/><line x1="${padding}" y1="${padding + chartHeight}" x2="${width - padding}" y2="${padding + chartHeight}"/></g>`;

    keys.forEach((key, index) => {
        const x = padding + 20 + index * (barWidth + 20);
        const barHeight = (accuracyMap[key] / maxValue) * chartHeight;
        const y = padding + chartHeight - barHeight;
        svg += `<rect x="${x}" y="${y}" width="${barWidth}" height="${barHeight}" fill="#3b82f6"/>`;
        svg += `<text x="${x + barWidth / 2}" y="${padding + chartHeight + 20}" text-anchor="middle">${escapeHtml(key)}</text>`;
        svg += `<text x="${x + barWidth / 2}" y="${y - 8}" text-anchor="middle">${accuracyMap[key].toFixed(2)}</text>`;
    });

    svg += '</svg>';
    saveSvg(outputPath, svg);
}

function transpose(matrix) {
    return matrix[0].map((_, colIndex) => matrix.map(row => row[colIndex]));
}

function simple2DProjection(points) {
    if (points.length === 0) return points;
    const dims = points[0].length;
    if (dims >= 2) {
        return points.map(row => [row[0], row[1]]);
    }
    return points.map(row => [row[0], 0]);
}

function buildImportanceMatrix(featureNames, importancesByTarget) {
    const series = Object.keys(importancesByTarget);
    const table = series.map(target => ({
        series: target,
        values: importancesByTarget[target].slice(0, featureNames.length),
    }));
    return table;
}

function main() {
    const args = parseArgs();
    if (!args.input || !args.out_dir) {
        console.error('Usage: node ml_analysis.js --input <dataset.csv> --out-dir <output-dir>');
        process.exit(1);
    }

    const datasetPath = path.resolve(args.input);
    const outputDir = path.resolve(args.out_dir);
    if (!fs.existsSync(datasetPath)) {
        throw new Error(`Input file not found: ${datasetPath}`);
    }
    fs.mkdirSync(outputDir, { recursive: true });

    const rawRows = readCsv(datasetPath);
    const rows = ensureRows(rawRows);
    if (rows.length === 0) {
        throw new Error('No valid rows found in CSV file');
    }

    const allRows = rows.map(row => {
        const result = {};
        Object.keys(row).forEach(key => {
            const mapped = mapRow({ [key]: row[key] });
            Object.assign(result, mapped);
        });
        return result;
    });

    const { matrix, featureNames } = buildFeatures(allRows);
    if (matrix.length === 0 || matrix[0].length === 0) {
        throw new Error('No features available for analysis');
    }

    const { normalized } = normalizeMatrix(matrix);
    const { bestK, ks, inertias, silhouettes } = chooseBestK(normalized);
    const clusterResult = kmeans(normalized.map(p => [...p]), bestK);
    const projected = simple2DProjection(normalized);

    saveLineChart(path.join(outputDir, OUTPUT_FILES.elbow), 'Анализ кластеров: Inertia + Silhouette', ks, [
        { label: 'Inertia', values: inertias, color: '#1f77b4' },
        { label: 'Silhouette', values: silhouettes.map(v => Number.isFinite(v) ? v * (Math.max(...inertias) / 2) : 0), color: '#ff7f0e' },
    ]);
    saveScatterPlot(path.join(outputDir, OUTPUT_FILES.clusters), 'Кластеризация зон', projected, clusterResult.labels);

    const targets = prepareTargets(allRows);
    const accuracyScores = {};
    const importanceByTarget = {};
    Object.entries(targets).forEach(([targetName, labels]) => {
        const validRows = allRows.map((row, index) => ({ row, label: labels[index] })).filter(item => item.label !== 'unknown');
        if (validRows.length < 10) return;
        const featureMatrix = validRows.map(item => matrix[allRows.indexOf(item.row)]);
        const labelValues = validRows.map(item => item.label);
        const { baseline, importances } = evaluateFeatureImportance(featureMatrix, labelValues, featureNames);
        accuracyScores[targetName] = baseline;
        importanceByTarget[targetName] = importances;
    });

    const importanceTable = buildImportanceMatrix(featureNames, importanceByTarget);
    if (importanceTable.length > 0) {
        saveFeatureImportancePlot(path.join(outputDir, OUTPUT_FILES.feature_importance), 'Важность признаков по критериям эргономики', featureNames, importanceTable);
    } else {
        saveSvg(path.join(outputDir, OUTPUT_FILES.feature_importance), svgHeader(900, 320) + `<rect width="900" height="320" fill="#fff"/><text x="450" y="170" text-anchor="middle" font-family="Arial,Helvetica,sans-serif" font-size="18">Недостаточно данных для оценки важности признаков</text></svg>`);
    }

    saveAccuracyPlot(path.join(outputDir, OUTPUT_FILES.classification_accuracy), accuracyScores);

    const result = {
        success: true,
        best_k: bestK,
        clusters: Array.from(new Set(clusterResult.labels)).length,
        accuracy: accuracyScores,
        graphs: {
            elbow: OUTPUT_FILES.elbow,
            clusters: OUTPUT_FILES.clusters,
            feature_importance: OUTPUT_FILES.feature_importance,
            classification_accuracy: OUTPUT_FILES.classification_accuracy,
        },
    };
    fs.writeFileSync(path.join(outputDir, OUTPUT_FILES.result), JSON.stringify(result, null, 2), 'utf8');
    console.log(JSON.stringify(result));
}

try {
    main();
} catch (error) {
    const result = { error: error.message || String(error) };
    fs.writeFileSync(path.join(process.cwd(), OUTPUT_FILES.result), JSON.stringify(result, null, 2), 'utf8');
    console.error(error);
    process.exit(1);
}
