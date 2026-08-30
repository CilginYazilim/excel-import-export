<?php
/**
 * =====================================================================
 *  MODAL – EXCEL'DEN İÇE AKTARMA (İKİ ADIMLI SİHİRBAZ)
 * ---------------------------------------------------------------------
 *  ADIM 1: Dosya seçimi
 *  ADIM 2: Doğrulama sonucunun önizlemesi + onay
 *
 *  İki adım TEK modalın içinde iki ayrı <div> olarak durur; hangisinin
 *  görüneceğine JavaScript karar verir. Ayrı modallar yapmak,
 *  aralarında geçerken ekranın titremesine yol açardı.
 *
 *  Kullandığı değişkenler:
 *    $excelColumns → system/function.php içindeki excel_columns()
 * =====================================================================
 */
declare(strict_types=1);

/* Doğrudan çağrılamaz: yalnızca index.php tarafından dahil edilir.
 * Gerekçesi index.php içindeki CY_APP tanımının yanında yazılıdır. */
if (!defined("CY_APP")) {
    http_response_code(404);
    exit;
}

/* Zorunlu sütun etiketlerini PHP tarafındaki TEK tanımdan üretiyoruz.
 * Listeyi elle yazmak, sütun değiştiğinde arayüzün yalan söylemesine
 * yol açardı: kullanıcı "Departman zorunlu" yazısını okuyup dosyayı
 * ona göre hazırlar, sunucu ise başka bir şey ister. */
$requiredLabels = [];

foreach ($excelColumns as $column) {
    if ($column['required']) {
        $requiredLabels[] = $column['label'];
    }
}
?>
<div class="modal fade cy-modal" id="importModal" tabindex="-1" aria-labelledby="importModalLabel" aria-hidden="true">
    <!-- Önizleme tablosu geniştir; mobilde tam ekran olması şart. -->
    <div class="modal-dialog modal-dialog-centered modal-xl modal-fullscreen-md-down">
        <div class="modal-content">

            <div class="modal-header">
                <h2 class="modal-title h6 mb-0" id="importModalLabel">Excel'den İçe Aktar</h2>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Kapat"></button>
            </div>

            <div class="modal-body">

                <!-- ---------- ADIM 1: DOSYA SEÇİMİ ---------- -->
                <div id="import_step_file">
                    <div class="alert alert-danger d-none" id="import_alert" role="alert"></div>

                    <div class="upload-box">
                        <label for="import_file" class="form-label">Excel dosyası (.xlsx)</label>
                        <input type="file" id="import_file" class="form-control"
                               accept=".xlsx,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet">
                        <div class="form-text mt-2">
                            En fazla <?= (int) (IMPORT_MAX_BYTES / 1024 / 1024) ?> MB &middot;
                            en fazla <?= number_format(IMPORT_MAX_ROWS, 0, ',', '.') ?> satır
                        </div>
                    </div>

                    <div class="cy-import-help mt-3">
                        <p class="mb-2"><strong>Nasıl çalışır?</strong></p>
                        <ol class="mb-3 ps-3">
                            <li>Dosyanın <strong>ilk satırı başlık</strong> olmalıdır.</li>
                            <li>Zorunlu sütunlar: <strong><?= e(implode(', ', $requiredLabels)) ?></strong></li>
                            <li>Sütunların <strong>sırası önemli değildir</strong>; başlık adına bakılır.</li>
                            <li>
                                Aynı <strong>e-postaya</strong> sahip kayıt varsa
                                <strong>güncellenir</strong>, yoksa yeni eklenir.
                            </li>
                            <li>Kaydetmeden önce her satır doğrulanır ve size gösterilir.</li>
                        </ol>

                        <p class="mb-0 small cy-muted">
                            Doğru başlıklara sahip boş bir dosya için tablonun üstündeki
                            <strong>📄 Örnek Excel</strong> düğmesini kullanabilirsiniz.
                        </p>
                    </div>
                </div>

                <!-- ---------- ADIM 2: ÖNİZLEME ---------- -->
                <div id="import_step_preview" class="d-none">

                    <!-- Özet rozetleri -->
                    <div class="d-flex flex-wrap gap-2 mb-3" id="import_summary"></div>

                    <div class="alert alert-warning d-none" id="import_warning" role="alert"></div>

                    <div class="table-responsive cy-preview-wrapper">
                        <table class="table table-sm cy-table cy-preview-table mb-0">
                            <thead>
                                <tr>
                                    <th scope="col" style="width:70px">Satır</th>
                                    <th scope="col" style="width:120px">Durum</th>
                                    <?php foreach ($excelColumns as $column): ?>
                                        <th scope="col"><?= e($column['label']) ?></th>
                                    <?php endforeach; ?>
                                </tr>
                            </thead>
                            <tbody id="import_preview_body"></tbody>
                        </table>
                    </div>
                </div>
            </div>

            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary cy-btn" data-bs-dismiss="modal">
                    Vazgeç
                </button>

                <!-- Adım 1'in butonu -->
                <button type="button" class="btn cy-btn cy-btn--primary" id="preview_button">
                    <span class="spinner-border spinner-border-sm me-1 d-none" id="preview_spinner"
                          role="status" aria-hidden="true"></span>
                    <span id="preview_label">Dosyayı Kontrol Et</span>
                </button>

                <!-- Adım 2'nin butonları -->
                <button type="button" class="btn btn-outline-secondary cy-btn d-none" id="import_back_button">
                    Başka Dosya Seç
                </button>

                <button type="button" class="btn cy-btn cy-btn--primary d-none" id="commit_button">
                    <span class="spinner-border spinner-border-sm me-1 d-none" id="commit_spinner"
                          role="status" aria-hidden="true"></span>
                    <span id="commit_label">Kaydet</span>
                </button>
            </div>
        </div>
    </div>
</div>
