<?php
/**
 * =====================================================================
 *  MODAL – EKLEME / DÜZENLEME FORMU
 * ---------------------------------------------------------------------
 *  index.php tarafından include edilir. Kullandığı değişkenler:
 *    $csrfToken  → forma gömülen CSRF anahtarı
 *
 *  NEDEN TEK FORM İKİ İŞ YAPIYOR?
 *  "Ekle" ve "Düzenle" alanları birebir aynıdır; ikinci bir form
 *  yazmak, ileride bir alan eklendiğinde birini güncelleyip diğerini
 *  unutmak demektir. Hangi işlemin yapılacağını gizli "action" alanı
 *  belirler; JavaScript modalı açarken onu doldurur.
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
<div class="modal fade cy-modal" id="userModal" tabindex="-1" aria-labelledby="userModalLabel" aria-hidden="true">
    <!--
        modal-fullscreen-sm-down : 576px altında modal ekranı tamamen
        kaplar. Küçük ekranda ortalanmış bir kutu, kenarlarda kullanılamaz
        bir boşluk bırakıyor ve form alanları gereksiz yere daralıyordu;
        tam ekranda klavye açıldığında da içerik kaymıyor.
    -->
    <div class="modal-dialog modal-dialog-centered modal-lg modal-fullscreen-sm-down">
        <form method="post" id="user_form" enctype="multipart/form-data" novalidate>
            <div class="modal-content">

                <div class="modal-header">
                    <h2 class="modal-title h6 mb-0" id="userModalLabel">Yeni Kayıt Ekle</h2>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Kapat"></button>
                </div>

                <div class="modal-body">
                    <div class="alert alert-danger d-none" id="form_alert" role="alert"></div>

                    <div class="row g-3">
                        <div class="col-md-6">
                            <label for="name" class="form-label">Ad <span class="text-danger">*</span></label>
                            <input type="text" name="name" id="name" class="form-control"
                                   placeholder="Örn: Evren" maxlength="150" autocomplete="given-name">
                            <div class="invalid-feedback" data-error-for="name"></div>
                        </div>

                        <div class="col-md-6">
                            <label for="surname" class="form-label">Soyad <span class="text-danger">*</span></label>
                            <input type="text" name="surname" id="surname" class="form-control"
                                   placeholder="Örn: ÇILGIN" maxlength="150" autocomplete="family-name">
                            <div class="invalid-feedback" data-error-for="surname"></div>
                        </div>

                        <div class="col-md-6">
                            <label for="email" class="form-label">E-posta <span class="text-danger">*</span></label>
                            <input type="email" name="email" id="email" class="form-control"
                                   placeholder="ornek@ornek.com" maxlength="190" autocomplete="email">
                            <div class="form-text">
                                İçe aktarmada bu alan <strong>anahtar</strong> olarak kullanılır.
                            </div>
                            <div class="invalid-feedback" data-error-for="email"></div>
                        </div>

                        <div class="col-md-6">
                            <label for="departman" class="form-label">Departman</label>
                            <input type="text" name="departman" id="departman" class="form-control"
                                   placeholder="Örn: Yazılım" maxlength="100">
                            <div class="invalid-feedback" data-error-for="departman"></div>
                        </div>

                        <div class="col-md-6">
                            <label for="maas" class="form-label">Maaş</label>
                            <input type="text" name="maas" id="maas" class="form-control"
                                   placeholder="Örn: 92.500,00" inputmode="decimal">
                            <div class="form-text">Boş bırakılabilir.</div>
                            <div class="invalid-feedback" data-error-for="maas"></div>
                        </div>

                        <div class="col-md-6">
                            <label for="baslama_tarihi" class="form-label">Başlama Tarihi</label>
                            <!--
                                type="date" tarayıcının takvimini açar ve değeri her zaman
                                YYYY-MM-DD biçiminde gönderir; sunucudaki validate_tarih()
                                bu biçimi zaten kabul eder.
                            -->
                            <input type="date" name="baslama_tarihi" id="baslama_tarihi" class="form-control">
                            <div class="invalid-feedback" data-error-for="baslama_tarihi"></div>
                        </div>

                        <div class="col-12">
                            <label for="image_user" class="form-label">Profil Görseli</label>
                            <div class="upload-box">
                                <input type="file" name="image_user" id="image_user" class="form-control"
                                       accept="image/jpeg,image/png,image/gif,image/webp">
                                <div class="form-text mt-2">
                                    JPG, PNG, GIF veya WEBP &middot;
                                    en fazla <?= (int) (UPLOAD_MAX_BYTES / 1024 / 1024) ?> MB &middot;
                                    boş bırakılabilir.
                                </div>
                            </div>
                            <div class="invalid-feedback d-block" data-error-for="image_user"></div>
                        </div>
                    </div>

                    <div id="image_preview_wrapper" class="d-none mt-3 text-center">
                        <span class="form-label d-block mb-1 cy-muted small">Önizleme</span>
                        <img src="" alt="Seçilen görselin önizlemesi" id="image_preview">
                    </div>
                </div>

                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary cy-btn" data-bs-dismiss="modal">
                        İptal
                    </button>
                    <button type="submit" id="submit_button" class="btn cy-btn cy-btn--primary">
                        <span class="spinner-border spinner-border-sm me-1 d-none" id="submit_spinner"
                              role="status" aria-hidden="true"></span>
                        <span id="submit_label">Kaydet</span>
                    </button>
                </div>

                <input type="hidden" name="action"     id="form_action" value="add">
                <input type="hidden" name="user_id"    id="user_id"     value="">
                <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
            </div>
        </form>
    </div>
</div>
