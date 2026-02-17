import { Chart, LineController, LineElement, PointElement, LinearScale, TimeScale, Tooltip, Filler } from 'chart.js';
import 'chartjs-adapter-luxon';

// Register Chart.js components
Chart.register(LineController, LineElement, PointElement, LinearScale, TimeScale, Tooltip, Filler);

// Generate random stub data for a chart
function generateStubData() {
    const now = Date.now();
    const points = [];
    const minArr = [];
    const maxArr = [];
    const lastArr = [];

    // Generate 20 data points over the last hour
    for (let i = 0; i < 20; i++) {
        const time = now - (19 - i) * 3 * 60 * 1000; // 3-minute intervals
        const baseValue = 50 + Math.random() * 20;
        const variance = 5 + Math.random() * 5;

        points.push(time);
        minArr.push(baseValue - variance);
        maxArr.push(baseValue + variance);
        lastArr.push(baseValue + (Math.random() - 0.5) * variance);
    }

    return { labels: points, minArr, maxArr, lastArr };
}

// Custom plugin to draw vertical wicks from min to max with neon styling
const rangeWicksPlugin = {
    id: 'rangeWicks',
    afterDatasetsDraw(chart) {
        const dsMin = chart.data.datasets[0];
        const dsMax = chart.data.datasets[1];
        const dsLast = chart.data.datasets[2];
        const x = chart.scales.x;
        const y = chart.scales.y;

        if (!dsMin || !dsMax || !dsLast) return;

        // Determine color based on trend
        const firstValue = dsLast.data[0];
        const lastValue = dsLast.data[dsLast.data.length - 1];
        const isPositive = lastValue >= firstValue;

        const ctx = chart.ctx;
        ctx.save();
        ctx.globalAlpha = 0.3;
        ctx.strokeStyle = isPositive ? '#10b981' : '#ef4444';
        ctx.lineWidth = 1;

        const n = Math.min(dsMin.data.length, dsMax.data.length);
        for (let i = 0; i < n; i++) {
            const xi = x.getPixelForValue(x.getLabelForValue(i));
            const yMin = y.getPixelForValue(dsMin.data[i]);
            const yMax = y.getPixelForValue(dsMax.data[i]);

            if (!isFinite(xi) || !isFinite(yMin) || !isFinite(yMax)) continue;

            ctx.beginPath();
            ctx.moveTo(xi, yMin);
            ctx.lineTo(xi, yMax);
            ctx.stroke();
        }
        ctx.restore();
    }
};

// Custom plugin to draw a hollow circle and trigger HTML tooltip
const hoverPointPlugin = {
    id: 'hoverPoint',
    afterDatasetsDraw(chart) {
        const callbacks = chart.config.options.tooltipCallbacks;

        if (chart.tooltip?._active?.length) {
            // Find the main line dataset (index 2) in active points
            const mainLinePoint = chart.tooltip._active.find(point => point.datasetIndex === 2);

            if (!mainLinePoint) {
                // Hide tooltip if no main line point
                if (callbacks?.onTooltipHide) {
                    callbacks.onTooltipHide();
                }
                return;
            }

            const ctx = chart.ctx;
            const x = mainLinePoint.element.x;
            const y = mainLinePoint.element.y;

            // Get color from chart config (stored as positionType)
            const positionType = chart.config.options.positionType;
            const color = (positionType && positionType.toLowerCase() === 'long') ? '#10b981' : '#ef4444';

            ctx.save();

            // Draw hollow circle (stroke only)
            ctx.beginPath();
            ctx.arc(x, y, 6, 0, 2 * Math.PI);
            ctx.strokeStyle = color;
            ctx.lineWidth = 2;
            ctx.stroke();

            ctx.restore();

            // Get the price value and timestamp
            const dataIndex = mainLinePoint.index;
            const price = chart.data.datasets[2].data[dataIndex];
            const timestamp = chart.data.labels[dataIndex];

            // Format price and time
            const priceText = typeof price === 'number' ? price.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) : price.toString();
            const timeText = new Date(timestamp).toLocaleTimeString('en-US', {
                hour: '2-digit',
                minute: '2-digit',
                hour12: false
            });

            // Trigger HTML tooltip callback
            if (callbacks?.onTooltipShow) {
                callbacks.onTooltipShow(x, y, priceText, timeText);
            }
        } else {
            // Hide tooltip when not hovering
            if (callbacks?.onTooltipHide) {
                callbacks.onTooltipHide();
            }
        }
    }
};

Chart.register(rangeWicksPlugin, hoverPointPlugin);

