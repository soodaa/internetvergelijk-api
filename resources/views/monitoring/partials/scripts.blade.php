<script>
    const lookupForm = document.getElementById('lookup-form');
    const lookupResults = document.getElementById('lookup-results');
    const lookupError = document.getElementById('lookup-error');
    const lookupSubmit = document.getElementById('lookup-submit');

    if (lookupForm) {
        lookupForm.addEventListener('submit', async (event) => {
            event.preventDefault();

            lookupError.textContent = '';
            lookupResults.innerHTML = '';
            lookupSubmit.disabled = true;
            lookupSubmit.textContent = 'Bezig...';

            const formData = new FormData(lookupForm);
            const params = new URLSearchParams(formData);

            try {
            const response = await fetch(`{{ route('monitoring.lookup') }}?${params.toString()}`, {
                    headers: {
                        'X-Requested-With': 'XMLHttpRequest'
                    }
                });

                lookupSubmit.disabled = false;
                lookupSubmit.textContent = 'Check provider(s)';

                if (!response.ok) {
                    const text = await response.text();
                    lookupError.textContent = text || 'Kon providers niet ophalen';
                    return;
                }

                const payload = await response.json();

                if (!payload.providers || payload.providers.length === 0) {
                    lookupError.textContent = 'Geen resultaten voor deze combinatie.';
                    return;
                }

                lookupResults.innerHTML = `
                    <table>
                        <thead>
                            <tr>
                                <th>Provider</th>
                                <th>Status</th>
                                <th>Download (dsl)</th>
                                <th>Download (glasvezel)</th>
                                <th>Download (kabel)</th>
                                <th>Duur (ms)</th>
                                <th>Notities</th>
                            </tr>
                        </thead>
                        <tbody>
                            ${payload.providers.map(row => {
                                const circuit = row.meta?.circuit?.state === 'open'
                                    ? `Circuit open (${row.meta.circuit.retry_after_seconds ?? '?'}s)`
                                    : '';
                                const retried = row.meta?.retry_attempts
                                    ? `Retry pogingen: ${row.meta.retry_attempts}`
                                    : '';
                                const message = row.message || circuit || retried
                                    ? [row.message, circuit, retried].filter(Boolean).join(' • ')
                                    : '—';

                                return `
                                    <tr>
                                        <td>${row.provider}</td>
                                        <td><span class="badge ${row.status === 'success' ? 'badge-ok' : row.status === 'warning' ? 'badge-warning' : 'badge-error'}">${row.status.toUpperCase()}</span></td>
                                        <td>${row.download.dsl ?? '—'}</td>
                                        <td>${row.download.glasvezel ?? '—'}</td>
                                        <td>${row.download.kabel ?? '—'}</td>
                                        <td>${row.meta.duration_ms ?? '—'}</td>
                                        <td>${message}</td>
                                    </tr>
                                `;
                            }).join('')}
                        </tbody>
                    </table>
                `;
            } catch (error) {
                lookupSubmit.disabled = false;
                lookupSubmit.textContent = 'Check provider(s)';
                lookupError.textContent = error.message || 'Onbekende fout tijdens ophalen.';
            }
        });
    }
</script>

