document.addEventListener("DOMContentLoaded", function () {

    console.log(le_ajax_obj.ajax_url); // Debug: Check if the localized variable is available

    document.getElementById('toggleApiKey').addEventListener('click', function (e) {
        e.preventDefault();
        const input = document.getElementById('le_api_key');
        input.type = input.type === 'password' ? 'text' : 'password';
    });

    document.getElementById('le_column_name').addEventListener('input', function () {
        this.value = this.value.toUpperCase();
    });

    document.getElementById('saveBtn').addEventListener('click', function () {
        const btn = this;
        const status = document.getElementById('saveStatus');
        btn.disabled = true;
        btn.innerText = 'Saving...';
        status.style.display = 'none';

        fetch(le_ajax_obj.ajax_url, {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: new URLSearchParams({
                action: 'le_save_settings',
                le_source_sheet_id: document.getElementById('le_source_sheet_id').value.trim(),
                le_target_sheet_id: document.getElementById('le_target_sheet_id').value.trim(),
                le_api_key: document.getElementById('le_api_key').value.trim(),
                le_column_name: document.getElementById('le_column_name').value.trim().toUpperCase(),
                _wpnonce: le_ajax_obj.nonce
            })
        })
            .then(res => res.json())
            .then(data => {
                console.log('data', data);
                status.style.display = 'inline';
                status.innerText = data.success ? '✅ Saved!' : '❌ Failed';
                status.style.color = data.success ? 'green' : 'red';
                btn.disabled = false;
                btn.innerText = '💾 Save Settings';
            });
    });

    function log(msg, color = '#1565c0') {
        document.getElementById('debug').innerHTML +=
            '<span style="color:' + color + '">' + new Date().toLocaleTimeString() + ': ' + msg + '</span><br>';
        document.getElementById('debug').scrollTop = document.getElementById('debug').scrollHeight;
    }

    document.getElementById('runBtn').addEventListener('click', function () {
        document.getElementById('status').innerText = '⏳ Processing all emails...';
        document.getElementById('status').style.color = 'blue';
        document.getElementById('progress').innerText = '';
        document.getElementById('debug').innerHTML = '';
        document.getElementById('summaryBox').style.display = 'none';
        document.getElementById('errorBox').style.display = 'none';
        log('🔄 Starting full extraction...');
        this.disabled = true;
        this.innerText = '⏳ Running...';

        const btn = this;

        fetch(le_ajax_obj.ajax_url, {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: 'action=le_run_process'
        })
            .then(res => res.text())
            .then(raw => {
                let data = {};
                try { data = JSON.parse(raw); } catch (e) { data = { message: raw }; }

                console.log('Response:', data);

                // Summary দেখাও
                if (data.summary) {
                    const s = data.summary;
                    document.getElementById('summaryBox').style.display = 'block';
                    document.getElementById('summaryContent').innerHTML = `
                        <table style="border-collapse:collapse;width:100%;">
                            <tr><td style="padding:4px 8px;">📧 Total Emails</td><td><strong>${s.total_emails}</strong></td></tr>
                            <tr><td style="padding:4px 8px;color:green;">✅ Success Domains</td><td><strong>${s.success_domains}</strong></td></tr>
                            <tr><td style="padding:4px 8px;color:red;">❌ Failed Domains</td><td><strong>${s.failed_domains}</strong></td></tr>
                            <tr><td style="padding:4px 8px;color:orange;">⏭️ Skipped (Duplicate)</td><td><strong>${s.skipped_domains}</strong></td></tr>
                            <tr><td style="padding:4px 8px;color:blue;">👤 Persons Written</td><td><strong>${s.total_persons}</strong></td></tr>
                        </table>
                    `;
                }

                // Errors দেখাও
                if (data.errors && data.errors.length > 0) {
                    document.getElementById('errorBox').style.display = 'block';
                    document.getElementById('errorContent').innerHTML =
                        data.errors.map(e => '<div style="margin-bottom:4px;color:#c0392b;">⚠️ ' + e + '</div>').join('');
                }

                document.getElementById('status').innerText = '✅ Completed';
                document.getElementById('status').style.color = 'green';
                document.getElementById('progress').innerHTML = '<strong style="color:green">' + (data.message || 'Done') + '</strong>';
                log('✅ Extraction complete!', 'green');

                btn.disabled = false;
                btn.innerText = '🚀 Run All Emails';
            })
            .catch(err => {
                log('❌ Error: ' + err, 'red');
                document.getElementById('status').innerText = '❌ Error';
                document.getElementById('status').style.color = 'red';
                btn.disabled = false;
                btn.innerText = '🚀 Run All Emails';
            });
    });

});