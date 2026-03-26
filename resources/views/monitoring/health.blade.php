<!DOCTYPE html>
<html lang="nl">
<head>
    <meta charset="UTF-8">
    <title>Speedcheck Health Dashboard</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            background-color: #0f1724;
            color: #f8fafc;
            margin: 0;
            padding: 24px;
        }

        .health-actions {
            display: flex;
            gap: 12px;
            align-items: center;
            margin-bottom: 24px;
        }

        .health-actions form button {
            background: #10b981;
            border: none;
            border-radius: 6px;
            color: #0f172a;
            padding: 10px 18px;
            cursor: pointer;
            font-weight: 600;
        }

        .health-placeholder {
            background: rgba(15, 23, 42, 0.6);
            border-radius: 8px;
            padding: 20px;
            color: #94a3b8;
            margin-bottom: 32px;
        }

        h1, h2 {
            margin-top: 0;
        }

        .container {
            max-width: 1200px;
            margin: 0 auto;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 32px;
            background: #111f33;
            border-radius: 8px;
            overflow: hidden;
        }

        th, td {
            padding: 12px 14px;
            border-bottom: 1px solid rgba(255, 255, 255, 0.08);
            text-align: left;
        }

        th {
            background: rgba(255, 255, 255, 0.05);
            text-transform: uppercase;
            font-size: 12px;
            letter-spacing: 0.08em;
        }

        tr:last-child td {
            border-bottom: none;
        }

        .badge {
            display: inline-block;
            padding: 4px 10px;
            border-radius: 999px;
            font-size: 12px;
            font-weight: 600;
        }

        .badge-ok {
            background: rgba(16, 185, 129, 0.2);
            color: #2dd4bf;
        }

        .badge-warning {
            background: rgba(234, 179, 8, 0.2);
            color: #facc15;
        }

        .badge-error {
            background: rgba(248, 113, 113, 0.2);
            color: #f87171;
        }

        .messages {
            font-size: 13px;
            color: #94a3b8;
        }

        .meta {
            font-size: 12px;
            color: #64748b;
        }

        .timestamp {
            margin-bottom: 24px;
            color: #94a3b8;
        }

        a {
            color: #60a5fa;
        }

        .lookup-card {
            background: #111f33;
            border-radius: 8px;
            padding: 20px;
            margin-bottom: 32px;
        }

        .lookup-form {
            display: flex;
            flex-wrap: wrap;
            gap: 12px;
            align-items: flex-end;
        }

        .lookup-form label {
            display: block;
            font-size: 12px;
            text-transform: uppercase;
            letter-spacing: 0.08em;
            color: #94a3b8;
            margin-bottom: 4px;
        }

        .lookup-form input {
            background: rgba(15, 23, 42, 0.7);
            border: 1px solid rgba(148, 163, 184, 0.4);
            border-radius: 6px;
            color: #f8fafc;
            padding: 10px 12px;
            width: 140px;
        }

        .lookup-form button {
            background: #2563eb;
            border: none;
            border-radius: 6px;
            color: #f8fafc;
            padding: 10px 18px;
            cursor: pointer;
            font-weight: 600;
        }

        .lookup-form button:disabled {
            opacity: 0.6;
            cursor: wait;
        }

        #lookup-error {
            color: #f87171;
            margin-top: 12px;
        }

        #lookup-results table {
            margin-top: 16px;
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>Speedcheck Health Dashboard</h1>
        <div class="health-actions">
            <form method="GET" action="{{ route('monitoring.health') }}">
                <input type="hidden" name="run" value="1">
                <button type="submit">Run healthcheck</button>
            </form>
            @if($generatedAt)
                <p class="timestamp">Laatste run: {{ $generatedAt->format('d-m-Y H:i:s') }}</p>
            @endif
        </div>

        <div class="lookup-card">
            <h2>Provider lookup</h2>
            <form id="lookup-form" class="lookup-form">
                <div>
                    <label for="lookup-postcode">Postcode</label>
                    <input id="lookup-postcode" name="postcode" type="text" maxlength="7" placeholder="2725DN" required>
                </div>
                <div>
                    <label for="lookup-number">Huisnummer</label>
                    <input id="lookup-number" name="number" type="text" placeholder="27" required>
                </div>
                <div>
                    <label for="lookup-extension">Toevoeging</label>
                    <input id="lookup-extension" name="extension" type="text" placeholder="(optioneel)">
                </div>
                <div>
                    <button id="lookup-submit" type="submit">Check provider(s)</button>
                </div>
            </form>
            <div id="lookup-error"></div>
            <div id="lookup-results"></div>
        </div>

        @if(!$hasRun)
            <div class="health-placeholder">
                Healthcheck nog niet uitgevoerd. Klik op “Run healthcheck” om provider- en tokenstatus te laden.
            </div>
        @else
            <h2>Providers</h2>
            <table>
                <thead>
                    <tr>
                        <th>Provider</th>
                        <th>Status</th>
                        <th>Adres</th>
                        <th>Expects</th>
                        <th>Gemeten download</th>
                        <th>Berichten</th>
                        <th>Duur (ms)</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($report['providers'] as $providerKey => $provider)
                        <tr>
                            <td>{{ $providerKey }}</td>
                            <td>
                                @php
                                    $badgeClass = match ($provider['status']) {
                                        'ok' => 'badge-ok',
                                        'warning' => 'badge-warning',
                                        default => 'badge-error',
                                    };
                                @endphp
                                <span class="badge {{ $badgeClass }}">{{ strtoupper($provider['status']) }}</span>
                            </td>
                            <td>
                                {{ $provider['address']->postcode }}
                                {{ $provider['address']->number }}
                                {{ $provider['address']->extension }}
                            </td>
                            <td>{{ implode(', ', $provider['expects']) }}</td>
                            <td>
                                @php
                                    $download = $provider['download'];
                                @endphp
                                kabel: {{ $download['kabel'] ?? '—' }} |
                                glasvezel: {{ $download['glasvezel'] ?? '—' }} |
                                dsl: {{ $download['dsl'] ?? '—' }}
                            </td>
                            <td class="messages">
                                @if(!empty($provider['messages']))
                                    @foreach($provider['messages'] as $message)
                                        <div>• {{ $message }}</div>
                                    @endforeach
                                @elseif(($provider['meta']['circuit']['state'] ?? null) === 'open')
                                    <div>• Circuit open ({{ $provider['meta']['circuit']['retry_after_seconds'] ?? 'cooldown' }}s)</div>
                                @else
                                    <span>geen</span>
                                @endif
                            </td>
                            <td class="meta">
                                {{ $provider['meta']['duration_ms'] ?? '—' }}
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>

            <h2>Tokens</h2>
            <table>
                <thead>
                    <tr>
                        <th>Provider</th>
                        <th>Status</th>
                        <th>Resttijd (min)</th>
                        <th>Bericht</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($report['tokens'] as $token)
                        @php
                            $badgeClass = $token['status'] === 'ok' ? 'badge-ok' : 'badge-warning';
                        @endphp
                        <tr>
                            <td>{{ $token['label'] }}</td>
                            <td><span class="badge {{ $badgeClass }}">{{ strtoupper($token['status']) }}</span></td>
                            <td>{{ $token['minutes_left'] ?? '—' }}</td>
                            <td class="messages">{{ $token['message'] }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif

        <p class="meta">
            Tip: Stel <code>MONITOR_USERNAME</code> en <code>MONITOR_PASSWORD</code> in je <code>.env</code> in om basic auth te activeren.
        </p>
    </div>
    @include('monitoring.partials.scripts')
</body>
</html>

