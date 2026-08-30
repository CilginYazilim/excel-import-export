<?php
/**
 * =====================================================================
 *  MODAL – KAYIT DETAYI (salt okunur)
 * ---------------------------------------------------------------------
 *  Buradaki tüm <dd> hücreleri BOŞ başlar; içlerini JavaScript
 *  doldurur (bkz. assets/js/app.js, "7) DETAY BUTONU").
 *
 *  NEDEN SUNUCUDA DOLDURULMUYOR?
 *  Detay, tablodaki bir satıra tıklanınca açılır. Sayfa yenilenmediği
 *  için sunucudan gelen HTML'i beklemek yerine tek bir AJAX isteğiyle
 *  veriyi alıp yerleştirmek hem hızlıdır hem de aynı modalı her kayıt
 *  için yeniden kullanmamızı sağlar.
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
<div class="modal fade cy-modal" id="detailModal" tabindex="-1" aria-labelledby="detailModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-fullscreen-sm-down">
        <div class="modal-content">

            <div class="modal-header">
                <h2 class="modal-title h6 mb-0" id="detailModalLabel">Kayıt Detayı</h2>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Kapat"></button>
            </div>

            <div class="modal-body">
                <div class="text-center mb-4">
                    <img src="" alt="" id="detail_image" class="d-none">
                    <span id="detail_initial" class="cy-avatar cy-avatar--initial cy-avatar--lg d-none"></span>
                    <h3 class="h5 mt-3 mb-0" id="detail_fullname"></h3>
                </div>

                <dl class="cy-detail">
                    <dt>Kayıt No</dt>
                    <dd id="detail_id"></dd>

                    <dt>E-posta</dt>
                    <dd id="detail_email"></dd>

                    <dt>Departman</dt>
                    <dd id="detail_departman"></dd>

                    <dt>Maaş</dt>
                    <dd id="detail_maas"></dd>

                    <dt>Başlama Tarihi</dt>
                    <dd id="detail_baslama"></dd>

                    <dt>Görsel</dt>
                    <dd id="detail_file"></dd>

                    <dt>Kayıt Tarihi</dt>
                    <dd id="detail_date"></dd>
                </dl>
            </div>

            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary cy-btn" data-bs-dismiss="modal">Kapat</button>
                <button type="button" class="btn cy-btn cy-btn--primary" id="detail_edit_button">
                    Bu Kaydı Düzenle
                </button>
            </div>
        </div>
    </div>
</div>