// Initialize a chart for a canvas element
function initChart(canvasId, chartData = null, positionType = null, tooltipCallbacks = null) {
    const canvas = document.getElementById(canvasId);
    if (!canvas) return;

    // Destroy existing chart if it exists
    const existingChart = Chart.getChart(canvas);
    if (existingChart) {
        existingChart.destroy();
    }

    // Convert new chart format [{timestamp, mark_price}] to old format if provided
    let labels, minArr, maxArr, lastArr;

    if (chartData && Array.isArray(chartData) && chartData.length > 0 && chartData[0].timestamp) {
        // New format: array of {timestamp, mark_price} objects
        labels = chartData.map(tick => tick.timestamp);
        lastArr = chartData.map(tick => tick.mark_price);

        // Calculate min/max bands from mark_price (±2% variance)
        minArr = lastArr.map(price => price * 0.98);
        maxArr = lastArr.map(price => price * 1.02);
    } else if (chartData && chartData.labels) {
        // Old format: {labels, minArr, maxArr, lastArr}
        ({ labels, minArr, maxArr, lastArr } = chartData);
    } else {
        // Generate stub data if no data provided
        ({ labels, minArr, maxArr, lastArr } = generateStubData());
    }

    const allVals = [...minArr, ...maxArr, ...lastArr].filter(v => Number.isFinite(v));
    const yMin = allVals.length ? Math.min(...allVals) : undefined;
    const yMax = allVals.length ? Math.max(...allVals) : undefined;
    const pad = allVals.length ? (yMax - yMin) * 0.03 : 0;

    // Determine color based on position type
    // LONG = green, SHORT = red
    let isPositive;
    if (positionType === 'LONG') {
        isPositive = true; // GREEN
    } else if (positionType === 'SHORT') {
        isPositive = false; // RED
    } else {
        // Fallback to trend-based color
        const firstValue = lastArr[0];
        const lastValue = lastArr[lastArr.length - 1];
        isPositive = lastValue >= firstValue;
    }

    // Neon gradient colors
    const lineColor = isPositive ? '#10b981' : '#ef4444';
    const glowColor = isPositive ? 'rgba(16, 185, 129, 0.5)' : 'rgba(239, 68, 68, 0.5)';

    // Create gradient for the main line area fill
    const ctx = canvas.getContext('2d');
    const gradientFill = ctx.createLinearGradient(0, 0, 0, canvas.height);
    if (isPositive) {
        gradientFill.addColorStop(0, 'rgba(16, 185, 129, 0.3)');
        gradientFill.addColorStop(0.5, 'rgba(16, 185, 129, 0.15)');
        gradientFill.addColorStop(1, 'rgba(16, 185, 129, 0)');
    } else {
        gradientFill.addColorStop(0, 'rgba(239, 68, 68, 0.3)');
        gradientFill.addColorStop(0.5, 'rgba(239, 68, 68, 0.15)');
        gradientFill.addColorStop(1, 'rgba(239, 68, 68, 0)');
    }

    // Create gradient for the band
    const bandGradient = ctx.createLinearGradient(0, 0, 0, canvas.height);
    if (isPositive) {
        bandGradient.addColorStop(0, 'rgba(16, 185, 129, 0.08)');
        bandGradient.addColorStop(1, 'rgba(16, 185, 129, 0.02)');
    } else {
        bandGradient.addColorStop(0, 'rgba(239, 68, 68, 0.08)');
        bandGradient.addColorStop(1, 'rgba(239, 68, 68, 0.02)');
    }

    return new Chart(canvas, {
        type: 'line',
        data: {
            labels,
            datasets: [
                {
                    data: minArr,
                    pointRadius: 0,
                    borderWidth: 0,
                    tension: 0.2
                },
                {
                    data: maxArr,
                    pointRadius: 0,
                    borderWidth: 0,
                    tension: 0.2,
                    fill: '-1',
                    backgroundColor: bandGradient
                },
                {
                    data: lastArr,
                    pointRadius: 0,
                    borderWidth: 2.5,
                    tension: 0.3,
                    borderColor: lineColor,
                    fill: true,
                    backgroundColor: gradientFill,
                    shadowOffsetX: 0,
                    shadowOffsetY: 0,
                    shadowBlur: 10,
                    shadowColor: glowColor
                }
            ]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            positionType: positionType, // Store for hover plugin
            tooltipCallbacks: tooltipCallbacks, // Store callbacks for HTML tooltip
            animation: {
                duration: 800,
                easing: 'easeInOutQuart'
            },
            interaction: {
                mode: 'index',
                intersect: false,
                axis: 'x'
            },
            scales: {
                x: {
                    type: 'time',
                    time: {
                        unit: 'minute',
                        displayFormats: {
                            minute: 'HH:mm'
                        }
                    },
                    grid: {
                        display: false
                    },
                    ticks: {
                        display: false
                    }
                },
                y: {
                    grid: {
                        display: false
                    },
                    ticks: {
                        display: false
                    },
                    suggestedMin: yMin !== undefined ? (yMin - pad) : undefined,
                    suggestedMax: yMax !== undefined ? (yMax + pad) : undefined
                }
            },
            plugins: {
                legend: {
                    display: false
                },
                tooltip: {
                    enabled: false
                }
            }
        }
    });
}

// Initialize all charts on page load
document.addEventListener('DOMContentLoaded', () => {
    const canvases = document.querySelectorAll('canvas[id^="chart-"]');
    canvases.forEach(canvas => {
        initChart(canvas.id);
    });
});

// Make initChart globally available
window.initChart = initChart;
