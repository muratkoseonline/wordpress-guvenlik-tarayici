// guvenlik-tarayici/scanner.js
document.addEventListener('DOMContentLoaded', function () {
    const appContainer = document.getElementById('scanner-app-container');
    if (!appContainer) return; // Eğer doğru sayfada değilsek çalıştırma

    const startBtn = document.getElementById('start-scan-btn');
    const progressContainer = document.getElementById('progress-container');
    const progressBar = document.getElementById('progress-bar-inner');
    const statusText = document.getElementById('status-text');
    const resultsContainer = document.getElementById('scan-results');
    const startArea = document.getElementById('start-area');

    const tasks = [
        { name: 'core_check', description: 'Adım 1/3: Çekirdek dosyalar kontrol ediliyor...' },
        { name: 'suspicious_scan', description: 'Adım 2/3: Şüpheli kod kalıpları taranıyor...' },
        { name: 'unknown_files_scan', description: 'Adım 3/3: Bilinmeyen dosyalar aranıyor...' }
    ];
    let currentTaskIndex = 0;
    let finalResults = {}; 

    startBtn.addEventListener('click', function() {
        startBtn.style.display = 'none';
        progressContainer.style.display = 'block';
        runNextTask();
    });

    async function runNextTask() {
        if (currentTaskIndex >= tasks.length) {
            updateProgress(100, 'Tarama Tamamlandı!');
            displayFinalResults();
            return;
        }
        const task = tasks[currentTaskIndex];
        statusText.textContent = task.description;

        // WordPress AJAX isteği için veri hazırlama
        const formData = new FormData();
        formData.append('action', 'gt_run_scan_task'); // PHP'deki wp_ajax_ hook'u
        formData.append('nonce', scanner_ajax_object.nonce); // Güvenlik anahtarı
        formData.append('task', task.name);

        try {
            const response = await fetch(scanner_ajax_object.ajax_url, {
                method: 'POST',
                body: formData
            });
            const result = await response.json();
            
            if (result.success) {
                finalResults[task.name] = result.data;
            } else {
                finalResults[task.name] = { error: result.data.message || 'Bilinmeyen bir hata oluştu.' };
            }
        } catch (error) {
            finalResults[task.name] = { error: 'Sunucuyla iletişim kurulamadı: ' + error.message };
        }

        currentTaskIndex++;
        const progressPercentage = Math.round((currentTaskIndex / tasks.length) * 100);
        updateProgress(progressPercentage, task.description);
        setTimeout(runNextTask, 250);
    }
    
    // Rapor gösterme ve ilerleme çubuğu fonksiyonları (değişiklik yok)
    function updateProgress(percentage, text) { /* ... */ }
    function displayFinalResults() { /* ... */ }
    function escapeHTML(str) { /* ... */ }

    // Raporlama fonksiyonlarını kopyala-yapıştır
    updateProgress = function(percentage, text) {
        progressBar.style.width = percentage + '%';
        progressBar.textContent = percentage + '%';
        if (percentage < 100) {
             statusText.textContent = text;
        } else {
             statusText.textContent = "Rapor oluşturuluyor...";
        }
    }

    displayFinalResults = function() {
        let html = '<h2><span class="status-info">&#9432;</span> Tarama Raporu</h2>';
        const unknown = finalResults.unknown_files_scan;
        html += '<h2><span class="status-error">&#9888;</span> Sunucudaki Bilinmeyen Dosyalar</h2>';
        if (unknown.error) {
            html += `<p class="status-error">Tarama sırasında hata: ${escapeHTML(unknown.error)}</p>`;
        } else if (!unknown.unknown_files || unknown.unknown_files.length === 0) {
            html += '<p class="status-ok">Harika! WordPress çekirdek klasörlerinde bilinmeyen dosya bulunamadı.</p>';
        } else {
            html += `<div class="summary-box summary-danger"><strong>DİKKAT! ${unknown.unknown_files.length}</strong> adet bilinmeyen dosya bulundu.</div>`;
            html += '<ul class="file-list">';
            unknown.unknown_files.forEach(file => { html += `<li>${escapeHTML(file)}</li>`; });
            html += '</ul>';
        }
        const suspicious = finalResults.suspicious_scan;
        html += '<h2><span class="status-error">&#9888;</span> Yüksek Şüpheli Kod İçeren Dosyalar (wp-content)</h2>';
        if (suspicious.error) {
            html += `<p class="status-error">Tarama sırasında hata: ${escapeHTML(suspicious.error)}</p>`;
        } else if (!suspicious.suspicious_files || suspicious.suspicious_files.length === 0) {
            html += '<p class="status-ok">Harika! Yüksek derecede şüpheli kod kalıbına sahip dosya bulunamadı.</p>';
        } else {
            html += `<div class="summary-box summary-warning"><strong>${suspicious.suspicious_files.length}</strong> adet dosyada yüksek şüpheli kod bulundu.</div>`;
            html += '<ul class="file-list">';
            suspicious.suspicious_files.forEach(file => { html += `<li>${escapeHTML(file)}</li>`; });
            html += '</ul>';
        }
        const core = finalResults.core_check;
        html += '<h2><span class="status-warning">&#9888;</span> WordPress Çekirdek Dosya Durumu</h2>';
         if (core.error) {
            html += `<p class="status-error">Tarama sırasında hata: ${escapeHTML(core.error)}</p>`;
        } else {
            html += `<div class="summary-box summary-info"><strong>${core.ok_count}</strong> adet çekirdek dosya doğrulandı ve temiz.</div>`;
            if(core.modified.length > 0) html += `<div class="summary-box summary-danger"><strong>${core.modified.length}</strong> adet çekirdek dosya DEĞİŞTİRİLMİŞ!</div>`;
            if(core.missing.length > 0) html += `<div class="summary-box summary-warning"><strong>${core.missing.length}</strong> adet çekirdek dosya EKSİK.</div>`;
            if(core.modified.length > 0) {
                html += '<h3>Değiştirilmiş Dosyalar:</h3><ul class="file-list">';
                core.modified.forEach(file => { html += `<li>${escapeHTML(file)}</li>`; });
                html += '</ul>';
            }
             if(core.missing.length > 0) {
                html += '<h3>Eksik Dosyalar:</h3><ul class="file-list">';
                core.missing.forEach(file => { html += `<li>${escapeHTML(file)}</li>`; });
                html += '</ul>';
            }
        }
        startArea.style.display = 'none';
        resultsContainer.innerHTML = html;
    }
    escapeHTML = function(str) { return str.replace(/[&<>'"]/g, tag => ({'&': '&amp;', '<': '&lt;', '>': '&gt;', "'": '&#39;', '"': '&quot;'}[tag] || tag)); }
});