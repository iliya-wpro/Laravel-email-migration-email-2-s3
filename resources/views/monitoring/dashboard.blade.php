<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="refresh" content="30">
    <title>Email Migration Monitor</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, sans-serif;
            background: #0f172a;
            color: #e2e8f0;
            line-height: 1.6;
            padding: 20px;
        }
        .container { max-width: 1400px; margin: 0 auto; }
        .header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 30px;
            padding-bottom: 20px;
            border-bottom: 1px solid #334155;
        }
        .header h1 { font-size: 24px; font-weight: 600; }
        .timestamp { color: #94a3b8; font-size: 14px; }
        .grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }
        .card {
            background: #1e293b;
            border-radius: 12px;
            padding: 24px;
            border: 1px solid #334155;
        }
        .card-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
        }
        .card-title {
            font-size: 16px;
            font-weight: 600;
            color: #94a3b8;
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }
        .status-badge {
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
            text-transform: uppercase;
        }
        .status-healthy { background: #065f46; color: #34d399; }
        .status-warning { background: #78350f; color: #fbbf24; }
        .status-critical { background: #7f1d1d; color: #f87171; }
        .status-pending { background: #1e3a5f; color: #60a5fa; }
        .status-completed { background: #065f46; color: #34d399; }
        .status-in_progress { background: #78350f; color: #fbbf24; }
        .metric {
            display: flex;
            justify-content: space-between;
            padding: 12px 0;
            border-bottom: 1px solid #334155;
        }
        .metric:last-child { border-bottom: none; }
        .metric-label { color: #94a3b8; }
        .metric-value { font-weight: 600; font-variant-numeric: tabular-nums; }
        .big-number {
            font-size: 48px;
            font-weight: 700;
            margin-bottom: 8px;
            font-variant-numeric: tabular-nums;
        }
        .big-label { color: #94a3b8; font-size: 14px; }
        .progress-bar {
            background: #334155;
            border-radius: 10px;
            height: 20px;
            overflow: hidden;
            margin: 20px 0;
        }
        .progress-fill {
            background: linear-gradient(90deg, #3b82f6, #8b5cf6);
            height: 100%;
            border-radius: 10px;
            transition: width 0.5s ease;
        }
        .progress-text {
            text-align: center;
            margin-top: 8px;
            color: #94a3b8;
        }
        .error-list {
            max-height: 400px;
            overflow-y: auto;
        }
        .error-item {
            background: #7f1d1d20;
            border: 1px solid #7f1d1d;
            border-radius: 8px;
            padding: 12px;
            margin-bottom: 10px;
        }
        .error-time {
            font-size: 12px;
            color: #f87171;
            margin-bottom: 4px;
        }
        .error-message {
            font-size: 13px;
            color: #fca5a5;
            word-break: break-word;
            font-family: monospace;
        }
        .indicator {
            width: 10px;
            height: 10px;
            border-radius: 50%;
            display: inline-block;
            margin-right: 8px;
        }
        .indicator-green { background: #34d399; box-shadow: 0 0 10px #34d399; }
        .indicator-red { background: #f87171; box-shadow: 0 0 10px #f87171; }
        .indicator-yellow { background: #fbbf24; box-shadow: 0 0 10px #fbbf24; }
        .full-width { grid-column: 1 / -1; }
        .two-col { grid-template-columns: 1fr 1fr; }
        @media (max-width: 768px) {
            .grid { grid-template-columns: 1fr; }
            .two-col { grid-template-columns: 1fr; }
        }
        .refresh-notice {
            text-align: center;
            color: #64748b;
            font-size: 12px;
            margin-top: 20px;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>Email Migration Monitor</h1>
            <div class="timestamp">Last updated: {{ now()->format('Y-m-d H:i:s') }} UTC</div>
        </div>

        <div class="grid">
            <!-- Queue Health -->
            <div class="card">
                <div class="card-header">
                    <span class="card-title">Queue Status</span>
                    <span class="status-badge status-{{ $stats['queue']['queue_health'] }}">
                        {{ $stats['queue']['queue_health'] }}
                    </span>
                </div>
                <div class="big-number">{{ number_format($stats['queue']['pending']) }}</div>
                <div class="big-label">Jobs in Queue</div>
                <div class="metric">
                    <span class="metric-label">Failed Jobs</span>
                    <span class="metric-value" style="color: {{ $stats['queue']['failed'] > 0 ? '#f87171' : '#34d399' }}">
                        {{ number_format($stats['queue']['failed']) }}
                    </span>
                </div>
                <div class="metric">
                    <span class="metric-label">Oldest Job Age</span>
                    <span class="metric-value">{{ $stats['queue']['oldest_job_age'] }} min</span>
                </div>
            </div>

            <!-- Migration Progress -->
            <div class="card">
                <div class="card-header">
                    <span class="card-title">Migration Progress</span>
                    <span class="status-badge status-{{ $stats['migration']['status'] }}">
                        {{ str_replace('_', ' ', $stats['migration']['status']) }}
                    </span>
                </div>
                <div class="big-number">{{ $stats['migration']['success_rate'] }}%</div>
                <div class="big-label">Success Rate</div>
                <div class="progress-bar">
                    <div class="progress-fill" style="width: {{ $stats['migration']['success_rate'] }}%"></div>
                </div>
                <div class="progress-text">
                    {{ number_format($stats['migration']['processed']) }} / {{ number_format($stats['migration']['total_emails']) }} emails processed
                </div>
            </div>

            <!-- Performance -->
            <div class="card">
                <div class="card-header">
                    <span class="card-title">Performance</span>
                </div>
                <div class="big-number">{{ $stats['performance']['emails_per_minute'] }}</div>
                <div class="big-label">Emails per Minute</div>
                <div class="metric">
                    <span class="metric-label">Elapsed Time</span>
                    <span class="metric-value">{{ $stats['performance']['elapsed_time'] }} min</span>
                </div>
                <div class="metric">
                    <span class="metric-label">Est. Completion</span>
                    <span class="metric-value">
                        @if($stats['performance']['estimated_completion'])
                            {{ $stats['performance']['estimated_completion'] }} min
                        @else
                            N/A
                        @endif
                    </span>
                </div>
            </div>

            <!-- System Health -->
            <div class="card">
                <div class="card-header">
                    <span class="card-title">System Health</span>
                </div>
                <div class="metric">
                    <span class="metric-label">
                        <span class="indicator {{ $stats['system']['db_connection'] ? 'indicator-green' : 'indicator-red' }}"></span>
                        Database
                    </span>
                    <span class="metric-value">{{ $stats['system']['db_connection'] ? 'Connected' : 'Disconnected' }}</span>
                </div>
                <div class="metric">
                    <span class="metric-label">
                        <span class="indicator {{ $stats['system']['storage_writable'] ? 'indicator-green' : 'indicator-red' }}"></span>
                        Storage
                    </span>
                    <span class="metric-value">{{ $stats['system']['storage_writable'] ? 'Writable' : 'Read-only' }}</span>
                </div>
                <div class="metric">
                    <span class="metric-label">PHP Version</span>
                    <span class="metric-value">{{ $stats['system']['php_version'] }}</span>
                </div>
                <div class="metric">
                    <span class="metric-label">Laravel Version</span>
                    <span class="metric-value">{{ $stats['system']['laravel_version'] }}</span>
                </div>
                <div class="metric">
                    <span class="metric-label">Memory Usage</span>
                    <span class="metric-value">{{ $stats['system']['memory_usage'] }}</span>
                </div>
                <div class="metric">
                    <span class="metric-label">Server Uptime</span>
                    <span class="metric-value">{{ $stats['system']['uptime'] }}</span>
                </div>
            </div>

            <!-- Migration Details -->
            <div class="card full-width">
                <div class="card-header">
                    <span class="card-title">Migration Details</span>
                </div>
                <div class="grid two-col">
                    <div>
                        <div class="metric">
                            <span class="metric-label">Total Emails</span>
                            <span class="metric-value">{{ number_format($stats['migration']['total_emails']) }}</span>
                        </div>
                        <div class="metric">
                            <span class="metric-label">Processed</span>
                            <span class="metric-value" style="color: #34d399">{{ number_format($stats['migration']['processed']) }}</span>
                        </div>
                        <div class="metric">
                            <span class="metric-label">Failed</span>
                            <span class="metric-value" style="color: #f87171">{{ number_format($stats['migration']['failed']) }}</span>
                        </div>
                    </div>
                    <div>
                        <div class="metric">
                            <span class="metric-label">Started At</span>
                            <span class="metric-value">{{ $stats['migration']['started_at'] ?? 'N/A' }}</span>
                        </div>
                        <div class="metric">
                            <span class="metric-label">Completed At</span>
                            <span class="metric-value">{{ $stats['migration']['completed_at'] ?? 'In Progress' }}</span>
                        </div>
                        @if(isset($stats['migration']['last_error']) && $stats['migration']['last_error'])
                        <div class="metric">
                            <span class="metric-label">Last Error</span>
                            <span class="metric-value" style="color: #f87171; font-size: 12px;">
                                {{ Str::limit($stats['migration']['last_error'], 100) }}
                            </span>
                        </div>
                        @endif
                    </div>
                </div>
            </div>

            <!-- Recent Errors -->
            @if(count($stats['recent_errors']) > 0)
            <div class="card full-width">
                <div class="card-header">
                    <span class="card-title">Recent Errors</span>
                    <span class="status-badge status-critical">{{ count($stats['recent_errors']) }} errors</span>
                </div>
                <div class="error-list">
                    @foreach($stats['recent_errors'] as $error)
                    <div class="error-item">
                        <div class="error-time">{{ $error['failed_at'] }} - Queue: {{ $error['queue'] }}</div>
                        <div class="error-message">{{ $error['exception'] }}</div>
                    </div>
                    @endforeach
                </div>
            </div>
            @endif
        </div>

        <div class="refresh-notice">
            Auto-refreshes every 30 seconds | Read-only monitoring dashboard
        </div>
    </div>
</body>
</html>
