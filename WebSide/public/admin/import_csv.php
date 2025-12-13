<?php
require_once __DIR__ . '/../includes/auth_check.php';
require_once __DIR__ . '/../includes/db.php';
require_login();

if (!is_admin()) {
    header("Location: /index.php");
    exit;
}

include 'includes/header.php';
include 'includes/sidebar.php';
?>

<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">

<style>
    body { font-family: 'Segoe UI', sans-serif; background: #f8f9fa; }
    .content { padding: 30px; min-height: calc(100vh - 60px); }
    
    .upload-card {
        border-radius: 12px;
        background: white;
        box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
        padding: 40px;
        text-align: center;
        border: 2px dashed #0047AB;
    }

    .upload-card.dragover {
        background: #f0f7ff;
    }

    .upload-icon { font-size: 3rem; color: #0047AB; margin-bottom: 20px; }
    .upload-title { font-size: 1.5rem; font-weight: 700; color: #0047AB; }
    .upload-subtitle { color: #666; margin: 15px 0; }

    .upload-btn {
        background: #0047AB;
        color: white;
        border: none;
        padding: 12px 40px;
        border-radius: 6px;
        font-weight: 600;
        cursor: pointer;
        transition: all 0.3s;
    }

    .upload-btn:hover:not(:disabled) {
        background: #003580;
        transform: translateY(-2px);
    }

    .upload-btn:disabled { background: #ccc; cursor: not-allowed; }

    .file-input { display: none; }

    .progress { height: 30px; margin: 20px 0; }
    .progress-bar { font-weight: 600; display: flex; align-items: center; justify-content: center; }

    .stats-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
        gap: 15px;
        margin-top: 20px;
    }

    .stat-card {
        background: white;
        padding: 20px;
        border-radius: 8px;
        border-left: 4px solid #0047AB;
        text-align: center;
    }

    .stat-label { font-size: 0.8rem; color: #666; text-transform: uppercase; margin-bottom: 8px; }
    .stat-value { font-size: 2.5rem; font-weight: 700; color: #0047AB; }

    .stat-card.success { border-left-color: #28a745; }
    .stat-card.success .stat-value { color: #28a745; }

    .stat-card.error { border-left-color: #dc3545; }
    .stat-card.error .stat-value { color: #dc3545; }

    .spinner-loading {
        width: 14px;
        height: 14px;
        border: 2px solid #0047AB;
        border-top: 2px solid transparent;
        border-radius: 50%;
        animation: spin 0.8s linear infinite;
        display: inline-block;
    }

    @keyframes spin {
        0% { transform: rotate(0deg); }
        100% { transform: rotate(360deg); }
    }
</style>

<div class="content">
    <div class="container-fluid">

        <div class="mb-5">
            <h2 class="text-primary fw-bold mb-2">📤 Import CSV Data</h2>
            <p class="text-secondary">Fast import for 30,000+ records</p>
        </div>

        <!-- Upload Card -->
        <div class="row">
            <div class="col-lg-8 mx-auto">
                <div class="upload-card" id="uploadCard">
                    <div class="upload-icon">📁</div>
                    <div class="upload-title">Choose CSV File</div>
                    <p class="upload-subtitle">Drag & drop or click to browse</p>
                    
                    <input type="file" id="csvFile" class="file-input" accept=".csv" />
                    
                    <button class="upload-btn" id="browseBtn">
                        📂 Browse Files
                    </button>

                    <p class="text-secondary mt-3" style="font-size: 0.9rem;">
                        Supports: CSV format (max 100MB)<br>
                        Expected columns: Age, Gender, TB, DB, Alkphos, Sgpt, Sgot, TP, ALB, A/G Ratio, Result
                    </p>
                </div>
            </div>
        </div>

    </div>
</div>

<!-- Modal -->
<div class="modal fade" id="importModal" tabindex="-1" aria-hidden="true" data-bs-backdrop="static" data-bs-keyboard="false">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header border-0">
                <h5 class="modal-title fw-bold text-primary">
                    <span class="spinner-loading me-2"></span>Importing Data
                </h5>
            </div>

            <div class="modal-body">
                <!-- Progress -->
                <div class="mb-4">
                    <div class="d-flex justify-content-between mb-2">
                        <span class="fw-bold">Progress</span>
                        <span id="progressText" class="badge bg-primary">0%</span>
                    </div>
                    <div class="progress">
                        <div id="progressBar" class="progress-bar bg-primary" style="width: 0%"></div>
                    </div>
                </div>

                <!-- Status -->
                <div class="mb-4">
                    <h6 class="fw-bold mb-2">Status:</h6>
                    <p id="statusText" class="text-secondary">Preparing...</p>
                </div>

                <!-- Stats -->
                <div class="stats-grid">
                    <div class="stat-card success">
                        <div class="stat-label">✅ Imported</div>
                        <div class="stat-value" id="importedCount">0</div>
                    </div>
                    <div class="stat-card error">
                        <div class="stat-label">❌ Failed</div>
                        <div class="stat-value" id="failedCount">0</div>
                    </div>
                </div>
            </div>

            <div class="modal-footer border-0">
                <button type="button" class="btn btn-primary" id="completeBtn" disabled onclick="window.location.href='dataset.php'">
                    ✅ Done
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Scripts -->
<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>

<script>
$(document).ready(function() {
    const uploadCard = $('#uploadCard');
    const csvFile = $('#csvFile');
    const browseBtn = $('#browseBtn');
    const importModal = new bootstrap.Modal(document.getElementById('importModal'));
    
    // Drag & drop
    uploadCard.on('dragover', (e) => {
        e.preventDefault();
        uploadCard.addClass('dragover');
    });

    uploadCard.on('dragleave', () => uploadCard.removeClass('dragover'));

    uploadCard.on('drop', (e) => {
        e.preventDefault();
        uploadCard.removeClass('dragover');
        const files = e.originalEvent.dataTransfer.files;
        if (files.length > 0) {
            csvFile[0].files = files;
            startImport();
        }
    });

    browseBtn.on('click', () => csvFile.click());
    csvFile.on('change', startImport);

    function startImport() {
        const file = csvFile[0].files[0];
        if (!file) return;

        if (!file.name.endsWith('.csv')) {
            alert('❌ Please select a CSV file');
            return;
        }

        importModal.show();
        uploadFile(file);
    }

    function uploadFile(file) {
        const formData = new FormData();
        formData.append('csvFile', file);

        updateProgress(10, 'Uploading file...');
        updateStats(0, 0);

        $.ajax({
            url: 'upload_csv.php',
            type: 'POST',
            data: formData,
            processData: false,
            contentType: false,
            success: (resp) => {
                console.log('Response:', resp);
                if (resp.success) {
                    updateProgress(100, '✅ Import complete!');
                    updateStats(resp.imported || 0, resp.failed || 0);
                    $('#completeBtn').prop('disabled', false);
                } else {
                    updateProgress(100, '❌ Error: ' + (resp.error || 'Unknown error'));
                }
            },
            error: (err) => {
                console.error('Error:', err);
                updateProgress(100, '❌ Upload failed');
                $('#completeBtn').prop('disabled', false);
            }
        });
    }

    function updateProgress(percent, message) {
        $('#progressBar').css('width', percent + '%');
        $('#progressText').text(percent + '%');
        if (message) $('#statusText').text(message);
    }

    function updateStats(imported, failed) {
        $('#importedCount').text(imported.toLocaleString());
        $('#failedCount').text(failed.toLocaleString());
    }
});
</script>

<?php include 'includes/footer.php'; ?>
