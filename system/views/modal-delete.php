<?php
/**
 * =====================================================================
 *  MODAL – SİLME ONAYI
 * ---------------------------------------------------------------------
 *  NEDEN AYRI BİR ONAY ADIMI VAR?
 *  Silme geri alınamaz ve kayıtla birlikte diskteki görseli de siler.
 *  Tarayıcının yerleşik confirm() kutusu bu iş için yetersizdir:
 *  hangi kaydın silineceğini gösteremez. Buradaki modal, silinecek
 *  kişinin adını ekrana yazar; yanlış satıra tıklama en sık yapılan
 *  kullanıcı hatasıdır.
 * =====================================================================
 */
declare(strict_types=1);

/* Doğrudan çağrılamaz: yalnızca index.php tarafından dahil edilir.
 * Gerekçesi index.php içindeki CY_APP tanımının yanında yazılıdır. */
if (!defined("CY_APP")) {
    http_response_code(404);
    exit;
}
?>
<div class="modal fade cy-modal" id="deleteModal" tabindex="-1" aria-labelledby="deleteModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-sm">
        <div class="modal-content">

            <div class="modal-header">
                <h2 class="modal-title h6 mb-0" id="deleteModalLabel">Kaydı Sil</h2>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Kapat"></button>
            </div>

            <div class="modal-body text-center">
                <div class="delete-icon" aria-hidden="true">!</div>
                <p class="mb-0">
                    <strong id="delete_label"></strong> kaydı ve varsa görseli
                    <u>kalıcı olarak</u> silinecek.
                </p>
            </div>

            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary cy-btn btn-sm" data-bs-dismiss="modal">
                    Vazgeç
                </button>
                <button type="button" class="btn btn-danger cy-btn btn-sm" id="confirm_delete">
                    Evet, Sil
                </button>
            </div>
        </div>
    </div>
</div>
